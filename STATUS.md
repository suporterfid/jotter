# Jotter — Project Status

- **Current Version:** v0.9.0 (v0 spec contracts complete; v1 work in progress)
- **Last Updated:** 2026-07-28
- **Repo:** https://github.com/suporterfid/jotter
- **Production Site:** https://hub.taskconnect.com.br/
- **CI Status:** 🔴 Red on `main` — PHPUnit and Vitest pass; one Playwright spec fails (see §3)
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

- **CI is red on `main`.** The workflow has failed on every run since 2026-07-27 22:28, including the latest (`f57003d`). The current failure is narrow: `frontend/e2e/notes.spec.ts:26` times out waiting for `[data-testid="editor-title"]` to contain `e2e-demo` (1 failed, 1 passed). PHPUnit and Vitest pass. §0.3 of the spec requires green CI per PR, so this blocks the next unit.
- **`WorkspaceAuthorizationPlaceholder` is still present** in `app/Http/Middleware/` but is no longer aliased — `AuthorizeWorkspaceAccess` replaced it. Dead code pending removal.
- **The WebDAV adapter is hand-rolled, not SabreDAV.** `sabre/dav` is not a dependency despite the commit message and earlier status notes saying so.

---

## 4. Next

1. Fix the failing Playwright spec and restore green CI; add branch protection so §0.3 is enforced rather than conventional.
2. Resolve the §14.5 decisions that block planning — C1, C2, and C6 gate the nearest work.
3. Then Milestone A's remainder: version history (once C6 is decided), search filters, import.
4. **Visual identity (#96)** — cross-cutting presentation workstream adopting a shared dark/purple design system with semantic tokens, Open Sans, and WCAG 2.2 AA across the SPA, the Laravel shell, and the published static site. Sequenced independently of Milestones A–D and blocked on none of the §14.5 decisions, but gated behind item 1 like every other PR. See `BACKLOG.md`.
