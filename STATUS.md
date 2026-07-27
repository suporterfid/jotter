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

## Done — PR5 notes CRUD API

- Workspace-scoped `GET/POST/PUT/DELETE /api/workspaces/{workspace}/notes[/{note}]`
- All file access flows through the path-safe vault service; Markdown remains canonical and MySQL remains a rebuildable projection
- Cross-workspace note identifiers return 404; traversal attempts are audited before becoming validation failures
- Fail-closed `workspace.authorization` middleware seam for PR7's local identity/membership enforcement
- Docker feature tests cover CRUD, disk reads, workspace isolation, and traversal rejection
- Merged to `main` with green Docker CI (#8)

## Done — PR6 frontend

- Vue 3 SPA workspace note browser, creation modal, note filtering, and note deletion
- Markdown editor with live preview (split/editor/preview mode toggles) and auto-save / manual save
- Wikilink (`[[note]]`, `[[note|alias]]`, `[[note#heading]]`) parsing and click navigation
- Wikilink autocomplete popup triggered when typing `[[` in the Markdown editor
- Backlinks panel displaying incoming database links for the selected note
- Full-text search UI with snippet display and ranked match navigation
- Safe Markdown HTML rendering sanitized with DOMPurify to prevent XSS and script execution
- Unit tests (`vitest`) and Playwright E2E happy path tests (`jt e2e`) green in Docker

## Done — PR7 auth abstraction & local identity (§7.6)

- Domain interface `App\Domain\Auth\Contracts\IdentityProvider` and `AuthenticatedSubject` DTO
- `LocalIdentityProvider` handling session authentication via web guard, password hashing, workspace membership check, and audit logging
- `GrandpaSSOnIdentityProvider` fail-closed stub for future GrandpaSSOn v1 integration
- Configurable service container binding (`JOTTER_AUTH_PROVIDER=local|grandpasson`) and `AuthorizeWorkspaceAccess` middleware enforcing workspace access
- Stateful API session middleware on API routes with CSRF cookie endpoint and audit log coverage (`auth.login.success`, `auth.login.failed`, `auth.logout`, `auth.rejected`)
- Vue 3 modal login workflow, user profile status badge, logout lifecycle, and 401 unauthenticated interceptor
- 100% green unit, feature, and Playwright E2E test suites in Docker

## Done — PR8 attachment uploads (§7.7)

- `AttachmentStorage` domain service storing files outside `public/` inside vault `_resources/` directory
- Workspace-scoped attachment endpoints: `GET/POST/DELETE /api/workspaces/{workspace}/attachments` and file streaming `GET /api/workspaces/{workspace}/attachments/{path}`
- Content-type and file extension allowlist validation with 20MB file size limit
- Path traversal protection via `VaultPathGuard` auditing `vault.path_traversal_rejected`
- Audit log tracking for attachment creation (`attachment.created`) and deletion (`attachment.deleted`)
- Vue 3 toolbar attachment button and automatic Markdown image/file link insertion
- 100% green feature (`AttachmentUploadTest`) and Playwright E2E test suites

## Done — PR9 deployment & shared hosting hardening (§7.8)

- Configured Hostinger Shared Web Hosting environment with PHP 8.2 & MariaDB 11.8.8
- Single shared database deployment (`u250556264_taskconnecthub`) with namespaced table prefixes (`jt_`, `tc_`, `sso_`)
- Integrated Apache master `.htaccess` subpath routing (`/` -> Jotter, `/tc` -> TaskConnect, `/sso` -> GrandpaSSOn)
- Fresh clean-slate database reset, automated remote migrations, and admin account seeding (`admin@taskconnect.com.br`)
- Live production verification: `https://hub.taskconnect.com.br/` (Jotter 200 OK), `https://hub.taskconnect.com.br/tc` (TaskConnect 200 OK), `https://hub.taskconnect.com.br/sso/login` (GrandpaSSOn 200 OK)

