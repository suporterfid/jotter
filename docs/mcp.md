# Model Context Protocol (MCP) Integration

Jotter exposes a Model Context Protocol (MCP) server over HTTP JSON-RPC 2.0 (`POST /api/mcp`) allowing AI assistants to query knowledge notes within authorized workspaces.

## Authentication & Authorization

- Transport authentication relies on machine tokens (`Bearer jt_mkt_...`) hashed with SHA-256 in `machine_tokens`.
- Authorization is evaluated through the `IdentityProvider` seam for each MCP request method and workspace scope.
- Machine tokens are strictly bounded to specific workspaces (`isAuthorizedForWorkspace`).

## Implemented Tools (Read-Only)

Jotter intentionally exposes **read-only** tools for knowledge context retrieval:

1. `list_notes`: List notes with metadata and front-matter within a workspace.
2. `read_note`: Fetch canonical Markdown content of a specific note path.
3. `search_notes`: Perform fulltext title and content search with ranked snippets.
4. `get_backlinks`: Retrieve internal incoming wikilinks to a target note.

## Write Tools Decision & Scope (Deferred)

Write tools (`create_note`, `update_note`, `delete_note`) are **intentionally deferred and gated**:

### Decision & Rationale
- **Security Policy §8 S2 & S5 Compliance**: Direct AI agent file mutations over remote transport risk unauthenticated bulk file modification, path traversal, or index corruption if token permissions are not fine-grained.
- **Single Source of Truth**: Writes to canonical Markdown files must uphold client workspace lock invariants, full audit trail logging, and property projection synchronization.
- **Revisit Conditions**: Write tools will be revisited in a future major version when:
  1. Fine-grained write-scope machine token capabilities (`mcp:write`) are added to the admin token UI.
  2. Per-workspace write rate limiting and transaction locking are implemented for MCP transport.

For now, read-only tools fully satisfy AI agent contextual lookup requirements while ensuring 100% data safety.
