# Changelog

All notable changes to Jotter will be documented here. Decision records live in `docs/decisions.md`; security-audit findings live in `docs/security-audit-2026.md`; visual-identity rollout tracking lives in `docs/visual-identity.md`. Split from a single overloaded `BACKLOG.md` in #208.

## Unreleased

### LGPD block 3: data subject self-service and `tenant:delete` — 2026-11-10

- **Your profile → Your data** (LGPD art. 18). **Download my data** (`GET /api/user/data-export`) returns one JSON document (`jotter.user-data/1`) with the account, sign-in identities, memberships, groups, note permissions, own comments (in full), watched notes, authored revisions and reviews (metadata only), public links created, notification settings and history, access tokens and connected apps (no secrets), PDF exports and the person's activity log. Note bodies stay out: they belong to the workspace. Limited to 5 per hour per person; audited as `user.data_exported`.
- **Ask for my account to be deleted** (`POST /api/user/deletion-request`, withdrawn with `DELETE`) records `users.deletion_requested_at` (migration `2026_11_10_000001`) and e-mails the other active administrators. Administration → Users lists the requests above the user list with a **Delete** button, `GET /api/auth/me` returns the date, and `jotter:doctor` warns (`deletion_requests`) once a request is older than 15 days, half of the 30 the privacy policy promises. The last active administrator cannot ask (409). Audit events `user.deletion_requested` and `user.deletion_request_cancelled`. Browser sessions only.
- **`php artisan tenant:delete {slug}`** replaces the manual `tinker` runbook. It supports `--dry-run`, `--export-to=` (a `tenant:export` ZIP first; nothing is deleted if it fails), confirmation or `--force`, and `--json`. It deletes the accounts that belong only to the tenant through `UserDeletion`; administrators and members of other tenants only lose their memberships. It keeps access records for the Marco Civil window, detached from the tenant, deletes the rest of the tenant's audit history and the tenant row (with cascades), and removes vault folders only when they are inside `VAULT_BASE_PATH`. It refuses the installation's only tenant. Audit event `tenant.deleted`.
- `docs/hosted-operations.md` ("Remove a tenant", new "Account deletion requests"), the privacy-policy draft (§7) and `docs/deployment.md` describe the new flows.

### LGPD block 1: access records, versioned terms, secure defaults — 2026-11-09

- **Access records (Marco Civil da Internet art. 15).** `audit:prune` now has two windows: access records (`auth.login_success`, `auth.login_failure`, `auth.logout`, `oauth.token_issued`, `mcp.connected`, all with IP) are kept for `JOTTER_ACCESS_LOG_RETENTION_DAYS`, every other event for `JOTTER_AUDIT_RETENTION_DAYS` (default 90). The access window is never shorter than the audit window, and by default equals it, so self-hosting is unchanged. A hosted (commercial) operator sets `JOTTER_ACCESS_LOG_RETENTION_DAYS=184`. New option `audit:prune --access-days=`; the scheduler no longer hard-codes `--days=90`, so it reads the configuration.
- **Versioned acceptance of the terms.** `JOTTER_TERMS_VERSION` labels the published Terms/Privacy texts. Sign-up records the accepted version in `users.terms_version` (migration `2026_11_09_000001`). `GET /api/auth/me` and the login answer carry `terms` (`version`, `accepted_version`, `accepted_at`, `acceptance_required`). When the version changes, or for accounts that never accepted it, the SPA shows a banner with the links and an **I have read and accept** button (`POST /api/auth/terms/accept`, browser sessions only, audited as `user.terms_accepted`). Without a version label nothing changes.
- **Secure defaults checked by `jotter:doctor`** (warnings, never critical): `session_security` (`SESSION_SECURE_COOKIE`, `SESSION_ENCRYPT`, `SESSION_HTTP_ONLY`), `log_retention` (a `single` log channel never rotates; use `LOG_STACK=daily` with `LOG_DAILY_DAYS`), and `access_log_retention` (judged only when Terms/Privacy URLs are published). `.env.example` now sets `SESSION_ENCRYPT=true`; existing sessions are signed out once when an installation turns it on.
- The privacy-policy draft separates access records (6 months) from the rest of the audit log (90 days), and `docs/legal/README.md` explains the version label.

### Account deletion and name correction (LGPD, #390 follow-up) — 2026-11-09

- **Administration → Users → Delete** (`DELETE /api/admin/users/{user}`, or `php artisan user:delete <e-mail>`) deletes the account in one transaction. It removes memberships (by user id, e-mail and linked SSO identities), manual tokens, OAuth grants, note ACL entries, identities and sessions; notifications, preferences, watches and group memberships cascade. Comments stay with "Deleted user" as author, revision, audit and PDF-export actors become `deleted-user`, and the e-mail is removed from audit metadata. Notes are untouched. Deleting yourself or the last active administrator is refused. Audit event `user.deleted` carries no personal data.
- **Edit profile** in the sidebar (`PUT /api/user/profile`) lets people correct their name with local sign-in; with OIDC or GrandpaSSOn the answer is 409, because the provider owns the name. Audit event `user.profile_updated`.
- The privacy-policy draft now explains how to answer correction and deletion requests.

### Terms of use and privacy policy drafts (#390) — 2026-11-08

- `docs/legal/termos-de-uso.rascunho.md` and `docs/legal/politica-de-privacidade.rascunho.md` are pt-BR drafts (LGPD) for the operator to review. They describe the data Jotter actually keeps, the default retention periods and the possible processors. Nothing is published. Configuring `JOTTER_BRAND_TERMS_URL` / `JOTTER_BRAND_PRIVACY_URL` shows the links and makes sign-up require acceptance.

