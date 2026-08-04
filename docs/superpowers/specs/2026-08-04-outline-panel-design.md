# Outline / TOC Panel — Design

Date: 2026-08-04
Source: `docs/20260803-jotter-obsidian-ui-parity-audit.md` §G.1, issue #286
(closed, tracked-not-implemented, filed via #291).

## Problem

`NoteEditor.vue` and `MarkdownPreview.vue` have no heading-navigation
surface. Obsidian's Outline pane lists the current note's heading
hierarchy and jumps to a heading on click. Jotter has no equivalent —
navigating a long note means scrolling.

## Decision

Right-drawer toggle, following the Comments-drawer pattern introduced by
#262 (`NoteEditor.vue:334-357`), rather than an always-visible left rail.
Rejected the left-rail alternative because it's permanent-width chrome,
and this codebase's whole 2026-08-03 audit pass (#250-252) has been
about removing exactly that kind of always-on cost.

## Architecture

- **`OutlinePanel.vue`** (new component, `frontend/src/components/`) —
  pure props-in/events-out, same shape as `CommentsPanel.vue`. Props:
  `headings: HeadingEntry[]`. Emits: `jump-to-heading(entry: HeadingEntry)`.
  Renders a nested list, indented by `level` (1-6), one row per heading.
  Empty state: "No headings" when `headings.length === 0`.

- **`services/outline.ts`** (new) — pure util:
  ```ts
  export interface HeadingEntry {
    level: number       // 1-6
    text: string        // heading text, trimmed of leading #'s
    line: number         // 0-based line index in the source
    id: string           // slug, deduped on collision
  }
  export function parseHeadings(markdown: string): HeadingEntry[]
  ```
  Regex-scans lines for `^#{1,6}\s+`, tracking a fenced-code-block toggle
  (lines starting with ` ``` ` or `~~~`) so `#` inside code fences (e.g.
  shell comments) is never picked up as a heading. Slug generation:
  lowercase, non-alnum → `-`, collapse repeats, trim; collisions get a
  `-2`, `-3`, ... suffix (same scheme `marked`'s own `gfm-heading-id`
  extension uses, kept consistent in case that extension is adopted
  later).

- **`services/markdown.ts`** — extend the `marked` renderer override to
  emit an `id` attribute on every rendered heading, using the same
  `parseHeadings`/slug logic so ids match between the outline and the
  rendered preview. This is also a prerequisite for wikilink `#heading`
  anchors, so it isn't wasted scope even outside this feature.

- **`NoteEditor.vue`**:
  - New `isOutlineDrawerOpen` ref, new `headings` computed
    (`parseHeadings(editableContent.value)`).
  - New "Outline" button in `.editor-controls`, placed next to the
    History/Comments buttons, `data-testid="outline-drawer-btn"`,
    toggles `isOutlineDrawerOpen`. Always visible/enabled regardless of
    heading count (navigation affordance, not a data panel — unlike
    Backlinks-style omit-when-empty, this stays discoverable).
  - New `<Teleport to="#app-right-drawer">` block, literal copy of the
    Comments-drawer block's shell/header/close-button, containing
    `<OutlinePanel :headings="headings" @jump-to-heading="jumpToHeading" />`.
  - New `jumpToHeading(entry: HeadingEntry)`:
    - `edit` / `split` mode: convert `entry.line` → character offset in
      `editableContent.value` (sum of `line.length + 1` for prior lines),
      call `textareaRef.value.setSelectionRange(offset, offset)` then
      `.focus()`. A focused textarea auto-scrolls its caret into view
      natively — no manual scroll math needed.
    - `preview`-only mode: no textarea to focus, so instead
      `document.querySelector('#' + CSS.escape(entry.id))?.scrollIntoView({ behavior: 'smooth', block: 'start' })`
      against the rendered `.markdown-preview` DOM.

## Edge cases

- Empty note / no headings → drawer opens showing "No headings", button
  itself is never hidden.
- Duplicate heading text → deduped slugs (`-2`, `-3`, ...).
- `#` inside fenced code blocks → excluded by the fence-toggle scan.
- Heading text containing inline Markdown (`## **bold** heading`) → the
  outline list shows the raw text including markers (no inline-markdown
  stripping) for v1; acceptable since Obsidian's own outline does the
  same for edge-case inline syntax we don't need to special-case here.

## Testing

- Unit (`outline.spec.ts`): ATX headings at all 6 levels, skips headings
  inside fenced code blocks (both ` ``` ` and `~~~` fences), dedups
  colliding slugs, empty input → `[]`.
- Component (`OutlinePanel.spec.ts`): renders nested list at correct
  indent per level, empty state when `headings` is `[]`, click emits
  `jump-to-heading` with the right entry.
- `NoteEditor.spec.ts` additions: outline button toggles drawer open/close;
  clicking a heading row in `edit` mode moves textarea selection to the
  expected offset and calls `.focus()`; in `preview` mode calls
  `scrollIntoView` on the matching `#id` element.

## Out of scope

- Reordering/collapsing outline sections (Obsidian supports collapsing
  a heading's sub-tree; not needed for v1 — the list is short enough
  without it for typical notes).
- Wikilink `#heading` anchor *navigation* (only the `id` attribute
  plumbing is added here as a byproduct; wiring `[[note#heading]]` to
  actually scroll to it is separate, pre-existing, out-of-scope work).
