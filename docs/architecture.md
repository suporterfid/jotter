# Architecture

## Source of truth

Jotter's vault data lives as plain `.md` files under a workspace-specific directory. Those files—not database rows—are authoritative. MySQL stores application state and a rebuildable projection for metadata, links, tags, and full-text search. Removing or rebuilding the index must never destroy note content.

The initial hierarchy is tenant → workspace → note/attachment, with note links, tags, identities, memberships, and an append-only audit log. Tenant and workspace foreign keys make ownership explicit, while paths and tag names are unique only within their owning workspace.

Per §13 Q1's default, `notes.search_content` is a nullable `LONGTEXT` search projection. It is not canonical note content and can be rebuilt from disk. The schema deliberately has no `body` or `content` column, and PR1 adds no `FULLTEXT` index or search behavior; those belong to PR4.

Foreign keys cascade deletion for owned projection/state rows. Nullable references that can safely lose their target (`note_links.target_note_id` and identities' local user) use `SET NULL`. Membership workspaces use restricted deletes because MySQL cannot combine `SET NULL` with the stored workspace-scope uniqueness expression; scoped memberships must be explicitly removed first. Audit scopes also use restricted deletes so tenant or workspace removal cannot mutate or erase historical entries. Audit entries have `created_at` only. Their final event vocabulary, context, and retention policy remain a required specification decision before PR7.

Membership uniqueness uses a generated workspace scope key so MySQL also rejects duplicate tenant-level memberships when `workspace_id` is `NULL`. Audit-log models reject updates and deletes, preserving the table's append-only contract while the detailed retention policy remains unresolved.

## Modular monolith and provider seams

Laravel is the shared-hosting-compatible modular monolith; Vue is a separately built SPA served by Laravel's public web root. Feature code will depend on Jotter-owned interfaces where identity or background-work boundaries are needed.

Only local providers may perform work in v0. Jotter must remain fully useful without GrandpaSSOn or TaskConnect. Neighbor integrations are optional seams, not runtime dependencies, and PR0 contains no provider or protocol implementation.

## Shared-hosting constraints

- The deployable web root is `public/`; vault content must never be placed there.
- Requests cannot rely on daemons, workers, websockets, or long-lived processes.
- Cron-invoked, bounded Artisan commands are the future scheduling boundary.
- Application code must not call `exec`, `shell_exec`, `proc_open`, or external command-line tools.
- Work must fit ordinary PHP execution, memory, upload, inode, and disk quotas.
- Deployment is a zip containing `vendor/` and built public assets, followed by migrations.

Docker is a development and build tool only. Production requires PHP 8.2+, MySQL 8, and a document root pointed at `public/`.

## Scope boundary

PR1 defines schema, models, seed configuration, and projection boundaries only. It does not create vault directories or implement filesystem access, indexing, parsing, search, CRUD APIs, providers, uploads, or UI.

There is no v1 work in this implementation. WebDAV, publishing, graph views, GrandpaSSOn integration, TaskConnect delegation, AI retrieval, MCP, daily notes, and related features remain out of scope.