### Self sign-up with administrator approval (#390) — 2026-11-08

- With `JOTTER_SIGNUP_ENABLED=true` (local authentication only), the sign-in screen offers **Create an account**. `POST /api/auth/register` creates an inactive account (`signup_status=pending`) and e-mails every active administrator. The answer is identical for e-mails that already have an account, and requests are limited per IP (`JOTTER_SIGNUP_REQUESTS_PER_HOUR`, 5).
- Administration → Users lists pending requests with **Approve** (activates the account, adds `JOTTER_SIGNUP_DEFAULT_WORKSPACE` with `JOTTER_SIGNUP_DEFAULT_ROLE` when set, e-mails the person) and **Reject** (e-mails the person and deletes the request). New endpoints: `POST /api/admin/users/{user}/approve|reject`.
- A pending person who signs in with the right password gets "waiting for approval" (403). `JOTTER_SIGNUP_ALLOWED_DOMAINS` limits who may ask. When the operator publishes terms or privacy URLs, accepting them is required and recorded (`terms_accepted_at`). Migration `2026_11_08_000002`; audit events `signup.requested|approved|rejected`.

### Workspace storage quotas (#390) — 2026-11-08

- Each workspace has a storage quota: `JOTTER_WORKSPACE_QUOTA_MB` (default **1024**, 0 = unlimited). It can be overridden per workspace in Administration → Workspaces or with `php artisan workspace:storage <slug> --quota=<MB|0|default>`. Migration `2026_11_08_000001` adds `storage_quota_mb`, `storage_bytes` and `storage_measured_at` to `workspaces`.
- Only growth is refused: notes, attachments, imports (checked before anything is written) and WebDAV/MCP writes that would pass the quota answer **507 Insufficient Storage** (MCP: a readable tool error). Edits that do not grow the vault and deletes always work.
- Usage is measured from disk on first use and hourly (`workspace:storage --measure`), and kept current by each write's size delta in between. `/api/workspaces` returns a `storage` status, the SPA shows a banner from 80% (`JOTTER_WORKSPACE_QUOTA_WARN_PERCENT`) and when the workspace is full, and `jotter:doctor` lists workspaces near or over their quota.
- **Upgrade note:** a workspace already over 1 GiB stops accepting growth right after the upgrade. Run `php artisan workspace:storage --measure` and raise the quotas you need.

### Instance backup and restore (#390) — 2026-11-08

- **`php artisan jotter:backup`** writes one ZIP with every Jotter table (NDJSON, prefix-independent) and every vault file, in pure PHP (no `mysqldump`, no `exec`). It is written to `JOTTER_BACKUP_PATH` (default `storage/app/private/backups`), never under `public/`, and keeps the newest `JOTTER_BACKUP_KEEP` (7). `--no-vaults` / `--no-database` limit the archive. With `JOTTER_BACKUP_DAILY=true` it runs nightly at 01:30.
- **`php artisan jotter:restore <zip>`** replaces the archived tables in one transaction (a corrupt archive changes nothing), skips generated columns, writes the vault files back (`--vault-root` moves them), refuses archives from newer code, and never writes outside a vault.
- Sessions, cache, queues, reset tokens and the semantic index are left out; GrandpaSSOn's tables in a shared database are never read. `jotter:doctor` reports the newest backup and warns after 48 hours when nightly backups are on. Runbook: `docs/deployment.md`, "Backup and restore". Also recorded: Jotter stays MIT-licensed (`docs/decisions.md`).

### Playwright E2E of the OAuth consent screen — 2026-11-06

- `frontend/e2e/oauth-consent.spec.ts` drives the consent screen in a real browser. A signed-in administrator registers a client (DCR), gets nothing pre-selected, is refused without a workspace, grants one workspace, and the client exchanges the code (PKCE) for a token whose `list_workspaces` shows only that workspace. Revoking it under Administration → Connected apps then makes the token answer 401. Without a session, the request goes to the SPA login, comes back to the consent screen after sign-in, and a denial returns `access_denied` with the client's `state`.
- `jt e2e` (both `scripts/jt.sh` and `scripts/jt.ps1`) turns on `JOTTER_OAUTH_ENABLED` for its stack; elsewhere, `compose.yaml` leaves it to `.env` (off by default). The spec skips with a message when OAuth is off.

### Semantic search, opt-in (#412) — 2026-11-06

- New MCP read tool **`semantic_search`** searches by meaning in workspaces that opted in. It returns `{mode, reason, results}` with the best passage and a score per note, and falls back to full-text search (`mode: "fulltext"`) when semantic search is off, the workspace has not opted in, the index is empty, or the provider fails.
- Embeddings come from any OpenAI-compatible `/embeddings` endpoint (`JOTTER_EMBEDDINGS_*`: OpenAI, Ollama, LM Studio…) and are stored in the rebuildable `note_embeddings` table (migration `2026_11_06_000002`, which also adds `workspaces.semantic_search`).
- `semantic:index` runs from the scheduler every five minutes and embeds at most 100 changed notes per run, never in a web request. `--workspace=<slug> --enable|--disable` opts a workspace in or out (opting out deletes its index), and `--rebuild` recreates the index.
- Off by default (`JOTTER_SEMANTIC_ENABLED=false`); with it off, any existing index is deleted. Results respect note ACLs and the token's workspace list. `jotter:doctor` reports which workspaces send text to which provider and how much is indexed. Decision recorded in `docs/decisions.md`.

