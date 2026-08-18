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

    'trash' => [
        'retention_days' => (int) env('JOTTER_TRASH_RETENTION_DAYS', 30),
        'purge_batch_size' => (int) env('JOTTER_TRASH_PURGE_BATCH', 100),
    ],

    'rendering' => [
        // Off by default: emits <span class="jotter-math">/<pre class="mermaid">
        // markup for $$LaTeX$$ and ```mermaid blocks instead of plain code, for a
        // client-side library (KaTeX/mermaid.js) to hydrate. When off, both
        // constructs render as plain code, matching Obsidian's own fallback when
        // the corresponding plugin/feature is unavailable.
        'katex_mermaid_enabled' => (bool) env('JOTTER_ENABLE_MATH_MERMAID', false),
    ],

    'external_embed_domains' => array_values(array_unique(array_filter(array_map(
        static fn (string $host): string => strtolower(trim($host)),
        explode(',', (string) env('JOTTER_EXTERNAL_EMBED_DOMAINS', '')),
    ), static fn (string $host): bool => $host !== ''))),

    'auth_provider' => env('JOTTER_AUTH_PROVIDER', env('AUTH_PROVIDER', 'local')),
    'auth_bypass' => (bool) env('JOTTER_AUTH_BYPASS', false),

    'oidc' => [
        'issuer_url' => env('JOTTER_OIDC_ISSUER_URL'),
        'client_id' => env('JOTTER_OIDC_CLIENT_ID'),
        'client_secret' => env('JOTTER_OIDC_CLIENT_SECRET'),
        'redirect_uri' => env('JOTTER_OIDC_REDIRECT_URI'),
        'scopes' => array_values(array_filter(array_map(
            static fn (string $scope): string => trim($scope),
            explode(' ', (string) env('JOTTER_OIDC_SCOPES', 'openid profile email')),
        ))),
        'post_login_redirect_uri' => env('JOTTER_OIDC_POST_LOGIN_REDIRECT_URI', env('APP_URL')),
        'allow_insecure_http' => (bool) env('JOTTER_OIDC_ALLOW_INSECURE_HTTP', false),
        'trusted_email_claim' => (bool) env('JOTTER_OIDC_TRUSTED_EMAIL_CLAIM', false),
        'configured' => filled(env('JOTTER_OIDC_ISSUER_URL'))
            && filled(env('JOTTER_OIDC_CLIENT_ID'))
            && filled(env('JOTTER_OIDC_CLIENT_SECRET'))
            && filled(env('JOTTER_OIDC_REDIRECT_URI')),
    ],

    // GrandpaSSOnIdentityProvider's AUTHSESSID cookie path reads GrandpaSSOn's own
    // `sessions`/`users` tables directly. On shared hosting they share one MySQL
    // database/schema with this app, distinguished only by table-name prefix — this
    // must match GrandpaSSOn's own DB_PREFIX, not Jotter's (JOTTER_DB_PREFIX/DB_PREFIX).
    'sso' => [
        'db_prefix' => env('JOTTER_SSO_DB_PREFIX', 'sso_'),
        // Used to build the "Sign in with GrandpaSSOn" redirect URL when
        // auth_provider=grandpasson. broker_base_url is GrandpaSSOn's own
        // mount point (e.g. https://hub.taskconnect.com.br/sso); client_id
        // must match a client registered in GrandpaSSOn's oauth_clients
        // table (via cron/seed_oauth_client.php) with a redirect_uri
        // allowing this app's URL.
        'broker_base_url' => env('JOTTER_SSO_BROKER_BASE_URL'),
        'client_id' => env('JOTTER_SSO_CLIENT_ID'),
    ],

    // GrandpaSSOn service-token (client_credentials) inbound auth for
    // systemic REST API integrations — see docs/superpowers/specs/
    // 2026-08-05-grandpasson-service-tokens-design.md. Off by default;
    // when off, GrandpaSSOnIdentityProvider::resolveIdentity() behaves
    // exactly as it did before this feature existed.
    'grandpasson_resource' => [
        'inbound_enabled' => (bool) env('JOTTER_GRANDPASSON_INBOUND_ENABLED', false),
        'introspect_url' => env('JOTTER_GRANDPASSON_INTROSPECT_URL'),
        'client_id' => env('JOTTER_GRANDPASSON_CLIENT_ID'),
        'client_secret' => env('JOTTER_GRANDPASSON_CLIENT_SECRET'),
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
