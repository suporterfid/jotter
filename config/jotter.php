<?php

return [
    // Identifies one installation when several share a host (logs, doctor, support).
    'instance_slug' => env('APP_INSTANCE_SLUG'),

    // Branding. Defaults reproduce the stock Jotter UI; a hosted operator (e.g.
    // "Cadernia") overrides them by environment only. Empty strings mean unset.
    'brand' => [
        'name' => env('JOTTER_BRAND_NAME') ?: env('APP_NAME', 'Jotter'),
        'logo_url' => env('JOTTER_BRAND_LOGO_URL') ?: null,
        'support_url' => env('JOTTER_BRAND_SUPPORT_URL') ?: null,
        'terms_url' => env('JOTTER_BRAND_TERMS_URL') ?: null,
        'privacy_url' => env('JOTTER_BRAND_PRIVACY_URL') ?: null,
        'powered_by' => (bool) env('JOTTER_BRAND_POWERED_BY', true),
    ],

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

    'pdf' => [
        'storage_path' => env('JOTTER_PDF_STORAGE_PATH') ?: storage_path('app/private/pdf-exports'),
        'retention_hours' => (int) env('JOTTER_PDF_RETENTION_HOURS', 24),
        'process_batch_size' => (int) env('JOTTER_PDF_PROCESS_BATCH', 10),
    ],

    'analytics' => [
        'rollup_batch_size' => (int) env('JOTTER_ANALYTICS_ROLLUP_BATCH', 500),
        'stale_days' => (int) env('JOTTER_ANALYTICS_STALE_DAYS', 30),
        'record_reads' => (bool) env('JOTTER_ANALYTICS_RECORD_READS', false),
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

    /*
     | MCP server (POST /api/mcp). Rate limits are per credential (token id) or
     | per subject for session callers; exceeding one answers 429 + Retry-After.
     | `allowed_origins` lists extra browser origins accepted in the Origin
     | header besides APP_URL's (DNS-rebinding guard, MCP 2025-06-18+).
     */
    'mcp' => [
        'rate_limits' => [
            'calls_per_minute' => (int) env('JOTTER_MCP_CALLS_PER_MINUTE', 120),
            'writes_per_minute' => (int) env('JOTTER_MCP_WRITES_PER_MINUTE', 30),
            'writes_per_day' => (int) env('JOTTER_MCP_WRITES_PER_DAY', 500),
        ],
        'allowed_origins' => array_values(array_filter(array_map(
            static fn (string $origin): string => rtrim(trim($origin), '/'),
            explode(',', (string) env('JOTTER_MCP_ALLOWED_ORIGINS', '')),
        ))),
        'instructions_max_bytes' => 4096,
    ],

    /*
     | Embedded OAuth 2.1 authorization server for MCP clients (brain plan PR 5).
     | Off by default (decision D4); while off, the MCP 401 does not advertise
     | protected-resource metadata.
     */
    'oauth' => [
        'enabled' => (bool) env('JOTTER_OAUTH_ENABLED', false),
        // Comma-separated redirect URIs a client may register. `*` matches any
        // port (`http://localhost:*/*`) or any path. Claude web's callback and
        // loopback clients (Claude Code, Codex, Hermes) are allowed by default;
        // add ChatGPT's callback as shown on its connector screen.
        'redirect_allowlist' => array_values(array_filter(array_map('trim', explode(',', (string) env(
            'JOTTER_OAUTH_REDIRECT_ALLOWLIST',
            'https://claude.ai/api/mcp/auth_callback,http://localhost:*/*,http://127.0.0.1:*/*',
        ))))),
        'code_ttl_seconds' => 60,
        'access_ttl_seconds' => (int) env('JOTTER_OAUTH_ACCESS_TTL', 3600),
        'refresh_ttl_days' => (int) env('JOTTER_OAUTH_REFRESH_TTL_DAYS', 30),
        'registrations_per_hour' => (int) env('JOTTER_OAUTH_REGISTRATIONS_PER_HOUR', 10),
        'token_requests_per_minute' => 60,
        'client_idle_days' => 30,
        // Client ID Metadata Documents (#411): a client_id that is an https URL
        // is fetched and validated instead of registered. Needs outbound HTTPS
        // from the server (jotter:doctor checks it). Fetches refuse private and
        // loopback addresses, redirects, and bodies over max_bytes.
        'cimd' => [
            'enabled' => (bool) env('JOTTER_OAUTH_CIMD_ENABLED', true),
            'timeout_seconds' => 5,
            'max_bytes' => 5120,
            'default_ttl_seconds' => 3600,
            'min_ttl_seconds' => 300,
            'max_ttl_seconds' => 86400,
            'probe_url' => env('JOTTER_OAUTH_CIMD_PROBE_URL', 'https://claude.ai/'),
        ],
    ],

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
