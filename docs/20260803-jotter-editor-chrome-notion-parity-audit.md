# Editor Chrome & Notion-Parity Audit

Date: 2026-08-03
Status: **Diagnosis only.** Nothing here is approved, planned, or
implemented. No code changed in the commit that introduced this file.
Trigger: production report against `e581585` — "the properties/metadata
area takes too much space even with the collapsible rows", and "it still
doesn't feel like Notion".

Method: read of `frontend/src/` at `e581585`, cross-referenced against
`docs/visual-identity.md`,
`docs/superpowers/specs/2026-07-30-notion-visual-identity-design.md`,
`docs/superpowers/specs/2026-08-01-collapsible-panels-design.md`, the
production screenshot, and a frame-by-frame read of the Notion homepage
hero video supplied with the report.

---

## Part A — The space problem

### A.0 The measured budget

Derived from the shipped CSS at a 16px root font size, desktop:

| Chrome element | Source | Height |
|---|---|---|
| "Add cover" bar (shown when the note has no cover) | `NoteEditor.vue:840-852` | ~35px |
| `.editor-bar` (title + path + 6 controls) | `NoteEditor.vue:758-765`, `:832-838` | ~99px |
| `.editor-status-bar` (save pill + word/char/read-time) | `NoteEditor.vue:1129-1138` | ~27px |
| **5 collapsed metadata panels** | see A.2 | **~335px** |
| **Total non-canvas chrome** | | **~496px** |

On the reported 1920×1080 browser (≈990px of viewport height) that leaves
roughly **half the window** for the thing the product exists to do. The
screenshot corroborates it: the editor body runs ~175px→630px, the five
panel headers run ~660px→985px.

The panels alone are **~34% of the viewport height, permanently, while
fully collapsed and displaying nothing but five zeros.**

### A.1 Root cause 1 — all five panels mount unconditionally

`NoteEditor.vue:223-254` renders `PropertiesPanel`, `CommentsPanel`,
`BacklinksPanel`, `OutgoingLinksPanel` and `UnlinkedMentionsPanel` with no
`v-if` on emptiness. A brand-new note pays the full 335px to be told
`0 / 0 / 0 / 0 / 0`. This is the largest single contributor and the
cheapest to fix. Notion renders no section that has no content.

### A.2 Root cause 2 — "collapsed" is not free; it costs ~67px per panel

`useCollapsiblePanel` + `PanelHeader` only hide the panel **body**
(`v-show` on `.properties-body` etc., e.g. `PropertiesPanel.vue:11`). The
panel shell survives intact:

| Cost | Source | Height |
|---|---|---|
| `border-top: 1px` | `PropertiesPanel.vue:152` (and 4 siblings) | 1px |
| `padding: var(--space-4)` top | `PropertiesPanel.vue:153` | 16px |
| header row (14px chevron + 2×4px button padding) | `PanelHeader.vue:80-84` | 22px |
| `.panel-header { margin-bottom: var(--space-3) }` | `PanelHeader.vue:51` | 12px |
| `padding: var(--space-4)` bottom | `PropertiesPanel.vue:153` | 16px |
| **per collapsed panel** | | **67px** |

Two of those costs are pure dead space in the collapsed state: the header's
`margin-bottom` exists to separate the header from a body that is not
rendered, and the 32px of vertical padding is sized for a panel that has
content. That is **28px × 5 = 140px of nothing**, on top of the 195px the
five headers legitimately need.

