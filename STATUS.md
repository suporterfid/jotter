# Jotter — Project Status

- **Current Version:** v0.9.0 (v0 spec contracts complete; v1 work in progress)
- **Last Updated:** 2026-07-28
- **Repo:** https://github.com/suporterfid/jotter
- **Production Site:** https://hub.taskconnect.com.br/
- **CI Status:** 🟢 Green on `main`. #140 and #142 fixed via PR #144, merged and confirmed on two green GitHub Actions runs, both issues closed. **`main` is now branch-protected** (#148): the `test` CI job is a required status check, `enforce_admins` is on, force-pushes and deletions are disabled — direct pushes and merges without green CI are rejected by GitHub. Verified live: an empty direct-push commit was rejected with `Required status check "test" is expected`. This is the mechanism that was missing when #140 regressed silently after #49.
- **Planning authority:** `docs/jotter-initial-spec-and-build-plan.md` §14 sequences post-v0 work from `docs/20260727-jotter-roadmap-ai-agent.md`

---

## 1. Accomplished (v0 spec and beyond)

- **PR0 — Scaffold & CI**: Laravel 12 + Vue 3 SPA + Vite + Docker dev + `jt` scripts + release target.
- **PR1 — Data Model & Migrations**: Idempotent schema (`tenants`, `workspaces`, `notes`, `note_links`, `tags`, `note_tags`, `attachments`, `users`, `memberships`, `audit_log`).
- **PR2 — Vault Storage Service**: Plain `.md` files on disk, front-matter parsing, `VaultPathGuard` traversal protection, `vault:reindex` Artisan command.
- **PR3 — Links & Backlinks**: `[[wikilinks]]` projected into `note_links`, resolved and unresolved refs retained.
- **PR4 — Full-Text Search**: MySQL `FULLTEXT` over title and content (`GET /api/workspaces/{id}/search`).
- **PR5 — Workspace Notes CRUD API**: Workspace-scoped notes endpoints.
- **PR6 — Frontend Vue 3 SPA**: Glassmorphism UI, Markdown editor, `[[` autocomplete, backlinks panel.
- **PR7 — Auth Abstraction**: `IdentityProvider` seam with `LocalIdentityProvider` and a `GrandpaSSOnIdentityProvider` adapter; `AuthorizeWorkspaceAccess` replaced the fail-closed placeholder.
- **PR8 — Attachment Management**: Uploads to vault `_resources/` with a 20 MB type/size allowlist and streaming endpoints.
- **PR9 — Deployment Hardening**: Shared-hosting deployment, AutoSSL, production `.env` configuration.
- **Post-v0 enhancements**: command palette, drag & drop uploads, GFM task lists, code-block copy, tag cloud + sorting, note graph view, sync endpoint, ZIP export, audit-log query,  - Server-Side CommonMark Renderer & XSS Sanitizer (`MarkdownServerRenderer.php`)
  - Background Job Dispatcher Seam (`JobDispatcher` + `LocalJobDispatcher`)
  - WebDAV Adapter (`WebDavController`) with `PROPFIND`, `GET`, `PUT`, `MKCOL`, `DELETE`, `OPTIONS`
  - Note Rename & Relocation Endpoint (`POST /api/workspaces/{w}/notes/{n}/move`)
  - Case-Insensitive Wikilink Resolution Policy (`WikilinkProjector.php`)
  - Audit Log Hardening Suite (`AuditEvent` enum, `AuditRecorder` with automatic redaction, append-only immutability, tenant-scoped queries)
  - Audit Log Retention & Pruning Command (`audit:prune --days=90` with chunked deletion)
  - Hardened Vault Import Pipeline (`VaultExtractor` with Zip-Slip protection, `POST /api/workspaces/{w}/import` endpoint, overwrite collision policy, and full export-import round-trip equivalence)
  - Typed Property Model Specification & Inference Matrix (`NotePropertyType` enum & decision record in `BACKLOG.md`)
  - Rebuildable Typed Property Projection (`note_properties` table, `NotePropertyProjector`, and drop-and-rebuild parity test `NotePropertyProjectionTest.php`)
  - Typed Properties API & Front-Matter Write-Through (`GET /api/workspaces/{w}/properties`, `POST` & `DELETE` note properties with disk write-through)
  - Admin Workspace CRUD & Validated Vault Root (`POST /api/admin/workspaces`, `PUT`, `archive`, `VaultRootGuard` base path confinement & nesting collision protection)
  - Admin Membership & Role Management (`GET/POST/PUT/DELETE /api/admin/workspaces/{w}/members`, role definitions, last owner protection)
  - Admin Local User Management (`GET/POST /api/admin/users`, deactivate, reactivate, password reset, self-service change password, provider gating)
  - Admin Administration UI (`frontend/src/components/AdminPanel.vue`, Workspaces/Members/Users tabs, modal & Vitest test suite)
  - Declarative Block Registry (`app/Domain/Vault/BlockRegistry.php`, `frontend/src/services/blockRegistry.ts`, PHPUnit `BlockRegistryTest` & Vitest `blockRegistry.spec.ts`)
  - Callouts, Toggles, Tables, and Dividers Dual-Path Rendering (`MarkdownServerRenderer.php` with TableExtension & callout regex, `markdown.ts` callout renderer)
  - Slash-Command Insertion Menu (`SlashMenu.vue` component driven by `blockRegistry.ts`, filter/navigation & `SlashMenu.spec.ts`)
  - MCP HTTP Transport & Machine-Token Auth (`POST /api/mcp`, `machine_tokens` table, SHA-256 tokens via `IdentityProvider` seam, `McpTransportAuthTest`)
  - Read-Only MCP Tools & Cross-Workspace Denial (`list_notes`, `read_note`, `search_notes`, `get_backlinks`, `McpReadOnlyToolsTest`)
  - MCP Write Tools Decision & Scope (`docs/mcp.md`, write tools intentionally deferred and gated per §8 S2 & S5)
  - Visual Identity Specification & Brand Assets (`docs/visual-identity.md`, `assets/brand/README.md`, brand assets inventory)
  - Visual Identity Semantic Design-Token Layer (`frontend/src/styles/tokens.css` with semantic variables, status extension tokens & contrast ratios table in `docs/visual-identity.md`)
  - Visual Identity Self-Host Open Sans & Remove Google Fonts CDN (`frontend/src/styles/fonts.css`, WOFF2 files under `frontend/src/assets/fonts/`, removed Google Fonts CDN links)
  - Visual Identity: Migrate SPA components off raw color literals onto semantic tokens (#100 — all 8 components, glassmorphism retired, `--color-*` tokens throughout)
  - Visual Identity: Type scale, responsive headings, heading hierarchy (#101 — scale tokens added to `tokens.css`, `style.css` H1 fixed from 6.5rem/lh-0.9 to fluid clamp, ≥16px prose enforced)
  - Visual Identity: Spacing, layout widths, radius, borders, elevation — retire glassmorphism (#102 — `--space-*`/`--radius-*` tokens everywhere, all `backdrop-filter` removed, solid overlay backgrounds, radial-gradient body removed)
  - Visual Identity: Buttons, links, inputs, focus rings, touch targets (#103 — all six `outline: none` declarations removed, global `:focus-visible` ring added in `App.vue`, `min-height: 36–44px` touch targets enforced, primary/secondary button vocabulary defined)
  - Visual Identity: Motion tokens and prefers-reduced-motion support (#104 — `--duration-*`/`--ease-standard` used across SPA, `@media (prefers-reduced-motion: reduce)` block added to `tokens.css`)
  - Visual Identity: Reconcile landing and welcome-page themes (#105 — removed unused `welcome.blade.php` and `tailwindcss` devDependencies, cleaned `style.css` base layer, documented import order in `main.ts`)
  - Visual Identity: Theme published static site (#106 — Blade view `publish/page.blade.php` + `publish.css` + self-hosted Open Sans WOFF2 fonts copied to output, responsive reading column, visited link styles, reduced motion block)
  - Visual Identity: App-shell document metadata — favicon, theme-color, color-scheme, social card (#107 — `app.blade.php` and `index.html` synchronized with `color-scheme: dark`, `theme-color: #000000`, favicons SVG/ICO/Apple, OG & Twitter cards)
  - Visual Identity: Project mark, wordmark, favicon, and social card (#108 — `assets/brand/` directory structured with brand guidelines `assets/brand/README.md`, wordmark added to `README.md`)
  - Visual Identity: WCAG 2.2 AA audit and axe-core regression test (#109 — `frontend/src/a11y.spec.ts` automated axe-core Vitest spec covering all SPA views, documented contrast audit matrix and manual checklist in `docs/visual-identity.md`)
  - Visual Identity: CI guard against raw color literals and unapproved font sources (#110 — `./scripts/check-design-tokens.sh` executable guard script enforcing raw color literal ban, palette token ban, font CDN ban, and un-annotated outline:none ban)
  - Milestone A: Filtered search by title, tags, and modified-date range (#52 — `SearchCriteria` value object, extended `GET /api/workspaces/{w}/search` params, index-backed multi-tag/title/date filtering)
  - CI stability: Playwright notes.spec.ts login wait & SPA error surfacing (#49 — aligned login wait to 10s, surfaced createNote errors in SPA)
  - Milestone A: Hardened archive extraction (#76 — `VaultExtractor` domain service with zip-slip, symlink, path-aliasing, type allowlist, and size/entry bounds)
  - Milestone A: Bounded import job and upload endpoint (#77 — `VaultImportCommand` Artisan command, `POST /api/workspaces/{w}/import` endpoint, staged upload cleanup)
  - Milestone A: Collision policy and JSON backup format round-trip (#78 — `VaultBackupRoundTripTest` equivalence suite, JSON v1.0 backup export/import support, configurable overwrite collision policy)
  - Milestone B: Typed property model design decisions (#79 — recorded in `BACKLOG.md`)
  - Milestone B: Rebuildable typed property projection (#80 — `note_properties` schema, `NotePropertyProjector`, `VaultReindexer` integration, drop-and-rebuild test)
  - Milestone B: Expose properties in notes API (#81 — `GET /api/workspaces/{w}/properties`, front-matter write-through via `VaultStorage`)
  - Milestone B: Broken-link and orphan report (#55 — `GET /api/workspaces/{w}/link-report` endpoint returning unresolved wikilinks and orphan notes)
  - Milestone A: Note version history with restore (#51 — `note_revisions` schema, `NoteRevisions` service with content-hash deduplication, `vault:prune-revisions` command, REST revision endpoints)
  - Milestone B: Daily notes and note templates (#56 — `_templates/` folder, `TemplateEngine` variable substitution, `POST /notes/from-template`, `GET|POST /daily/{date?}`)
  - Milestone B: Richer block and slash-command surface epic (#57 — declarative block registry, dual-path sanitization, callouts, toggles, tables, dividers, slash insertion menu)
  - Milestone B: MCP server epic (#58 — machine-token auth over IdentityProvider seam, read-only vault tools & resources)
  - Milestone C: GrandpaSSOn identity adapter (#59 — `GrandpaSSOnIdentityProvider` adapter over `IdentityProvider` seam)
  - Milestone C: Workspace and membership administration epic (#60 — workspace CRUD, membership & role management, local user management)
  - Spec Debt: Audit log hardening epic (#64 — standardized event vocabulary, redaction, append-only DB enforcement, retention)
  - Spec Debt: Note identity epic (#65 — path rename/move endpoint, wikilink case-insensitive & ambiguity resolution policy)
  - Visual Identity: Shared dark/purple design system epic (#96 — semantic token layer, Open Sans typography, WCAG 2.2 AA audit, CI token guard)
  - Decisions: Resolved all six roadmap/spec conflicts C1–C6 in `BACKLOG.md` (#50)
  - Tracking: Completed post-v0 implementation plan tracking (#68)
  - Milestone C: Inline comments and mentions (#61 — `note_comments` schema, `WorkspaceCommentController`, mention parsing)
  - Milestone C: Notification and event bus (#62 — `notifications` schema, `WorkspaceEventEmitter`, `WorkspaceNotificationController`)
  - Milestone D: Structured collections and views (#63 — `WorkspaceCollectionController` table view over `note_properties`)
  - v2 Epic: Heavy content processing delegated to TaskConnect (#67 — `docs/taskconnect-integration.md` delegation contract)

---

## 2. Position against the product roadmap

`docs/20260727-jotter-roadmap-ai-agent.md` ranks twelve near-term priorities. **Five of its top six already ship here** — search, nested folders, tags, backlinks, and attachments. Its gap analysis says otherwise because its baseline describes a different product (offline-first, Material You, realtime collaboration); spec §14.1 records this and §14.3 carries the authoritative delivered-state table.

| Roadmap milestone | State |
|---|---|
| A — knowledge foundations | Mostly delivered. Open: version history, search filters, import. |
| B — connected knowledge | Partial. Command palette and graph view ship; templates, broken-link report, typed properties, MCP server open. |
| C — team collaboration | Data model and per-request authorization ship. GrandpaSSOn adapter, admin UI, comments, notifications open. |
| D — structured work | Not started; blocked on decision C2 (spec §14.5). |

Six roadmap items conflict with hard constraints and are parked pending decisions — realtime presence, structured collections, synced blocks, offline/mobile-first, history storage, and the baseline's provenance. `BACKLOG.md` carries them under **Needs a decision**.

---

## 3. Known issues

- **UI audit vs. `docs/20260727-jotter-roadmap-ai-agent.md`, 2026-07-29: 14 gaps found and filed (#156–#169).** The backend has shipped far more than the SPA exposes — `BACKLOG.md`'s `[x]` marks track API delivery, not UI/UX delivery. 13 backend-complete features have zero frontend (version history, comments, notifications, typed properties, collections/table/board/calendar views, templates/daily notes, export, import, publish, audit log viewer, broken-link report, attachment browser, and search filters); nested folders render as a flat list rather than a tree (degraded, not missing). Prioritized and being worked one issue at a time:
  - ~~**#156 — search filters (title/tags/modified-date) not exposed.**~~ **Closed.** Filter bar added to `SearchResults.vue`, wired through to the existing backend `SearchCriteria` params. Verified in a real browser (not just unit tests): created two notes sharing a search term, confirmed the title filter narrows 2 matches to 1 and keeps the correct one. Merged via **PR #170**.
  - ~~**#169 — nested folders are a flat list, not a tree.**~~ **Closed.** Added a recursive `NoteTreeNode.vue`; `Sidebar.vue` now groups notes by folder segment into a collapsible tree (folders sorted alphabetically before files, default-expanded, note-count badge per folder). Leaf rows kept identical markup/classes to the old flat list, so `.notes-list` still contains each note's path text and `e2e/notes.spec.ts`'s existing assertion needed no changes. Verified via vitest (24/24), `vue-tsc`, the `a11y.spec.ts` axe check, and a manual Playwright run against the dev server with nested test notes. Merged via **PR #172**.
  - ~~**#168 — no attachment browser/delete UI.**~~ **Closed.** `getAttachments()`/`deleteAttachment()` were already defined in `api.ts` and fully supported server-side, but the SPA never called either — drag-drop upload in `NoteEditor.vue` was the only entry point. Added `AttachmentsPanel.vue` (workspace-level grid, thumbnails, delete-with-confirm), toggled from a new sidebar header button. Verified via vitest (29/29) and a manual Playwright run simulating a real drag-drop upload, listing, and delete. Merged via **PR #174**.
  - ~~**#157 — no version history/restore UI.**~~ **Closed.** `WorkspaceNoteRevisionController` (list/show/restore) and the deduplicated `note_revisions` table were fully wired server-side with no SPA UI for any of it. Added `HistoryPanel.vue` (revision list, read-only preview, restore action) opened via a new `history-btn` in `NoteEditor.vue`. Verified via vitest (36/36) and a manual Playwright run: saved two edits, previewed and restored the oldest of 3 revisions, confirmed the editor reflected it. Merged via **PR #176**.
  - ~~**#160 — no typed properties UI.**~~ **Closed.** Added `PropertiesPanel.vue` in `NoteEditor.vue` (list, typed add form, delete), wired to the existing property endpoints. Also found and fixed a real backend bug while verifying in a browser: `NoteProperty::type` wasn't cast to its enum, so `match($p->type)` in both controllers' `metadata()` always fell through to `default` — every non-string property value (numeric/boolean/datetime/list/json) silently returned `null` from the API despite being stored correctly. Fixed with a model-level enum cast; regression test confirmed to fail without it. Merged via **PR #178**.
  - ~~**#162 — no templates/daily-notes UI.**~~ **Closed.** New Note modal now offers an optional template picker (from `_templates/`) calling the existing `createFromTemplate` endpoint; a new daily-note header button gets-or-creates today's journal entry (idempotent). Verified via vitest (43/43) and a manual Playwright run confirming `{{title}}`/`{{workspace}}`/`{{date}}` substitution and no-duplicate-on-repeat-click. Merged via **PR #180**. This closes out Tier 2.
  - **#158** — Tier 2's last item (comments). Open, next in the queue.
  - **#166, #164, #163, #167, #165** — Tier 3, admin/ops UI (audit log, import, export, link report, publish). Open.
  - **#159, #161** — Tier 4, new app-shell surfaces (notifications, collections/board/calendar — Milestone D). Open.
- ~~**#145 — `WorkspaceCommentController::destroy()` had no comment-ownership check.**~~ **Closed.** Any workspace member could delete any other member's comment — the endpoint only checked workspace-level authorization, not that the caller was the comment's author. Found via a codebase security audit, 2026-07-28. Fixed by requiring the caller be the comment's author or a workspace/tenant admin; regression tests added in `tests/Feature/WorkspaceCommentAuthorizationTest.php`. Merged via **PR #149**.
- ~~**#146 — MCP `read_note` tool calls undefined `VaultStorage::getNoteContent()`.**~~ **Closed.** Fatal error on every call; the actual method is `readContents()`. Found in the same audit. Confirmed both ways (revert reproduces 500, fix passes) via an extended `McpReadOnlyToolsTest`. Merged via **PR #150**.
- ~~**#147 — `GrandpaSSOnIdentityProvider` unconditionally creates every new SSO user as an admin.**~~ **Closed.** `is_admin => true` was hardcoded for new SSO-created users, and a null `is_admin` also resolved to admin — both now default to `false`. Gated behind `AUTH_PROVIDER=grandpasson` (not the default). Confirmed both ways (revert reproduces admin escalation, fix passes). Merged via **PR #151**. (The `WHERE expires_at > time()` behavior noted as "dead code" when this was filed was an artifact of that PR's own fabricated test schema, not GrandpaSSOn's real one — see #154, which found and fixed the actual bug: this code was never querying GrandpaSSOn's real tables at all.)
- ~~**#154 — `GrandpaSSOnIdentityProvider` queried Jotter's own tables, not GrandpaSSOn's.**~~ **Closed.** `DB::table('sessions')`/`DB::table('users')` go through Eloquent's default connection, which silently applies *this app's own* `DB_PREFIX` (e.g. `jt_` in production) — so this code queried Jotter's own local session table, never GrandpaSSOn's actual identity data, on shared hosting where the two apps share one MySQL database. A more fundamental bug than #147: even with that fix, real SSO sessions could never resolve. Fixed by querying GrandpaSSOn's real tables via the raw PDO connection with a separately configurable prefix (`jotter.sso.db_prefix` / `JOTTER_SSO_DB_PREFIX`, default `sso_`). Found via a cross-repo deployment-compatibility review for hub.taskconnect.com.br. Merged via **PR #153**.
- ~~**#148 — `main` has no branch protection requiring green CI before merge.**~~ **Closed.** This is exactly how #140 regressed silently after #49. Fixed via a repo-settings change (not a code diff): `main` now requires the `test` CI job to pass, `enforce_admins` is on, force-pushes/deletions are disabled. Verified live — a direct push was rejected by GitHub with `Required status check "test" is expected.`
- ~~**#140 — CI red on `main`.**~~ **Closed.** Root cause: `docker/php/entrypoint.sh` chowned `storage/app/private` for `www-data` but never `storage/app/vaults`, the default vault root (`config/jotter.php`). On a genuinely fresh checkout that directory doesn't exist, and `VaultPathGuard::ensureVaultRoot()`'s `mkdir()` failed with permission denied on the first note-create request — a real 500 that the old invisible `alert()` in `App.vue` hid from Playwright, making it look like a flaky timeout. Found by pulling the actual GitHub Actions logs, adding inline console/network diagnostics to `notes.spec.ts` and a Laravel-log dump to `.github/workflows/ci.yml`, then reproducing locally by resetting `storage/app` permissions to match a fresh checkout (confirmed failing without the fix, passing with it). `App.vue`'s `alert()` is also replaced with a DOM-visible `data-testid="error-banner"` regardless of trigger. Fixed and merged via **PR #144**, confirmed on two green GitHub Actions runs.
- ~~**`WorkspaceAuthorizationPlaceholder` is fully deleted from `app/`**~~ — **Closed (#142).** The six dead `withoutMiddleware(WorkspaceAuthorizationPlaceholder::class)` calls in `tests/Feature/WorkspaceNotesApiTest.php` are removed (not replaced with the real middleware class): every test in that file already authenticates as an `is_admin` user via `LocalIdentityProvider`, and `LocalIdentityProvider::isAuthorizedForWorkspace()` short-circuits to `true` for admins regardless of membership, so no bypass was ever needed. Verified via `jt test` (98 PHPUnit, 20 Vitest, all pass) and grepped the rest of the suite for stray references to the deleted class — none found. Merged via **PR #144**.
- **The WebDAV adapter is hand-rolled, not SabreDAV.** `sabre/dav` is not a dependency despite the commit message and earlier status notes saying so.
- ~~**`BACKLOG.md` was self-contradictory**~~ — the "Recorded Decisions" and "Needs a decision" sections disagreed about whether C1–C6 were resolved. Fixed; see #141.

---

## 4. Next

1. ~~Merge the `claude/fix-140-142-ci-and-dead-tests` PR~~ — **done.** PR #144 merged 2026-07-28, `main` is green on two consecutive GitHub Actions runs, #140 and #142 closed.
2. ~~Clean up **#142**~~ — done in the same PR.
3. ~~**Security/correctness audit findings #145–#148**~~ — **all closed.** #145, #146, #147 fixed and merged (PR #149, #150, #151); #148 fixed via a `main` branch-protection rule (required status check, `enforce_admins`, no force-push/delete), verified live. From this point on, every change to `main` (including doc-only updates like this one) must go through a PR with a green `test` check — direct pushes are rejected.
4. Resolve the remaining §14.5 decision — roadmap baseline provenance is the only one still open; C1–C6 are recorded resolved (see `BACKLOG.md`).
5. Milestone A/B/C/D are all recorded complete, and CI is now green again — treat that claim as trustworthy for the current `main` HEAD, but note there is still no branch protection requiring green CI before merge (#148, open), so this can regress silently again (as #140 did after #49).
6. **Visual identity (#96)** — cross-cutting presentation workstream adopting a shared dark/purple design system with semantic tokens, Open Sans, and WCAG 2.2 AA across the SPA, the Laravel shell, and the published static site. Recorded complete; now unblocked since item 1 is done.
