<?php

return [
    'seed' => [
        'tenant_name' => env('JOTTER_TENANT_NAME', 'Jotter'),
        'tenant_slug' => env('JOTTER_TENANT_SLUG', 'default'),
        'workspace_name' => env('JOTTER_WORKSPACE_NAME', 'Default Workspace'),
        'workspace_slug' => env('JOTTER_WORKSPACE_SLUG', 'default'),
        'vault_path' => env('JOTTER_VAULT_PATH') ?: storage_path('app/vaults/default'),
    ],

    'vault' => [
        // Bounded reconcile batch size for shared-hosting PHP memory/time limits.
        'reindex_batch_size' => (int) env('JOTTER_VAULT_REINDEX_BATCH', 50),
    ],
];
