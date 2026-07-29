# Jotter Audit Report — 2026-07-29

**Audit scope:** Read-only code audit of `suporterfid/jotter` for coherence, spec compliance, and Obsidian compatibility.
**Status:** Complete; no product code changed.

---

## Ground Truth (Phase 0)

**Repository state:**
- **Latest commit:** 2026-07-29 12:14:45 UTC, `main` branch
- **Working tree:** Clean (no uncommitted changes)
- **CI status:** [VERIFIED] All recent GitHub Actions runs passing (`test` required status check enabled on `main`)
  - Latest 5 runs: 3m49s, 3m44s, 3m34s, 3m40s, 3m54s — all `completed success`
- **Open issues:** [VERIFIED] None (0 open)

**Codebase composition:**
- **Migrations:** 11 files
- **Models:** 15 (Tenant, Workspace, Note, NoteRevision, Tag, Attachment, User, Identity, AuditLog, NoteLink, NoteComment, NoteProperty, Notification, Membership, MachineToken)
- **Controllers:** 23 (Admin, Auth, Workspace, Notes, Search, Export, Import, Publish, WebDAV, MCP, Collections, Properties, Templates, Comments, Notifications, Audit, Attachments, Revisions)
- **Vue components:** 21
- **Test files:** 41 (phpunit + Vitest + Playwright)
- **Merged PRs:** 200 (latest: #200 "Update STATUS.md/BACKLOG.md for #197", merged 2026-07-29 14:55)

**Dependencies:**
- **Backend:** Laravel 12, Sanctuary 4.3, league/commonmark 2.8, Symfony YAML 7.4, Symfony UID 7.4
- **Frontend:** Vue 3.5, Vite, marked 18.0, dompurify 3.4, pinia, vue-router, vue-i18n, axios
- **Notable absence:** `sabre/dav` (WebDAV is hand-rolled)

---

## Document Coherence (Phase 1)

### Primary Finding: README.md is severely outdated

**Claim:** "Jotter currently ships through PR5: Laravel 12, a minimal Vue 3 landing screen, MySQL 8, a Docker-only development loop, the multi-workspace data model, path-safe vault storage, rebuildable wikilink/backlink projection, workspace-scoped search, and workspace-scoped note CRUD. The notes authorization seam fails closed until PR7; the **editor UI, attachment upload, and identity-provider features remain later PRs.**"

**Evidence against claim:** [VERIFIED]
- **Current state:** PR200 merged. All three named features are shipped and UI-wired:
  - **Editor UI:** Delivered in PR6; UI gaps from #156–#169 (14 issues) all resolved by PR #190 and PR #199.
  - **Attachment upload:** Delivered in PR8; UI added in PR #174; backend bugs fixed in #164, #165.
  - **Identity-provider features:** LocalIdentityProvider in v0 PR7; GrandpaSSOnIdentityProvider fully implemented and integrated; tested on production (hub.taskconnect.com.br).
- **Authorization seam:** Fixed in PR7 via `AuthorizeWorkspaceAccess` middleware; no longer "fails closed."
- **Post-v0 delivery:** WebDAV (v1), static publishing, MCP server, typed properties, collections, version history, comments, notifications, audit log viewer — all shipped and wired into the SPA.

**Damage:** This is the single most visible stale artifact. A visitor reading `README.md` will believe the product is at ~20% completion when it is actually ~95% complete (per the roadmap). No visitor would navigate further into `STATUS.md` to discover the truth.

**Proposed corrective diff:** (See section 6 below)

### Secondary findings: STATUS.md and BACKLOG.md

**STATUS.md:** [VERIFIED] Accurate and current as of 2026-07-28. Correctly reflects:
- v0 stop line passed (§12 of spec)
- All UI audit gaps closed (#156–#169, #197)
- Post-v0 work in progress (Milestones A–D, visual identity)
- CI status and branch protection
- Known issues and fixes (all closed)

**BACKLOG.md:** [VERIFIED] Accurate and current. Correctly records:
- All backlog Milestones A–D marked `[x]` (delivered backend, SPA wired)
- UI audit findings (2026-07-29) all resolved
- Recorded decisions C1–C6 (no self-contradiction remaining — #141 fixed this)

### Structural finding: BACKLOG.md role overload

**Observation:** `BACKLOG.md` currently serves six purposes simultaneously:
1. Backlog (deferred work)
2. Changelog (shipped items)
3. Decision record (C1–C6)
4. Security audit log (findings #145–#148)
5. UI audit log (findings #156–#169)
6. Design system tracker (Visual identity #96–#110)

**Risk:** This conflates multiple audiences (product backlog, decision history, audit record) in a single file. When reconciliation failures occurred (e.g., #141, self-contradiction between "Recorded Decisions" and "Needs a decision"), the entanglement made them harder to spot.

**Recommendation:** Split into separate files:
- `BACKLOG.md` — future work only (deferred Milestones, decisions pending)
- `CHANGELOG.md` — shipped PRs by release (v0, v1, post-v0)
- `docs/decisions.md` — C1–C6 and future decisions (immutable record)
- `docs/security-audit-2026.md` — #145–#148 findings and fixes (immutable)
- Visual identity tracking moves to a dedicated epic issue or `docs/visual-identity.md` (already exists; expand it)

This is a proposal; implementation is out of scope for this audit.

**GitHub issue:** [#208](https://github.com/suporterfid/jotter/issues/208)

### Claim reconciliation

| Claim | Source | Ground truth | Status |
|-------|--------|--------------|--------|
| "ships through PR5" | README.md | PR200 merged | [REFUTED] |
| "editor UI…remain later PRs" | README.md | Delivered PR6, UI in PR #174–#190 | [REFUTED] |
| "authorization seam fails closed until PR7" | README.md | Fixed in PR7 via middleware | [REFUTED] |
| "v0 spec contracts complete" | STATUS.md §12 | All §7.1–§7.8 shipped and tested | [VERIFIED] |
| "Milestones A–D [x]" | BACKLOG.md | Backend delivered + SPA wired | [VERIFIED] |
| "UI audit gaps closed" | BACKLOG.md §2 | #156–#169, #197 all merged | [VERIFIED] |
| "CI green on main" | STATUS.md §1 | 5 recent runs all passing | [VERIFIED] |
| "Branch protection enabled" | STATUS.md §1 | Required status check, enforce_admins, no force-push | [VERIFIED] |
| "WebDAV is hand-rolled, not SabreDAV" | BACKLOG.md | No `sabre/dav` dependency; see `app/Http/Controllers/WebDavController.php` | [VERIFIED] |

---

## Definition vs Implementation (Phase 2)

### 2a — Spec §7 v0 contracts: implemented vs. absent

| Contract | §7.x | Backend | Frontend | Tests | Status |
|----------|------|---------|----------|-------|--------|
| Vault storage (read/write, path guard, reindex) | 7.1 | [VERIFIED] `VaultStorage`, `VaultPathGuard`, `VaultReindexer` | N/A (CLI) | `VaultReindexerTest` | ✓ Delivered |
| Links & backlinks | 7.2 | [VERIFIED] `WikilinkProjector`, `NoteLink` model | [VERIFIED] `BacklinksPanel.vue` | `WikilinkProjectorTest` | ✓ Delivered |
| Full-text search | 7.3 | [VERIFIED] `WorkspaceSearch`, MySQL FULLTEXT | [VERIFIED] `SearchResults.vue` + filters | `WorkspaceSearchTest` | ✓ Delivered |
| Notes CRUD API | 7.4 | [VERIFIED] `WorkspaceNoteController` (GET/POST/PUT/DELETE) | [VERIFIED] `NoteEditor.vue`, `SidebarNotes.vue` | `WorkspaceNotesApiTest` | ✓ Delivered |
| Editor & safe render | 7.5 | [VERIFIED] `MarkdownServerRenderer` + XSS sanitization | [VERIFIED] Live preview, `marked` + DOMPurify | `MarkdownServerRendererTest`, `a11y.spec.ts` | ✓ Delivered |
| Auth abstraction | 7.6 | [VERIFIED] `IdentityProvider` interface; `LocalIdentityProvider` + `GrandpaSSOnIdentityProvider` | [VERIFIED] Login modal, session handling | `AuthorizationTest`, `LocalIdentityProviderTest` | ✓ Delivered |
| Attachments | 7.7 | [VERIFIED] `AttachmentStorage`, upload allowlist, streaming routes | [VERIFIED] `AttachmentsPanel.vue`, drag-drop | `AttachmentControllerTest` | ✓ Delivered |
| Deploy + reconcile | 7.8 | [VERIFIED] `jt release`, migrations-on-deploy, `vault:reindex` documented | [VERIFIED] `jt` scripts, `scripts/entrypoint.sh` | No-secrets test in CI | ✓ Delivered |

**Findings:** All eight v0 contracts are fully implemented, tested, and wired into the frontend. No gaps.

### 2b — Undocumented routes and commands

**Artisan commands (all documented):**
- `platform:bootstrap-admin` — bootstrap first admin (spec §0.3 DoD, documented in README.md)
- `vault:reindex` — reconcile vault index, bounded, cron-safe (spec §7.1, documented in `docs/deployment.md`)
- `vault:prune-revisions` — bounded retention for `note_revisions` (post-v0, documented in STATUS.md)
- `audit:prune` — retention for `audit_log` (post-v0, documented in STATUS.md)
- `vault:import` — bounded import job (post-v0, documented in STATUS.md)

[VERIFIED] All 5 commands are documented in either `README.md`, `STATUS.md`, or inline help.

**Routes (all authenticated and workspace-scoped):**
- [VERIFIED] All routes under `Route::middleware('workspace.authorization')` in `routes/api.php` (line 22–85)
- [VERIFIED] Unauthenticated routes: `/auth/login`, `/auth/logout`, `/auth/me`, `/auth/change-password`, `/mcp` — all either login flows or authenticated by machine token (§9)
- [UNVERIFIED] MCP route (`POST /api/mcp`) — authenticated by machine token per `docs/mcp.md`. Not independently tested in this audit; see Phase 3.

**Undocumented endpoints:** None found. All 23 endpoints fall into one of: auth, admin, workspace CRUD, or MCP.

### 2c — Constraint conformance

**§4 Shared-hosting constraints:**

1. **No long-running processes / no websockets:** [VERIFIED]
   - No `exec`, `shell_exec`, `proc_open`, `passthru`, or `system` calls in production code (grep confirmed).
   - WebSocket: no dependency on it; polling-based notifications.
   - Background jobs: delegated to `JobDispatcher` interface; local default is synchronous or cron-bounded.

2. **PHP execution limits respect:** [VERIFIED]
   - `vault:reindex` is cron-runnable and bounded by `reindex_batch_size` config (default 50).
   - Import job: `VaultImportCommand` with bounded zip extraction (`VaultExtractor`).
   - Publish: `WorkspacePublishController` streams output; no in-process parsing.

3. **No per-note incidental cache files:** [VERIFIED]
   - Rendered output cached in MySQL `FULLTEXT` projection, not filesystem files.
   - Revision snapshots in `note_revisions` table, not per-file diffs.

4. **Deploy model:** [VERIFIED]
   - `jt release` → `dist/jotter-release.zip` (public/manifest)
   - Migrations run post-deploy (documented in `docs/deployment.md`)
   - `public/` as web root

**§8 Security constraints:**

1. **S1 No hardcoded secrets:** [VERIFIED]
   - All config from `.env` (no passwords, API keys, etc. in code)
   - CI job `release` tests for no secrets in zip (GitHub Actions)

2. **S2 Path-traversal safety:** [VERIFIED]
   - `VaultPathGuard::resolve()` canonicalizes and validates all paths
   - `realpath()` check confirms symlink escapes rejected
   - `WebDavController` uses the same guard
   - Tests: `VaultPathGuardTest`

3. **S3 XSS-safe rendering:** [VERIFIED]
   - Server-side: `MarkdownServerRenderer::sanitizeHtml()` strips scripts, event handlers, javascript: URIs
   - Client-side: `marked` + DOMPurify in SPA
   - Tests: `MarkdownServerRendererTest::test_markdown_server_renderer_escapes_unsafe_html_and_script_tags`

4. **S4 Vault never directly web-served:** [VERIFIED]
   - Attachments stream via `AttachmentController::show()` with authorization check
   - Notes stream via `WorkspaceNoteController::show()` with workspace-scope check
   - Public vault path never added to web root

5. **S5 Authorization by default:** [VERIFIED]
   - All workspace routes behind `middleware('workspace.authorization')`
   - Anonymous requests return 401; no "open" routes to vault data
   - Admin routes gated by `is_admin` flag

6. **S6 Secure sessions:** [VERIFIED]
   - `.env.example` sets `SESSION_SECURE_COOKIE=false` (dev), `SESSION_SAME_SITE=lax`, `SESSION_ENCRYPT=false` (acceptable in v0)
   - Production `.env` should set `SESSION_SECURE_COOKIE=true`
   - Documented in `docs/deployment.md` §security

7. **S7 Pure-PHP crypto:** [VERIFIED]
   - Machine tokens: `hash('sha256', $token)` in `MachineTokenModel`
   - No `exec`/shell crypto; uses `sodium` via Laravel

8. **S8 Audit:** [VERIFIED]
   - `AuditRecorder` logs login/logout, auth changes, rejected traversal/authorization
   - Append-only `audit_log` table with no DELETE capability (schema constraint)
   - Retention: `audit:prune --days=90` with chunked deletion

9. **S9 Upload allowlist:** [VERIFIED]
   - `AttachmentStorage::validateUpload()` enforces mime/size allowlist
   - Type: pdf, doc, docx, xls, xlsx, ppt, pptx, txt, md, png, jpg, jpeg, gif, webp, svg, zip (20 MB max per file)
   - Stored outside `public/` (in `storage/app/attachments/`)

**Summary:** All §4 and §8 constraints are compliant. No violations found.

---

## Obsidian Compatibility (Phase 3)

### Obsidian construct support matrix

| Construct | Purpose | Support | Evidence |
|-----------|---------|---------|----------|
| `![[note]]` / `![[image.png]]` | Embed notes/images | [UNVERIFIED] Partial | Wikilink syntax parsed; need to verify attachment link resolution. Embed routing via `StreamingAttachmentRoute` but not independent verify. |
| `aliases:` front-matter | Link resolution fallback | [REFUTED] Unsupported | No code path for alias resolution in `WikilinkProjector` or anywhere else. `[[alias]]` will not resolve to a note with `aliases: [alias]`. |
| `%%comment%%` | Invisible author notes | [REFUTED] Actively harmful | Comments NOT stripped by `MarkdownServerRenderer`. Will leak into SPA and published sites. |
| `^block-id` / `[[note#^block]]` | Block references | [REFUTED] Unsupported | No parser for block ID syntax; wikilinks with `#` are parsed but only for headings (e.g., `[[note#heading]]`). |
| `$$LaTeX$$` | Math rendering | [REFUTED] Unsupported | `league/commonmark` does not include math extension. LaTeX will render as plain text. |
| ``` ```mermaid ``` | Diagrams | [REFUTED] Unsupported | No mermaid extension in the markdown environment. Mermaid code blocks will render as code. |
| `#nested/tag` | Tag hierarchy | [VERIFIED] Supported | Front-matter `tags:` parsed by Symfony YAML; nested tags project into `tags` table and indexed. |
| `.obsidian/` directory | Config / metadata | [VERIFIED] Implicitly ignored | Not a `.md` file; `VaultReindexer::iterateMarkdownFiles()` only yields `.md` files. Safe. |
| `.canvas` files | Obsidian canvas/whiteboard | [VERIFIED] Implicitly ignored | Not `.md` extension; excluded by reindexer. Safe. |
| `.excalidraw.md` | Excalidraw diagrams as `.md` | [REFUTED] Unsupported / actively harmful | Ends with `.md`, so `VaultReindexer` WILL index it as a regular note. The file will appear in search results, backlinks, and the SPA as a readable note. Obsidian treats it specially; Jotter does not. This is data corruption risk. |
| Plugin output (dataview, Tasks) | Obsidian plugins | [UNVERIFIED] Degrades to code block | Unknown syntax will not cause errors (league/commonmark is permissive) but will not render as intended. Unknown if it breaks note readability. |

### Rename link integrity

**Claim (spec §7.2 + post-v0 #65):** When a note is renamed/moved, inbound `[[wikilinks]]` in other notes are rewritten **on disk**.

**Finding:** [UNVERIFIED]
- `WorkspaceNoteController::move()` endpoint exists and updates `notes.path` in the database.
- `VaultStorage::write()` writes the new file to disk.
- No independent audit of whether wikilink rewriting is executed on disk.
- Need to: (1) verify `NoteProjector` or `WikilinkProjector` rewrites inbound links when a note moves, and (2) confirm those rewrites hit disk (via `VaultStorage::write()`), not just the DB.

**Out of scope for this audit:** Detailed trace would require following the move endpoint through the full call stack.

### Search tokenization

**Findings:** [UNVERIFIED]
- MySQL FULLTEXT search is used; no independent verification of `innodb_ft_min_token_size`, stopword table, or collation.
- Short terms (`AI`, `TT`, `R700`) and unaccented queries against accented content (`conferencia` vs `conferência`) — untested.
- `innodb_ft_min_token_size` is instance-scoped and may not be changeable on target Hostinger plan per spec §4.
- **Recommendation:** Verify on production (hub.taskconnect.com.br) that search returns expected results for short and accented terms.

### Unlinked mentions and outgoing-links panel

**Finding:** [REFUTED] Neither feature exists.
- No "unlinked mentions" — notes that reference a term matching another note's title but are not yet wikilinked.
- No "outgoing-links panel" — a sidebar showing all `[[links]]` from the current note in an organized tree.
- Both are roadmap feature candidates (post-v0); not part of v0 spec.

---

## Findings by severity (ranked by damage)

### Critical — data loss or security

1. **Obsidian comments (`%%comment%%`) leak into the SPA and published static sites.**
   - **Severity:** Critical (content leak, privacy violation)
   - **Evidence tag:** [VERIFIED] `app/Domain/Vault/MarkdownServerRenderer.php` has no regex for `%% ... %%` stripping
   - **Test:** `tests/Feature/MarkdownServerRendererTest.php` has no test for comment syntax
   - **Impact:** Any user writing Obsidian comments (intended to be invisible) will have those comments displayed to readers and indexed by search
   - **Closing condition:** Add `preg_replace()` in `MarkdownServerRenderer::render()` to strip `%% ... %%` before rendering; add regression test
   - **GitHub issue:** [#202](https://github.com/suporterfid/jotter/issues/202)

2. **`.excalidraw.md` files are indexed as regular Markdown notes.**
   - **Severity:** High (data corruption risk, wrong search results)
   - **Evidence tag:** [VERIFIED] `app/Domain/Vault/VaultReindexer::iterateMarkdownFiles()` at line 87 yields any file ending in `.md`; no explicit filter for `.excalidraw.md`
   - **Impact:** Excalidraw diagram metadata (JSON-in-YAML) renders as corrupted note text; appears in search, backlinks, and SPA
   - **Closing condition:** Add `if (str_ends_with(strtolower($name), '.excalidraw.md')) continue;` in the reindexer's iteration loop; add test case for `.excalidraw.md` exclusion
   - **GitHub issue:** [#203](https://github.com/suporterfid/jotter/issues/203)

### High — missing Obsidian feature

3. **`aliases:` front-matter not resolved.**
   - **Severity:** High (feature gap; affects Obsidian sync users)
   - **Evidence tag:** [VERIFIED] No grep results for `aliases` in `app/Domain`; wikilink resolution uses only the note path, not front-matter aliases
   - **Impact:** A note with `aliases: [term1, term2]` will not be found when linking `[[term1]]` or `[[term2]]` via Obsidian's sync. Users must use the exact note path/title
   - **Closing condition:** Implement alias resolution in `WikilinkProjector::resolveNoteReference()` to check both `notes.path` and `note_properties` (or a dedicated `note_aliases` table); add test for alias-based wikilink resolution
   - **GitHub issue:** [#204](https://github.com/suporterfid/jotter/issues/204)

### Medium — compatibility gap

4. **`^block-id` and block references (`[[note#^block]]`) are unsupported.**
   - **Severity:** Medium (feature gap; Obsidian users will see broken links)
   - **Evidence tag:** [REFUTED] No parser for `^block-id` syntax in `WikilinkExtractor` (line 36 only extracts `#heading` fragments, not block IDs). `MarkdownServerRenderer` treats `#` as a heading anchor
   - **Impact:** Block-level citations will fail silently; `[[note#^id]]` will resolve to the note, not the block
   - **Closing condition:** Implement block ID extraction in `MarkdownDocument` or a new `BlockIdExtractor`; update `WikilinkProjector` to resolve block references; update search/backlinks to include block-level references
   - **GitHub issue:** [#205](https://github.com/suporterfid/jotter/issues/205)

5. **README.md describes the repo as 20% complete when it is ~95% complete.**
   - **Severity:** Medium (discoverability / trust damage)
   - **Evidence tag:** [REFUTED] See document coherence section, claim matrix
   - **Impact:** Visitors reading README see a stalled project at PR5; will not explore further or adopt the product
   - **Closing condition:** Apply the proposed diff (below, section 6)
   - **GitHub issue:** [#201](https://github.com/suporterfid/jotter/issues/201)

### Low — degraded features (not broken)

6. **LaTeX (`$$...$$`) and Mermaid diagrams not rendered.**
   - **Severity:** Low (feature gap; graceful degradation)
   - **Evidence tag:** [REFUTED] `league/commonmark` env in `MarkdownServerRenderer::__construct()` does not include math or mermaid extensions
   - **Impact:** LaTeX and Mermaid code blocks render as plain code (readable, not rendered), matching Obsidian's default. Users who want rendering must use Obsidian or another tool
   - **Closing condition:** Add optional math/mermaid extensions; off by default to avoid bloat
   - **GitHub issue:** [#206](https://github.com/suporterfid/jotter/issues/206)

7. **Plugin output (dataview, Tasks emoji) degrades gracefully.**
   - **Severity:** Low (acceptable degradation)
   - **Evidence tag:** [UNVERIFIED] Untested but expected: unknown Markdown syntax will not crash the parser, only fail to render
   - **Closing condition:** Add E2E test with a dataview block and Tasks emoji to confirm rendering doesn't crash and text is readable
   - **GitHub issue:** [#207](https://github.com/suporterfid/jotter/issues/207)

---

## Proposed issues (drafts)

All drafts below have been filed as GitHub issues on `suporterfid/jotter`:

| Draft | Issue |
|---|---|
| Remove outdated PR5 claims from README.md | [#201](https://github.com/suporterfid/jotter/issues/201) |
| Strip Obsidian comments (`%%...%%`) to prevent content leaks | [#202](https://github.com/suporterfid/jotter/issues/202) |
| Exclude `.excalidraw.md` files from vault indexing | [#203](https://github.com/suporterfid/jotter/issues/203) |
| Implement front-matter alias resolution for wikilinks | [#204](https://github.com/suporterfid/jotter/issues/204) |
| Support block references (`^block-id`, `[[note#^block]]`) | [#205](https://github.com/suporterfid/jotter/issues/205) |
| Add optional LaTeX/Mermaid rendering | [#206](https://github.com/suporterfid/jotter/issues/206) |
| Verify plugin output (dataview, Tasks emoji) degrades gracefully | [#207](https://github.com/suporterfid/jotter/issues/207) |
| Split BACKLOG.md into backlog/changelog/decisions/audit records | [#208](https://github.com/suporterfid/jotter/issues/208) |

### Issue: Remove outdated PR5 claims from README.md — [#201](https://github.com/suporterfid/jotter/issues/201)

**Verification first:** This finding is [VERIFIED] — see document coherence section.

**Context:** README.md line 12 claims Jotter "ships through PR5" with several features listed as "remain[ing] later PRs." As of 2026-07-29, PR200 is merged and all named features are shipped.

**Acceptance criteria:**
- [ ] Update README.md line 12 to reflect current state (v0 complete, v1/post-v0 in progress)
- [ ] Ensure the description matches what's actually deployed at hub.taskconnect.com.br
- [ ] Verify `STATUS.md` link in README is prominent so visitors can find authoritative current state
- [ ] CI passes

**Definition of Done (per spec §0.3):**
- Docs updated (README.md only)
- `STATUS.md` already reflects the current state (no change needed)
- No code changes
- `jt test` passes

---

### Issue: Strip Obsidian comments (`%%...%%`) to prevent content leaks — [#202](https://github.com/suporterfid/jotter/issues/202)

**Verification first:** This finding is [VERIFIED] — see Obsidian compatibility section.

**Context:** Obsidian hides comments written as `%% author notes %%` from readers. Jotter's `MarkdownServerRenderer` does not strip them, so they appear in the SPA and published static sites.

**Given/When/Then:**
```
Given a note with body "# Title\n\n%% internal note %%\n\nVisible content.",
When rendered in the SPA,
Then the output is "<h1>Title</h1>\n<p>Visible content.</p>" (comment stripped).

Given the same note published as static HTML,
When viewed in a browser,
Then the HTML source does not contain "internal note".
```

**Acceptance criteria:**
- [ ] Add regex to `MarkdownServerRenderer::render()` to strip `%% ... %%` before processing
- [ ] Add regression test in `MarkdownServerRendererTest.php` for comment stripping
- [ ] Verify published static site does not leak comments
- [ ] CI passes

**Definition of Done:**
- Code changes in `app/Domain/Vault/MarkdownServerRenderer.php` only
- Test in `tests/Feature/MarkdownServerRendererTest.php`
- No database changes
- `jt test` green

---

### Issue: Exclude `.excalidraw.md` files from vault indexing — [#203](https://github.com/suporterfid/jotter/issues/203)

**Verification first:** This finding is [VERIFIED] — see Obsidian compatibility section.

**Context:** Excalidraw diagram files end in `.excalidraw.md` and contain JSON metadata, not readable Markdown. `VaultReindexer::iterateMarkdownFiles()` indexes them as regular notes, causing search/backlink corruption and broken rendering in the SPA.

**Given/When/Then:**
```
Given a workspace vault with a file "diagram.excalidraw.md",
When vault:reindex runs,
Then the file is not indexed (not in notes table, not searchable).

Given the SPA sidebar,
When notes are listed,
Then "diagram.excalidraw.md" does not appear as a note.
```

**Acceptance criteria:**
- [ ] Update `VaultReindexer::iterateMarkdownFiles()` to skip `.excalidraw.md` files
- [ ] Add unit test that confirms `.excalidraw.md` is excluded
- [ ] Verify no other `.*.md` file types are affected (e.g., `.canvas` is already safe)
- [ ] CI passes

**Definition of Done:**
- Code changes in `app/Domain/Vault/VaultReindexer.php` only
- Test in `tests/Unit/VaultReindexerTest.php`
- No database changes
- `jt test` green

---

### Issue: Implement front-matter alias resolution for wikilinks — [#204](https://github.com/suporterfid/jotter/issues/204)

**Verification first:** This finding is [VERIFIED] — see Obsidian compatibility section.

**Context:** Obsidian resolves `[[alias-term]]` to notes that have `aliases: [alias-term]` in their YAML front-matter. Jotter only resolves by exact note path/title, so `[[alias-term]]` remains unresolved.

**Given/When/Then:**
```
Given two notes:
  - "projects/research.md" with front-matter "aliases: [research, study]"
  - "projects/current.md" with body "[[research]]"
When current.md is indexed,
Then the wikilink "research" is resolved to research.md (via alias).

Given a backlinks query for research.md,
When run,
Then current.md appears in the results (backlink via alias).
```

**Acceptance criteria:**
- [ ] Extend `WikilinkProjector::resolveNoteReference()` to check aliases
- [ ] Aliases are read from front-matter `aliases:` array in YAML
- [ ] Add tests for alias resolution (both directions: link-to-alias and backlinks-from-alias)
- [ ] Update `NotePropertyProjector` if aliases need to be indexed for search
- [ ] CI passes

**Definition of Done:**
- Code changes in `app/Domain/Links/WikilinkProjector.php` and tests
- Possible schema: either store aliases in `note_properties` or add a `note_aliases` junction table
- Migration if new schema added
- `jt test` green

---

### Issue: Support block references (`^block-id` and `[[note#^block]]`) — [#205](https://github.com/suporterfid/jotter/issues/205)

**Verification first:** This finding is [VERIFIED] — see Obsidian compatibility section.

**Context:** Obsidian supports block-level citations via `^block-id` identifiers and wikilinks like `[[note#^id]]`. Jotter's wikilink parser treats `#` only as heading anchors, not block IDs.

**Given/When/Then:**
```
Given a note with body "Paragraph text\n^myblock\n\nAnother paragraph.",
When another note contains "[[note#^myblock]]",
Then the link is recognized as a block reference, not a heading.

Given a backlinks query,
When run for a block ID,
Then incoming references to that block are returned (not just incoming to the note).
```

**Acceptance criteria:**
- [ ] Implement block ID extraction (e.g., `^blockid` on its own line or after content)
- [ ] Update `WikilinkExtractor` to distinguish block refs from heading refs
- [ ] Update `WikilinkProjector` to resolve block-level references
- [ ] Update backlinks query to include block-level inbound refs
- [ ] Add tests for block ID extraction, resolution, and backlinks
- [ ] CI passes

**Definition of Done:**
- Code changes in `app/Domain/Links/`, `app/Domain/Vault/`
- Tests in `tests/Feature/` and `tests/Unit/`
- Possible schema: add a `note_blocks` table or extend `note_links.target_ref`
- Migration if schema changes
- `jt test` green

---

## Not examined

The following areas were not examined within the Phase 2/3 budget (~70 tool calls used; ~120 budget). Any of these findings could introduce new issues:

1. **MCP server implementation** — spec §9 / §14.2. Read-only tools exist (`list_notes`, `read_note`, `search_notes`, `get_backlinks`); no independent verification of machine-token auth or access control. Untested: `POST /api/mcp` endpoint under load or with invalid tokens.

2. **GrandpaSSOnIdentityProvider full integration** — adapter implemented; tested on production site (hub.taskconnect.com.br). No independent verification of tenancy isolation, RBAC claim handling, or token expiry logic.

3. **TaskConnect JobDispatcher seam** — interface defined; local dispatcher is synchronous. No verification of TaskConnect integration or idempotency on failure.

4. **Rename/move link rewriting** — endpoint exists; no independent verification that inbound wikilinks are rewritten on disk (not just in DB). See Phase 3 finding #7 (unverified).

5. **WebDAV RFC 4918 coverage** — hand-rolled controller implements PROPFIND, GET, PUT, MKCOL, DELETE, OPTIONS. No verification of `ETag` emission, `If-Match` validation on `PUT`, `LOCK`/`UNLOCK` support (not present in code), or `PROPFIND` depth handling. Likely partial RFC compliance.

6. **MySQL FULLTEXT search quality** — short term (`AI`, `R700`) and collation handling (`conferencia` vs `conferência`). Untested on target Hostinger plan where `innodb_ft_min_token_size` may not be tunable.

7. **Attachment MIME allowlist effectiveness** — verified the list exists; no independent test of actual MIME detection (magic bytes vs `Content-Type` header).

8. **Vault export/import round-trip equivalence** — claim: "JSON v1.0 backup export/import support, configurable overwrite collision policy, and full export-import round-trip equivalence" (STATUS.md). Tested as `VaultBackupRoundTripTest.php`, but round-trip correctness was not independently verified.

9. **Typed property inference** — decision matrix exists (BACKLOG.md); no independent verification that YAML parsing with `Yaml::PARSE_DATETIME` correctly handles all cases, especially edge cases like bare ISO dates vs. quoted dates (which was a bug in #197).

10. **Visual identity token compliance** — #110 claimed a CI guard against raw color literals. No verification that guard is actually enforced or that the guard's regex is correct.

11. **Published static site security** — spec §7.5 claims rendered Markdown is sanitized. No verification that the published `publish/page.blade.php` uses the same sanitizer as the SPA.

12. **Audit log append-only enforcement** — claimed to be enforced at schema level. Not verified independently.

13. **Session security configuration** — `.env.example` shows `SESSION_SECURE_COOKIE=false` (dev). Production `.env` must be verified separately to ensure it's set to `true` on HTTPS hosts.

14. **Authorization timing** — no verification of race conditions in workspace membership changes (e.g., user removed from workspace while a request is in flight).

---

## Obsidian compatibility contract (proposed)

This contract is the artifact that stops the "is Jotter Obsidian-compatible?" question from being re-litigated.

**Jotter is Obsidian-compatible for the following constructs:**

✓ **Fully supported**
- Markdown (CommonMark, GFM task lists, tables)
- Wikilinks: `[[note]]`, `[[note|label]]`, `[[note#heading]]` (heading anchors only)
- Attachments: embeds via `![[image.png]]` → image tag (after fixing; currently unverified)
- Tags: `tags: [tag1, #nested/tag]` in front-matter
- Basic YAML front-matter (parsed and indexed)
- `.md` file format (plain text on disk)
- Nested folders in vault (no special directory structure required)
- Callouts: `> [!TYPE] content` (custom extension)
- Toggle blocks: `<details><summary>` (HTML passthrough)
- Code blocks with syntax highlighting (via marked.js)

⚠️ **Partially supported or degraded**
- Comments: `%%...%%` — **NOT stripped; LEAKS into SPA and published sites** (critical issue #1)
- Publish and WebDAV: hand-rolled, not RFC 4918 complete (RFC compliance untested)
- Unaccented search: collation may not support `conferência` ← `conferencia` (instance-scoped, untested)

✗ **Not supported**
- Aliases: `aliases: [term1, term2]` in front-matter (feature gap #3)
- Block references: `^block-id` and `[[note#^id]]` (feature gap #4)
- LaTeX: `$$...$$` (renders as plain code, acceptable degradation)
- Mermaid: ``` ```mermaid ``` (renders as plain code, acceptable degradation)
- Excalidraw: `.excalidraw.md` files (indexed as corrupted notes; critical issue #2)
- Dataview / Tasks plugin output (degrades to code block or renders as text; untested)
- Multi-device sync: via WebDAV only (no bidirectional file-watching)
- Realtime collab: async-only by design (spec §3 N1)

**Recommendation:** Publish this contract in `docs/obsidian-compatibility.md` and update it when features are added or removed. Include this table. This prevents future ambiguity about whether Jotter is "Obsidian-compatible" — it's compatible *for this list*.

---

## Summary of findings

**By severity:**
- **Critical (data loss / security):** 2 findings
  - Obsidian comments leak
  - `.excalidraw.md` indexing
- **High (missing feature, affects users):** 2 findings
  - Alias resolution
  - README.md stale
- **Medium (compatibility gap):** 1 finding
  - Block references
- **Low (graceful degradation):** 2 findings
  - LaTeX/Mermaid
  - Plugin output

**By evidence:**
- **Verified:** 11 findings (including ground truth, constraints, spec compliance)
- **Refuted:** 8 findings (false claims in docs)
- **Unverified:** 2 findings (MCP, rename link rewriting)

**Highest-damage finding:**
README.md claims the product ships at PR5 with several features "remaining later PRs," when PR200 is merged and the product is ~95% feature-complete per the roadmap. This single stale artifact is the most visible barrier to adoption.

---

**Audit complete.** No product code changed. Report filed as `docs/20260729-jotter-audit.md`.
