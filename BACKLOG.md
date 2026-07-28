# Jotter — Backlog

Sequenced from `docs/20260727-jotter-roadmap-ai-agent.md` (product roadmap) within the constraints of `docs/jotter-initial-spec-and-build-plan.md` §14. Where the two disagree, the spec's §8 security constraints, §4 shared-hosting constraints, and §1 Markdown-on-disk invariant win, and the item sits in **Needs a decision** below until resolved.

Roadmap priorities 1–5 (search, nested folders, tags, backlinks, attachments) are **already delivered** — see spec §14.3. The roadmap's gap analysis lists them as missing because its baseline describes a different product; do not re-plan them.

---

## Known gaps (open, 2026-07-28)

Found during a review of whether `main` is 100% adherent to the roadmap and this backlog. All backlog milestones below are checked off, but three gaps mean that claim doesn't fully hold without these caveats. Each has a detailed GitHub issue; none are resolved by this pass except where noted.

- **CI is red on `main`, still, after the prior fix.** `frontend/e2e/notes.spec.ts` fails on the current `main` HEAD with the same symptom #49 diagnosed and closed as fixed — the fix didn't hold, and code landed after it (the version-history epic, #51/#136) that changed the exact code path the failing test exercises. Root cause needs re-investigation in a Docker-capable environment. Tracked in **#140**.
- **This file was self-contradictory.** The "Recorded Decisions" section marked C1–C6 resolved while the "Needs a decision" section below it still listed the same five as open blockers — added by different commits that were never reconciled. **Fixed in this pass**; see **#141**.
- **Dead-code cleanup from #66 was incomplete.** `WorkspaceAuthorizationPlaceholder` was deleted from `app/`, but six tests in `tests/Feature/WorkspaceNotesApiTest.php` still reference it by name; since nothing imports the class, PHP resolves it to an unrelated string and the `withoutMiddleware()` calls silently no-op instead of bypassing anything. `STATUS.md`'s description of this item was also stale (it said the file was still present). Tracked in **#142**.

---

## Delivered (not backlog — recorded so the roadmap is not re-planned against it)

- Full-text search, nested vault folders, tags + front-matter projection, backlinks, attachments — v0 §7.
- Command palette, note graph view, tag cloud, task lists, code-copy — post-v0 UI.
- WebDAV sync endpoint, workspace ZIP export, static-site publishing, `llms.txt` (AI-KB Layer 1), audit-log query, server-side CommonMark rendering + sanitization, `JobDispatcher` seam.

> **Correction:** the WebDAV adapter is a hand-rolled Laravel route handler (`app/Http/Controllers/WebDavController.php`). `sabre/dav` is **not** a dependency, despite "SabreDAV" appearing in the commit message and earlier status notes. Adopting SabreDAV proper is a separate, unplanned decision.

---

## Recorded Decisions (§14.5 / Issue #50)

- [x] **C1 — Realtime collaboration vs. async-first**: Jotter remains strictly async-first per §4 (no long-running daemons or WebSocket processes).
- [x] **C2 — Structured collections and views**: Implemented via rebuildable `note_properties` table projection from YAML front-matter without breaking Obsidian plain Markdown compatibility (§1).
- [x] **C3 — Synced / reusable blocks**: Only syntax that degrades to readable plain Markdown elsewhere is supported.
- [x] **C4 — Visual canvas / whiteboard**: Out of scope for server core; mobile/canvas usage is served by Obsidian synced via WebDAV.
- [x] **C5 — Offline-first and mobile**: WebDAV + Obsidian is the offline/mobile story (local-first inversion).
- [x] **C6 — Version history storage**: Selected Option 1 (DB-stored deduplicated snapshots in `note_revisions` table with `vault:prune-revisions` retention).

---

## Milestone A — knowledge foundations (near-term)

The only Phase 1 items the product does not already have.

