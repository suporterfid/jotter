<?php

namespace App\Domain\Vault\Store\S3;

use App\Domain\Audit\AuditEvent;
use App\Domain\Audit\AuditRecorder;
use App\Domain\Vault\Store\VaultStore;
use App\Domain\Vault\Store\VaultStoreDrivers;
use App\Domain\Vault\Store\VaultStoreResolver;
use App\Domain\Vault\VaultReindexer;
use App\Models\Tenant;
use App\Models\TenantVaultStore;
use App\Models\VaultMove;
use App\Models\Workspace;

/**
 * Lifecycle and LGPD with workspaces in S3 buckets (#447, plan §6.2–§6.4):
 *
 * - erase(): `tenant:delete` (and the erasure-log replay after a restore)
 *   deletes every object under each workspace prefix of the tenant, and under
 *   every prefix a vault:move of its workspaces used (retained sources,
 *   partial or rolled-back copies). With bucket versioning on,
 *   old versions stay until the bucket's lifecycle rule expires them (≤ 30
 *   days recommended), which the result says.
 * - backupIncludes(): S3 workspaces enter the backup ZIP unless their bucket
 *   has versioning on (§6.4); unknown counts as off.
 * - afterRestore(): the bucket is the truth. Files of an S3 workspace that the
 *   backup holds and the bucket lost are put back (never over an existing
 *   object), then every S3 workspace is reindexed from its bucket.
 */
class S3Lifecycle
{
    public function __construct(
        private readonly VaultStoreResolver $resolver,
        private readonly S3BucketInspector $inspector,
        private readonly AuditRecorder $audit = new AuditRecorder,
    ) {}

    /**
     * @return list<array{store_id: int, repository: string, status: string, guidance: string}>
     */
    public function erase(Tenant $tenant, ?string $actorId = null): array
    {
        $results = [];
        foreach ($this->targetsOf($tenant) as [$record, $workspace, $prefix]) {
            $location = $this->location($record, $prefix);
            $versioned = ($record->settings['bucket_state']['versioning'] ?? null) === S3BucketInspector::ENABLED;
            $guidance = $versioned
                ? "Bucket versioning keeps the deleted objects of {$location} as old versions until its lifecycle rule expires them; shorten or run that rule if the erasure must be immediate."
                : "Objects under {$location} were deleted; the bucket keeps no old versions.";

            try {
                if ($prefix === '') {
                    throw new \RuntimeException('the workspace has no key prefix; refusing to empty the whole bucket.');
                }
                app(S3StoreFactory::class)->store($record, $workspace, $prefix)->deleteDirectory('');
                $status = 'erased';
            } catch (\Throwable $exception) {
                $status = 'failed: '.mb_substr($exception->getMessage(), 0, 300);
            }

            // Detached from the tenant: the tenant's own audit rows go with it, this one stays.
            $this->audit->record(AuditEvent::VAULT_REMOTE_ERASED, null, null, $actorId, [
                'tenant' => (string) $tenant->slug,
                'vault_store_id' => $record->id,
                'repository' => $location,
                'status' => $status,
                'guidance' => $guidance,
            ]);
            $results[] = ['store_id' => (int) $record->id, 'repository' => $location, 'status' => $status, 'guidance' => $guidance];
        }

        return $results;
    }

    /** @return list<string> bucket locations a tenant deletion would empty */
    public function locationsOf(Tenant $tenant): array
    {
        return array_map(fn (array $target): string => $this->location($target[0], $target[2]), $this->targetsOf($tenant));
    }

    /** §6.4: whether a backup copies this S3 workspace's files (unless the operator forces either way). */
    public function backupIncludes(Workspace $workspace): bool
    {
        $record = $workspace->vaultStore;
        if ($record === null || $record->driver !== VaultStoreDrivers::S3) {
            return false;
        }

        return ! $this->inspector->versioned($record);
    }

    /**
     * @param  array<int, array<string, string>>  $filesByWorkspace  workspace id => [relative path => temp file]
     * @return array{restored: int, reindexed: int, failed: list<string>}
     */
    public function afterRestore(array $filesByWorkspace = []): array
    {
        $summary = ['restored' => 0, 'reindexed' => 0, 'failed' => []];
        TenantVaultStore::query()->where('driver', VaultStoreDrivers::S3)->update(['status' => TenantVaultStore::STATUS_UNTESTED]);

        $workspaces = Workspace::query()->whereNotNull('vault_store_id')
            ->whereHas('vaultStore', fn ($query) => $query->where('driver', VaultStoreDrivers::S3))->orderBy('id')->get();

        foreach ($workspaces as $workspace) {
            try {
                $store = $this->resolver->for($workspace);
                foreach ($filesByWorkspace[(int) $workspace->id] ?? [] as $path => $file) {
                    $summary['restored'] += $this->putBackIfMissing($store, (string) $path, $file);
                }
                app(VaultReindexer::class)->reindex($workspace, actorId: 'cli:jotter:restore');
                $summary['reindexed']++;
            } catch (\Throwable $exception) {
                $summary['failed'][] = $workspace->slug.': '.mb_substr($exception->getMessage(), 0, 200);
            }
        }

        return $summary;
    }

    private function putBackIfMissing(VaultStore $store, string $path, string $file): int
    {
        $stream = fopen($file, 'rb');
        if ($stream === false) {
            return 0;
        }
        try {
            if ($store->exists($path)) {
                return 0;
            }
            $store->writeStream($path, $stream);

            return 1;
        } finally {
            fclose($stream);
        }
    }

    /**
     * Each S3 location holding the tenant's files: workspaces on S3 now, and
     * sources or copies of moves not finalized yet.
     *
     * @return list<array{0: TenantVaultStore, 1: Workspace, 2: string}>
     */
    private function targetsOf(Tenant $tenant): array
    {
        $targets = [];
        $seen = [];
        $workspaces = Workspace::query()->where('tenant_id', $tenant->id)->get();
        $add = function (?TenantVaultStore $record, Workspace $workspace, string $prefix) use (&$targets, &$seen): void {
            if ($record === null || $record->driver !== VaultStoreDrivers::S3) {
                return;
            }
            $key = $record->id.'|'.trim($prefix, '/');
            if (isset($seen[$key])) {
                return;
            }
            $seen[$key] = true;
            $targets[] = [$record, $workspace, trim($prefix, '/')];
        };

        foreach ($workspaces as $workspace) {
            $add($workspace->vaultStore, $workspace, (string) $workspace->vault_prefix);
            // Every move, whatever its status: a retained source, a partial copy, a rolled-back copy.
            $moves = VaultMove::query()->where('workspace_id', $workspace->id)->get();
            foreach ($moves as $move) {
                $add($move->fromStore, $workspace, (string) $move->from_prefix);
                $add($move->toStore, $workspace, (string) $move->to_prefix);
            }
        }

        return $targets;
    }

    private function location(TenantVaultStore $record, string $prefix): string
    {
        return 's3://'.($record->settings['bucket'] ?? '?').'/'.trim($prefix, '/');
    }
}
