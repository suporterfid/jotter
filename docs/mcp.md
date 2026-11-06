# Model Context Protocol (MCP) Integration

Jotter exposes a Model Context Protocol (MCP) server at `POST /api/mcp`
(Streamable HTTP transport: one JSON-RPC 2.0 message per POST, JSON responses,
no SSE stream; `GET` answers 405) allowing AI assistants to query notes within
authorized workspaces. Client setup for Claude Code, Cursor, and Claude Desktop
is in [mcp-clients.md](mcp-clients.md).

## Protocol surface

- `initialize` — echoes the client's `protocolVersion` when it is one of
  `2025-11-25`, `2025-06-18`, `2025-03-26`, `2024-11-05`; otherwise answers the newest.
  Capabilities: `tools` (no `listChanged`), empty `resources` and `prompts`.
  `instructions` is the fixed server text plus the default workspace's
  `_brain/instructions.md` (first workspace of the token's list, or the first
  reachable one; ACL applies), cut at 4 KB.
- `notifications/*` — acknowledged with `202` and no body.
- `ping`, `tools/list`, `tools/call`, `resources/list` (empty), `prompts/list` (empty).
- Every tool publishes an `inputSchema`, a `title`, and `annotations`
  (`readOnlyHint: true`, `openWorldHint: false` on every read tool, so ChatGPT
  does not ask for write confirmation). `tools/list` only shows tools the
  credential's scopes allow; calling a tool without its scope answers 403 with
  `WWW-Authenticate: Bearer error="insufficient_scope"`.
- `workspace_id` is optional whenever the token can reach exactly one
  workspace, otherwise the call returns an `isError` tool result naming the
  accessible workspaces.
- **Headers.** A `MCP-Protocol-Version` header with an unsupported value answers
  400 (absent is accepted). A browser `Origin` header must match `APP_URL`'s
  origin or `JOTTER_MCP_ALLOWED_ORIGINS`, otherwise 403 (`mcp.origin_rejected`);
  CLI and server-side clients send no `Origin` and pass.
- **401** carries `WWW-Authenticate: Bearer realm="jotter", scope="mcp:read"`,
  plus `resource_metadata="<APP_URL>/.well-known/oauth-protected-resource/api/mcp"`
  once the embedded OAuth server is enabled (`JOTTER_OAUTH_ENABLED`, later PR).
- **Rate limits** per token (per subject for sessions): 120 calls/min, and for
  write tools 30/min and 500/day (`JOTTER_MCP_CALLS_PER_MINUTE`,
  `JOTTER_MCP_WRITES_PER_MINUTE`, `JOTTER_MCP_WRITES_PER_DAY`). Exceeding one
  answers HTTP 429 with `Retry-After` and is audited as `mcp.rate_limited`.
  Notifications are not counted.
- Every call is audited as `mcp.method_called` with `method`, `tool`,
  `token_id`, and `client_slug`.

## Authentication & Authorization

- Transport authentication relies on machine tokens (`Authorization: Bearer jt_mkt_...`)
  stored only as SHA-256 hashes in `machine_tokens`. Issue them in
  Administration → MCP tokens (`POST /api/admin/machine-tokens`) or with
  `php artisan mcp:token <email>`; revoke from the same screen. Issue and
  revoke are audited (`machine_token.created` / `machine_token.revoked`).
- Tokens work with every identity provider (`local`, `oidc`, `grandpasson`):
  `CredentialScopedIdentityProvider` resolves them before the configured provider.
  An unknown, revoked, or expired `jt_mkt_` token answers 401 and never falls
  back to a browser session.
- **Scopes** (decision "Scoped credentials" in `docs/decisions.md`):
  `mcp:read` for `/api/mcp`; `api:read` / `api:write` for the REST API and
  WebDAV; `mcp:write` reserved for the MCP write tools (later PR). Admin, auth,
  and user-settings routes (`/api/admin/*`, `/api/auth/*`, `/api/user/*`) never
  accept a token. A missing scope answers 403 with
  `WWW-Authenticate: Bearer error="insufficient_scope", scope="…"`.