### OAuth Client ID Metadata Documents (#411) — 2026-11-06

- The OAuth server accepts an `https://` URL as `client_id` (CIMD, as recommended by MCP 2025-11-25) and announces `client_id_metadata_document_supported`. The document is fetched, checked (its `client_id` equals the URL, the redirect URIs pass the allowlist, it is a public client) and cached in `oauth_clients` (`kind=cimd`, TTL from `Cache-Control` between 5 min and 24 h).
- SSRF guards: https on port 443 only, no IP-literal hosts, every resolved address must be public (private, loopback, link-local, CGNAT and IPv4-mapped ranges refused), the connection is pinned to the checked address, no redirects, 5 s timeout, 5 KB cap.
- The consent screen shows the host that published the document. Errors are shown on the error page with the reason; nothing is cached on failure.
- `jotter:doctor` warns when outbound HTTPS fails. `JOTTER_OAUTH_CIMD_ENABLED=false` turns CIMD off; dynamic registration keeps working either way. Migration `2026_11_06_000001` widens `oauth_clients.client_id` to 512 characters and adds `kind` and `metadata_expires_at`.

### WebDAV `ETag` / `If-Match` (#410) — 2026-11-06

- WebDAV files carry a strong `ETag`, the SHA-256 of the bytes on disk (the same value as MCP `sha256` and REST `content_hash`), on `GET`/`HEAD`, `PROPFIND` (`getetag`) and every successful `PUT`.
- `PUT` and `DELETE` with `If-Match` succeed only when the tag still matches, otherwise **412** with the current `ETag`; `If-None-Match: *` creates only. The check runs under the per-note lock the MCP tools and the editor take (423 when it is busy). `GET` answers 304 to a matching `If-None-Match`. `HEAD` is now served.
- A `PUT` without conditional headers still overwrites and still answers 201.

### Fix: manual MCP tokens for GrandpaSSOn users (#414) — 2026-11-06

- A GrandpaSSOn sign-in now links the SSO user's UUID to the local account in `identities` (provider `grandpasson`), as OIDC already did. Manual tokens resolve the local user id, so memberships stored under the UUID now apply to them; before, `list_workspaces` came back empty for those people.
- `mcp:token` infers the tenant from every subject id of the account (local id, e-mail, linked identities), not only the local id.
- The link is written only when missing or pointing at another account, so a normal request adds one read. People who have not signed in since the upgrade get it on their next sign-in.

### OAuth follow-ups: admin Connected apps and GrandpaSSOn sign-in — 2026-11-04

