# Architecture

## Source of truth

Jotter's vault data lives as plain `.md` files under a workspace-specific directory. Those files—not database rows—are authoritative. MySQL stores application state and a rebuildable projection for metadata, links, tags, and full-text search. Removing or rebuilding the index must never destroy note content.

The initial hierarchy is tenant → workspace → note/attachment, with note links, tags, identities, memberships, and an append-only audit log. Tenant and workspace foreign keys make ownership explicit, while paths and tag names are unique only within their owning workspace.

Per §13 Q1's default, `notes.search_content` is a nullable `LONGTEXT` search projection. It is not canonical note content and can be rebuilt from disk. The schema deliberately has no `body` or `content` column. PR4 adds a MySQL `FULLTEXT(title, search_content)` index over that metadata/projection pair; deleting or rebuilding the index never changes the vault files.

PR2's vault storage service reads and writes Markdown under each workspace `vault_path`, parses YAML front-matter with Symfony YAML, and refreshes the `notes` / tag projection on every write. Nested folders are allowed (Q4) only when the canonical path remains inside the vault root. Path-traversal attempts are rejected before candidate filesystem access and appended to `audit_log` as `vault.path_traversal_rejected`. `php artisan vault:reindex --workspace=<id>` reconciles the projection from disk in bounded batches for shared-hosting limits.

PR3 extracts `[[note]]`, `[[note|alias]]`, and `[[note#heading]]` from Markdown bodies into the rebuildable `note_links` projection. `target_ref` retains the raw in-bracket reference; aliases and headings are removed only when resolving the target. A link whose target is missing or ambiguous remains stored with a `NULL target_note_id`; later writes and reindexation reconcile all workspace links against the MySQL note index. Backlinks are therefore `note_links` queries, never filesystem scans. TODO(spec: PR3): confirm a user-facing resolution policy for duplicate titles and case-insensitive link names; until then, exact workspace-relative paths take precedence, exact unique titles are supported, and ambiguous references remain unresolved.

PR4's read-only `GET /api/workspaces/{workspace}/search?q=` endpoint executes a parameter-bound MySQL FULLTEXT query scoped by `workspace_id`, ranks by the returned relevance score, and returns only note metadata plus a bounded excerpt derived from the projection. The endpoint never reads vault files and never exposes `search_content`; the separate PR5 CRUD API and PR7 authorization guard remain out of scope.

Foreign keys cascade deletion for owned projection/state rows. Nullable references that can safely lose their target (`note_links.target_note_id` and identities' local user) use `SET NULL`. Membership workspaces use restricted deletes because MySQL cannot combine `SET NULL` with the stored workspace-scope uniqueness expression; scoped memberships must be explicitly removed first. Audit scopes also use restricted deletes so tenant or workspace removal cannot mutate or erase historical entries. Audit entries have `created_at` only. Their final event vocabulary, context, and retention policy remain a required specification decision before PR7.

Membership uniqueness uses a generated workspace scope key so MySQL also rejects duplicate tenant-level memberships when `workspace_id` is `NULL`. A composite tenant/workspace foreign key prevents memberships from crossing tenant boundaries.

Audit-log models reject instance updates and deletes, while restricted scope foreign keys prevent cascading history changes. Query-builder or direct-SQL mutation is not blocked at the database layer yet; the least-privilege/trigger and retention contract remains a required security decision before PR7.

## Modular monolith and provider seams

Laravel is the shared-hosting-compatible modular monolith; Vue is a separately built SPA served by Laravel's public web root. Feature code will depend on Jotter-owned interfaces where identity or background-work boundaries are needed.

Only local providers may perform work in v0. Jotter must remain fully useful without GrandpaSSOn or TaskConnect. Neighbor integrations are optional seams, not runtime dependencies, and PR0 contains no provider or protocol implementation.

## Shared-hosting constraints

- The deployable web root is `public/`; vault content must never be placed there.
- Requests cannot rely on daemons, workers, websockets, or long-lived processes.
- Cron-invoked, bounded Artisan commands are the scheduling boundary (`vault:reindex` in PR2).
- Application code must not call `exec`, `shell_exec`, `proc_open`, or external command-line tools.
- Work must fit ordinary PHP execution, memory, upload, inode, and disk quotas.
- Deployment is a zip containing `vendor/` and built public assets, followed by migrations.

Docker is a development and build tool only. Production requires PHP 8.2+, MySQL 8, and a document root pointed at `public/`.

## Scope boundary

PR4 adds the MySQL FULLTEXT index and read-only search endpoint only. It does not add notes CRUD APIs, auth providers, uploads, UI, or any v1 feature.

There is no v1 work in this implementation. WebDAV, publishing, graph views, GrandpaSSOn integration, TaskConnect delegation, AI retrieval, MCP, daily notes, and related features remain out of scope.
