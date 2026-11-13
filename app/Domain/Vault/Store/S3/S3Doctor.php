<?php

namespace App\Domain\Vault\Store\S3;

use App\Domain\Health\DoctorCheck;
use App\Domain\Vault\Store\VaultStoreDrivers;
use App\Models\TenantVaultStore;
use App\Models\Workspace;
use Illuminate\Support\Facades\Schema;

/**
 * `jotter:doctor` for S3 stores (#447, plan §6.2, §6.4, §6.5): reads each
 * bucket's versioning and lifecycle (two small calls per store, 3 s / 10 s
 * timeouts), warns when versioning is off (backups then include the
 * workspace) or when old versions outlive the 30-day backup window, and
 * fails for stores the provider refused and workspaces left `degraded`.
 */
class S3Doctor
{
    public function __construct(private readonly ?S3BucketInspector $inspector = null) {}

    public function check(bool $databaseReachable): DoctorCheck
    {
        $label = 'S3 vault stores';
        if (! (new VaultStoreDrivers)->isEnabled(VaultStoreDrivers::S3)) {
            return DoctorCheck::pass('s3_vault', $label, false, 'S3 driver off (JOTTER_VAULT_DRIVERS).');
        }
        if (! $databaseReachable || ! Schema::hasTable('vault_stores')) {
            return DoctorCheck::pass('s3_vault', $label, false, 'Database not reachable; S3 stores not checked.');
        }

        $inspector = $this->inspector ?? app(S3BucketInspector::class);
        $problems = [];
        $warnings = [];
        $details = ['stores' => []];
        $stores = TenantVaultStore::query()->where('driver', VaultStoreDrivers::S3)->orderBy('id')->get();

        foreach ($stores as $store) {
            $name = (string) $store->name;
            $workspaces = Workspace::query()->where('vault_store_id', $store->id)->get(['id', 'slug', 'storage_state']);
            $state = $store->decryptedCredentials() === null
                ? ['versioning' => null, 'noncurrent_expiry_days' => null, 'lifecycle_rules' => null, 'error' => 'credentials do not decrypt']
                : $inspector->inspect($store);

            $details['stores'][] = [
                'id' => $store->id,
                'name' => $name,
                'bucket' => ($store->settings['bucket'] ?? '').'/'.trim((string) ($store->settings['prefix'] ?? ''), '/'),
                'endpoint' => $store->settings['endpoint'] ?? null,
                'status' => $store->status,
                'workspaces' => $workspaces->pluck('slug')->all(),
                'versioning' => $state['versioning'],
                'noncurrent_expiry_days' => $state['noncurrent_expiry_days'],
                'conditional_writes' => app(S3StoreFactory::class)->conditionalWrites($store),
            ];

            if ($store->status === TenantVaultStore::STATUS_FAILED) {
                $problems[] = "{$name}: the provider refused access ({$store->last_error}); its workspace is read-only until the connection is tested again";
            }
            foreach ($workspaces as $workspace) {
                if ($workspace->storage_state === 'degraded' && $store->status !== TenantVaultStore::STATUS_FAILED) {
                    $problems[] = "{$name}: workspace {$workspace->slug} is degraded (the bucket was unreachable); test the connection to clear it";
                }
            }
            // A store with no workspace yet is still worth the warning: a move would use it.
            foreach (S3BucketInspector::warnings($state, $name) as $warning) {
                $warnings[] = $warning;
            }
            if (! app(S3StoreFactory::class)->conditionalWrites($store)) {
                $warnings[] = "{$name}: no native conditional writes (GCS); Jotter serializes its own writes, but edits made directly in the bucket can be overwritten";
            }
        }

        $details['warnings'] = $warnings;
        $access = ' Anyone with direct access to a bucket sees every note of its workspaces, whatever the note permissions in Jotter.';

        if ($problems !== []) {
            $extra = $warnings === [] ? '' : ' Warnings: '.implode('; ', $warnings).'.';

            return DoctorCheck::fail('s3_vault', $label, false, implode('; ', $problems).'.'.$extra.$access, $details);
        }

        $count = $stores->count();
        $summary = $count === 0 ? 'No S3 store yet.' : sprintf('%d S3 store%s working.', $count, $count === 1 ? '' : 's');
        if ($warnings !== []) {
            $summary .= ' Warnings: '.implode('; ', $warnings).'.';
        }

        return DoctorCheck::pass('s3_vault', $label, false, $summary.$access, $details);
    }
}
