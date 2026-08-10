# Jotter

<p align="center">
  <img src="assets/brand/wordmark.svg" alt="Jotter" width="280">
</p>

> Your pocket notebook — self-hosted, on the cPanel your grandpa never gave up.


Self-hosted, Markdown knowledge base for the cPanel your grandpa never gave up. Plain `.md` files, PHP + MySQL, your notes stay yours.

Jotter's v0 spec is complete (PR0–PR9) and v1 work is in progress: Laravel 12, a Vue 3 SPA with a Markdown editor and `[[wikilink]]` autocomplete, MySQL 8, a Docker-only development loop, the multi-workspace data model, path-safe vault storage, rebuildable wikilink/backlink projection, workspace-scoped search, workspace-scoped note CRUD, attachment uploads, and an `IdentityProvider` auth seam (`LocalIdentityProvider` plus a `GrandpaSSOnIdentityProvider` adapter) with workspace-scoped authorization enforced by default. Post-v0 additions include WebDAV sync, static site publishing, an MCP server, typed properties, admin workspace/member/user management, and a full visual identity system. See [STATUS.md](STATUS.md) for the authoritative current state.

## Documentation

- [Project Status](STATUS.md) — authoritative current state
- [Architecture Specification](file:///home/ubuntu/projects/web/iroh/jotter/docs/architecture.md)
- [Visual Identity Specification](file:///home/ubuntu/projects/web/iroh/jotter/docs/visual-identity.md)
- [Model Context Protocol (MCP)](file:///home/ubuntu/projects/web/iroh/jotter/docs/mcp.md)

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