- **Administration → Connected apps**: a new tab lists every person's OAuth connections (client, redirect host, user, workspaces, scopes, client slug, last use, expiry) and revokes any of them, using the existing `/api/admin/oauth-grants` API.
- **OAuth consent after a GrandpaSSOn sign-in**: GrandpaSSOn always returns to `APP_URL`, which dropped the `?return_to=` of the consent screen. The SPA shell route (`/`) now resumes a pending consent from the session once the browser session resolves. The remembered path is limited to `/oauth/authorize?…` and expires after ten minutes; the OIDC callback uses the same rule.
- **Fix: OAuth tokens for GrandpaSSOn users.** With GrandpaSSOn the subject id is the SSO user's UUID, so the token resolver could not load the account and every OAuth access token answered 401. Grants and tokens now also store the local `user_id` (new nullable column on `oauth_grants` and `machine_tokens`, migration `2026_11_04_000002`); access checks still use the subject id, so memberships keyed by the SSO UUID apply. The admin grant list shows the right e-mail for these users.
- Follow-ups filed as issues: WebDAV `ETag`/`If-Match` (#410), CIMD (#411), semantic search (#412).

### Five-client guide and OAuth doctor checks — 2026-11-04 (brain multi-agent epic, PR 6)

- **`docs/mcp-clients.md` rewritten** for the brain: preparing the vault (`brain:init`, OAuth on), scoped manual tokens, and setup for Claude Code, Codex CLI/IDE, Hermes Agent, Claude web/Desktop/mobile (OAuth), and ChatGPT Developer Mode (OAuth, callback allowlist), plus Cursor and Claude Desktop via `mcp-remote`; the same three-step smoke test for every client; common errors for scopes, write paths, hash conflicts, 429, and OAuth.
- **Token snippets** in Administration → MCP tokens gain Codex (`config.toml` with `bearer_token_env_var`) and Hermes (`config.yaml` with `tools.include`).
- **`jotter:doctor`**: when OAuth is enabled, checks the issuer is HTTPS, the metadata and OAuth routes are registered (in-process, no HTTP call to itself), the redirect allowlist is non-empty and only https or loopback, and `oauth:prune` is scheduled; reports "disabled" otherwise.
- README and `/llms.txt` describe the brain, scoped tokens, write tools, and OAuth.

### Embedded OAuth 2.1 server for MCP — 2026-11-04 (brain multi-agent epic, PR 5)

- **Claude web and ChatGPT connect by URL** once `JOTTER_OAUTH_ENABLED=true` (off by default): RFC 9728 protected-resource and RFC 8414 authorization-server metadata, RFC 7591 dynamic client registration (redirect URIs checked against `JOTTER_OAUTH_REDIRECT_ALLOWLIST`, 10/hour per IP), authorization code with mandatory PKCE `S256`, rotating refresh tokens with reuse revocation, RFC 7009 revocation. Opaque hashed tokens, no JWT or keys (`App\Domain\OAuth\OAuthServer`, `RedirectUriPolicy`).
- **Consent screen** (Blade, branded, en/pt-BR): client name and redirect host, loopback warning, workspaces (none pre-selected, one organisation), optional write with pre-filled write paths, client slug, validity. Without a session the request resumes after SPA or OIDC login (`return_to` limited to `/oauth/authorize?…`).
- **Access tokens** `jt_oat_` (1 h) are `machine_tokens` rows with `kind=oauth` and the grant's scopes, workspaces, and write paths, so MCP applies the same rules as manual tokens; OAuth never grants `api:*`. Refresh tokens `jt_ort_` (30 days) rotate; replaying a code or a rotated refresh token revokes the grant.
- **Connected apps**: `GET/DELETE /api/user/oauth-grants` with a sidebar modal; `GET/DELETE /api/admin/oauth-grants` for administrators. Revoking a grant revokes its access and refresh tokens.
- **`oauth:prune`** daily at 02:45. Migration `2026_11_04_000001` (idempotent) adds `oauth_clients`, `oauth_grants`, `oauth_auth_codes`, `oauth_refresh_tokens`, `machine_tokens.oauth_grant_id`, and the `machine_tokens.client_id` foreign key.
- **Audit**: `oauth.client_registered`, `oauth.grant_created`, `oauth.token_issued`, `oauth.grant_revoked`.
- **Tests**: `OAuthPkceFlowTest`, `OAuthRefreshRotationTest`, `OAuthMetadataTest`, `OAuthPruneCommandTest`, OIDC return tests; Vitest for `ConnectedAppsModal` and `oauthReturnTo`.

### Brain tools and the `brain` vault pack — 2026-11-04 (brain multi-agent epic, PR 4)

- **`get_brain_context`**: one call returns the vault router, the agent rules, every project's state (front matter of `projetos/*/estado.md`), and the 10 latest changes, trimmed to 24 KB with a `truncated` flag (`App\Domain\Mcp\BrainTools`).
- **`list_recent_changes`** (`since?`, `limit` ≤ 100): revisions with actor and, for agent writes, the MCP client (matched by the written SHA-256 in `mcp.note_written`).
- **`get_outgoing_links`** over MCP; the REST endpoint now shares the same `App\Domain\Links\OutgoingLinks` service.
- **`search` / `fetch` in the OpenAI connector shape** across every reachable workspace (`id` = `<workspace_id>:<path>`, `url` = SPA deep link), so ChatGPT Deep Research can use the brain.
- **SPA deep link** `/?workspace=<id>&note=<id>` opens the note and cleans the URL.
- **`brain:init <workspace>`** (`--locale`, `--dry-run`, `--json`) lays out `_brain/`, `projetos/`, `decisoes/`, `diario/`, `inbox/`, `pessoas/` from `resources/brain/{pt-BR,en}`; idempotent, never overwrites, audited as `brain.initialized`. **`templates:pack --pack=brain`** builds the same layout as an importable ZIP.
- **Tests**: `BrainContextToolTest`, `OpenAiSearchFetchTest`, `BrainInitCommandTest`, updated `McpProtocolTest`; Vitest for the deep link.

### MCP write tools and optimistic concurrency — 2026-11-03 (brain multi-agent epic, PR 3)

- **`create_note`, `append_to_note`, `update_note`** over MCP, listed only for tokens with `mcp:write` and confined to the token's write paths (`App\Domain\Mcp\McpNoteWriter`). `_brain/`, `.trash/`, `_resources/`, `_templates/`, `.obsidian/` are never writable; only `.md`; 256 KB per call; the tenant plan and the write budget (30/min, 500/day → 429) apply.
- **Glob matching** of write paths by `WritePathPolicy` (own translation: `**` crosses folders, `*`/`?` stay in one segment, `{client}` = client slug) instead of `fnmatch`, whose `FNM_PATHNAME` mode does not treat `**` as recursive.
- **Concurrency**: per-note `Cache::lock` keyed by `sha1(path)`; `update_note` compares `expected_sha256` with the hash of the file on disk inside the lock, so out-of-band edits not yet reindexed are never overwritten. `append_to_note` supports a `heading` section (`MarkdownSectionAppender`) and a 24 h `idempotency_key`.
- **`read_note`** returns `structuredContent {path, sha256, updated_at}` plus an `outputSchema`; write tools publish `outputSchema` and write annotations (`update_note` is `destructiveHint: true`).
- **Audit** `mcp.note_written` with tool, token, client, path, bytes, and SHA-256 before/after; every write still creates a revision.
- **REST**: `PUT /notes/{id}` accepts `base_sha256` and answers 409 `{current_sha256}` on a stale base (423 if the note is locked); `GET` and the save response include `content_hash` (the `GET` value is hashed from disk). The editor sends it on every autosave and shows a conflict banner with "reload" / "overwrite".
- **Fix**: MCP tool `content` (`params.arguments.content`) is no longer trimmed or turned into `null` by the request middleware, so written bytes and hashes match what the agent sent.
- **Tests**: `McpWriteToolsTest`, `NoteOptimisticConcurrencyTest`, `WritePathPolicyTest`, `MarkdownSectionAppenderTest`, updated `McpToolCatalogTest`; Vitest for the conflict flow.

### MCP protocol polish and rate limits — 2026-11-03 (brain multi-agent epic, PR 2)

- **Protocol `2025-11-25`** negotiated first; an unsupported `MCP-Protocol-Version` header answers 400; a foreign browser `Origin` answers 403 (`mcp.origin_rejected`, extra origins via `JOTTER_MCP_ALLOWED_ORIGINS`).
- **401 is discoverable**: `WWW-Authenticate: Bearer realm="jotter", scope="mcp:read"`, with `resource_metadata` once `JOTTER_OAUTH_ENABLED=true` (OAuth server lands later; off by default).
- **Tool annotations and titles** on every tool (`readOnlyHint: true`, `openWorldHint: false` for reads) via `App\Domain\Mcp\McpToolCatalog`; `tools/list` is filtered by scope and a tool needing a missing scope answers 403 `insufficient_scope`.
- **Dynamic `instructions`**: server text plus the default workspace's `_brain/instructions.md`, ACL-checked, cut at 4 KB on a UTF-8 boundary (`McpInstructions`).
- **Rate limits** (`McpRateLimiter`, cache-backed, no daemon): 120 calls/min per token; write budget 30/min and 500/day ready for the write tools. 429 with `Retry-After`, audited as `mcp.rate_limited`. Configurable in `jotter.mcp.rate_limits` / `.env`.
- **Audit**: `mcp.method_called` now records `tool`, `token_id`, `client_slug`, and the tenant.
- **Tests**: extended `McpProtocolTest`; new `McpRateLimitTest`, `McpToolCatalogTest`.

### Scoped machine tokens — 2026-11-03 (brain multi-agent epic, PR 1)

- **BREAKING — existing machine tokens are now MCP read-only (`mcp:read`).** A token used on the REST API or WebDAV gets 403 `insufficient_scope`. Reissue it with the scopes it needs, for example: `php artisan mcp:token you@example.com --workspace=<slug> --scope=api:read --scope=api:write --name="Obsidian sync"`. Tokens owned by administrators were given an explicit list of every workspace in their tenant, so their MCP reach is unchanged.
- **Scopes** `mcp:read`, `mcp:write`, `api:read`, `api:write`, enforced per route by `EnforceCredentialAudience`; `/api/admin/*`, `/api/auth/*`, `/api/user/*` never accept a token. Denials are audited (`auth.forbidden`, reason `insufficient_scope` / `credential_not_allowed`, with `token_id` and `client_slug`).
- **Workspace allowlist, write paths, client slug, expiry, last use** on `machine_tokens` (migration `2026_11_03_000001`, idempotent). Tokens never inherit the administrator bypass, so note ACLs apply to agents; administrator-owned tokens must list their workspaces.
- **Every identity provider**: `CredentialScopedIdentityProvider` + `MachineTokenResolver` resolve tokens under `local`, `oidc`, and `grandpasson`. Unknown or expired `jt_mkt_` tokens answer 401 without falling back to the session. GrandpaSSOn service tokens need `kb:read` on MCP.
- **Issuance**: `mcp:token --workspace --scope --write-path --client --expires`, and the same fields in Administration → MCP tokens (workspace checkboxes, scopes, write paths pre-filled with `inbox/{client}/**` and `diario/**` when `mcp:write` is chosen, expiry; the list shows scope, reach, last use, expiry).
- **Decision** recorded in `docs/decisions.md` (D1–D8), superseding the write-tools deferral in `docs/mcp.md`.
- **Tests**: `McpTokenScopeTest`, `TokenAudienceTest`, `TokenWorkspaceAllowlistTest`, `MachineTokenWritePathTest`, extended `AdminMachineTokenTest`, Vitest for the token form.

### CI runs locally, without GitHub Actions — 2026-11-03

- **Removed** `.github/workflows/ci.yml`. Nothing runs on GitHub anymore; the `test` required status check on `main` must be removed from branch protection by the owner.
- **`jt ci`** (`scripts/jt.sh` and `scripts/jt.ps1`) runs the former workflow's steps — `composer install`, `up`, `test`, `e2e`, `release`, `release:verify` — then `down --volumes`. It uses its own Compose project (`jotter-ci-<hash>`) so it never touches the development database, refuses a dirty tree unless `--allow-dirty`, supports `--dry-run`, and writes `dist/ci/last-run.txt` (summary), `dist/ci/last-run.log`, and `dist/ci/passed-<sha>` on a clean pass. `make ci` calls it.
- **Pre-push hook** `.githooks/pre-push`, enabled with `jt ci:install-hook`: every pushed commit needs `dist/ci/passed-<sha>`; pushing HEAD without one runs `jt ci` first. Skip once with `JOTTER_SKIP_CI=1`.
- **`compose.ci.yaml`** no longer publishes port 8080, so a CI run coexists with a running dev stack (Playwright already uses the internal `jotter-web` alias).
- **Brain multi-agent plan files** — `docs/superpowers/specs/2026-11-03-jotter-brain-multiagent-design.md` and `docs/superpowers/plans/2026-11-03-jotter-brain-multiagent.md` were referenced by #401 but not committed (`/docs/superpowers` is git-ignored); added with `git add -f`.

## v0.10.0 — 2026-08-31

Tagged release: [v0.10.0](https://github.com/suporterfid/jotter/releases/tag/v0.10.0), built with `jt release` as `jotter-release-v0.10.0.zip` (+ `.sha256`, attached to the GitHub Release; `VERSION` inside the artifact reads `v0.10.0`). Packages the four 2026-08-31 deliveries below — multi-instance release + installation doctor (#391), branding by environment + hosted-mode plan/trial (#392), CLI provisioning + transactional e-mail (#394), and the MCP client path with migration guides and the demo vault (#395) — plus the design-token guard fix (#393).

## v1 (post-v0) — 2026-08-31: MCP client guides, migration guides, demo vault, README

Connecting Claude Code, Cursor, and Claude Desktop to a vault is now a documented, tested five-minute path (`docs/mcp-clients`).

- **MCP server compatibility** — `initialize` negotiates the protocol version (`2025-06-18` / `2025-03-26` / `2024-11-05`), `notifications/*` answer 202, `ping`/`resources/list`/`prompts/list` implemented, every tool publishes an `inputSchema`, new `list_workspaces` tool, and `workspace_id` defaults when the token reaches exactly one workspace (otherwise a recoverable `isError` result lists the options). `serverInfo.version` reports the release version.
- **Machine tokens** — `App\Domain\Auth\MachineTokenIssuer`; admin API `GET/POST /api/admin/machine-tokens`, `DELETE …/{id}` (revoke); `php artisan mcp:token <email>`; audited as `machine_token.created` / `machine_token.revoked`. Administration gains an **MCP tokens** tab that issues a token and shows copy-ready snippets for Claude Code (`claude mcp add` + `.mcp.json`), Cursor (`.cursor/mcp.json`), and Claude Desktop (`claude_desktop_config.json` via `mcp-remote`) with the current host filled in (`McpClientSnippets.vue`).
- **Docs** — `docs/mcp-clients.md` (per-client setup validated against current official docs, smoke test, curl proof, common errors), `docs/migrate-from-obsidian.md`, `docs/migrate-from-notion.md`, `docs/mcp.md` refreshed; README gains "Use with AI agents (MCP)", "Hosted option" (cadernia.app), and "Self-hosting on shared PHP hosting"; `/llms.txt` lists the guides and the MCP endpoint.
- **Import sources** — `source=generic|obsidian|notion` on `POST /api/workspaces/{id}/import`, `vault:import --source=`, and a selector in the import dialog. Obsidian: strips the vault-folder wrapper, skips `.obsidian/` and `.trash/`. Notion: strips 32-hex page ids from paths, URL-decodes links, turns page links into `[[wikilinks]]`, keeps attachments; CSV databases are reported, not imported (`ImportPathNormalizer`).
- **`demo:seed {workspace-slug}`** — 25 interlinked pt-BR notes (wiki, ADRs, meeting notes, runbooks, projects) from `resources/demo/pt-BR` for public demos and screenshots; every wikilink resolves.
- **Tests** — `McpProtocolTest`, `AdminMachineTokenTest`, `ImportSourcesTest`, `DemoSeedCommandTest`, `LlmsTxtTest`; Vitest `McpClientSnippets.spec.ts`.

## v1 (post-v0) — 2026-08-31: CLI provisioning and transactional e-mail

A new hosted customer is ready with one SSH command, and e-mail leaves for real (`feat/provisioning-and-mail`). Runbook: `docs/hosted-operations.md`.

- **`tenant:provision`** — tenant, workspace with vault at `VAULT_BASE_PATH/<workspace-slug>`, owner admin with a random 20-character password (created through `platform:bootstrap-admin`), owner membership, trial plan (`--trial-days=14 --seats=5`), starter templates in `--locale`, welcome e-mail; prints URLs and the password once (`--json` too). Exit 2 when the tenant exists; existing users are reused without touching their password. The password is never logged or audited (`tenant.provisioned`).
- **Transactional e-mail** — shared `x-mail-layout` shell with operator branding; `WelcomeEmail` (login, WebDAV, MCP guide, trial length), `PasswordResetEmail` (sent by `AdminUserController::resetPassword`, no password inside), `TrialReminderEmail` (3 days before) and `TrialEndedEmail` (on expiry), both driven by the daily `tenant:expire-trials` task and sent at most once per tenant (`trial_reminder_sent_at`, `trial_ended_notified_at`). Notification e-mails reuse the layout. i18n in `lang/{en,pt-BR}/emails.php`. Sending is synchronous; if `QUEUE_CONNECTION=database` is used, the scheduler drains it with `queue:work --stop-when-empty`. `.env.example` documents SMTP (Resend / Postmark).
- **`tenant:export {slug} --to=`** — one ZIP with every vault file, the JSON backup (`WorkspaceJsonBackup`, now shared with the API export), and `tenant.json` (plan, workspaces, memberships, users) for LGPD portability; refuses the document root; audited as `tenant.exported`.
- **Starter templates** — `resources/templates/{en,pt-BR}/_templates/` (ADR, meeting notes, daily, runbook, PRD) using TemplateEngine placeholders; `templates:pack` builds a ZIP the import endpoint accepts.
- **Tests** — `TenantProvisionCommandTest`, `TenantExportCommandTest`, `TemplatePackTest`, `TransactionalEmailTest` (all with `Mail::fake()`).

## v1 (post-v0) — 2026-08-31: Branding by environment and hosted-mode plan/trial

Jotter (MIT) is also offered as the hosted service "Cadernia". Both are the same engine: branding and plan state are configuration with self-hosting-neutral defaults (`docs/decisions.md`, "Single engine; hosting is configuration"). Zero change with defaults; no payment code (`feat/brand-and-plan`).

- **Branding.** `JOTTER_BRAND_NAME` (default `APP_NAME`), `JOTTER_BRAND_LOGO_URL`, `JOTTER_BRAND_SUPPORT_URL`, `JOTTER_BRAND_TERMS_URL`, `JOTTER_BRAND_PRIVACY_URL`, `JOTTER_BRAND_POWERED_BY` (default `true`) in `config/jotter.php`/`.env.example`, exposed as `data.brand` on `GET /api/auth/config`. The SPA uses them for the tab title, sidebar header (`BrandMark`), login heading, footer links and "Powered by Jotter" (`BrandFooter`); `app.blade.php` for `<title>`/OG; published pages get a footer with the links and the powered-by credit.
- **Plan and trial.** `tenants.plan_status` (`self_hosted` default, `trial`, `active`, `past_due`, `read_only`), `trial_ends_at`, `plan_name`, `plan_seats`. `GET /api/tenants` returns a `plan` payload; the SPA shows "Trial ends in N days" (en/pt-BR) and a read-only notice. Expired trial, `past_due`, and `read_only` block writes behind `workspace.write`, WebDAV `PUT`/`MKCOL`/`DELETE`, and import with 402 + localized message while reading, search, ZIP/JSON/PDF export, and login keep working. `plan_seats` refuses a membership for a new subject beyond the limit (402). Audited as `plan.write_blocked` / `plan.seat_limit_reached`.
- **Operator commands.** `tenant:plan {slug} --status= --trial-days= --seats= --name= [--json]` (audited as `tenant.plan_changed`) and `tenant:show {slug} [--json]`. Scheduled `tenant:expire-trials` (daily 03:00 via `schedule:run`) moves overdue trials to `read_only` and records `tenant.trial_expired`.
- **Tests.** `BrandingTest`, `TenantPlanEnforcementTest`, `TenantPlanCommandsTest`, scheduler registration; Vitest `Brand.spec.ts`, `PlanBanner.spec.ts`.

## v1 (post-v0) — 2026-08-31: Multi-instance release and installation doctor

Shared-hosting production means one installation per client on its own subdomain, no daemon, and a single cron per installation. This entry makes the release ZIP install predictably N times on one host and lets each installation diagnose itself (`feat/multi-instance-release`).

- **Versioned artifact.** `jt release` resolves the version from the exact git tag or `0.0.0-<short sha>`, writes it to `VERSION` inside the ZIP, and names the artifact `dist/jotter-release-<version>.zip` with a matching `.sha256`. `GET /api/auth/config` exposes it as `data.version`; `jt release:verify` accepts the newest ZIP or an explicit path; CI uploads the glob.
- **`php artisan jotter:doctor`** (`--json` for machines, exit code 1 on any critical failure). Checks PHP version and required/recommended extensions, `APP_KEY`, `APP_ENV`, `APP_DEBUG`, `APP_URL` HTTPS, `storage/` and `bootstrap/cache` writability, `VAULT_BASE_PATH` existence/writability/location outside the document root, vault free disk space, database connectivity, pending migrations, `MAIL_MAILER`, `APP_INSTANCE_SLUG`, and the scheduler heartbeat (last `schedule:run` within 5 minutes). Logic lives in `App\Domain\Health\InstanceDoctor`.
- **`GET /api/health`** — unauthenticated `{status, version, scheduler_last_run_at}`, 503 when MySQL does not answer, registered outside the `api` middleware group so session/cache/throttle failures cannot turn a 503 into a 500. Exposes no slug, path, hostname, or configuration.
- **Scheduler is the only executor.** `routes/console.php` registers every periodic job (`notifications:send-digest`, new `notifications:process-deliveries`, `pdf:process-exports`, `analytics:rollup`, `vault:reindex --all`, `vault:purge-trash`, `vault:prune-revisions`, `audit:prune`, plus a heartbeat) for the one-line `schedule:run` cron, with cache-lock overlap protection and no `runInBackground()`. `notifications:process-deliveries` closes a real gap: `LocalJobDispatcher` only logged `SendNotificationEmail`, so pending deliveries were never sent without a queue worker. `vault:reindex` gained `--all`.
- **`APP_INSTANCE_SLUG`** identifies an installation; it is shared into every log line's context and printed by the doctor. `.env.example` now documents every production-required variable.
- **Rehearsal verb.** `jt release:doctor` extracts the newest ZIP into `dist/install-test/`, writes an isolated `.env`, runs `schedule:run` once, and runs the doctor inside the extracted copy (both `jt.sh` and `jt.ps1`).
- **Docs and tests.** `docs/deployment.md` gains "Multiple instances on one shared host", the health endpoint contract, and the scheduled-jobs table; feature tests cover the doctor (ok, failure, severities, JSON), `/api/health` (200/503/no leaks), scheduler registration and heartbeat, and the deliveries command.

## v1 (post-v0) — 2026-07-29: UI audit follow-through

`BACKLOG.md`'s Milestone checkmarks tracked backend/API delivery, not UI delivery — a 2026-07-29 audit of the SPA against the roadmap found 14 backend-complete features with no frontend surface. All 14 gaps (#156–#169) plus a follow-up (#197) are closed:

- **#156** search filters (title/tags/modified-date) exposed in `SearchResults.vue` (PR #170)
- **#169** nested folders rendered as a collapsible tree, not a flat list, via `NoteTreeNode.vue` (PR #172)
- **#168** attachment browser/management added (`AttachmentsPanel.vue`, PR #174)
- **#157** version history/restore added (`HistoryPanel.vue`, PR #176)
- **#160** typed properties UI added (`PropertiesPanel.vue`, PR #178); also fixed `NoteProperty::type` not casting to its enum, which silently nulled every non-string property value from the API
- **#162** templates/daily notes added: template picker in the New Note modal, daily-note header button (PR #180)
- **#158** comments added (`CommentsPanel.vue`, PR #182)
- **#166** audit log viewer added (`AuditLogViewer.vue`, PR #184); also fixed `AuditLogQueryController` requiring a `tenant_id` match most recorder call sites never populated
- **#164** workspace import added; also fixed sidebar 5-icon-button overflow (consolidated into a "More actions" menu) and `storage/app/imports` missing from `entrypoint.sh`'s ownership loop (PR #186)
- **#163** workspace export added to the sidebar's More-actions menu (PR #188)
- **#167** broken-link/orphan report added (`LinkReportViewer.vue`, PR #190)
- **#165** workspace publish added; also fixed `storage/app/public` permissions and a missing `index.html` on published sites (PR #192)
- **#159** notifications bell/dropdown added to the sidebar header (PR #194)
- **#161** collections table view added (`CollectionsTableView.vue`, sortable/filterable over `note_properties`); also fixed a property filter that only compared `value_string` and a frontend sort bug (PR #196)
- **#197** collections board/calendar views added (`CollectionsBoardView.vue`, `CollectionsCalendarView.vue`); extracted `services/collectionUtils.ts`; also fixed Symfony YAML resolving bare unquoted ISO dates to a Unix timestamp `int` instead of a string, which silently misclassified unquoted date properties as `NUMERIC` (PR #199)

## v1 (post-v0) — Milestones A–D, Spec Debt & Foundation Epics

Sequenced from `docs/20260727-jotter-roadmap-ai-agent.md` within `docs/jotter-initial-spec-and-build-plan.md` §14. All items below are delivered both backend and UI (see the 2026-07-29 UI audit above for the reconciliation of that distinction).

- **Milestone A** — version history with restore/dedup/pruning (#51); filtered search by title/tags/modified-date via `SearchCriteria` (#52); hardened Markdown/JSON import (zip-slip/symlink/allowlist guards, bounded job, round-trip backup, collision policy) (#53, #76, #77, #78)
- **Milestone B** — daily notes and templates (#56); broken-link report (#55); typed note properties with front-matter write-through (#54, #79, #80, #81); block registry, callouts, toggles, tables, dividers, slash-command menu (#57, #86, #87, #88); MCP server with machine-token auth and read-only tools (#58, #89, #90, #91)
- **Milestone C** — GrandpaSSOn identity adapter (#59); workspace administration UI (workspace/membership/user CRUD) (#60, #82, #83, #84); comments and `@mention` parsing (#61); notification/event bus feeding `audit_log` and `notifications` (#62)
- **Spec Debt & Foundation Epics** — audit log hardening (standardized vocabulary, redaction, append-only enforcement, retention) (#64, #70, #71, #72); note identity (rename/move endpoint, case-insensitive wikilink resolution) (#65, #73, #74, #75); visual identity design system adoption (#96, #97–#110 — see `docs/visual-identity.md`)
- **Milestone D** — metadata table view, board/calendar views, relations via wikilinks and typed front-matter (#63)
- **v2** — document parsing/web crawler/RAG delegated to TaskConnect via `JobDispatcher` contract (#67)

## v0 — spec-complete delivery

- Full-text search, nested vault folders, tags + front-matter projection, backlinks, attachments (spec §7)
- Command palette, note graph view, tag cloud, task lists, code-copy (post-v0 UI)
- WebDAV sync endpoint (hand-rolled `app/Http/Controllers/WebDavController.php` — `sabre/dav` is **not** a dependency, despite "SabreDAV" appearing in an earlier commit message and status note), workspace ZIP export, static-site publishing, `llms.txt` (AI-KB Layer 1), audit-log query, server-side CommonMark rendering + sanitization, `JobDispatcher` seam

## Unreleased

### Added

- Laravel 12 and Vue 3/Vite scaffold
- Workspace membership role enforcement (#347): `viewer` memberships are read-only across API mutation routes and WebDAV writes; GrandpaSSOn service tokens require the `kb:write` scope and workspace audience.
- Generic, opt-in OIDC SSO (#348): discovery-backed authorization-code + PKCE S256 login, state/nonce validation, stable `iss|sub` identities, and just-in-time non-admin users without implicit memberships. Local and GrandpaSSOn providers remain unchanged.

### Removed

- Unused `welcome.blade.php` default Laravel scaffold view and unused `tailwindcss` devDependencies (#105).

- Docker-only local, test, E2E, and release workflows
- MySQL 8 development service
- Local administrator bootstrap command
- Shared-hosting release artifact and secret inspection
- CI and project documentation
- Multi-workspace tenant, workspace, note-index, link, tag, attachment, identity, membership, and audit data model
- Idempotent default tenant/workspace seeding with a configured on-disk vault path
- Rebuildable note search projection while Markdown files remain canonical
- Path-safe workspace vault storage service for Markdown read/write
- YAML front-matter parsing into the notes projection with incremental tag updates
- Bounded `vault:reindex` Artisan reconcile command for out-of-band disk edits
- Production Composer dependency on `symfony/yaml` for front-matter handling
- Workspace-scoped notes CRUD API backed by path-safe vault reads/writes/deletes
- Fail-closed notes authorization seam pending the PR7 identity provider
- 404 handling for out-of-band deleted Markdown files without false traversal audit entries
