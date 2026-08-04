# Contextual/Local Graph Panel — Design

Date: 2026-08-04
Source: `docs/20260803-jotter-obsidian-ui-parity-audit.md` §G.3, issue #289
(reopened, was tracked-not-implemented, filed via #291).

## Problem

`GraphView.vue` is mounted once, globally, toggled via `App.vue`'s
`isGraphViewActive`, and shows the whole vault's graph. Obsidian also
offers a local graph scoped to the current note's immediate neighbors,
embeddable near the note. Jotter has no per-note graph — only the
all-or-nothing global view.

## Decisions

- **1-hop only** ("immediate neighbors," per the audit's own wording) —
  no N+1 fetches for a deeper hop. Built entirely from data
  `NoteEditor.vue` already has: `note.backlinks: Backlink[]` (already on
  the `NoteDetail` prop, no fetch) and the existing
  `outgoingLinks: OutgoingLink[]` ref (already fetched via
  `getOutgoingLinks`). No new API calls anywhere in this feature.
- **Unresolved (dangling) outgoing links are skipped**, not shown as
  ghost nodes. `OutgoingLink.resolved === false` entries have no `id` to
  navigate to or metadata to render — every node in the local graph is a
  real, clickable note.
- **New dedicated component**, not an extension of `GraphView.vue`.
  `GraphView`'s existing layout places all nodes on a single circle with
  no "center" concept, and its edges are currently a fake
  adjacent-node topology (`GraphView.vue:116-131`'s `graphEdges`
  computed connects circularly-adjacent nodes, not real link data) —
  bending it to also do a centered, real-edge radial layout would
  tangle two different jobs into one file. A local graph needs a
  genuinely different layout, so it gets its own small component.

## Architecture

- **`LocalGraphPanel.vue`** (new component, `frontend/src/components/`).
  Props:
  ```ts
  interface LocalGraphNeighbor {
    id: number
    title: string
    path: string
    direction: 'backlink' | 'outgoing'
  }
  defineProps<{
    centerTitle: string
    neighbors: LocalGraphNeighbor[]
  }>()
  defineEmits<{ (e: 'select-neighbor', title: string): void }>()
  ```
  Pure props-in/events-out, no owned state, no API calls — mirrors
  `OutlinePanel`/`WikilinkPreviewPopup`'s shape.
  - Layout: the center note is fixed at the SVG's center (visually
    distinguished — larger radius and/or an accent stroke); neighbors
    are placed evenly around it on a circle (radial "hub" layout,
    computed the same way `GraphView.vue:95-113`'s existing circular
    node-placement math works, just anchored around a fixed center node
    instead of no center at all).
  - Edges: a backlink neighbor's edge is styled distinctly from an
    outgoing neighbor's edge (e.g. arrowhead direction or a color/dash
    difference) — cheap, since each neighbor already carries its own
    `direction`, and it's a real signal Obsidian's own local graph shows
    too.
  - Empty state ("No connections yet") when `neighbors` is empty — same
    convention as `OutlinePanel`/`CommentsPanel`'s empty states.
  - Click a neighbor node → emits `select-neighbor(title)`.

- **`NoteEditor.vue`**:
  - New computed `localGraphNeighbors: LocalGraphNeighbor[]` —
    `note.backlinks.map(b => ({ id: b.id, title: b.title, path: b.path,
    direction: 'backlink' }))` concatenated with
    `outgoingLinks.value.filter(l => l.resolved).map(l => ({ id: l.id!,
    title: l.title!, path: l.path!, direction: 'outgoing' }))`, deduped
    by `id` (a mutual link — both a backlink and a resolved outgoing
    link — collapses to one node; first occurrence wins, so a mutual
    link displays as a `'backlink'`-styled edge, since backlinks are
    concatenated first — an arbitrary but deterministic tie-break, not
    worth a second edge per node for v1).
  - New `isLocalGraphDrawerOpen` ref, new "Local Graph" toggle button in
    `.editor-controls` (same mechanical pattern as the Outline button
    from G.1), new `<Teleport to="#app-right-drawer">` block (same shell
    as the Comments/Outline drawers).
  - `<LocalGraphPanel :center-title="note.title" :neighbors="localGraphNeighbors"
    @select-neighbor="target => $emit('navigate-wikilink', target)" />` —
    reuses the **existing** `navigate-wikilink` emit (already wired up
    through to `App.vue`'s `handleWikilinkNavigation`), so clicking a
    neighbor node needs zero new navigation plumbing.

## Edge cases

- Note has zero backlinks and zero resolved outgoing links → empty
  state, drawer button still always visible/enabled (navigation
  affordance, not a data panel — same reasoning as the Outline button in
  G.1, not omit-when-empty like Backlinks/OutgoingLinks panels).
- A note that links to itself (self-referential wikilink, if one somehow
  exists) → `note.id` would appear in `outgoingLinks` as a neighbor
  distinct from the center; not specifically filtered, since it's a
  genuinely rare/degenerate case and rendering a self-loop edge is
  harmless (not worth special-casing for v1).
- Very high neighbor counts (a heavily-linked note) → no pagination or
  crowding mitigation for v1; the radial layout simply gets denser. Out
  of scope — matches the audit's own effort estimate (M, not a
  force-directed/collision-avoiding layout engine).

## Testing

- Component (`LocalGraphPanel.spec.ts`): renders the center node plus
  one node per neighbor; a neighbor present in both `backlinks` and
  `outgoingLinks` (simulated via the *caller* pre-deduping, since dedup
  itself is `NoteEditor.vue`'s computed, not this component's job —
  this component just renders whatever `neighbors` it's given) renders
  once; empty `neighbors` shows the empty state; clicking a neighbor
  node emits `select-neighbor` with that neighbor's title.
- `NoteEditor.spec.ts` additions: the drawer button toggles open/close;
  the panel receives a `neighbors` array correctly built from
  `note.backlinks` + resolved entries of `outgoingLinks`, with an
  unresolved outgoing link excluded and a mutual link deduped to one
  entry; clicking a neighbor node in the mounted panel emits
  `navigate-wikilink` with the expected target.

## Out of scope

- Multi-hop (2+) neighbor expansion.
- Dangling/unresolved-link ghost nodes.
- Force-directed or collision-avoiding layout, drag-to-reposition,
  zoom/pan.
- Any change to the existing global `GraphView.vue` (including its own
  fake-edge-topology issue, noted here only for context on why this
  feature doesn't reuse that component — fixing that is separate,
  pre-existing, out-of-scope work).