- **Reach**: a token reaches the owner's explicit workspace memberships, narrowed
  by its workspace list; a token owned by an administrator must list its
  workspaces and gets no administrator bypass, so note ACLs apply. Write paths
  (`{glob, ops}`, `{client}` = the token's client slug) and an optional expiry
  are stored for the write tools; `_brain/`, `.trash/`, `_resources/`,
  `_templates/`, `.obsidian/` can never be write targets.
- CLI example:
  `php artisan mcp:token joe@example.com --workspace=brain --scope=mcp:read --scope=mcp:write --write-path="inbox/{client}/**:create,append,update" --client=hermes --expires=180d`.
- Authorization is then evaluated through the `IdentityProvider` seam per
  workspace and per note (`NoteAccess`), like the owner in the SPA but narrowed
  as above. GrandpaSSOn service tokens need `kb:read` to use MCP.
- Failed authentication is audited as `mcp.auth_failed`; every method call as
  `mcp.method_called`.

## OAuth 2.1 for web clients (Claude web, ChatGPT)

Claude web/Desktop/mobile and ChatGPT connect by URL: they read the `WWW-Authenticate`
header of the MCP 401, discover the server, register themselves, and send the person to a
consent screen. Enable it with `JOTTER_OAUTH_ENABLED=true` (off by default; every route
below answers 404 while off).

| Endpoint | Purpose |
| --- | --- |
| `GET /.well-known/oauth-protected-resource[/api/mcp]` | RFC 9728: `resource` = `<APP_URL>/api/mcp`, `authorization_servers` = `[<APP_URL>]` |
| `GET /.well-known/oauth-authorization-server` | RFC 8414 metadata: code flow, PKCE `S256`, `none` / `client_secret_post` / `client_secret_basic` |
| `POST /oauth/register` | RFC 7591 dynamic registration (not needed by clients using a metadata document URL); every `redirect_uri` must match `JOTTER_OAUTH_REDIRECT_ALLOWLIST`; 10 per hour per IP |
| `GET/POST /oauth/authorize` | Requires a browser session (local, OIDC, or GrandpaSSOn); PKCE `S256` mandatory; exact `redirect_uri` (loopback on any port); `resource` must be the MCP URL; consent screen |
| `POST /oauth/token` | `authorization_code` (single use, 60 s) and `refresh_token` (rotating); RFC 6749 errors |
| `POST /oauth/revoke` | RFC 7009; revoking a refresh token revokes its grant |

- **Consent**: nothing pre-selected; the person picks workspaces (one organisation), read
  is always on, write is optional with write paths pre-filled as
  `inbox/{client}/**:create,append,update` and `diario/**:append`, a client slug, and a
  validity (30 days … until revoked). Loopback redirects get an extra warning. Without a
  session the request waits while the person signs in (SPA login or OIDC) and resumes.
- **Tokens**: access `jt_oat_…` (1 hour, stored in `machine_tokens` with `kind=oauth`) and
  refresh `jt_ort_…` (30 days, rotated on every use). OAuth grants only `mcp:read`,
  `mcp:write`, `offline_access` — never `api:*` (decision D8). Replaying a used code or a
  rotated refresh token revokes the whole grant.
- **Connected apps**: everyone sees their grants in the sidebar (link icon → *Connected
  apps*) with workspaces, scopes, last use and expiry, and can revoke them;
  administrators list and revoke all grants through `GET/DELETE /api/admin/oauth-grants`.
- `oauth:prune` (daily 02:45) deletes expired codes and tokens and idle DCR clients
  without an active grant. Audit events: `oauth.client_registered`,
  `oauth.grant_created`, `oauth.token_issued`, `oauth.grant_revoked`.
- **Client ID Metadata Documents (CIMD)**: metadata announces
  `client_id_metadata_document_supported`. A client may skip registration and send an
  `https://` URL as `client_id`. Jotter fetches that JSON document, checks that its
  `client_id` equals the URL, that every `redirect_uri` passes
  `JOTTER_OAUTH_REDIRECT_ALLOWLIST`, and that it is a public client
  (`token_endpoint_auth_method: none`), then caches it as an `oauth_clients` row with
  `kind=cimd`. The cache honors `Cache-Control: max-age` between 5 minutes and 24 hours
  (1 hour by default) and is refreshed when it expires.
  - The fetch is limited to https on port 443, to host names whose every address is
    public (no private, loopback, link-local or CGNAT ranges), with the connection pinned
    to the checked address. It follows no redirects, takes at most 5 s and reads at most
    5 KB.
  - The consent screen names the host that published the document, because `client_name`
    is only what the document claims.
  - Clients without CIMD keep using dynamic registration. Turn CIMD off with
    `JOTTER_OAUTH_CIMD_ENABLED=false`; `jotter:doctor` warns when outbound HTTPS does not
    work.

## Tools

### Read (`mcp:read`)

1. `list_workspaces`: Workspaces the token can read (`id`, `slug`, `name`).
2. `list_notes`: List notes (`id`, `path`, `title`, `updated_at`) within a workspace (`limit` ≤ 100).
3. `read_note`: Canonical Markdown of a note `path`. `structuredContent` carries
   `{path, sha256, updated_at}`; `sha256` is computed from the file on disk, not the
   index, and is what `update_note` expects.
4. `search_notes`: Full-text title and content search with ranked snippets (`query`).
5. `get_backlinks`: Incoming wikilinks to a `target` note (path or title).
6. `get_brain_context`: **start here.** One call returns `_brain/README.md`,
   `_brain/instructions.md`, the front matter of every `projetos/<slug>/estado.md`
   (`status`, `proximo_passo`, `atualizado`, `dono`) and the 10 latest changes, trimmed
   to 24 KB (recent changes first, then projects, then the texts; `truncated: true`).
7. `list_recent_changes`: Latest revisions (`path`, `updated_at`, `actor`, and `client`
   for agent writes, matched through `mcp.note_written`); `since?`, `limit` ≤ 100.
8. `get_outgoing_links`: Wikilinks from a note `path`, resolved or not; ACL applied.
9. `search` / `fetch`: the OpenAI connector shape, so ChatGPT and Deep Research (which
   only call these two) can use the vault. `search {query}` → `{results: [{id, title, url}]}`
   across every reachable workspace (≤ 20, `id` = `<workspace_id>:<path>`);
   `fetch {id}` → `{id, title, text, url, metadata}`. `url` is the SPA deep link
   `<APP_URL>/?workspace=<id>&note=<id>`.

### The `brain` vault layout

`php artisan brain:init <workspace> [--locale=pt-BR|en] [--dry-run]` writes the
convention below and never overwrites an existing file (re-running is safe; audited as
`brain.initialized`). `php artisan templates:pack --pack=brain` builds the same layout as
a ZIP for the import endpoint. Folder names are identical in both locales so agent write
paths stay `inbox/{client}/**` and `diario/**`.

```text
_brain/README.md            router: what exists and where to look (humans only)
_brain/instructions.md      agent rules, also returned by initialize (humans only)
projetos/<slug>/estado.md   status, latest decision, next step (front matter)
decisoes/AAAA-MM-DD-<slug>.md
diario/AAAA-MM-DD.md        agents only append
inbox/<client>/             the only area where agents create and rewrite notes
pessoas/<nome>.md
```

### Write (`mcp:write`, only inside the token's write paths)

| Tool | Arguments | Behaviour | Operation |
| --- | --- | --- | --- |
| `create_note` | `workspace_id?`, `path`, `content`, `if_exists: fail\|suffix` | Creates the note; with `suffix` an existing path becomes `name-2.md` … `name-50.md` | `create` |
| `append_to_note` | `workspace_id?`, `path`, `content`, `heading?`, `idempotency_key?` | Appends at the end, or at the end of the `heading` section (created when missing); creates the note when absent; the same key within 24 h is a no-op (`status: duplicate`) | `append` |
| `update_note` | `workspace_id?`, `path`, `content`, `expected_sha256` | Replaces the content only when the file's current SHA-256 matches; otherwise nothing is written and `current_sha256` is returned | `update` |

Every write (`App\Domain\Mcp\McpNoteWriter`):

1. normalizes the path with `VaultPathGuard`, requires `.md`, and refuses `_brain/`,
   `.trash/`, `_resources/`, `_templates/`, `.obsidian/` whatever the write paths say;
2. needs `mcp:write`, a writer role in the workspace, and note ACL `edit` for existing notes;
3. matches the path and operation against the token's write paths (`**` crosses
   folders, `*`/`?` stay in one segment, `{client}` is the token's client slug);
4. checks the tenant plan, a 256 KB limit per call, and the write rate budget (429);
5. takes `Cache::lock("note:{workspace}:sha1(path)")` and compares hashes against the
   bytes on disk inside the lock;
6. writes through `VaultStorage::write` (revision, projections, links), then emits the
   note-edited event (agents do not auto-watch notes);
7. audits `mcp.note_written` with `tool`, `token_id`, `client_slug`, `path`, `bytes`,
   `sha256_before`, `sha256_after`.

Rule failures come back as an `isError` tool result the model can read; nothing touches
the disk. Deleting and moving notes is not available over MCP, and the server never
stamps front matter: the origin of a write lives in the audit log and the revision
history.

The REST API offers the same protection to people: `PUT /api/workspaces/{id}/notes/{note}`
accepts an optional `base_sha256` (the `content_hash` returned by `GET` and by the
previous save) and answers 409 `{current_sha256}` when the file changed in between. The
editor sends it on every autosave and, on 409, offers "reload their version" or "keep
mine and overwrite". WebDAV (Obsidian) does the same with `ETag` / `If-Match` and 412;
see [migrate-from-obsidian.md](migrate-from-obsidian.md#conflicts-with-agents-and-the-web-editor).

## History: write tools deferral (superseded)

> **Superseded 2026-11-03** by the "Scoped credentials and MCP write tools" entry in
> `docs/decisions.md`. Both revisit conditions below are met: the `mcp:write` scope
> exists in token issuance, and MCP writes are rate limited and run under a per-note
> lock. The original rationale is kept for the record.

### Original decision & rationale
- **Security Policy §8 S2 & S5 Compliance**: Direct AI agent file mutations over remote transport risk unauthenticated bulk file modification, path traversal, or index corruption if token permissions are not fine-grained.
- **Single Source of Truth**: Writes to canonical Markdown files must uphold client workspace lock invariants, full audit trail logging, and property projection synchronization.
- **Revisit Conditions**: Write tools will be revisited in a future major version when:
  1. Fine-grained write-scope machine token capabilities (`mcp:write`) are added to the admin token UI.
  2. Per-workspace write rate limiting and transaction locking are implemented for MCP transport.
