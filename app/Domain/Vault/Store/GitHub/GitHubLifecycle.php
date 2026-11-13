<?php

namespace App\Domain\Vault\Store\GitHub;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use App\Models\VaultJournalEntry;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;

/**
 * Lifecycle and LGPD with a workspace on GitHub (#444, general plan §6):
 *
 * - erase(): `tenant:delete` (and the erasure-log replay after a restore)
 *   deletes every file of the tenant's repositories in one final commit. Git
 *   history still holds the content and Jotter never rewrites it: the
 *   organization, controller of the repository (it confirmed so when
 *   connecting), is told to archive or delete the repository.
 * - afterRestore(): the repository is the truth. The manifest, sync cursors
 *   and blob cache are not in backups; they are dropped so the next pull reads
 *   the whole tree again and re-projects every note. Restored journal rows keep
 *   their base version, so an edit older than GitHub's becomes a conflict copy,
 *   never a silent overwrite.
 * - status(): the pending / synced / delayed / read-only badge of a workspace.
 */
class GitHubLifecycle
{
    public function __construct(
        private readonly GitHubStoreFactory $factory,
        private readonly GitHubFlusher $flusher,
        private readonly AuditRecorder $audit = new AuditRecorder,
    ) {}

    /**
     * @return list<array{store_id: int, repository: string, status: string, guidance: string}>
     */
    public function erase(Tenant $tenant, ?string $actorId = null): array
    {
        $results = [];
        $stores = TenantVaultStore::query()->where('tenant_id', $tenant->id)->where('driver', 'github')->orderBy('id')->get();

        foreach ($stores as $record) {
            $repository = $this->repository($record);
            $guidance = "Archive or delete the GitHub repository {$repository}: its Git history still holds the deleted content, and Jotter never rewrites history.";
            $status = 'erased';

            try {
                if ($record->status === TenantVaultStore::STATUS_FAILED) {
                    $status = 'unreachable';
                } else {
                    $workspace = Workspace::query()->where('vault_store_id', $record->id)->first()
                        ?? new Workspace(['tenant_id' => $tenant->id, 'slug' => 'erase', 'name' => 'erase']);
                    $this->factory->store($record, $workspace)->deleteDirectory('');
                    $result = $this->flusher->flush($record, maxBatches: 1000, ignoreBackoff: true);
                    $left = VaultJournalEntry::query()->where('vault_store_id', $record->id)->whereNull('flushed_at')->count();
                    $status = $left === 0 && in_array($result['status'], ['flushed'], true) ? 'erased' : 'incomplete: '.$result['status'];
                }
            } catch (\Throwable $exception) {
                $status = 'failed: '.mb_substr($exception->getMessage(), 0, 300);
            }

            // Detached from the tenant: the tenant's own audit rows go with it, this one stays.
            $this->audit->record(AuditEvent::VAULT_REMOTE_ERASED, null, null, $actorId, [
                'tenant' => (string) $tenant->slug,
                'vault_store_id' => $record->id,
                'repository' => $repository,
                'status' => $status,
                'guidance' => $guidance,
            ]);
            $results[] = ['store_id' => (int) $record->id, 'repository' => $repository, 'status' => $status, 'guidance' => $guidance];
        }

        return $results;
    }

    /** @return list<string> repositories a tenant deletion would empty */
    public function repositoriesOf(Tenant $tenant): array
    {
        return TenantVaultStore::query()->where('tenant_id', $tenant->id)->where('driver', 'github')->orderBy('id')->get()
            ->map(fn (TenantVaultStore $record): string => $this->repository($record))->values()->all();
    }

    public function afterRestore(): void
    {
        foreach (['vault_remote_files', 'vault_sync_states', 'vault_blob_cache'] as $table) {
            DB::table($table)->delete();
        }
        // Commits that were in flight when the backup was taken are unknown now.
        VaultJournalEntry::query()->whereNull('flushed_at')->update(['flush_id' => null, 'commit_sha' => null]);
        TenantVaultStore::query()->where('driver', 'github')->update(['status' => TenantVaultStore::STATUS_UNTESTED]);
    }

    /**
     * @return array{driver: string, state: string, pending: int}|null null for the local folder
     */
    public static function status(Workspace $workspace): ?array
    {
        if ($workspace->vault_store_id === null) {
            return null;
        }
        $record = TenantVaultStore::query()->find($workspace->vault_store_id);
        if ($record === null) {
            return null;
        }
        if ($record->driver !== 'github') {
            return ['driver' => (string) $record->driver, 'state' => 'synced', 'pending' => 0];
        }

        $pending = VaultJournalEntry::query()->where('vault_store_id', $record->id)->whereNull('flushed_at')->count();
        $sync = DB::table('vault_sync_states')->where('vault_store_id', $record->id)->first(['next_flush_at', 'failing_since']);
        $oldest = VaultJournalEntry::query()->where('vault_store_id', $record->id)->whereNull('flushed_at')->min('created_at');

        $state = match (true) {
            $record->status === TenantVaultStore::STATUS_FAILED => 'read_only',
            $pending === 0 => 'synced',
            $sync?->failing_since !== null,
            $sync?->next_flush_at !== null && now()->lt($sync->next_flush_at),
            $oldest !== null && now()->diffInMinutes($oldest, true) > 5 => 'delayed',
            default => 'pending',
        };

        return ['driver' => 'github', 'state' => $state, 'pending' => $pending];
    }

    private function repository(TenantVaultStore $record): string
    {
        return ($record->settings['owner'] ?? '?').'/'.($record->settings['repo'] ?? '?').'@'.$this->factory->branch($record);
    }
}
