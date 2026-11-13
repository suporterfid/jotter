<?php

namespace App\Domain\Vault\Store;

use App\Domain\Vault\VaultPathGuard;
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
    ) {}

    public function for(Workspace $workspace): VaultStore
    {
        return new LocalVaultStore((string) $workspace->vault_path, $workspace, $this->guard);
    }
}
