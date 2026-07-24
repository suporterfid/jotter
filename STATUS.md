# Status

## Done — PR0 scaffold

- Laravel 12 application skeleton on PHP 8.2
- Minimal Vue 3/Vite landing screen
- Docker Compose development and CI environments with MySQL 8
- Docker-only `jt` commands for setup, tests, E2E, tools, and release
- Bootstrap-admin Artisan command and test
- Frontend unit test and Playwright smoke test
- Shared-hosting release zip, checksum, and artifact secret test
- CI workflow and baseline documentation

## Done — PR1 data model

- Idempotent initial tenant/workspace projection and state schema
- Focused Eloquent models and relationships
- Configured, repeat-safe default tenant/workspace seeding without users or credentials
- Data-model, scoping, relationship, and seeder feature tests
- Schema/projection documentation
- Merged to `main` with green Docker CI (#2)

## Done — PR2 vault storage

- Path-safe vault Markdown read/write rooted at each workspace `vault_path`
- Symfony YAML front-matter parsing into the rebuildable `notes` projection
- Incremental projection updates on write (path, title, frontmatter, content_hash, search_content, tags)
- Bounded `vault:reindex --workspace=<id>` reconcile for out-of-band disk edits
- Path-traversal rejection with audit coverage (§7.1 / §8 S2)
- Wikilink / `note_links` extraction left as explicit PR3 TODO
- Merged to `main` with green Docker CI (#3)

## Done — PR3 links & backlinks

PR3 projects `[[note]]`, `[[note|alias]]`, and `[[note#heading]]` into the rebuildable `note_links` index; unresolved targets are retained with `NULL target_note_id`; writes and `vault:reindex` reconcile resolution; and backlinks are MySQL relations/queries only. Markdown bodies remain canonical files on disk and are not persisted to MySQL. Merged after green Docker CI (#5).

## Done — PR4 search

- MySQL `FULLTEXT(title, search_content)` index, kept rebuildable from the vault projection
- Read-only `GET /api/workspaces/{workspace}/search?q=` endpoint with workspace scope, ranking, bounded snippets, and input validation
- No canonical Markdown body is returned or stored outside the rebuildable `search_content` projection
- Docker feature tests cover the index, ranking, workspace isolation, snippets, and query validation
- Frontend dependencies run from a named Docker volume; `jt test` no longer starts jsdom workers from the slow host bind mount
- Playwright navigation and test timeouts allow the Docker browser to finish the cold app load without aborting its frame
- `jt test` forces Laravel onto `jotter_testing`, keeping test migrations out of the seeded development database
- Merged to `main` with green Docker CI (#6)

## Next — PR5 notes CRUD API

The next ordered unit is the §7.4 workspace-scoped notes CRUD API behind the future auth guard placeholder.
