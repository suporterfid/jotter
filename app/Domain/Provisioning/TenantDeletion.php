<?php

namespace App\Domain\Provisioning;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Users\UserDeletion;
use App\Models\Identity;
use App\Models\Membership;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * Removes a whole tenant (hosted customer) at the end of the agreed retention
 * period (LGPD art. 16), replacing the manual `tinker` runbook:
 *
 * - accounts that belong only to this tenant are deleted through UserDeletion
 *   (administrators and people with memberships elsewhere only lose their
 *   memberships here);
 * - access records (AuditEvent::accessRecordValues(): sign-in, sign-out,
 *   tokens issued, MCP connections) are kept for the Marco Civil art. 15
 *   window but detached from the tenant; `audit:prune` removes them later.
 *   Every other audit row of the tenant is deleted;
 * - the tenant row goes, and with it (foreign-key cascades) its workspaces,
 *   notes, revisions, comments, tokens, grants, groups and projections;
 * - each workspace vault directory is removed from disk, but only when it lies
 *   inside the configured vault base path;
 * - each GitHub repository of the tenant gets a final commit removing every
 *   file, before anything else (#444); its Git history stays, so the command
 *   prints and audits the advice to archive or delete the repository.
 *
 * Instance backups taken before this keep the data until they rotate out
 * (JOTTER_BACKUP_KEEP); the privacy policy says so.
 */
final class TenantDeletion
{
    public function __construct(
        private readonly UserDeletion $users = new UserDeletion,
        private readonly AuditRecorder $audit = new AuditRecorder,
    ) {}

    /**
     * What a deletion would do, without changing anything.
     *
     * @return array{tenant: string, workspaces: list<array{slug: string, vault_path: string, removable: bool}>, remote_vaults: list<string>, delete_users: list<string>, keep_users: list<string>}
     */
    public function plan(Tenant $tenant): array
    {
        [$delete, $keep] = $this->partitionUsers($tenant);

        return [
            'remote_vaults' => app(\App\Domain\Vault\Store\GitHub\GitHubLifecycle::class)->repositoriesOf($tenant),
            'tenant' => (string) $tenant->slug,
            'workspaces' => $tenant->workspaces()->orderBy('id')->get()->map(fn (Workspace $workspace): array => [
                'slug' => (string) $workspace->slug,
                'vault_path' => (string) $workspace->vault_path,
                'removable' => $this->removableVault((string) $workspace->vault_path) !== null,
            ])->values()->all(),
            'delete_users' => $delete->pluck('email')->values()->all(),
            'keep_users' => $keep->pluck('email')->values()->all(),
        ];
    }

    /**
     * @throws RuntimeException when the tenant must not be deleted
     * @return array<string, mixed>
     */
    public function delete(Tenant $tenant, ?string $actorId = null): array
    {
        if (Tenant::query()->whereKeyNot($tenant->id)->doesntExist()) {
            throw new RuntimeException('This is the only tenant of the installation; it is not deleted.');
        }

        $slug = (string) $tenant->slug;
        $tenantId = (int) $tenant->id;
        $workspaces = $tenant->workspaces()->get();
        $workspaceIds = $workspaces->pluck('id')->map(static fn ($id): int => (int) $id)->all();
        [$deleteUsers] = $this->partitionUsers($tenant);
        $counts = ['workspaces' => count($workspaceIds), 'users_deleted' => 0];

        // Remote repositories first: once the tenant row goes, their credentials go with it.
        $counts['remote_vaults'] = app(\App\Domain\Vault\Store\GitHub\GitHubLifecycle::class)->erase($tenant, $actorId);

        DB::transaction(function () use ($tenant, $tenantId, $workspaceIds, $deleteUsers, $actorId, &$counts): void {
            foreach ($deleteUsers as $user) {
                $this->users->delete($user, $actorId);
                $counts['users_deleted']++;
            }

            $scope = static fn ($query) => $query->where('tenant_id', $tenantId)->orWhereIn('workspace_id', $workspaceIds);
            $counts['access_records_kept'] = DB::table('audit_log')
                ->where($scope)
                ->whereIn('event', AuditEvent::accessRecordValues())
                ->update(['tenant_id' => null, 'workspace_id' => null, 'note_id' => null]);
            $counts['audit_deleted'] = DB::table('audit_log')->where($scope)->delete();
            $counts['memberships'] = Membership::query()->where('tenant_id', $tenantId)->delete();

            $createdAt = $tenant->created_at?->toIso8601String();
            $tenant->delete();

            // Block 4c: a tombstone, so a restore of an older backup deletes this tenant again.
            (new \App\Domain\Privacy\ErasureLog)->record('tenant', $tenantId, $createdAt, $actorId, ['slug' => (string) $tenant->slug]);
        });

        $removed = [];
        $skipped = [];
        foreach ($workspaces as $workspace) {
            $path = $this->removableVault((string) $workspace->vault_path);
            if ($path === null) {
                $skipped[] = (string) $workspace->vault_path;

                continue;
            }
            File::deleteDirectory($path);
            $removed[] = $path;
        }
        $counts['vaults_removed'] = count($removed);
        $counts['vaults_skipped'] = $skipped;

        $this->audit->record(event: AuditEvent::TENANT_DELETED, actorId: $actorId, metadata: [
            'tenant' => $slug,
            'workspaces' => $counts['workspaces'],
            'users_deleted' => $counts['users_deleted'],
            'audit_deleted' => $counts['audit_deleted'],
            'access_records_kept' => $counts['access_records_kept'],
            'vaults_removed' => $counts['vaults_removed'],
            'vaults_skipped' => count($skipped),
            'remote_vaults' => array_map(static fn (array $r): string => $r['repository'].': '.$r['status'], $counts['remote_vaults']),
        ]);

        return $counts;
    }

    /**
     * Accounts reached by this tenant's memberships, split into those deleted
     * with it (non-administrators with no membership in any other tenant) and
     * those kept.
     *
     * @return array{0: Collection<int, User>, 1: Collection<int, User>}
     */
    private function partitionUsers(Tenant $tenant): array
    {
        $subjects = Membership::query()->where('tenant_id', $tenant->id)->pluck('subject_id')->map(static fn ($id): string => (string) $id)->unique()->values();
        $ids = $subjects->filter(static fn (string $id): bool => ctype_digit($id))->map(static fn (string $id): int => (int) $id)
            ->merge(Identity::query()->whereIn('subject_id', $subjects)->whereNotNull('user_id')->pluck('user_id'))
            ->unique()->values();
        $users = User::query()
            ->where(static fn ($query) => $query->whereIn('id', $ids)->orWhereIn('email', $subjects))
            ->orderBy('id')
            ->get();

        return $users->partition(static fn (User $user): bool => ! $user->is_admin
            && Membership::query()->whereIn('subject_id', $user->membershipSubjectIds())->where('tenant_id', '!=', $tenant->id)->doesntExist())
            ->map(static fn (Collection $part): Collection => $part->values())
            ->all();
    }

    /**
     * The canonical vault directory when it exists and lies strictly inside the
     * configured vault base path; null otherwise (never delete outside it).
     */
    private function removableVault(string $vaultPath): ?string
    {
        $base = realpath((string) (config('vault.base_path') ?: storage_path('app/vaults')));
        $path = realpath($vaultPath);
        if ($base === false || $path === false || ! is_dir($path)) {
            return null;
        }

        return str_starts_with($path, rtrim($base, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR) ? $path : null;
    }
}
