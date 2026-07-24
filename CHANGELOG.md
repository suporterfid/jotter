# Changelog

All notable changes to Jotter will be documented here.

## Unreleased

### Added

- Laravel 12 and Vue 3/Vite scaffold
- Docker-only local, test, E2E, and release workflows
- MySQL 8 development service
- Local administrator bootstrap command
- Shared-hosting release artifact and secret inspection
- CI and project documentation
- Multi-workspace tenant, workspace, note-index, link, tag, attachment, identity, membership, and audit data model
- Idempotent default tenant/workspace seeding with a configured on-disk vault path
- Rebuildable note search projection while Markdown files remain canonical
- Path-safe workspace vault storage service for Markdown read/write
- YAML front-matter parsing into the notes projection with incremental tag updates
- Bounded `vault:reindex` Artisan reconcile command for out-of-band disk edits
- Production Composer dependency on `symfony/yaml` for front-matter handling
- Workspace-scoped notes CRUD API backed by path-safe vault reads/writes/deletes
- Fail-closed notes authorization seam pending the PR7 identity provider
- 404 handling for out-of-band deleted Markdown files without false traversal audit entries
