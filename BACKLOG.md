# Jotter — Backlog

Deferred work only. Sequenced from `docs/20260727-jotter-roadmap-ai-agent.md` (product roadmap) within the constraints of `docs/jotter-initial-spec-and-build-plan.md` §14. Where the two disagree, the spec's §8 security constraints, §4 shared-hosting constraints, and §1 Markdown-on-disk invariant win, and the item sits in **Needs a decision** below until resolved.

Roadmap priorities 1–5 (search, nested folders, tags, backlinks, attachments) are **already delivered** — see spec §14.3. The roadmap's gap analysis lists them as missing because its baseline describes a different product; do not re-plan them.

This file previously also carried the shipped-item changelog, decision records, security-audit log, UI-audit log, and design-system tracker — six roles in one file, a structural cause of the reconciliation failures recorded in `docs/20260729-jotter-audit.md` (e.g. #141: this file simultaneously listing C1–C6 as resolved and as open blockers). Split per #208:

- **Shipped work** (all Milestones A–D, Spec Debt epics, v0/v1/v2 delivery, and the 2026-07-29 UI-audit follow-through) → `CHANGELOG.md`
- **Decision records** (C1–C6, the typed-property model decision, and future decisions) → `docs/decisions.md`
- **Security/correctness audit findings** → `docs/security-audit-2026.md`
- **Visual-identity design-system tracking** (#96–#110) → `docs/visual-identity.md`

As of 2026-07-29, every previously-tracked Milestone is delivered (backend and UI) and closed. Nothing is currently pending in this backlog beyond the two sections below.

---

## Needs a decision (spec §14.5)

C1, C2, C3, C5, and C6 were resolved — see `docs/decisions.md`. This section previously still listed them as open `TODO(spec)` blockers after they were resolved; that self-contradiction was found and fixed (#141).

- **Roadmap baseline provenance.** `TODO(spec): confirm whether the roadmap's gap analysis was drawn from a different product of the same name. Until confirmed, spec §14.3 governs what counts as delivered.`

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
feature set.

- **No drag-and-drop cards between board columns (#299, L, P1).**
- **No card creation from the board (#300, M, P2).**
- **Card face shows only title + path (#302, M–L, P2).**
- **Tags/labels not surfaced or filterable on the board (#306, S–M, P2).**
- **No column configuration — reorder/rename/color/WIP limit/collapse (#301, L, P3).**
- **No multiple boards / saved views (#303, L, P3).**
- **No swimlanes / second grouping dimension (#304, L, P3).**
- **No card-level checklists distinct from note content (#305, M–L, P3) — needs a scope decision first (derived from existing task-list syntax vs. a new data model), see the issue.**
- **No archive state / done-column automation (#307, M, P3).**
- **No per-card activity feed (#308, M, P3).**

## Not adopted

- **Visual canvas / whiteboard** — spec §3 N3; the roadmap itself lists whiteboard parity as a non-goal for the next cycle.
- **Chat-and-files parity, full database-view breadth** — named as non-goals by the roadmap.
- **Realtime multi-user editing** — spec §3 N1, permanently out on shared hosting.