The collapse feature shipped exactly as
`2026-08-01-collapsible-panels-design.md` §3 specified — the spec's §2
"Out of scope" explicitly kept the collapsed shell untouched ("no other
template changes"). The feature is not defective; **its scope was too
narrow to solve the reported problem.** This is why collapsing everything
did not relieve the pressure.

### A.3 Root cause 3 — the panels steal fixed height instead of scrolling away

`.editor-container` is `display: flex; flex-direction: column; height: 100%;
overflow: hidden` (`NoteEditor.vue:749-756`), the panels are flex siblings
placed **after** `.editor-body { flex: 1 }` (`:1011-1016`). The panels take
their natural height first; the writing surface absorbs the entire loss and
can never reclaim it. There is no scroll position at which the user gets
the full canvas back.

In Notion nothing behaves this way: page properties live inside the page's
own scroll flow (they scroll off), and comments/updates live in an overlay
drawer that never reflows the canvas.

### A.4 Root cause 4 — five sections where Notion has at most two

Each panel independently repeats: a top border, 32px of padding, an
ALL-CAPS letterspaced title, and a count pill. Five times. Notion's
equivalent surface for the same information is:

- **Properties** — inline rows immediately under the page title, collapsing
  to a single `N properties` line.
- **Comments** — a right drawer, plus one `Add a comment` affordance under
  the title.
- **Backlinks** — a single small chip under the title (`↗ 1 backlink`).
- **Outgoing links / unlinked mentions** — *do not exist in Notion at all.*
  These are Obsidian vocabulary. Two of Jotter's five permanent sections
  are concepts a Notion user has never seen.

### A.5 Root cause 5 — collapse state is global-per-type and has no escape hatch

`useCollapsiblePanel.ts:4` keys on the panel type
(`jotter-panel-collapsed:<key>`), so the preference is workspace-wide and
note-agnostic. There is no "hide all metadata", no keyboard shortcut, no
per-note memory, no reordering, and no way to remove a panel from the
layout permanently. The only control is five separate chevrons, each
costing 67px whichever way it is pointing.

---

## Part B — Notion-parity gaps

Grouped by how strongly each one contributes to "this is not Notion",
strongest first. Evidence for the Notion side is the supplied hero video
(a `Ramp HQ` page: cover-less title with a large icon, an inline database
block with `Company tasks / My tasks / Current sprint / Timeline` tabs,
drag-drop cards, live collaborator cursors, `+ New page` per column).

### B.1 The editing model is a Markdown source editor (highest impact)

`NoteEditor.vue:151-159` is a single `<textarea>` in `--font-mono`, paired
with a rendered preview. Notion has **no source mode at all** — text is
edited in place, WYSIWYG, block by block.

Worse for perception: the `Edit | Split | Preview` toggle
(`NoteEditor.vue:73-98`) is a control whose very existence announces "this
is a Markdown tool". It defaults to `split` (`:401`), which is precisely
the two-column monospace layout in the screenshot. Nothing in Notion has
ever looked like that. **No amount of token or spacing work will close the
Notion gap while this toggle and this textarea are the primary surface.**

### B.2 The slash menu is fully built and never mounted

`SlashMenu.vue` (168 lines) and `blockRegistry.ts` (7 block definitions:
to-do list, code block, wikilink, callout, toggle, table, divider) exist,
are styled, and are unit-tested by `SlashMenu.spec.ts`. A repo-wide grep
shows **the spec file is its only importer** — `SlashMenu` is mounted
nowhere. `NoteEditor.vue`'s `handleInput` (`:602-626`) handles `[[` and
only `[[`; there is no `/` trigger anywhere in the product.

`STATUS.md:41` lists "Slash-Command Insertion Menu" as delivered. It is
delivered as a *component*, not as a *feature* — exactly the
API-delivered-vs-UI-delivered reconciliation failure `STATUS.md:109`
warns about. The single most iconic Notion interaction currently ships as
dead code.

### B.3 No reading column

`docs/visual-identity.md:386-388` already records this honestly: the
"Notion page" 640–760px centered column "was part of the original design
intent for a Notion-like feel but was never actually implemented; treat
this as an open item, not a shipped feature." Confirmed at `e581585` —
there is no `max-width` in `NoteEditor.vue` or `MarkdownPreview.vue`. On a
1920px display, prose runs the full pane width. Notion never does.

### B.4 The title is chrome, and it is read-only

`NoteEditor.vue:55` renders `<h2 class="editor-title">{{ note.title || note.path }}</h2>`
inside `<header class="editor-bar">`, which carries
`border-bottom: 1px solid var(--color-border)` (`:764`) and shares its row
with six controls. A repo-wide grep finds **no rename affordance anywhere
in the frontend** — the only way to retitle a note is to edit the `#`
heading in the Markdown source.

In Notion the title is page *content*: it sits inside the scrolling
canvas below the cover, there is no rule under it, and clicking it types
into it. Two independent mismatches — structural (chrome vs. content) and
functional (read-only vs. the primary rename path).

### B.5 Explicit save, and a permanent statistics footer

`NoteEditor.vue:130-138` is a `Save Changes` / `Saved` button;
`:208-220` is a footer showing a save-state pill plus word count, char
count and reading time, always. Notion autosaves invisibly and shows a
word count only on demand. This is both ~27px of always-on chrome and a
mental-model mismatch: the user is being asked to think about saving.

### B.6 Properties are an add-form, and values cannot be edited

`PropertiesPanel.vue:38-88` is a `name input + type <select> + value input
+ Add` form; existing values render through `formatValue()` as read-only
text (`:21`, `:115-120`) with only a delete button. So a user can create
and destroy a property but **cannot change its value** — they must delete
and re-add it.

Notion: properties are rows under the title, the value itself is the
editing surface (click `Status` → pick from a menu), type is chosen once at
creation and never re-stated in the row, and the whole block collapses
behind `N properties`. Jotter surfaces the type as a badge on every row
(`:20`) — an implementation detail Notion deliberately hides.

### B.7 Comments cannot be anchored from the UI

The data model supports anchoring — `CommentsPanel.vue:23` renders
`line {{ comment.anchor_line }}` — but there is no way to select text and
comment on it. The only entry point is the global form at the bottom of the
page. In Notion every comment starts from a selection and lives in the
margin or the drawer.

### B.8 Cover and icon affordances are permanent chrome

`NoteEditor.vue:10-16` pins a **full-width bordered `Add cover` button**
above the header at all times when the note has no cover — visible in the
screenshot as a dedicated row on a note that has no cover. Notion reveals
`Add cover` / `Add icon` only on hover over the title area, and they vanish
once set. The cover itself is a fixed `height: 200px` (`:859-864`); Notion's
is taller (~30vh) and repositionable (repositioning was explicitly scoped
out in the cover spec — noted here for completeness, not as a defect).

### B.9 No collapsible sidebar

`Sidebar.vue:703-705` — `width: 280px; min-width: 280px`, fixed at every
desktop width. No `«` collapse, no hover-peek, no drag-resize. Notion's
sidebar collapses to hand the page the full window. Combined with B.3,
Jotter has neither of the two mechanisms that make a Notion page feel calm:
it can neither widen the canvas nor narrow the text.

### B.10 There is no right-hand surface, and secondary views replace the page

`App.vue:60-162`: `GraphView`, `AttachmentsPanel`, `AuditLogViewer`,
`LinkReportViewer`, `CollectionsTableView`, `CollectionsBoardView`,
`CollectionsCalendarView`, `SearchResults` and `NoteEditor` are all
`v-if` / `v-else-if` **siblings**. Every secondary surface either replaces
the note entirely or stacks under it. There is no drawer, no overlay panel,
no right rail anywhere in the app shell.

This is the structural gap the attached video makes most obvious: in the
video, the board **is** the page. `Company tasks / My tasks / Current
sprint / Timeline` are tabs on a database *block* embedded in `Ramp HQ`,
with `+ New page` inside each column and cards dragged between them —
without ever leaving the page. In Jotter the equivalent views are
workspace-level modes reached from a sidebar "More actions" menu that
unmount the note. A Notion user reads "views are part of a page"; Jotter
says "views are a different screen".

### B.11 Component texture contradicts the token layer

The tokens are genuinely Notion-like (`tokens.css` — `#F7F6F3`, `#37352F`,
`#191919`, 3px radii, border+tint instead of shadow). The composition on
top of them is not:

- Every list item in every panel is a **bordered card**:
  `border: 1px solid var(--color-border)` + `background:
  var(--color-surface-emphasis)` — `.property-item`
  (`PropertiesPanel.vue:172-180`), `.backlink-item`, `.comment-item`,
  `.mention-item`, `.outgoing-link-item`. Notion's list rows are
  **borderless**, transparent, and only tint on hover.
- Section headers are `text-transform: uppercase; letter-spacing: 0.05em`
  (`PanelHeader.vue:54-56`) — the `PROPERTIES / COMMENTS / BACKLINKS /
  OUTGOING LINKS / UNLINKED MENTIONS` stack in the screenshot. Notion uses
  no all-caps section headers inside a page.
- No block-level hover affordances: no `⋮⋮` drag handle, no `+` between
  blocks. In the video these are the primary way the page is manipulated.

`docs/visual-identity.md` §1 already states the right principle ("minimal
elevation… border and background tint"). The panels over-applied it: they
put a border on *every row* rather than on the container, which reads as
"form", not "document".

---

## Part C — Severity

| # | Gap | Cost | Effort | Priority |
|---|---|---|---|---|
| A.1 | Empty panels always mounted | 335px on a new note | XS | **P0** |
| A.2 | Collapsed shell costs 67px each | 140px pure waste | XS | **P0** |
| A.3/A.4 | 5 stacked sections steal fixed height | structural | M | **P0** |
| B.8 | `Add cover` is permanent chrome | 35px + tone | XS | **P1** |
| B.5 | Save button + stats footer | 27px + mental model | S | **P1** |
| B.3 | No reading column | tone | S | **P1** |
| B.2 | Slash menu built but unmounted | signature interaction absent | S | **P1** |
| B.4 | Title is chrome, and read-only | tone + missing rename | M | **P1** |
| B.6 | Properties are a form; values not editable | functional | M | **P1** |
| B.9 | Sidebar not collapsible | tone | S | **P2** |
| B.11 | Bordered-row texture, ALL-CAPS headers | tone | S | **P2** |
| B.7 | Comments cannot be anchored | functional | M | **P2** |
| B.10 | No drawer; views replace the page | structural | L | **P2** |
| B.1 | Markdown textarea + Edit/Split/Preview | **the** identity gap | XL | **P3 (decision)** |

Note the shape of this table: everything from P0 to P2 is achievable inside
the current architecture and would remove the space complaint entirely.
**B.1 alone is the thing that decides whether the product can ever *feel*
like Notion**, and it is a product decision — not a UI task — because it
collides with the Markdown-on-disk invariant (`AGENTS.md`, spec §1). It
should be decided deliberately, not drifted into.

---

## Part D — Target state (proposed, not adopted)

Sketched so the gaps above have something to be measured against.

**D.1 Kill the bottom stack.** Metadata is not a footer.
- Properties become inline rows directly under the title, inside the page
  scroll flow, collapsing to a single `N properties` line — Notion's model,
  and the one that makes the space complaint structurally impossible.
- Backlinks / outgoing links / unlinked mentions collapse into **one**
  "Links" surface, rendered only when non-empty, ideally as a chip under
  the title that opens a drawer.
- Comments move to a right drawer that overlays rather than reflows.
- Nothing renders at zero count.

**D.2 Introduce a right drawer in the app shell.** `App.vue` currently has
no concept of one; it is the prerequisite for D.1's comments and links, and
for B.10 later.

**D.3 Give the page a reading column** (640–760px, `docs/visual-identity.md`
§11's own open item) **and a collapsible sidebar** (B.9). These two together
do more for "feels like Notion" per line of code than anything else on the
list.

**D.4 Move the title into the canvas, make it editable, autosave, retire
the status footer, and hover-reveal the cover/icon affordances.** This
converts `.editor-bar` from a toolbar into what Notion has: a thin
breadcrumb row on the left, quiet actions on the right.

**D.5 Wire the slash menu that already exists.** Lowest effort-to-perceived-
Notion-ness ratio in the entire list — the component and its 7 block
definitions are already written and tested.

**D.6 Then, and separately, decide B.1.** Options, in ascending cost:
(a) keep the textarea, drop the toggle, default to a single rendered-ish
view; (b) inline WYSIWYG over Markdown (TipTap/Milkdown/ProseMirror with a
Markdown serializer, preserving the on-disk invariant); (c) a real block
model. (b) is the only one that both reaches Notion parity and keeps
`AGENTS.md`'s Markdown-on-disk rule intact, and it is a multi-PR epic.

---

## Part E — Incidental findings

Small, unrelated to the two headline complaints, recorded so they are not
lost:

1. `useCollapsiblePanel.ts:12-15` persists inside `toggle()`, whereas
   `2026-08-01-collapsible-panels-design.md` §3 specified
   `watch(collapsed, …)`. Equivalent today because `toggle()` is the only
   mutator; it will silently stop persisting the moment anything assigns
   `collapsed.value` directly.
2. `useCollapsiblePanel.ts:7` reads `localStorage` at setup with no
   `try/catch`. In a storage-blocked context this throws during component
   setup rather than falling back to the default.
3. `SlashMenu.spec.ts` passes against a component no user can reach — the
   suite is green on a feature that does not exist in the product (B.2).

---

## Open questions for the maintainer

1. **B.1 is a fork in the road.** Is Notion-*feel* the goal (→ inline
   WYSIWYG, D.6b, an epic), or is Notion-*calm* enough (→ D.1–D.5, keeping
   an honest Markdown editor)? Everything else in this document is
   independent of the answer and can proceed either way.
2. **Do outgoing links and unlinked mentions stay?** They are Obsidian
   concepts occupying two of five permanent slots. Keep them, demote them
   into a single Links drawer, or drop them?
3. **Should collapse state stay global-per-type** (A.5), or become
   per-note, or disappear entirely once metadata is no longer a footer?
