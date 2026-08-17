# Changelog

All notable changes to Jotter will be documented here. Decision records live in `docs/decisions.md`; security-audit findings live in `docs/security-audit-2026.md`; visual-identity rollout tracking lives in `docs/visual-identity.md`. Split from a single overloaded `BACKLOG.md` in #208.

## v1 (post-v0) — 2026-07-29: UI audit follow-through

`BACKLOG.md`'s Milestone checkmarks tracked backend/API delivery, not UI delivery — a 2026-07-29 audit of the SPA against the roadmap found 14 backend-complete features with no frontend surface. All 14 gaps (#156–#169) plus a follow-up (#197) are closed:

- **#156** search filters (title/tags/modified-date) exposed in `SearchResults.vue` (PR #170)
- **#169** nested folders rendered as a collapsible tree, not a flat list, via `NoteTreeNode.vue` (PR #172)
- **#168** attachment browser/management added (`AttachmentsPanel.vue`, PR #174)
- **#157** version history/restore added (`HistoryPanel.vue`, PR #176)
- **#160** typed properties UI added (`PropertiesPanel.vue`, PR #178); also fixed `NoteProperty::type` not casting to its enum, which silently nulled every non-string property value from the API
- **#162** templates/daily notes added: template picker in the New Note modal, daily-note header button (PR #180)
- **#158** comments added (`CommentsPanel.vue`, PR #182)
- **#166** audit log viewer added (`AuditLogViewer.vue`, PR #184); also fixed `AuditLogQueryController` requiring a `tenant_id` match most recorder call sites never populated
- **#164** workspace import added; also fixed sidebar 5-icon-button overflow (consolidated into a "More actions" menu) and `storage/app/imports` missing from `entrypoint.sh`'s ownership loop (PR #186)
- **#163** workspace export added to the sidebar's More-actions menu (PR #188)
- **#167** broken-link/orphan report added (`LinkReportViewer.vue`, PR #190)
- **#165** workspace publish added; also fixed `storage/app/public` permissions and a missing `index.html` on published sites (PR #192)
- **#159** notifications bell/dropdown added to the sidebar header (PR #194)
- **#161** collections table view added (`CollectionsTableView.vue`, sortable/filterable over `note_properties`); also fixed a property filter that only compared `value_string` and a frontend sort bug (PR #196)
- **#197** collections board/calendar views added (`CollectionsBoardView.vue`, `CollectionsCalendarView.vue`); extracted `services/collectionUtils.ts`; also fixed Symfony YAML resolving bare unquoted ISO dates to a Unix timestamp `int` instead of a string, which silently misclassified unquoted date properties as `NUMERIC` (PR #199)

## v1 (post-v0) — Milestones A–D, Spec Debt & Foundation Epics

Sequenced from `docs/20260727-jotter-roadmap-ai-agent.md` within `docs/jotter-initial-spec-and-build-plan.md` §14. All items below are delivered both backend and UI (see the 2026-07-29 UI audit above for the reconciliation of that distinction).

- **Milestone A** — version history with restore/dedup/pruning (#51); filtered search by title/tags/modified-date via `SearchCriteria` (#52); hardened Markdown/JSON import (zip-slip/symlink/allowlist guards, bounded job, round-trip backup, collision policy) (#53, #76, #77, #78)
- **Milestone B** — daily notes and templates (#56); broken-link report (#55); typed note properties with front-matter write-through (#54, #79, #80, #81); block registry, callouts, toggles, tables, dividers, slash-command menu (#57, #86, #87, #88); MCP server with machine-token auth and read-only tools (#58, #89, #90, #91)
- **Milestone C** — GrandpaSSOn identity adapter (#59); workspace administration UI (workspace/membership/user CRUD) (#60, #82, #83, #84); comments and `@mention` parsing (#61); notification/event bus feeding `audit_log` and `notifications` (#62)
- **Spec Debt & Foundation Epics** — audit log hardening (standardized vocabulary, redaction, append-only enforcement, retention) (#64, #70, #71, #72); note identity (rename/move endpoint, case-insensitive wikilink resolution) (#65, #73, #74, #75); visual identity design system adoption (#96, #97–#110 — see `docs/visual-identity.md`)
- **Milestone D** — metadata table view, board/calendar views, relations via wikilinks and typed front-matter (#63)
- **v2** — document parsing/web crawler/RAG delegated to TaskConnect via `JobDispatcher` contract (#67)

## v0 — spec-complete delivery

- Full-text search, nested vault folders, tags + front-matter projection, backlinks, attachments (spec §7)
- Command palette, note graph view, tag cloud, task lists, code-copy (post-v0 UI)
- WebDAV sync endpoint (hand-rolled `app/Http/Controllers/WebDavController.php` — `sabre/dav` is **not** a dependency, despite "SabreDAV" appearing in an earlier commit message and status note), workspace ZIP export, static-site publishing, `llms.txt` (AI-KB Layer 1), audit-log query, server-side CommonMark rendering + sanitization, `JobDispatcher` seam

## Unreleased

### Added

- Laravel 12 and Vue 3/Vite scaffold
- Workspace membership role enforcement (#347): `viewer` memberships are read-only across API mutation routes and WebDAV writes; GrandpaSSOn service tokens require the `kb:write` scope and workspace audience.

### Removed

- Unused `welcome.blade.php` default Laravel scaffold view and unused `tailwindcss` devDependencies (#105).

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
