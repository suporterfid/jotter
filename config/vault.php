<?php

return [
    // An empty VAULT_BASE_PATH (as shipped in .env.example) falls back to the
    // default, matching VaultRootGuard, instead of resolving to ''.
    'base_path' => env('VAULT_BASE_PATH') ?: storage_path('app/vaults'),
];
