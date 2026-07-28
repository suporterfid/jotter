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
