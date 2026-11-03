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

## Implemented Tools (Read-Only)

Jotter intentionally exposes **read-only** tools for knowledge context retrieval:

1. `list_workspaces`: Workspaces the token can read (`id`, `slug`, `name`).
2. `list_notes`: List notes (`id`, `path`, `title`, `updated_at`) within a workspace (`limit` ≤ 100).
3. `read_note`: Fetch canonical Markdown content of a specific note `path`.
4. `search_notes`: Full-text title and content search with ranked snippets (`query`).
5. `get_backlinks`: Incoming wikilinks to a `target` note (path or title).

## Write Tools Decision & Scope (Deferred)

> **Superseded 2026-11-03** by the "Scoped credentials and MCP write tools" entry in
> `docs/decisions.md`: the `mcp:write` scope now exists, and `create_note`,
> `append_to_note`, `update_note` (no delete) land in the write-tools PR of the
> brain multi-agent plan, after rate limits. The original rationale is kept below.

Write tools (`create_note`, `update_note`, `delete_note`) are **intentionally deferred and gated**:

### Decision & Rationale
- **Security Policy §8 S2 & S5 Compliance**: Direct AI agent file mutations over remote transport risk unauthenticated bulk file modification, path traversal, or index corruption if token permissions are not fine-grained.
- **Single Source of Truth**: Writes to canonical Markdown files must uphold client workspace lock invariants, full audit trail logging, and property projection synchronization.
- **Revisit Conditions**: Write tools will be revisited in a future major version when:
  1. Fine-grained write-scope machine token capabilities (`mcp:write`) are added to the admin token UI.
  2. Per-workspace write rate limiting and transaction locking are implemented for MCP transport.

For now, read-only tools fully satisfy AI agent contextual lookup requirements while ensuring 100% data safety.
