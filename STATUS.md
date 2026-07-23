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

## In progress — PR3 links & backlinks

PR2 is merged and green. PR3 is the active ordered unit from §7.2: project `[[note]]`, `[[note|alias]]`, and `[[note#heading]]` into the rebuildable `note_links` index; keep unresolved targets with `NULL target_note_id`; reconcile resolution on writes and `vault:reindex`; and expose backlinks only through MySQL relations/queries. Markdown bodies remain canonical files on disk and are not persisted to MySQL.

## Next — PR4 search

After PR3 is reviewed, merged, and green, the next ordered unit is the §7.3 MySQL `FULLTEXT` search index and search endpoint.
