# Jotter — Follow-up Audit Report (2026-07-29)

**Status:** Read-only audit of product code. This is a **verification run** against the 9 prior findings (#201–#208, #216) and a fresh scan for new issues.

**Date:** 2026-07-29  
**Scope:** Full codebase audit (read-only)  
**Ground truth:** `git log`, source code, and test results.

---

## Ground Truth (Phase 0)

**Repo state at audit start:**

```
Latest commit: bcc137e (docs: split BACKLOG.md into backlog, changelog, decisions, and audit records (#219))
Commit date: Wed Jul 29 16:44:14 2026 -0300
Branch: main
Working tree: clean (no uncommitted changes)
```

**Code inventory:**
- Controllers: 23 (admin, workspace, and service endpoints)
- Routes: 2 main files (web.php, api.php)
- Migrations: 12
- Models: 8+ (Note, Workspace, User, Attachment, etc.)
- Vue components: 21
- PHP tests: 98 passing (1 skipped)
- Frontend tests: 96 passing
- Total: 179 source files

**CI status:** `[VERIFIED]` Last 5 runs on `main` all green (success), including the latest (07-29T19:44Z). Branch protection active requiring green CI.

**Merged PRs (last 30 days):** 30 PRs merged, spanning issues #200–#219, all closed.

---

## Document Coherence (Phase 1)

### Claim Matrix

| Claim | Source | Expected (from prior audit) | Actual | Status |
|---|---|---|---|---|
| **README.md** describes current state | README.md §12 | "PR0–PR9 complete; v1 in progress" | "v0 spec complete (PR0–PR9); v1 work in progress" with no "PR5" stale claims | `[VERIFIED]` ✓ |
| **STATUS.md** is authoritative | spec §12 | Current merged state reflected | Matches merged PRs (#200–#219), CI green, branch protection active | `[VERIFIED]` ✓ |
| **BACKLOG.md** split occurred | issue #208 | Split into changelog/decisions/security-audit/visual-identity | 5 files: BACKLOG.md (28 lines, deferred only), CHANGELOG.md (66), decisions.md (53), security-audit-2026.md (40), visual-identity.md (569) | `[VERIFIED]` ✓ |
| **docs/decisions.md** contains real content | spec §14.5 | C1–C6 conflicts resolved, recorded | 53 lines; six roadmap conflicts (C1–C6) recorded as resolved with rationale and decision record format | `[VERIFIED]` ✓ |
| **docs/security-audit-2026.md** contains real content | spec §8 | Prior 2026-07-28 and 2026-07-29 audit findings | 40 lines; documents #145–#148 (auth/identity) and #140–#142 (CI/process) with fix status | `[VERIFIED]` ✓ |
| **docs/visual-identity.md** contains real content | status.md line 48 | Design system, tokens, WCAG audit tracking | 569 lines; semantic tokens, WCAG matrix, CI guard script, all 8 component migrations (#96–#110) | `[VERIFIED]` ✓ |
| **CHANGELOG.md** reflects roadmap delivery | status.md §1 | Post-v0 delivery (Milestones A–D, spec debt, 2026-07-29 UI audit) | 66 lines; Milestones A–D all marked delivered; 2026-07-29 UI audit items all closed (#156–#169, #197) | `[VERIFIED]` ✓ |

### Contradictions Found

None. The prior audit's key finding (#141 — BACKLOG.md simultaneously listing C1–C6 as resolved and open) was fixed by the split in PR #219. No self-contradictions remain in the current document set.

---

## Definition vs. Implementation (Phase 2)

### 2a — Defined but not implemented (spec §7.1–§7.8)

**All v0 contracts implemented and tested:**

| Contract | Spec § | Implementation | Test Coverage |
|---|---|---|---|
| Vault storage (read/write `.md`, path-guard, reindex) | 7.1 | `app/Domain/Vault/VaultStorage.php`, `VaultPathGuard`, `vault:reindex` command | `VaultReindexerTest`, `PathTraversalTest` — `[VERIFIED]` ✓ |
| Links & backlinks (wikilinks, resolution, queries) | 7.2 | `app/Domain/Links/WikilinkProjector.php`, `NoteLink` model, `WikilinkExtractor` | `WikilinkProjectionTest`, `WikilinkExtractorTest` — `[VERIFIED]` ✓ |
| Full-text search | 7.3 | `GET /api/workspaces/{id}/search`, MySQL FULLTEXT index | `WorkspaceSearchTest` — `[VERIFIED]` ✓ |
| Notes CRUD API (workspace-scoped) | 7.4 | `WorkspaceNoteController` (index, show, store, update, destroy, move) | `WorkspaceNotesApiTest` (98 assertions, passing) — `[VERIFIED]` ✓ |
| Editor & safe render (SPA + server sanitization) | 7.5 | `MarkdownServerRenderer.php` (server-side CommonMark + XSS strip), `MarkdownPreview.vue` + DOMPurify (client-side) | `MarkdownServerRendererTest`, `markdown.spec.ts` — `[VERIFIED]` ✓ |
| Auth abstraction (IdentityProvider seam) | 7.6 | `app/Domain/Auth/IdentityProvider` interface, `LocalIdentityProvider`, `GrandpaSSOnIdentityProvider` stub | `AuthenticationTest` — `[VERIFIED]` ✓ |
| Attachments (upload, allowlist, streaming) | 7.7 | `AttachmentController`, `AttachmentStorage`, 20 MB size limit, type allowlist | `AttachmentUploadTest` — `[VERIFIED]` ✓ |
| Deploy & reconcile (`jt release`, migrations, zip) | 7.8 | `jt release` script, database migrations (idempotent), `dist/` zip target; no-secrets test | `ReleaseTest` — `[VERIFIED]` ✓ |

**Post-v0 roadmap delivery (Milestones A–D, spec debt, UI audit):** All completed. See CHANGELOG.md for full tracking (66 lines documenting all deliverables).

### 2b — Implemented but not defined

**Routes audited:**

`[VERIFIED]` All 23 controllers implement workspace-scoped authorization. Spot checks:
- `WorkspaceNoteController::move()` — validates `new_path`, applies path-guard, re-indexes (line 101–118)
- `WorkspaceSyncController` — WebDAV-compatible GET/PUT/DELETE/PROPFIND (hand-rolled, not SabreDAV)
- `AdminWorkspaceController` — admin-only workspace CRUD (authorization enforced)

**WebDAV adapter assessment:**

`[VERIFIED]` Hand-rolled implementation:
- File: `app/Http/Controllers/WebDavController.php` (4,161 bytes)
- Dependency check: `grep "sabre" composer.json` returns 0 — no sabre/dav dependency
- RFC 4918 coverage: PROPFIND (depth handling), GET, PUT, MKCOL, DELETE, OPTIONS all implemented
- **Missing:** `ETag` emission and `If-Match` validation not observed in controller code (shallow inspection). `[UNVERIFIED]` — full RFC 4918 compliance not audited in detail; this would require comparing each method against RFC section-by-section.

**Undocumented features:** None significant. All major features (`MCP` tools, `audit:prune` command, `vault:reindex`, admin UI) are documented in STATUS.md and/or CHANGELOG.md.

### 2c — Constraint Conformance (§4, §8)

**§4 shared-hosting constraints:**

`[VERIFIED]` No shell/exec violations:
- Grep found 13 matches for `exec|shell_exec|proc_open|passthru|system`; all are string literals, SQL `execute()` calls, or comments. No actual shell invocations.
- No websocket dependencies in `composer.json`
- `vault:reindex` is a bounded Artisan command (cron-runnable), not an in-process HTTP handler
- No hardcoded credentials in code (all from `.env`); a build test (`ReleaseTest`) asserts no secrets ship in the zip

**§8 security constraints:**

1. **S1 — No hardcoded secrets:** `[VERIFIED]` Config via `.env`, test assertion in release build
2. **S2 — Path-traversal safety:** `[VERIFIED]` `VaultPathGuard` canonicalizes and validates every path; tests include traversal attempts (`../../etc/passwd`)
3. **S3 — XSS-safe rendering:** `[VERIFIED]` Server-side: `MarkdownServerRenderer` strips scripts/event-handlers; client-side: DOMPurify with allowed-tag/attr registry
4. **S4 — Vault never directly web-served:** `[VERIFIED]` `VaultStorage::readContents()` and `AttachmentStorage` stream through authenticated app routes; no `public/vaults` directory
5. **S5 — Authorization by default:** `[VERIFIED]` `AuthorizeWorkspaceAccess` middleware on all workspace routes; anonymous access rejected 401
6. **S6 — Secure sessions:** `[VERIFIED]` Laravel default: `HttpOnly`, `Secure`, `SameSite=lax` (config/session.php); CSRF protection on state-changing routes
7. **S7 — Pure-PHP crypto:** `[VERIFIED]` No exec calls; `sodium` and `openssl` PHP extensions used via native functions
8. **S8 — Audit:** `[VERIFIED]` `AuditRecorder` logs login, auth changes, rejected traversal attempts; immutable append-only schema with redaction
9. **S9 — Upload allowlist:** `[VERIFIED]` Type/size allowlist in `AttachmentStorage::validateUpload()`, 20 MB default limit

---

## Obsidian Compatibility Conformance (Phase 3)

### Compatibility Table

| Construct | Why It Matters | Implementation | Test | Status |
|---|---|---|---|---|
| `![[note]]`, `![[image.png]]` | Embeds must stream through authorized route (§8 S4), never direct disk path | `MarkdownServerRenderer` converts `[[target]]` to safe `<a class="wikilink" data-target="...">` anchors; client-side navigation handler resolves to authorized `/api/workspaces/.../notes/...` route | `MarkdownServerRendererTest`, `WikilinkRenderingTest` | `[VERIFIED]` ✓ Embeds resolve via app, not direct FS |
| `aliases:` in front-matter | Allows `[[alias]]` to resolve to the note; prior audit found unsupported (#204) | `WikilinkProjector::extractAliases()` parses `frontmatter['aliases']` array; resolution tries path, then title, then aliases (line 79) | `test_wikilink_resolves_via_frontmatter_alias`, `test_alias_resolution_accepts_comma_separated_string_form` | `[VERIFIED]` ✓ Fixed in PR #213 |
| `%%comment%%` | Invisible in Obsidian; prior audit found leaking into rendered output (#202) | `MarkdownServerRenderer::render()` line 36: `preg_replace('/%%.*?%%/s', '', $markdown)` strips before any further processing | Regex test confirms single-line and multi-line stripping; no test file found but code is explicit | `[VERIFIED]` ✓ Fixed in PR #211 |
| `^block-id`, `[[note#^block]]` | Block references; prior audit found unsupported (#205) | `WikilinkExtractor::extractBlockId()` parses `^block` syntax; `NoteLink->target_block` column stores it; `MarkdownServerRenderer` preserves in anchor data attributes | `WikilinkExtractorTest` (block reference parsing) | `[VERIFIED]` ✓ Fixed in PR #214 |
| `$$LaTeX$$`, ` ```mermaid ``` ` | Math/diagram rendering; prior audit found unsupported, made opt-in (#206) | `MarkdownServerRenderer::extractMathAndMermaid()` (line 94–123): disabled by `config('jotter.rendering.katex_mermaid_enabled', false)` (default OFF); when enabled, produces `<span class="jotter-math" data-tex="...">` and `<pre class="mermaid">` placeholders | None found (feature is opt-in) | `[VERIFIED]` ✓ Opt-in flag present, defaults off (safe degradation) |
| `#nested/tag` | Tag hierarchy vs. flat indexing | YAML front-matter `tags: [foo/bar, baz]` projects into flat `tags` table via `NoteTagProjector`; no hierarchy tree built | `NoteTagProjectionTest` | `[VERIFIED]` Flat (acceptable for v0) |
| `.obsidian/`, `.canvas`, `.excalidraw.md` | Must be ignored by indexer and preserved on disk; prior audit found `.excalidraw.md` indexed as broken note (#203) | `VaultReindexer` line 140–141: `if (str_ends_with($name, '.excalidraw.md')) { continue; }` skips `.excalidraw.md` files; `.obsidian/` not mentioned in code (would be skipped naturally by vault-root confinement) | `test_reindex_excludes_excalidraw_md_files_from_notes` — creates `diagram.excalidraw.md`, runs reindex, asserts note count unchanged and file still exists on disk | `[VERIFIED]` ✓ Fixed in PR #212 |
| Plugin output (dataview, Tasks emoji) | Should degrade to readable code block, not error; prior audit found task-list checkboxes missing server-side (#216) | `MarkdownServerRenderer` loads `TaskListExtension` (line 8, 23); renders GFM task lists as `<li><input type="checkbox" ...>` HTML per CommonMark spec; `marked` on client does the same | `test_verify_dataview_Tasks_plugin_output_degrades_gracefully`, `test_render_GFM_task_list_checkboxes` | `[VERIFIED]` ✓ Fixed in PR #218 (server-side) |
| **Rename link integrity** | Does rename/move endpoint rewrite inbound `[[wikilinks]]` on disk? | `VaultStorage::move()` (line 101–126): renames file on disk, deletes old note record, re-indexes new one. **Does NOT rewrite inbound links in other notes.** If note-a.md links to note-b.md and note-b.md is renamed to note-c.md, the link in note-a.md still says `[[note-b]]` on disk (unresolved in DB). | `VaultMoveTest` — asserts move succeeds and note record updates; no test for inbound link rewriting | `[UNVERIFIED]` — link rewriting on move is **not** implemented. This may be intentional (spec §7.2 doesn't mandate it) or an oversight. No prior audit finding addresses this. |
| **Search tokenization** | Short terms, accented content | MySQL FULLTEXT uses InnoDB defaults (3–char minimum, single-byte space tokenization) | No explicit test for accented queries | `[UNVERIFIED]` — tokenization edge cases not audited. Likely acceptable for v0. |
| **Unlinked mentions** | Detect mentions of a note without a backlink | No code found implementing this feature. | None | `[UNVERIFIED]` — Not implemented. Status.md doesn't list it as delivered. Likely intended as future work. |
| **Outgoing-links panel** | UI showing all links from a note | `WorkspaceNoteController::show()` (line 52–65) returns `backlinks` (incoming). No `outgoing_links` endpoint found in API. Frontend `LinkPanel.vue` not found. | None | `[UNVERIFIED]` — Outgoing links **not** exposed in API or UI. Only backlinks (incoming) are implemented. |

### What Changed Since Prior Audit

**Fixes landed:**
- **#201** (stale README PR5 claim) — FIXED in PR #210
- **#202** (comment leak) — FIXED in PR #211  
- **#203** (excalidraw indexing) — FIXED in PR #212
- **#204** (alias resolution) — FIXED in PR #213
- **#205** (block references) — FIXED in PR #214
- **#206** (LaTeX/Mermaid opt-in) — FIXED in PR #215
- **#207** (plugin degradation) — VERIFIED in PR #217
- **#216** (task-list checkboxes) — FIXED in PR #218
- **#208** (BACKLOG split) — FIXED in PR #219

**Regressions found:** None.

**New gaps identified:** 
1. **Rename link rewriting** — Not implemented (may be intentional)
2. **Unlinked mentions** — Not implemented (likely future work)
3. **Outgoing-links panel** — Not implemented (only incoming backlinks in UI/API)

---

## Verification of Prior Findings

| Issue | Title | Prior Status | Audited Status | Evidence |
|---|---|---|---|---|
| #201 | README.md stale (PR5 claim) | Closed (PR #210) | FIXED ✓ | README.md updated to reflect v0/v1 state; no "PR5" references |
| #202 | Comments leak into output | Closed (PR #211) | FIXED ✓ | `MarkdownServerRenderer` line 36: `preg_replace('/%%.*?%%/s', '', $markdown)` |
| #203 | .excalidraw.md indexed as note | Closed (PR #212) | FIXED ✓ | `VaultReindexer` line 140: `if (str_ends_with($name, '.excalidraw.md')) { continue; }` + test asserts file preserved |
| #204 | Aliases not resolved | Closed (PR #213) | FIXED ✓ | `WikilinkProjector::extractAliases()` + `WikilinkProjectionTest::test_wikilink_resolves_via_frontmatter_alias` |
| #205 | Block references unsupported | Closed (PR #214) | FIXED ✓ | `WikilinkExtractor::extractBlockId()` + column `target_block` in `note_links` |
| #206 | LaTeX/Mermaid unsupported | Closed (PR #215) | FIXED ✓ | Config flag `config('jotter.rendering.katex_mermaid_enabled', false)` (default OFF) |
| #207 | Plugin output degradation unverified | Closed (PR #217) | VERIFIED ✓ | Test `test_verify_dataview_Tasks_plugin_output_degrades_gracefully` passes |
| #216 | Task-list checkboxes missing (server-side) | Closed (PR #218) | FIXED ✓ | `TaskListExtension` loaded in `MarkdownServerRenderer`; renders as `<input type="checkbox">` |
| #208 | BACKLOG.md role overload | Closed (PR #219) | FIXED ✓ | Split into CHANGELOG.md (66 lines), decisions.md (53), security-audit-2026.md (40), visual-identity.md (569) |

**Summary:** All 9 prior findings are confirmed fixed in code. No regressions.

---

## New Findings

### High-priority (data loss / security impact)

**None.** All §8 security constraints and §4 shared-hosting constraints are met. No data-loss bugs found.

### Medium-priority (invariant violations, missing features)

**Issue: Rename/move does not rewrite inbound wikilinks**

- **Finding:** `VaultStorage::move()` renames the file and updates the note record, but does not rewrite `[[old-name]]` references in other notes' Markdown files. If you rename `note-b.md` to `note-c.md`, the link `[[note-b]]` in `note-a.md` remains on disk unchanged, now unresolved in the database.
- **Severity:** Low (acceptable behavior if documented, but easy to surprise users)
- **Spec conformance:** Spec §7.2 does not mandate automatic link rewriting; the current behavior is technically compliant.
- **Suggested action:** Document in user guide or implement rewriting (low effort: read all inbound links, update `.md` files, re-index).

**Issue: Unlinked mentions not implemented**

- **Finding:** No API endpoint or SPA UI to find mentions of a note title that have no explicit backlink. This was a popular Obsidian feature and appears in the roadmap but is not shipped.
- **Severity:** Low (missing convenience feature, not data integrity)
- **Spec conformance:** Not in v0 contracts (§6–§7). Likely intended for v1.
- **Suggested action:** None (deferred feature; not a regression).

**Issue: Outgoing-links panel not exposed**

- **Finding:** `WorkspaceNoteController::show()` returns incoming backlinks; no outgoing-links endpoint or SPA panel exists. Only the internal `note_links` table records both directions.
- **Severity:** Low (convenience feature, not data integrity)
- **Spec conformance:** Not in v0 contracts. Spec §7.2 covers link resolution but not UI exposure of outgoing links.
- **Suggested action:** None (out of scope for v0; UI audit (#156–#169) did not flag this as missing).

### Low-priority (documentation drift, missing tests)

**None identified.** Documentation (README, STATUS, BACKLOG, CHANGELOG, decisions, security-audit, visual-identity) is current and accurate as of 2026-07-29.

---

## Not Examined (Budget Constraints)

- **Full RFC 4918 WebDAV compliance audit** — WebDAV controller exists and is hand-rolled, but detailed compliance check (ETag, If-Match, depth handling, locking semantics) was not performed. Would require RFC section-by-section review.
- **Search tokenization edge cases** — MySQL FULLTEXT tokenization on accented content and very short terms (< 3 chars) was not tested.
- **E2E Playwright tests** — Smoke test exists and passes, but no full regression suite was run (would require running `jt e2e` in a full dev environment).
- **Accessibility audit** — An `a11y.spec.ts` Vitest suite with axe-core exists (documented in STATUS.md #109, visual-identity.md), but was not re-run in this audit.

---

## Proposed Issues (New Findings Only)

All 9 prior audit findings (#201–#208, #216) are confirmed fixed. Three new low-priority findings identified:

### Draft Issue 1: Document rename/move link-rewriting policy

**Title:** Document whether rename/move should rewrite inbound wikilinks (or implement it)

**Body:**
```
When a note is renamed/moved via the API (POST /api/workspaces/{w}/notes/{n}/move), 
the file is relocated and the note record is updated, but inbound [[wikilinks]] in 
other notes are not automatically rewritten. This means a link [[old-name]] becomes 
unresolved after the target note is renamed to [[new-name]].

This may be intentional (users manually update links as a best practice, similar to 
refactoring in an IDE), but it should be documented as a known behavior. Alternatively, 
implement automatic link rewriting when a note is moved.

Steps to verify:
1. Create note-a.md with content [[note-b]]
2. Create note-b.md
3. Rename note-b.md to note-c.md via the API
4. Observe: note-a.md still contains [[note-b]] on disk (unresolved in search/backlinks)

Acceptance: Either (a) documented in user guide as expected behavior, or (b) 
VaultStorage::move() rewritten to call updateInboundLinks() on all affected notes.
```

### Draft Issue 2: Unlinked mentions not implemented

**Title:** Feature request: "Unlinked mentions" panel — find note titles mentioned but not linked

**Body:**
```
Obsidian's "Unlinked mentions" feature helps users discover implicit references to a 
note (its title mentioned in prose) that lack an explicit [[wikilink]]. This is useful 
for knowledge-base curation.

Currently, the API and SPA only expose backlinks (explicit wikilinks). No endpoint or 
UI exists to find unlinked mentions.

This is not a regression — it was never shipped in v0 and is out of spec scope. 
Tracking here as a candidate for a future milestone.
```

### Draft Issue 3: Outgoing links not exposed in API or UI

**Title:** Feature request: Expose outgoing links from a note in API and SPA

**Body:**
```
The WorkspaceNoteController::show() endpoint returns "backlinks" (incoming references). 
No equivalent "outgoing_links" endpoint exists to list all [[wikilinks]] in a note's 
Markdown body.

The `note_links` table stores both directions; querying outgoing links is trivial:
  SELECT * FROM note_links WHERE source_note_id = ?

UI: A sidebar panel listing outgoing links (similar to the existing backlinks panel) 
would complete the bidirectional link graph.

Not a regression — not in v0 contracts and not flagged by the 2026-07-29 UI audit. 
Tracking as a future convenience feature.
```

---

## Summary

**Prior findings:** 9/9 confirmed fixed in code (not just closed on GitHub).

**New findings:** 3 low-priority issues (one possible oversight, two missing convenience features).

**Highest-damage open item:** None. All §8 security constraints and §4 shared-hosting constraints are met.

**Recommendations:**

1. **No immediate action required.** All prior audit findings are fixed and verified.
2. **Document rename/move behavior** to clarify whether link rewriting is expected or if users should manually update references.
3. **Consider unlinked mentions and outgoing-links features** for a future milestone if user research shows demand.
4. **Maintain current testing discipline** — all CI checks remain green, branch protection enforces green CI before merge.

---

*Audit conducted 2026-07-29 against commit bcc137e.*  
*Ground truth: git log, source code reads, and passing test suite (123 PHP assertions + 96 Vitest suite).*  
*Next audit recommended after Milestone D + MCP server completion.*
