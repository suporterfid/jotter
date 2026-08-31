# Jotter

<p align="center">
  <img src="assets/brand/wordmark.svg" alt="Jotter" width="280">
</p>

> Your pocket notebook — self-hosted, on the cPanel your grandpa never gave up.


Self-hosted, Markdown knowledge base for the cPanel your grandpa never gave up. Plain `.md` files, PHP + MySQL, your notes stay yours.

Jotter's v0 spec is complete (PR0–PR9) and v1 work is in progress. The foundation is Laravel 12, a Vue 3 SPA, MySQL 8 as a rebuildable index, and a Docker-only development loop, on a multi-workspace data model with path-safe vault storage and an `IdentityProvider` auth seam (`LocalIdentityProvider` plus a `GrandpaSSOnIdentityProvider` adapter) with workspace-scoped authorization enforced by default.

What ships on top of that today:

- **Editing** — inline WYSIWYG over Markdown (Milkdown) with a slash-command menu, selection formatting toolbar, `[[wikilink]]` autocomplete, and raw-source/split/preview modes still available
- **Knowledge graph** — backlinks, outgoing links, unlinked mentions, transclusion, per-note local graph, hover preview, outline pane, tabs, and a broken-link/orphan report
- **Collaboration** — comments with `@mentions` anchored to a text selection, in-app notifications, version history with restore, and an append-only audit log with redaction and retention
- **Structure** — typed properties projected from YAML front matter, plus table, kanban (drag-and-drop, swimlanes, checklists), and calendar views over them
- **Content operations** — full-text search with title/tag/date filters, templates and daily notes, attachments, ZIP/JSON export and import, static site publishing, and WebDAV sync
- **Platform** — admin workspace/member/user management, an MCP server with machine-token auth, `en`/`pt-BR` localization with RTL support, and a light/dark/system visual identity across the app and published pages

See [STATUS.md](STATUS.md) for the authoritative current state and [BACKLOG.md](BACKLOG.md) for what is deferred.

## Use with AI agents (MCP)

Jotter is an MCP server: Claude Code, Cursor, Claude Desktop, and any other
Model Context Protocol client can list, read, search, and follow backlinks
across the workspaces a token is allowed to see — read-only, per-user
authorization, every call audited.

1. Administration → **MCP tokens** → issue a token (or `php artisan mcp:token you@example.com`).
2. Paste the generated snippet, e.g. for Claude Code:

   ```sh
   claude mcp add --transport http jotter https://<host>/api/mcp \
     --header "Authorization: Bearer jt_mkt_…"
   ```

3. Ask the assistant to "list my notes".

Step-by-step for each client, `.mcp.json` / `.cursor/mcp.json` /
`claude_desktop_config.json` blocks, and common errors:
[docs/mcp-clients.md](docs/mcp-clients.md). Server reference:
[docs/mcp.md](docs/mcp.md). Migrating an existing vault? See
[docs/migrate-from-obsidian.md](docs/migrate-from-obsidian.md) and
[docs/migrate-from-notion.md](docs/migrate-from-notion.md).

## Hosted option

If you would rather not run it yourself, [Cadernia](https://cadernia.app)
offers Jotter as a hosted service. It runs this same open-source engine —
branding, plans, and provisioning are configuration, not a fork
([docs/decisions.md](docs/decisions.md)) — so you can export a full ZIP and
self-host at any time.

## Self-hosting on shared PHP hosting

Jotter targets ordinary PHP 8.2 + MySQL 8 shared hosting: no daemons, no
workers, one cron entry. Build the release ZIP with `./scripts/jt.sh release`,
upload, point the document root at `public/`, run migrations, add the cron,
and check the installation with `php artisan jotter:doctor`. Full guide:
[docs/deployment.md](docs/deployment.md); running several customers on one
host and operating them over SSH: [docs/hosted-operations.md](docs/hosted-operations.md).

## Documentation

- [Project Status](STATUS.md) — authoritative current state
- [Backlog](BACKLOG.md) — deferred work and open decisions
- [Architecture Specification](docs/architecture.md)
- [Initial Spec & Build Plan](docs/jotter-initial-spec-and-build-plan.md) — planning authority
- [Decision Record](docs/decisions.md)
- [Visual Identity Specification](docs/visual-identity.md)
- [Model Context Protocol (MCP)](docs/mcp.md) — server reference
- [Connect AI clients (Claude Code, Cursor, Claude Desktop)](docs/mcp-clients.md)
- [Migrate from Obsidian](docs/migrate-from-obsidian.md) · [Migrate from Notion](docs/migrate-from-notion.md)
- [Deployment](docs/deployment.md)
- [Hosted operations](docs/hosted-operations.md)

## Requirements

- Docker with Docker Compose V2
- No host PHP, Composer, Node, npm, or MySQL installation

The frontend dependency tree runs from a Docker named volume, so the `jt` commands do not depend on a host `node_modules` directory or its bind-mount performance.

## Start from a clean clone

On macOS/Linux:

```sh
./scripts/jt.sh up
```

On Windows PowerShell:

```powershell
.\scripts\jt.ps1 up
```

The command generates untracked development credentials in `.env`, installs locked dependencies, builds the Vue app, migrates MySQL, and serves Jotter at [http://localhost:8080](http://localhost:8080).

## Commands

Use `scripts/jt.sh` or `scripts/jt.ps1` with:

- `up` — bootstrap and start the application
- `down` — stop containers
- `test` — run Laravel and frontend unit tests
- `e2e` — run the Playwright smoke test
- `artisan`, `composer`, `npm` — run the corresponding tool in a container
- `release` — create `dist/jotter-release.zip` and its SHA-256 checksum
- `release:verify` — scan an existing release ZIP for secrets and private keys

Bootstrap the first local administrator after startup:

```sh
./scripts/jt.sh artisan platform:bootstrap-admin admin@example.com 'use-a-long-random-password'
```

The password is hashed and is never echoed by the command.

## Deployment

The release zip contains a deployable `app/` tree with production Composer dependencies and built assets. Point the hosting document root at `app/public/`. See [docs/deployment.md](docs/deployment.md).

## Architecture

The intended source of truth is Markdown on disk; MySQL is a rebuildable index and application-state store. See [docs/architecture.md](docs/architecture.md) and the [authoritative initial spec](docs/jotter-initial-spec-and-build-plan.md).

## Search API

`GET /api/workspaces/{workspace}/search?q=...` searches the MySQL `FULLTEXT(title, search_content)` projection within one workspace and returns at most 50 ranked matches with an id, path, title, bounded snippet, and relevance score. `search_content` is never returned or treated as canonical note content; the Markdown file remains the source of truth. The endpoint is authenticated and workspace-scoped like the rest of the notes API.

## License

MIT
