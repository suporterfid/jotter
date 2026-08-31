# Model Context Protocol (MCP) Integration

Jotter exposes a Model Context Protocol (MCP) server at `POST /api/mcp`
(Streamable HTTP transport: one JSON-RPC 2.0 message per POST, JSON responses,
no SSE stream; `GET` answers 405) allowing AI assistants to query notes within
authorized workspaces. Client setup for Claude Code, Cursor, and Claude Desktop
is in [mcp-clients.md](mcp-clients.md).

## Protocol surface

- `initialize` — echoes the client's `protocolVersion` when it is one of
  `2025-06-18`, `2025-03-26`, `2024-11-05`; otherwise answers the newest.
  Capabilities: `tools` (no `listChanged`), empty `resources` and `prompts`.
- `notifications/*` — acknowledged with `202` and no body.
- `ping`, `tools/list`, `tools/call`, `resources/list` (empty), `prompts/list` (empty).
- Every tool publishes an `inputSchema`; `workspace_id` is optional whenever the
  token can reach exactly one workspace, otherwise the call returns an
  `isError` tool result naming the accessible workspaces.

## Authentication & Authorization

- Transport authentication relies on machine tokens (`Authorization: Bearer jt_mkt_...`)
  stored only as SHA-256 hashes in `machine_tokens`. Issue them in
  Administration → MCP tokens (`POST /api/admin/machine-tokens`) or with
  `php artisan mcp:token <email>`; revoke from the same screen. Issue and
  revoke are audited (`machine_token.created` / `machine_token.revoked`).
- A token acts as the user it was issued for: authorization is evaluated
  through the `IdentityProvider` seam per workspace (`isAuthorizedForWorkspace`)
  and per note (`NoteAccess`), exactly like that user in the SPA.
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

Write tools (`create_note`, `update_note`, `delete_note`) are **intentionally deferred and gated**:

### Decision & Rationale
- **Security Policy §8 S2 & S5 Compliance**: Direct AI agent file mutations over remote transport risk unauthenticated bulk file modification, path traversal, or index corruption if token permissions are not fine-grained.
- **Single Source of Truth**: Writes to canonical Markdown files must uphold client workspace lock invariants, full audit trail logging, and property projection synchronization.
- **Revisit Conditions**: Write tools will be revisited in a future major version when:
  1. Fine-grained write-scope machine token capabilities (`mcp:write`) are added to the admin token UI.
  2. Per-workspace write rate limiting and transaction locking are implemented for MCP transport.

For now, read-only tools fully satisfy AI agent contextual lookup requirements while ensuring 100% data safety.