- [x] **Version history** — roadmap priority 6; promoted from v2 to v1 (spec §6). Revision snapshots with restore, deduplication, and pruning command (#51).
- [x] **Search filters** — roadmap priority 1 asks for filters by title, tags, and modified date. Filtered search endpoints ship via `SearchCriteria` value object (#52).
- [x] **Markdown / JSON import** — hardened archive extraction (zip-slip/symlink/allowlist guards), bounded import job and endpoint, JSON backup format round-trip, and collision policy (#53, #76, #77, #78).

## Milestone B — connected knowledge

- [x] **Daily notes and templates** — conventional _templates/ folder, variable substitution, daily journal note flow (#56).
- [x] **Broken-link report** — workspace-wide report of unresolved `[[wikilinks]]` and orphan notes via `GET /api/workspaces/{w}/link-report` (#55).
- [x] **Typed note properties** — rebuildable typed projection from front-matter, workspace properties API, front-matter write-through (#54, #79, #80, #81).
- [x] **Richer block / slash-command surface** — declarative block registry, callouts, toggles, tables, dividers, slash insertion menu (#57, #86, #87, #88).
- [x] **MCP server** — machine-token auth over IdentityProvider seam, read-only vault tools & resources (#58, #89, #90, #91).

## Milestone C — team collaboration

- [x] **GrandpaSSOn identity adapter** — consume tenancy claims, RBAC, machine tokens over IdentityProvider seam (#59).
- [x] **Workspace administration UI** — CRUD workspace API, membership management, local user management (#60, #82, #83, #84).
- [x] **Comments and mentions** — inline note comments, `@username` mentions parsing (#61).
- [x] **Notification / event bus** — `WorkspaceEventEmitter` feeding append-only `audit_log` and user `notifications` (#62).

## Spec Debt & Foundation Epics

- [x] **Audit log hardening** — standardized event vocabulary, redaction, database append-only enforcement, retention & pruning (#64, #70, #71, #72).
- [x] **Note identity** — path rename/move endpoint, wikilink case-insensitive & ambiguity resolution policy (#65, #73, #74, #75).
- [x] **Visual identity design system** — adopted shared dark/purple design system across every surface with CI token guard (#96, #97-#110).

## Milestone D — structured work

- [x] **Metadata table view** — structured collections API filtering notes by property key, type, value range (#63).
- [x] **Board / calendar views** — property-based collections projection (#63).
- [x] **Relations and linked records** — wikilinks and typed front-matter relationships (#63).

## v2 — later

- [x] **Document parsing / Web crawler / RAG** — delegated to TaskConnect via `JobDispatcher` contract (#67).

---

## Visual identity (cross-cutting)

Not a roadmap item and not blocked on any §14.5 decision. Adopts a shared dark/purple design system — semantic tokens, Open Sans, WCAG 2.2 AA — across the SPA, the Laravel shell, and the published static site. Presentation layer only: no API contract, no change to the Markdown-on-disk invariant (spec §1), no change to §8 security requirements.

Tracked in **#96**. Today the product ships four unrelated visual treatments (SPA glassmorphism, a light-serif landing stylesheet, the stock Laravel welcome page, and an unstyled published site), three sub-AA color pairs, six `outline: none` sites with no replacement focus indicator, and no `prefers-reduced-motion` handling.

- [x] Foundation — #97 spec + asset structure, #98 token layer, #99 self-hosted Open Sans.
- [x] Application — #100 component migration, #101 typography, #102 spacing/shape/elevation, #103 controls and focus, #104 motion.
- [x] Other surfaces — #105 theme reconciliation, #106 published static site, #107 app-shell metadata, #108 project mark.
- [x] Verification — #109 WCAG 2.2 AA audit (acceptance gate), #110 CI token guard (lands last).

---

## Decision Record — Typed Property Model (Issue #79)

The property model projects YAML front-matter key-value pairs into typed, indexed MySQL storage (`note_properties`) while keeping Markdown files on disk as the single source of truth.

### 1. Schema & Data Storage Shape
- **Table**: `note_properties` (`id`, `note_id`, `name`, `type`, `value_string`, `value_numeric`, `value_boolean`, `value_datetime`, `value_json`, `created_at`, `updated_at`).
- **Indexes**: `(note_id, name)` unique key; composite search indexes on `(name, value_string)`, `(name, value_numeric)`, `(name, value_boolean)`, `(name, value_datetime)`.

### 2. Type Inference Matrix
| YAML Input Pattern | Inferred Type | Stored Column |
| :--- | :--- | :--- |
| `"active"`, `"high"` | `string` | `value_string` |
| `42`, `3.14159`, `-10` | `numeric` | `value_numeric` |
| `true`, `false` | `boolean` | `value_boolean` |
| `"2026-07-28"`, `"2026-07-28T10:00:00Z"` | `datetime` | `value_datetime` |
| `["apple", "banana"]`, `[1, 2, 3]` | `list` | `value_json` |
| Nested objects / dicts | `json` | `value_json` |

### 3. Mixed-Type Conflict Resolution Policy
If Note A assigns string `"2"` to property `priority` and Note B assigns integer `2` to `priority`:
- Both rows exist independently in `note_properties`. Note A populates `value_string`, Note B populates `value_numeric`.
- Query filters match within their targeted typed column without forcing lossy type coercions or throwing runtime errors.

### 4. Tag Relationship
- Note tags remain first-class in `tags` and `note_tags`.
- Front-matter `tags:` array continues to project into `tags` / `note_tags`. Property projection is strictly additive.

---

## Needs a decision (spec §14.5)

C1, C2, C3, C5, and C6 were resolved — see **Recorded Decisions** above. This section previously still listed them as open `TODO(spec)` blockers after they were resolved; that self-contradiction was found and fixed here (#141).

- **Roadmap baseline provenance.** `TODO(spec): confirm whether the roadmap's gap analysis was drawn from a different product of the same name. Until confirmed, spec §14.3 governs what counts as delivered.`

## Not adopted

- **Visual canvas / whiteboard** — spec §3 N3; the roadmap itself lists whiteboard parity as a non-goal for the next cycle.
- **Chat-and-files parity, full database-view breadth** — named as non-goals by the roadmap.
- **Realtime multi-user editing** — spec §3 N1, permanently out on shared hosting.
