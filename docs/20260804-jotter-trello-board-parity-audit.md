# Trello Board-Parity Audit

Date: 2026-08-04
Status: **Diagnosis only.** Nothing here is approved, planned, or
implemented.
Trigger: product question — "what Trello features does Jotter's board
view still lack?" — answered against `frontend/src/components/
CollectionsBoardView.vue` at the tip of `main` (post-#298).

Method: read of `CollectionsBoardView.vue` (305 lines) and its
`group-change`/`select-note`/`page-change` emit contract. Current
capability: notes grouped into columns by a single typed property
(e.g. `status`), read-only — click a card to navigate to its note. No
drag-and-drop, no per-column config, no card creation from the board.

## Findings

### T.1 No drag-and-drop cards between columns

The board is read-only display. Moving a card between Trello columns
updates the underlying field automatically on drop; in Jotter, moving a
note between board columns requires opening it and editing the grouped
property by hand. This is the single biggest gap — everything else is
secondary to actually making the board interactive.

Effort: L. Priority: P1.

### T.2 No card creation from the board

No "+ Add card" affordance per column. Every note has to be created
elsewhere (sidebar, command palette) before it can appear on the board.

Effort: M. Priority: P2.

### T.3 No column configuration (reorder, rename, color, WIP limit, collapse)

Columns are purely derived from the distinct values present for the
grouped property — there is no persisted column entity to reorder,
rename independently of the underlying value, color-code, cap with a
WIP limit, or collapse.

Effort: L. Priority: P3.

### T.4 Card face shows only title + path

Trello cards surface a cover image, labels, due date, checklist
progress, member avatars, and comment count at a glance
(`CollectionsBoardView.vue:51-53`: just `board-card-title` +
`board-card-path`). Jotter's card is effectively a bare link.

Effort: M-L. Priority: P2.

### T.5 No multiple boards / saved views

The board view is a single global view per workspace with one active
`groupProperty` at a time — not saved per named board, and only one
board can be "open" at once. Trello supports many boards, each with its
own grouping/filter configuration persisted independently.

Effort: L. Priority: P3.

### T.6 No swimlanes (second grouping dimension)

Only single-dimension grouping (columns by one property) is supported.
Trello-style swimlanes — e.g. status columns × assignee rows — would
need a second grouping axis.

Effort: L. Priority: P3.

### T.7 No card-level checklists distinct from note content

Trello's per-card checklist is a first-class card feature. Jotter notes
already support Markdown task lists in their own content, but there is
no *card-face* checklist-progress summary independent of opening the
note — see T.4's "checklist progress" sub-point, listed separately here
since it's also a scope/design question: is a checklist-progress
indicator derived from the note's own `- [ ]` lines (no new data model),
or a genuinely separate card-level structure? Needs a decision before
implementation, not just an estimate.

Effort: M (if derived from existing task-list syntax) to L (if a new
data model). Priority: P3.

### T.8 Tags/labels not surfced or filterable on the board

Notes already carry properties/tags in frontmatter, but the board
neither displays them on the card face nor lets a user filter the board
by tag — only the single grouping property is used.

Effort: S-M. Priority: P2.

### T.9 No archive state / done-column automation

No archived/done state for cards, no automation to move a card
(update its property) on reaching a terminal column.

Effort: M. Priority: P3.

### T.10 No per-card activity feed

Trello logs card moves/comments/field changes on the card itself.
Jotter has workspace-level audit logs (`AuditLogViewer.vue`,
`AuditLogQueryController`) but no per-note/per-card filtered timeline
surfaced on the card or note itself.

Effort: M. Priority: P3.

## Severity

| # | Gap | Effort | Priority |
|---|---|---|---|
| T.1 | No drag-and-drop between columns | L | **P1** |
| T.2 | No card creation from the board | M | P2 |
| T.4 | Card face shows only title + path | M-L | P2 |
| T.8 | Tags/labels not surfaced or filterable | S-M | P2 |
| T.3 | No column configuration | L | P3 |
| T.5 | No multiple boards / saved views | L | P3 |
| T.6 | No swimlanes | L | P3 |
| T.7 | No card-level checklists (needs a scope decision first) | M-L | P3 |
| T.9 | No archive / done automation | M | P3 |
| T.10 | No per-card activity feed | M | P3 |
