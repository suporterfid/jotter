# Obsidian UI-Parity Audit

Date: 2026-08-03
Status: **Diagnosis only.** Nothing here is approved, planned, or
implemented.
Trigger: product question — "what does Obsidian offer in UI that Jotter
doesn't?" — answered against `frontend/src/` at the tip of `main`
(post-#250–#285 Notion-parity fixes).

Method: grep/read of `frontend/src/` cross-referenced against Obsidian's
UI vocabulary (Live Preview, quick switcher/command palette, hover
preview, outline pane, local graph, panes/tabs, transclusion, tag pane).
Items already shipped were dropped from this list during verification:
command palette (`CommandPalette.vue`, mounted in `App.vue:215`),
tag cloud + filter pills (`Sidebar.vue:289-305`, `SearchResults.vue:37-47`),
collapsible sidebar (#259), right-hand drawer (#262). Canvas/whiteboard
is an explicit non-goal (`BACKLOG.md` "Not adopted", spec §3 N3).

## Findings

### G.1 No headings outline/TOC pane for the current note

`NoteEditor.vue` and `MarkdownPreview.vue` have no heading-navigation
surface. Obsidian's Outline pane lists the current note's heading
hierarchy and jumps the cursor/scroll on click. Jotter has no equivalent
— navigating a long note means scrolling.

Effort: S. Priority: P2.

### G.2 No hover preview for wikilinks

`MarkdownPreview.vue:41-46` handles `wikilink` clicks (navigate) and
`:89` only tints on hover (`:deep(.wikilink:hover)`). There is no popup
showing the target note's content on hover, the single most-used
Obsidian affordance for following a train of thought without leaving the
page.

Effort: M (popup component, position calc, fetch-on-hover, debounce).
Priority: P2.

### G.3 No contextual/local graph per note

`GraphView.vue` is mounted once, globally, toggled via `App.vue:69-74`
(`isGraphViewActive`) and shows the whole vault's graph. Obsidian also
offers a *local graph* scoped to the current note's immediate neighbors,
embeddable in the sidebar. Jotter has no per-note graph — only the
all-or-nothing global view.

Effort: M. Priority: P3.

### G.4 No multi-pane / tabbed editing

`App.vue:147` mounts exactly one `NoteEditor` instance; there is no tab
bar and no split-pane layout. Obsidian lets a user open several notes
side by side or in tabs and keeps that layout across sessions. Jotter
can show exactly one note at a time.

Effort: L (structural — multi-instance editor state, tab/pane layout,
persistence). Priority: P3.

### G.5 No transclusion (`![[note]]` embeds)

No occurrence of embed/transclusion handling anywhere in
`frontend/src/` (grep for "transclu"/"embed" is empty). Obsidian renders
`![[Note Title]]` as the referenced note's content inline, and
`![[Note#^block]]` as a single embedded block. Jotter's wikilinks
(`[[...]]`) are link-only; there is no embed syntax or renderer.

Effort: M–L (parser support in the wikilink pipeline, embed renderer,
circular-embed guard). Does not collide with the Markdown-on-disk
invariant — the source stays plain `![[...]]` text; only rendering
pulls in the referenced content. Priority: P2.

## Severity

| # | Gap | Effort | Priority |
|---|---|---|---|
| G.1 | No outline/TOC pane | S | P2 |
| G.2 | No hover preview for wikilinks | M | P2 |
| G.5 | No transclusion (`![[note]]` embeds) | M–L | P2 |
| G.3 | No contextual/local graph per note | M | P3 |
| G.4 | No multi-pane / tabbed editing | L | P3 |
