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

    'auth_provider' => env('JOTTER_AUTH_PROVIDER', env('AUTH_PROVIDER', 'local')),
    'auth_bypass' => (bool) env('JOTTER_AUTH_BYPASS', false),

    // GrandpaSSOnIdentityProvider's AUTHSESSID cookie path reads GrandpaSSOn's own
    // `sessions`/`users` tables directly. On shared hosting they share one MySQL
    // database/schema with this app, distinguished only by table-name prefix — this
    // must match GrandpaSSOn's own DB_PREFIX, not Jotter's (JOTTER_DB_PREFIX/DB_PREFIX).
    'sso' => [
        'db_prefix' => env('JOTTER_SSO_DB_PREFIX', 'sso_'),
    ],

    'attachments' => [
        'max_size_kb' => (int) env('JOTTER_ATTACHMENT_MAX_SIZE_KB', 20480), // 20MB
        'allowed_mimes' => [
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/svg+xml',
            'image/webp',
            'image/avif',
            'application/pdf',
            'text/plain',
            'text/markdown',
            'text/csv',
            'application/json',
            'audio/mpeg',
            'audio/wav',
            'audio/ogg',
            'video/mp4',
            'video/webm',
        ],
        'allowed_extensions' => [
            'png', 'jpg', 'jpeg', 'gif', 'svg', 'webp', 'avif',
            'pdf', 'txt', 'md', 'csv', 'json',
            'mp3', 'wav', 'ogg', 'mp4', 'webm',
        ],
    ],
];
