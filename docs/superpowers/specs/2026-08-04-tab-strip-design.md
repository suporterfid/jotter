# Tab Strip (Multi-Note Open, Single Active Pane) — Design

Date: 2026-08-04
Source: `docs/20260803-jotter-obsidian-ui-parity-audit.md` §G.4, issue #290
(reopened, was tracked-not-implemented, filed via #291).

## Problem

`App.vue:147` mounts exactly one `NoteEditor` instance; there is no tab
bar and no split-pane layout. Obsidian lets a user open several notes
side by side or in tabs and keeps that layout across sessions. Jotter
can show exactly one note at a time.

## Scope decision

G.4 splits into two genuinely separate projects:

- **A. Tab strip, one note visible at a time** (this spec) — multiple
  notes stay "open" as tabs; switching a tab unmounts the old
  `NoteEditor` and mounts the new one (browser-tab semantics). Fixes
  the audit's actual complaint without touching `NoteEditor.vue`'s
  internals.
- **B. True split-screen** (explicitly out of scope, separate future
  work) — multiple `NoteEditor` instances mounted *simultaneously*. Not
  attempted here: `document.getElementById(heading.id)`-style lookups
  already exist in `NoteEditor.vue` (G.1's outline-scroll, G.5's embed
  rendering) that would collide across two simultaneously-mounted panes
  showing notes with matching heading text; plus per-pane right-drawers,
  resizable layout, and drag-to-split are all real additional scope B
  would require.

## Decisions

- **Every `select-note` action opens/activates a tab** (reusing one if
  the note is already open) rather than replacing the active tab's
  content. No modifier-key UX needed anywhere — all ~8 existing
  `@select-note="handleSelectNote"` call sites across `App.vue`
  (Sidebar, GraphView, LocalGraphPanel, BacklinksPanel,
  OutgoingLinksPanel, search results, three collection views) keep
  working completely unchanged, since "ensure a tab exists" becomes
  `handleSelectNote`'s own behavior, not something each caller opts
  into.
- **Tabs store note *ids*, not paths** — a deliberate improvement over
  the audit's literal "array of open note paths" suggestion. `App.vue`
  already keeps `notes.value` (the id-keyed list backing the sidebar
  tree) in sync on every note load (existing comment at
  `App.vue:536-545`), so tab labels stay correct across renames for
  free; paths would have gone stale the moment a note was renamed.
- **Persisted to `localStorage`**, scoped per workspace id, same pattern
  `composables/useCollapsiblePanel.ts` already uses for its own state
  (`jotter-panel-collapsed:<key>` → here, `jotter-open-tabs:<workspaceId>`).

## Architecture

- **`composables/useOpenTabs.ts`** (new) — owns `openNoteIds: Ref<number[]>`.
  `App.vue`'s existing `activeNoteId` ref stays the single source of
  truth for "which tab is active"; the composable does not duplicate
  it.
  ```ts
  interface StoredTabs {
    openNoteIds: number[]
    activeNoteId: number | null
  }

  function useOpenTabs(): {
    openNoteIds: Ref<number[]>
    loadTabs(workspaceId: number): number | null // returns the restored active id, or null
    saveTabs(workspaceId: number, activeNoteId: number | null): void
    openTab(noteId: number): void // appends if not already present
    closeTab(noteId: number, activeNoteId: number | null): number | null
      // removes noteId; returns the id that should become active next:
      // - if the closed tab wasn't the active one, returns activeNoteId unchanged
      // - if it was active: left neighbor, else right neighbor, else null (no tabs left)
  }
  ```
  `loadTabs`/`saveTabs` degrade gracefully on missing or corrupt stored
  JSON (empty list, `null` active) rather than throwing.

- **`components/TabStrip.vue`** (new) — pure props-in/events-out, no
  owned state:
  ```ts
  defineProps<{
    tabs: { id: number; title: string }[]
    activeId: number | null
  }>()
  defineEmits<{
    (e: 'select-tab', noteId: number): void
    (e: 'close-tab', noteId: number): void
  }>()
  ```
  One item per tab, `active` styling on the current one, a close `×`
  button per tab (`@click.stop` so closing doesn't also select).

- **`App.vue`**:
  - `handleSelectNote(noteId)` gains one line —
    `openTab(noteId)` — before its existing body. Every other call site
    is unchanged.
  - `handleDeleteNote` additionally purges the deleted note from
    `openNoteIds` via the same `closeTab` path (whether or not it was
    the active tab), so a deleted note's tab never lingers as a ghost.
  - A `computed` derives the tab-strip's `tabs` prop from
    `openNoteIds.value.map(id => notes.value.find(n => n.id === id))`,
    filtering out any id no longer present in `notes.value` (e.g. a
    note deleted from another session/tab) rather than showing an
    "Untitled" ghost.
  - `<TabStrip>` renders as a sibling right above `<main>`, gated on a
    computed that's true only when tabs exist *and* none of the
    existing mutually-exclusive view flags (`isGraphViewActive`,
    `isAttachmentsActive`, `isSearchActive`, etc.) are active — tabs
    represent open *notes*, so they're hidden while another exclusive
    view (search, attachments, graph, ...) is showing, matching the
    verbose-but-explicit style `App.vue` already uses for these flags.
  - On workspace load, `loadTabs(workspaceId)` restores `openNoteIds`
    and the last-active note id; if that id still exists in the
    freshly-fetched notes list, `handleSelectNote` it, else fall back
    to the existing "select first note" behavior unchanged.
  - A `watch` on `[openNoteIds, activeNoteId]` calls `saveTabs` whenever
    either changes.

## Edge cases

- Closing the active tab → activates the left neighbor (or right, for
  the first tab), matching standard browser-tab convention; not asked
  as a design question since it's an unambiguous default, not a fork.
- Closing the last remaining tab → `activeNoteId`/`activeNoteDetail`
  both reset to `null`, falling into the existing "Empty State" branch
  (`App.vue`'s current `v-else` block) — no new empty-state UI needed.
- No unsaved-changes confirmation on tab close — `NoteEditor.vue`
  already autosaves (#269), so there is nothing to lose by closing.
- Restored `activeNoteId` from `localStorage` pointing at a note that
  no longer exists (deleted while the tab was closed in a prior
  session) → falls back to "select first note," same as the existing
  cold-start path when there's no stored state at all.

## Testing

- Unit (`useOpenTabs.spec.ts`): `openTab` dedups; `closeTab` returns the
  left neighbor when closing a middle/last active tab, the right
  neighbor when closing the first, `null` when closing the last
  remaining tab, and returns `activeNoteId` unchanged when closing a
  *non*-active tab; `loadTabs`/`saveTabs` round-trip through
  `localStorage`; `loadTabs` degrades gracefully on missing/corrupt
  stored JSON.
- Component (`TabStrip.spec.ts`): one item per tab, `active` class on
  the right one, clicking a tab emits `select-tab`, clicking its close
  button emits `close-tab` without also emitting `select-tab`.
- `App.spec.ts` additions: selecting the same note twice doesn't
  duplicate its tab; selecting a second note keeps the first tab
  present; closing the active tab switches to and loads a neighbor;
  closing the last tab clears to the empty state; deleting a note
  removes its tab.

## Out of scope

- True split-screen / simultaneously-mounted multi-pane editing (B,
  above) — separate future work, blocked on first fixing G.1/G.5's
  `document.getElementById`-based DOM lookups to be pane-scoped.
- Drag-to-reorder tabs.
- Any modifier-key ("open in new tab") interaction — every selection
  already opens/activates a tab per the Decisions section.
- Tab overflow/scrolling UI for very large numbers of open tabs — no
  cap or scroll affordance added; out of scope for v1, matching the L
  estimate's own ceiling.
