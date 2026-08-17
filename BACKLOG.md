# Jotter — Backlog

Deferred work only. Sequenced from `docs/20260727-jotter-roadmap-ai-agent.md` (product roadmap) within the constraints of `docs/jotter-initial-spec-and-build-plan.md` §14. Where the two disagree, the spec's §8 security constraints, §4 shared-hosting constraints, and §1 Markdown-on-disk invariant win, and the item sits in **Needs a decision** below until resolved.

Roadmap priorities 1–5 (search, nested folders, tags, backlinks, attachments) are **already delivered** — see spec §14.3. The roadmap's gap analysis lists them as missing because its baseline describes a different product; do not re-plan them.

This file previously also carried the shipped-item changelog, decision records, security-audit log, UI-audit log, and design-system tracker — six roles in one file, a structural cause of the reconciliation failures recorded in `docs/20260729-jotter-audit.md` (e.g. #141: this file simultaneously listing C1–C6 as resolved and as open blockers). Split per #208:

- **Shipped work** (all Milestones A–D, Spec Debt epics, v0/v1/v2 delivery, and the 2026-07-29 UI-audit follow-through) → `CHANGELOG.md`
- **Decision records** (C1–C6, the typed-property model decision, and future decisions) → `docs/decisions.md`
- **Security/correctness audit findings** → `docs/security-audit-2026.md`
- **Visual-identity design-system tracking** (#96–#110) → `docs/visual-identity.md`

As of 2026-07-29, every previously-tracked Milestone is delivered (backend and UI) and closed. The open work in this file is the Confluence-parity section (#347–#360, filed 2026-08-17) plus the one item under "Needs a decision".

---

## Needs a decision (spec §14.5)

C1, C2, C3, C5, and C6 were resolved — see `docs/decisions.md`. This section previously still listed them as open `TODO(spec)` blockers after they were resolved; that self-contradiction was found and fixed (#141).

- **Roadmap baseline provenance (#360).** `TODO(spec): confirm whether the roadmap's gap analysis was drawn from a different product of the same name. Until confirmed, spec §14.3 governs what counts as delivered.` The evidence is already assembled in spec §14.1 — what remains is recording the confirmation in `docs/decisions.md` and removing this item, not investigating it.

## WYSIWYG editor epic (decision resolved 2026-08-05 — option (b))

`docs/20260805-jotter-wysiwyg-editor-epic-spec.md` resolves the #263 decision recorded above: Notion-feel is the goal, delivered as inline WYSIWYG over Markdown (Milkdown, preserving the on-disk invariant — see `docs/decisions.md` for full rationale). Sequenced as five issues, each gated on the previous one merged and green:

- ~~**WY.1 — Markdown ⇄ Milkdown round-trip fidelity harness, no UI change (#321, M, P2).**~~ **Shipped.** `frontend/src/services/__tests__/wysiwygRoundTrip.spec.ts`; 8 known gaps (front matter, wikilinks, embeds, callouts, list/table cosmetic normalization) documented as required WY.3 scope, not silently dropped.
- ~~**WY.2 — Additive "Live" WYSIWYG view mode alongside Edit/Split/Preview (#322, L, P2).**~~ **Shipped.** `NoteEditorWysiwyg.vue` + `frontMatterGuard.ts`; front matter now safely round-trips through this mode (WY.1's most severe known gap, closed).
- ~~**WY.3 — Native nodes for wikilinks/embeds/callouts/toggles/tables + slash menu on the WYSIWYG surface (#323, L, P2).**~~ **Shipped.** `wysiwygNodes/{wikilink,embed,callout,toggle}.ts`; all four moved from KNOWN_GAP_FIXTURES to LOSSLESS_FIXTURES. Known limit: multi-paragraph toggle bodies aren't parsed (single-line form only).
- ~~**WY.4 — Port comment-anchoring and history/restore off textarea coordinate hacks (#324, M, P3).**~~ **Shipped.** `getSelectionAnchorLine()` (exact ProseMirror position mapping); fixed a real restore-into-same-note-id bug affecting all view modes, not just Live.
- ~~**WY.5 — Make "Live" the default view mode; keep raw source as an opt-in fallback (#325, M, P3).**~~ **Shipped — epic complete, resolves #263.** `viewMode` defaults to `'live'`; decided to keep `Preview` (not retire it) rather than cross into the toggle-removal non-goal below.

Fully removing the Edit/Split/Preview toggle (the audit's literal D.6b ask) is deliberately not committed scope in WY.1–WY.5 — see the spec doc §6.

## Obsidian UI-parity gaps

`docs/20260803-jotter-obsidian-ui-parity-audit.md` — findings from a UI
comparison against Obsidian, verified against `frontend/src/` post-#285.
Command palette, tag cloud/filter, collapsible sidebar, and the
right-hand drawer were already shipped and dropped from this list during
verification. All five remaining items shipped 2026-08-04 (G.4 scope A —
tab strip, single active pane — only; true split-screen, scope B, is
separate future work, blocked on pane-scoping G.1/G.5's DOM-id lookups):

- ~~No headings outline/TOC pane for the current note~~ (#286, shipped #292).
- ~~No hover preview for wikilinks~~ (#287, shipped #293).
- ~~No transclusion / `![[note]]` embeds~~ (#288, shipped #294).
- ~~No contextual/local graph per note~~ (#289, shipped #295).
- ~~No multi-pane / tabbed editing (scope A)~~ (#290, shipped #297).

## Trello board-parity gaps

`docs/20260804-jotter-trello-board-parity-audit.md` — findings from a
comparison of `CollectionsBoardView.vue` against Trello's board/card
feature set. All ten shipped:

- ~~**No drag-and-drop cards between board columns (#299, L, P1).**~~ **Shipped** (PR #310).
- ~~**No card creation from the board (#300, M, P2).**~~ **Shipped** (PR #311).
- ~~**Card face shows only title + path (#302, M–L, P2).**~~ **Shipped** — cover, due date, checklist, comments (PR #313).
- ~~**Tags/labels not surfaced or filterable on the board (#306, S–M, P2).**~~ **Shipped** (PR #312).
- ~~**No column configuration — reorder/rename/color/WIP limit/collapse (#301, L, P3).**~~ **Shipped** (PR #315).
- ~~**No multiple boards / saved views (#303, L, P3).**~~ **Shipped** (PR #314).
- ~~**No swimlanes / second grouping dimension (#304, L, P3).**~~ **Shipped** (PR #316).
- ~~**No card-level checklists distinct from note content (#305, M–L, P3).**~~ **Shipped** — checklists as a separate structure, not derived from task-list syntax (PR #317).
- ~~**No archive state / done-column automation (#307, M, P3).**~~ **Shipped** (PR #318).
- ~~**No per-card activity feed (#308, M, P3).**~~ **Shipped** (PR #319).

## Confluence-parity gaps

`docs/20260817-jotter-confluence-parity-audit.md` — findings from a
comparison against Confluence's feature set, verified against `app/`,
`frontend/src/`, `database/migrations/` and `routes/api.php` at
`dcda766`. Fourteen gaps, four of them never reported before. Filed as
#347–#360. Nothing here is started.

Ordered by recommended priority, not by theme. The audit's §4 argues the
sequence; the short version is that role enforcement is a security gap
and outranks SSO, and that page-level ACLs mean nothing until it lands.

- **Membership roles are stored but never enforced (#347, M, P1).** `ALLOWED_ROLES` defines owner/admin/editor/viewer; `isAuthorizedForWorkspace()` (`:170`) only checks a membership row exists. Across all of `app/`, `role` appears solely in `Membership.php`'s `$fillable` and in the admin controller that writes it — a `viewer` can write and delete. Blocks #349 and #359.
- **No standard corporate SSO (#348, L, P1).** Only `LocalIdentityProvider` and the site-specific `GrandpaSSOnIdentityProvider`. Generic OIDC adapter over the existing seam; OIDC before SAML because a redirect flow fits shared hosting. Land #347 first so JIT-provisioned users default to `viewer`.
- **No per-page permissions (#349, L, P2).** Authorization is workspace-granular and the `IdentityProvider` contract has no note-level concept. Must suppress restricted notes across every projection — search, links, graph, collections, tree, export, publish, MCP. **Blocked on #347.**
- **Note deletion is immediate and unrecoverable (#350, S–M, P2).** No `deleted_at` or `SoftDeletes` anywhere; `note_revisions` doesn't help once the note is gone. Soft delete + trash + bounded cron purge, with `vault:reindex` taught not to resurrect trashed notes.
- **Notifications only ever fire for `@mentions` (#351, M, P2).** `WorkspaceEventEmitter` has one method and writes only `'type' => 'mention'`; there is no watch/subscribe concept. Event vocabulary + page watching. Gates #352.
- **No email delivery or digest (#352, M, P2).** No `Mailable` in `app/`; `config/mail.php` is stock. Mail channel + preferences + cron digest via `JobDispatcher`. **Gated on #351** — shipping first would email mentions and nothing else.
- **No external content embeds (#353, M, P3).** `BlockRegistry.php` is already a sanitizing declarative registry with an `embed` block, but for internal transclusion. Add external embeds as a registered block with a domain allowlist and iframe sandbox, agreeing across both render paths. Not a plugin marketplace — spec §3 N3.
- **No PDF export (#354, M, P3).** Export is ZIP/JSON of Markdown only. Constrained by §3 N2 / §4: either delegate via `JobDispatcher`/TaskConnect or use a pure-PHP renderer over the existing server-rendered HTML.
- **Public sharing is whole-workspace only (#355, M, P3).** `WorkspacePublishController::publish()` iterates every note in the workspace. Per-note tokenised share links with expiry and revocation, without leaking workspace context.
- **No installable PWA (#356, S, P3).** No manifest or service worker. Note that §3 N3 excludes PWA **from v0**, not permanently — unlike N1. Shell caching only; decision C5 (WebDAV + Obsidian is the offline story) still stands.
- **No usage analytics (#357, M, P3).** Only raw `audit_log` in a table. Needs a separate rollup table fed by a bounded cron command — aggregating live over `audit_log` loses everything at the 90-day prune.
- **Split-screen blocked by document-global DOM lookups (#358, L, P3).** G.4 scope A shipped (#297). The blocker is not that G.1/G.5 are unresolved — both shipped — but that G.1's implementation scrolls via `document.getElementById` (`NoteEditor.vue:845`), which resolves to the wrong pane once two are mounted. Pane-scope the lookups first; that stage stands alone.
- **No content approval workflow (#359, L, P3).** Lowest value for this product's audience; filed for completeness. Closing it as not-planned is a legitimate outcome if no demand appears. Needs #347.
- **Roadmap baseline provenance (#360, XS, P3).** Docs only — see "Needs a decision" below.

## Not adopted

- **Visual canvas / whiteboard** — spec §3 N3; the roadmap itself lists whiteboard parity as a non-goal for the next cycle.
- **Chat-and-files parity, full database-view breadth** — named as non-goals by the roadmap.
- **Realtime multi-user editing** — spec §3 N1, permanently out on shared hosting. Re-examined by the 2026-08-17 Confluence-parity audit and left here deliberately: it is decision **C1, resolved** in `docs/decisions.md`, so no implementation issue was filed. Reopening it requires a superseding decision entry, not a PR. If it is ever reopened, only polling fits the constraint — §4 and `AGENTS.md` rule out websockets outright, so live presence is reachable but live cursors and OT/CRDT are not.
