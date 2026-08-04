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
- **Markdown textarea + Edit/Split/Preview toggle vs. real Notion-feel (#263, XL, P3).** `docs/20260803-jotter-editor-chrome-notion-parity-audit.md` §B.1, Part D.6, Open Question 1: `NoteEditor.vue`'s core editing surface is a single `<textarea>` in `--font-mono` paired with a rendered preview and an Edit/Split/Preview toggle — a control whose mere existence announces "this is a Markdown tool." No amount of chrome/spacing work (the rest of this audit's #250–#262) closes that gap while this textarea remains the primary editing surface. This collides directly with the Markdown-on-disk invariant (spec §1/`AGENTS.md`), so it's a product decision, not a UI task — do not drift into it via smaller PRs. Options, ascending cost: (a) keep the textarea, drop the toggle, default to a single rendered-ish view; (b) inline WYSIWYG over Markdown (TipTap/Milkdown/ProseMirror with a Markdown serializer, preserving the on-disk invariant) — the only option reaching real Notion parity while keeping the Markdown-on-disk rule intact, a multi-PR epic; (c) a real block model (furthest from current architecture, highest cost). The open question to resolve first: is Notion-*feel* the actual goal, or is Notion-*calm* enough via #250–#262 while keeping an honest Markdown editor? All other issues from the audit are independent of this answer and can proceed regardless.

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
