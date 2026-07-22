# Architecture

## Source of truth

Jotter's future vault data lives as plain `.md` files under a workspace-specific directory. Those files—not database rows—are authoritative. MySQL stores application state and a rebuildable projection for metadata, links, tags, and full-text search. Removing or rebuilding the index must never destroy note content.

PR0 establishes only the application and operational scaffold. It does not implement vault storage or an index.

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

There is no v1 work in this scaffold. WebDAV, publishing, graph views, GrandpaSSOn integration, TaskConnect delegation, AI retrieval, MCP, daily notes, and related features remain out of scope.
