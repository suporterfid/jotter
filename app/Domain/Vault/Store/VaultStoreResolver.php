<?php

namespace App\Domain\Vault\Store;

use App\Domain\Vault\VaultPathGuard;
use App\Models\TenantVaultStore;
use App\Models\Workspace;

/**
 * The only place that knows which driver holds a workspace's vault. A
 * workspace without a store uses its local `vault_path`, exactly as before
 * pluggable stores existed.
 */
class VaultStoreResolver
{
    public function __construct(
        private readonly VaultPathGuard $guard = new VaultPathGuard,
        private readonly VaultStoreDrivers $drivers = new VaultStoreDrivers,
    ) {}

    public function for(Workspace $workspace): VaultStore
    {
        if ($workspace->vault_store_id === null) {
            return $this->local($workspace);
        }

        $record = $workspace->vaultStore;
        if ($record === null) {
            throw new StoreUnavailable("Workspace {$workspace->id} points at a missing vault store.");
        }

        return $this->forStore($record, $workspace, (string) $workspace->vault_prefix);
    }

    /** The workspace's own folder (`vault_path`), whatever store it currently uses. */
    public function local(Workspace $workspace): LocalVaultStore
    {
        return new LocalVaultStore((string) $workspace->vault_path, $workspace, $this->guard);
    }

    /**
     * A workspace's view of a registered store, under $prefix (`vault:move`
     * uses it for the destination before the swap).
     *
     * @throws StoreUnavailable
     */
    public function forStore(TenantVaultStore $record, Workspace $workspace, string $prefix = ''): VaultStore
    {
        if (! $this->drivers->isEnabled($record->driver)) {
            throw new StoreUnavailable("Vault driver [{$record->driver}] is not enabled (JOTTER_VAULT_DRIVERS).");
        }

        $prefix = trim($prefix, '/');
        if ($prefix !== '') {
            $prefix = VaultPathNormalizer::normalize($prefix, nfc: true, workspaceId: (int) $workspace->id);
        }

        return match ($record->driver) {
            VaultStoreDrivers::LOCAL => new LocalVaultStore(
                rtrim((string) ($record->settings['root'] ?? ''), '/').($prefix === '' ? '' : '/'.$prefix),
                $workspace,
                $this->guard,
            ),
            // D1: one repository per workspace, so the prefix is always '' on GitHub.
            VaultStoreDrivers::GITHUB => app(GitHub\GitHubStoreFactory::class)->store($record, $workspace),
            default => throw new StoreUnavailable("Vault driver [{$record->driver}] is not available in this version of Jotter."),
        };
    }
}
