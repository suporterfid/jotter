# Jotter

> Your pocket notebook — self-hosted, on the cPanel your grandpa never gave up.

Self-hosted, Markdown knowledge base for the cPanel your grandpa never gave up. Plain `.md` files, PHP + MySQL, your notes stay yours.

Jotter currently ships through PR2: Laravel 12, a minimal Vue 3 landing screen, MySQL 8, a Docker-only development loop, the multi-workspace data model, and a path-safe vault storage service that keeps Markdown files on disk as the source of truth. Search endpoints, notes CRUD APIs, wikilinks/backlinks, attachment upload, and identity-provider features remain later PRs.

## Requirements

- Docker with Docker Compose V2
- No host PHP, Composer, Node, npm, or MySQL installation

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

Bootstrap the first local administrator after startup:

```sh
./scripts/jt.sh artisan platform:bootstrap-admin admin@example.com 'use-a-long-random-password'
```

The password is hashed and is never echoed by the command.

## Deployment

The release zip contains a deployable `app/` tree with production Composer dependencies and built assets. Point the hosting document root at `app/public/`. See [docs/deployment.md](docs/deployment.md).

## Architecture

The intended source of truth is Markdown on disk; MySQL is a rebuildable index and application-state store. See [docs/architecture.md](docs/architecture.md) and the [authoritative initial spec](docs/jotter-initial-spec-and-build-plan.md).

## License

MIT
