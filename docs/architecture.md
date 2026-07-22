# Architecture

## Source of truth

Jotter's vault data lives as plain `.md` files under a workspace-specific directory. Those files—not database rows—are authoritative. MySQL stores application state and a rebuildable projection for metadata, links, tags, and full-text search. Removing or rebuilding the index must never destroy note content.

The initial hierarchy is tenant → workspace → note/attachment, with note links, tags, identities, memberships, and an append-only audit log. Tenant and workspace foreign keys make ownership explicit, while paths and tag names are unique only within their owning workspace.

Per §13 Q1's default, `notes.search_content` is a nullable `LONGTEXT` search projection. It is not canonical note content and can be rebuilt from disk. The schema deliberately has no `body` or `content` column, and PR1 adds no `FULLTEXT` index or search behavior; those belong to PR4.

PR2's vault storage service reads and writes Markdown under each workspace `vault_path`, parses YAML front-matter with Symfony YAML, and refreshes the `notes` / tag projection on every write. Nested folders are allowed (Q4) only when the canonical path remains inside the vault root. Path-traversal attempts are rejected before candidate filesystem access and appended to `audit_log` as `vault.path_traversal_rejected`. `php artisan vault:reindex --workspace=<id>` reconciles the projection from disk in bounded batches for shared-hosting limits. Wikilink extraction into `note_links` is deferred to PR3.

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

PR2 implements path-safe vault I/O, front-matter projection, incremental index-on-write, and bounded reconcile only. It does not implement wikilink/backlink resolution, search endpoints, notes CRUD APIs, auth providers, uploads, or UI.

There is no v1 work in this implementation. WebDAV, publishing, graph views, GrandpaSSOn integration, TaskConnect delegation, AI retrieval, MCP, daily notes, and related features remain out of scope.
