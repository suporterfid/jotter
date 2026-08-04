# Wikilink Hover Preview — Design

Date: 2026-08-04
Source: `docs/20260803-jotter-obsidian-ui-parity-audit.md` §G.2, issue #287
(reopened, was tracked-not-implemented, filed via #291).

## Problem

`MarkdownPreview.vue:41-46` handles `wikilink` clicks (navigate) and only
tints on hover (`:deep(.wikilink:hover)`). There is no popup showing the
target note's content on hover — the single most-used Obsidian
affordance for following a train of thought without leaving the page.

## Decisions

- **Popup content:** rendered Markdown (via the existing `renderMarkdown()`),
  truncated with a `max-height` box and a bottom fade-out, not raw text —
  matches how Obsidian's own popup looks.
- **Unresolved wikilinks** (target note doesn't exist) still get a popup:
  "No note yet — click to create '<target>'", matching this app's
  existing click-to-create behavior (`App.vue:897-899`) and Obsidian's
  own unresolved-link hover treatment.
- **No hover-into-popup interaction** for v1 — the popup dismisses
  immediately on `mouseleave` of the link, matching the audit's proposed
  fix as written ("dismiss on mouseleave/scroll/click-away").

## Architecture

- **`services/wikilinks.ts`** (new) — pure util:
  ```ts
  export function resolveWikilinkTarget(target: string, notes: NoteMeta[]): NoteMeta | undefined
  ```
  Extracted from `App.vue:886-893`'s inline matching logic (title match,
  path match, path+`.md` match, all case-insensitive lowercased
  comparison). `App.vue`'s `handleWikilinkNavigation` is refactored to
  call this util instead of repeating the match inline — same logic, no
  duplication, since this exact code is being touched anyway.

- **`MarkdownPreview.vue`** — gains delegated `mouseover`/`mouseout`
  handlers on `.markdown-preview`, mirroring the existing `click`
  delegation (`handlePreviewClick`), detecting `.wikilink` via
  `closest()`. On `mouseover` of a wikilink: starts a 300ms
  `setTimeout`; if it fires before a corresponding `mouseout`, emits
  `hover-wikilink(target: string, rect: DOMRect)` where `rect` is the
  anchor's `getBoundingClientRect()`. On `mouseout` of a wikilink: clears
  any pending timer and emits `unhover-wikilink()` unconditionally (safe
  no-op if nothing was hovering).

- **`NoteEditor.vue`** — owns hover state:
  ```ts
  interface HoveredPreview {
    rect: DOMRect
    resolved: { note: NoteMeta; content: string | null } | null // null content = still loading
    unresolvedTarget: string | null // set instead of `resolved` when no note matches
  }
  const hoveredPreview = ref<HoveredPreview | null>(null)
  ```
  A `Map<number, string>` (`noteContentCache`) caches fetched content by
  note id for the component's lifetime. On `hover-wikilink(target, rect)`:
  1. `resolveWikilinkTarget(target, props.allNotes)`.
  2. No match → `hoveredPreview.value = { rect, resolved: null, unresolvedTarget: target }`.
  3. Match, cache hit → `hoveredPreview.value = { rect, resolved: { note, content: cache.get(note.id) }, unresolvedTarget: null }`.
  4. Match, cache miss → set `hoveredPreview.value` immediately with
     `content: null` (so the popup can show a loading state), then
     `await getNote(workspaceId, note.id)`, cache the result, and — only
     if `hoveredPreview.value` still refers to this same note id (a
     stale response from a hover the user has already left must not
     clobber a newer one) — update `content`.

  On `unhover-wikilink` → `hoveredPreview.value = null`.

- **`WikilinkPreviewPopup.vue`** (new) — pure render component. Props:
  `rect: DOMRect`, `note: NoteMeta | null`, `content: string | null`,
  `unresolvedTarget: string | null`. No owned state, no emits besides
  none needed (dismissal is parent-driven).
  - Resolved + `content` loaded → renders `renderMarkdown(content)`
    inside a `max-height: 240px; overflow: hidden` box with a bottom
    `mask-image` fade.
  - Resolved + `content === null` (loading) → a small "Loading..." line.
  - `unresolvedTarget` set → "No note yet — click to create
    '`{{ unresolvedTarget }}`'".
  - Position: `position: fixed`, `top: rect.bottom + 4`, `left: rect.left`;
    clamp `left` so `left + popupWidth <= window.innerWidth`, flipping to
    `rect.right - popupWidth` when the default position would overflow
    (computed once on mount via the popup's own measured width, matching
    how `autocompleteStyle`/`slashMenuStyle` already position their
    dropdowns elsewhere in `NoteEditor.vue`).

## Dismiss behavior

- `unhover-wikilink` (mouseleave of the link) — immediate.
- Scroll of `.markdown-preview` or the edit textarea (split mode has
  both visible) — dismiss, since the anchor's `rect` goes stale the
  moment its container scrolls.
- No separate click-away handling: the popup only exists while actively
  hovering a link; clicking the link navigates via the existing
  `handlePreviewClick`, which unmounts the current note (and the popup
  with it) as a side effect of navigation.

## Edge cases

- Rapid mouse movement across several links before the 300ms timer
  fires — each `mouseover` restarts its own timer; only a link the user
  actually pauses on triggers a fetch, because a `mouseout` before the
  timer fires cancels it (no emit at all, not even a spurious
  `unhover-wikilink`).
- Same link hovered twice in a session — second hover is a cache hit
  (`noteContentCache.has(note.id)`), no network call.
- Target note deleted/renamed between hover and the `getNote()` response
  resolving — request fails, `hoveredPreview.value` (if still pointing
  at that note id) is simply left with `content: null` (stays on the
  loading state) rather than surfacing an error banner; this is a
  passive affordance, not a user-initiated action, so failures should be
  silent rather than interrupt.

## Testing

- Unit (`wikilinks.spec.ts`): `resolveWikilinkTarget` — match by title,
  by path, by path+`.md`, case-insensitivity, no match returns
  `undefined`.
- Component (`WikilinkPreviewPopup.spec.ts`): renders resolved-note
  content via `renderMarkdown`, renders the loading state when
  `content === null`, renders the unresolved "No note yet" state,
  position style reflects the given `rect`.
- `MarkdownPreview.spec.ts` additions: `mouseover` on a `.wikilink`
  followed by advancing fake timers 300ms emits `hover-wikilink` with
  the right target and a `DOMRect`; `mouseout` before 300ms elapses
  cancels the timer (no emit); `mouseout` after a successful hover emits
  `unhover-wikilink`.
- `NoteEditor.spec.ts` additions: hovering a resolved wikilink resolves
  via `allNotes` and fetches via a mocked `getNote`; hovering the same
  link twice calls the mock only once (cache); hovering an unresolved
  target shows the new-note popup variant without calling `getNote`.

## Out of scope

- Hovering into the popup itself to scroll/interact with it (Obsidian
  supports this; not needed for v1 per the audit's proposed fix).
- Prefetching hover targets ahead of time (e.g. on note load) — fetches
  stay lazy, triggered only by an actual hover.
