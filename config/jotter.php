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

    // Legal texts (LGPD). `terms_version` labels the published Terms/Privacy
    // texts (e.g. "2026-11-09"). When it is set and a terms or privacy URL is
    // configured, every user whose recorded `users.terms_version` differs is
    // asked to accept the current texts again. Empty = no versioning (default).
    // Two-step verification (TOTP) for password sign-ins (block 4b). Anyone with
    // a local password can turn it on in "Your profile". With this on,
    // administrators of a local-provider installation must set it up before
    // /api/admin/* answers (403 `two_factor_setup_required` until then).
    'two_factor' => [
        'required_for_admins' => (bool) env('JOTTER_2FA_REQUIRED_FOR_ADMINS', false),
    ],

    'legal' => [
        'terms_version' => env('JOTTER_TERMS_VERSION') ?: null,
    ],

    // Audit retention (`audit:prune`, nightly). Access records (sign-in,
    // sign-out, OAuth tokens issued, MCP connections: date, time and IP) can be
    // kept longer than the rest. Marco Civil da Internet art. 15 requires six
    // months of access records from a commercial application provider, so a
    // hosted operator sets JOTTER_ACCESS_LOG_RETENTION_DAYS=184. The default
    // (empty) keeps access records exactly as long as the rest of the audit log.
    'audit' => [
        'retention_days' => (int) env('JOTTER_AUDIT_RETENTION_DAYS', 90),
        'access_retention_days' => env('JOTTER_ACCESS_LOG_RETENTION_DAYS') ? (int) env('JOTTER_ACCESS_LOG_RETENTION_DAYS') : null,
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

    // Pluggable vault stores (docs/decisions.md, "Pluggable vault stores"). `local`
    // (the workspace folder) is always on; listing `s3` and/or `github` turns on the
    // tenant "Storage" screen. With the default, nothing changes for anyone.
    'vault_stores' => [
        'drivers' => array_values(array_unique(array_filter(array_map(
            'trim',
            explode(',', strtolower((string) env('JOTTER_VAULT_DRIVERS', 'local'))),
        )))),
        // Hosts (exact names or IPs) allowed to resolve to private addresses, e.g. an internal MinIO.
        'allowed_private_hosts' => array_values(array_filter(array_map(
            'trim',
            explode(',', strtolower((string) env('JOTTER_VAULT_ALLOWED_PRIVATE_HOSTS', ''))),
        ))),
        // vault:move keeps the source this many days for --rollback (D9).
        'move_retain_days' => (int) env('JOTTER_VAULT_MOVE_RETAIN_DAYS', 7),
        // Files copied per vault:process-moves run (cron, every minute).
        'move_batch_size' => (int) env('JOTTER_VAULT_MOVE_BATCH', 200),
    ],

    // GitHub vault store (#431, docs/20261113-jotter-github-vault-implementation-plan.md).
    // Only used when JOTTER_VAULT_DRIVERS lists `github`. Hosted installs register one
    // GitHub App (app_id, slug, private key, webhook secret); self-hosted installs may
    // use a fine-grained PAT per store instead and leave the App settings empty.
    'github' => [
        'api_url' => rtrim((string) env('JOTTER_GITHUB_API_URL', 'https://api.github.com'), '/'),
        'web_url' => rtrim((string) env('JOTTER_GITHUB_WEB_URL', 'https://github.com'), '/'),
        'app_id' => env('JOTTER_GITHUB_APP_ID') ?: null,
        'app_slug' => env('JOTTER_GITHUB_APP_SLUG') ?: null,
        // Outside public/ and never in the release zip.
        'private_key_path' => env('JOTTER_GITHUB_APP_PRIVATE_KEY_PATH') ?: null,
        // Required in hosted mode (App webhook, `push` + `installation*` events).
        'webhook_secret' => env('JOTTER_GITHUB_WEBHOOK_SECRET') ?: null,
        // Seconds: connect / whole request.
        'connect_timeout' => (int) env('JOTTER_GITHUB_CONNECT_TIMEOUT', 3),
        'timeout' => (int) env('JOTTER_GITHUB_TIMEOUT', 10),
        // D5: attachments up to 10 MB by default, never above GitHub's 100 MiB.
        'max_file_bytes' => min(100 * 1024 * 1024, (int) env('JOTTER_GITHUB_MAX_FILE_BYTES', 10 * 1024 * 1024)),
        // Files per commit in vault:flush (§5.4).
        'flush_batch' => (int) env('JOTTER_GITHUB_FLUSH_BATCH', 100),
        // Write budget per actor, below GitHub's 500/h and 80/min (§5.7).
        'writes_per_hour' => (int) env('JOTTER_GITHUB_WRITES_PER_HOUR', 400),
        'writes_per_minute' => (int) env('JOTTER_GITHUB_WRITES_PER_MINUTE', 60),
        // Blob cache cap per store, bytes (LRU).
        'blob_cache_bytes' => (int) env('JOTTER_GITHUB_BLOB_CACHE_BYTES', 50 * 1024 * 1024),
        // Webhook pull limits (§5.5): time and files handled after the 202.
        'webhook_pull_seconds' => (int) env('JOTTER_GITHUB_WEBHOOK_PULL_SECONDS', 20),
        'webhook_pull_files' => (int) env('JOTTER_GITHUB_WEBHOOK_PULL_FILES', 200),
        // Doctor warns when a repository grows past this (KB, GitHub's `size`).
        'repo_warn_kb' => (int) env('JOTTER_GITHUB_REPO_WARN_KB', 800 * 1024),
        // Gradual launch (#445): when non-empty, only these tenant ids or slugs may connect GitHub.
        'beta_tenants' => array_values(array_filter(array_map(
            'trim',
            explode(',', (string) env('JOTTER_GITHUB_BETA_TENANTS', '')),
        ))),
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

    // Semantic search (#412, docs/decisions.md). Off unless JOTTER_SEMANTIC_ENABLED
    // and opted in per workspace (`php artisan semantic:index --workspace=<slug> --enable`).
    // Embeddings come from an OpenAI-compatible /embeddings endpoint (OpenAI, a
    // local Ollama or LM Studio, or any compatible gateway); note text is sent
    // there only for opted-in workspaces. Indexing runs from the scheduler, never
    // during a web request; a query makes one short embedding call and falls
    // back to full-text search when it fails.
    'semantic' => [
        'enabled' => (bool) env('JOTTER_SEMANTIC_ENABLED', false),
        'base_url' => env('JOTTER_EMBEDDINGS_URL', 'https://api.openai.com/v1'),
        'api_key' => env('JOTTER_EMBEDDINGS_API_KEY'),
        'model' => env('JOTTER_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
        // Requested vector size when the model supports it (OpenAI v3 models);
        // smaller vectors keep the in-PHP similarity scan fast. 0 = model default.
        'dimensions' => (int) env('JOTTER_EMBEDDINGS_DIMENSIONS', 512),
        'index_timeout_seconds' => 30,
        'query_timeout_seconds' => 5,
        'chunk_chars' => 1500,
        'chunk_overlap_chars' => 200,
        'batch_size' => 32,
        // Upper bound on chunks compared per query (MySQL 8 has no vector index).
        'max_chunks_scanned' => (int) env('JOTTER_SEMANTIC_MAX_CHUNKS', 20000),
    ],

    // Self sign-up with administrator approval (#390). Local authentication only
    // (OIDC and GrandpaSSOn manage accounts themselves). Off by default.
    'signup' => [
        'enabled' => (bool) env('JOTTER_SIGNUP_ENABLED', false),
        // Optional: comma-separated e-mail domains allowed to ask (empty = any).
        'allowed_domains' => array_values(array_filter(array_map(
            static fn (string $domain): string => strtolower(trim($domain)),
            explode(',', (string) env('JOTTER_SIGNUP_ALLOWED_DOMAINS', '')),
        ))),
        // Optional: on approval, add the person to this workspace (slug) with this role.
        'default_workspace' => env('JOTTER_SIGNUP_DEFAULT_WORKSPACE'),
        'default_role' => env('JOTTER_SIGNUP_DEFAULT_ROLE', 'viewer'),
        'requests_per_hour' => (int) env('JOTTER_SIGNUP_REQUESTS_PER_HOUR', 5),
    ],

    // Workspace storage quota (#390). Per-workspace override: workspaces.storage_quota_mb
    // (`php artisan workspace:storage <slug> --quota=<MB>`; 0 = unlimited). Only growth is
    // refused (507); editing within the size and deleting always work.
    'quota' => [
        'default_mb' => (int) env('JOTTER_WORKSPACE_QUOTA_MB', 1024),
        'warn_percent' => (int) env('JOTTER_WORKSPACE_QUOTA_WARN_PERCENT', 80),
    ],

    // Instance backup (#390): `php artisan jotter:backup` writes one ZIP with every
    // Jotter table (NDJSON) and every vault file, in pure PHP (no mysqldump, no exec).
    // `daily` schedules it at 01:30; `keep` is how many archives stay in `path`.
    // Erasure log (LGPD block 4c): tombstones of deleted tenants and accounts,
    // kept outside the database and copied into every backup, replayed by
    // `jotter:restore` so a restore never brings deleted data back. Entries
    // hold ids and timestamps only and are pruned after `days`.
    'erasure_log' => [
        'path' => env('JOTTER_ERASURE_LOG_PATH') ?: storage_path('app/private/erasure-log.ndjson'),
        'days' => (int) env('JOTTER_ERASURE_LOG_DAYS', 400),
    ],

    'backup' => [
        'path' => env('JOTTER_BACKUP_PATH') ?: storage_path('app/private/backups'),
        'daily' => (bool) env('JOTTER_BACKUP_DAILY', false),
        'keep' => (int) env('JOTTER_BACKUP_KEEP', 7),
        // Encrypts each archive (libsodium, `*.zip.enc`). Generate with
        // `php artisan jotter:backup-key` and keep a copy OFF this server: without
        // it the backups cannot be restored. After a rotation, list old keys in
        // JOTTER_BACKUP_PREVIOUS_KEYS (comma-separated) so older archives still open.
        'key' => env('JOTTER_BACKUP_KEY') ?: null,
        'previous_keys' => array_values(array_filter(array_map('trim', explode(',', (string) env('JOTTER_BACKUP_PREVIOUS_KEYS', ''))))),
        // Never backed up: ephemeral state and projections rebuilt by commands.
        'exclude_tables' => ['sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs', 'password_reset_tokens', 'note_embeddings'],
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
