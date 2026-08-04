# Local Graph Panel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A right-drawer panel shows the current note's immediate neighbors (backlinks + resolved outgoing links) as a small radial graph, centered on the current note, closing gap G.3 of the Obsidian UI-parity audit (issue #289).

**Architecture:** A new pure `LocalGraphPanel.vue` (props in, `select-neighbor` event out, no owned state) renders an SVG radial layout — center node fixed, neighbors placed evenly around it. `NoteEditor.vue` computes the neighbor list from data it already has (`note.backlinks`, the existing `outgoingLinks` ref) — no new API calls — dedupes mutual links, and wires the panel into a right-drawer using the exact mechanical pattern the Outline drawer (G.1) already established.

**Tech Stack:** Vue 3 `<script setup lang="ts">`, inline SVG (same technique as the existing `GraphView.vue`, but with a genuinely different centered-radial layout), Vitest + `@vue/test-utils`.

## Global Constraints

- Working directory: `/home/ubuntu/projects/web/iroh/jotter`, branch `feature/local-graph` (spec commit `bf7bba3` already on this branch).
- Frontend root: `frontend/`; source: `frontend/src/`; component tests are flat files at `frontend/src/<Name>.spec.ts`.
- Test runner: `./scripts/jt.sh npm run test -- <file>.spec.ts` for one frontend file, `./scripts/jt.sh test` for the full combined suite.
- Commit style: lowercase `type: summary` (`feat:`), one commit per task, test + implementation together.
- **1-hop only, no new API calls** — build neighbors from `note.backlinks: Backlink[]` (already on the `NoteDetail` prop) and the existing `outgoingLinks: OutgoingLink[]` ref (already fetched via `getOutgoingLinks`, `NoteEditor.vue:868`). Do not add a fetch.
- **Unresolved outgoing links are excluded** — `OutgoingLink.resolved === false` entries never become neighbors.
- **Deviation from the spec's stated navigation mechanism, chosen while gathering context for this plan:** the spec said "reuse the existing `navigate-wikilink` emit." While mapping exact insertion points, `BacklinksPanel.vue` and `OutgoingLinksPanel.vue` (`NoteEditor.vue:408-419`) were found to already emit `select-note(noteId: number)` — an id-based emit `NoteEditor.vue` already re-emits upward (`NoteEditor.vue:479`), used by the *exact same two data sources* (backlinks and outgoing links) this feature also uses. `LocalGraphPanel` emits `select-neighbor(noteId: number)` and `NoteEditor.vue` re-emits it as `select-note`, matching its two sibling panels exactly rather than round-tripping through title-based wikilink resolution.
- `Backlink { id, path, title, target_ref? }` and `OutgoingLink { id: number | null, path: string | null, title: string | null, target_ref, target_block, resolved }` already exist at `frontend/src/services/types.ts:30-44` — do not redefine them.

---

### Task 1: `LocalGraphPanel.vue` component

**Files:**
- Modify: `frontend/src/services/types.ts` (add `LocalGraphNeighbor` interface — no test needed, it has no runtime behavior)
- Create: `frontend/src/components/LocalGraphPanel.vue`
- Test: `frontend/src/LocalGraphPanel.spec.ts`

**Interfaces:**
- Produces: `export interface LocalGraphNeighbor { id: number; title: string; path: string; direction: 'backlink' | 'outgoing' }` (in `types.ts`) and the `LocalGraphPanel` component — props `centerTitle: string`, `neighbors: LocalGraphNeighbor[]`; emits `select-neighbor(noteId: number)`. Consumed by Task 2 (`NoteEditor.vue`).

- [ ] **Step 1: Write the failing test**

```ts
// frontend/src/LocalGraphPanel.spec.ts
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import LocalGraphPanel from './components/LocalGraphPanel.vue'
import type { LocalGraphNeighbor } from './services/types'

const neighbors: LocalGraphNeighbor[] = [
  { id: 2, title: 'Backlinked Note', path: 'backlinked.md', direction: 'backlink' },
  { id: 3, title: 'Outgoing Note', path: 'outgoing.md', direction: 'outgoing' },
]

describe('LocalGraphPanel', () => {
  it('shows the empty state when there are no neighbors', () => {
    const wrapper = mount(LocalGraphPanel, { props: { centerTitle: 'Current Note', neighbors: [] } })
    expect(wrapper.text()).toContain('No connections yet.')
  })

  it('renders the center node plus one node per neighbor', () => {
    const wrapper = mount(LocalGraphPanel, { props: { centerTitle: 'Current Note', neighbors } })
    expect(wrapper.find('[data-testid="local-graph-center"]').text()).toContain('Current Note')
    expect(wrapper.findAll('[data-testid="local-graph-neighbor"]')).toHaveLength(2)
  })

  it('styles backlink and outgoing edges differently', () => {
    const wrapper = mount(LocalGraphPanel, { props: { centerTitle: 'Current Note', neighbors } })
    expect(wrapper.find('.local-graph-edge-backlink').exists()).toBe(true)
    expect(wrapper.find('.local-graph-edge-outgoing').exists()).toBe(true)
  })

  it('emits select-neighbor with the clicked neighbor id', async () => {
    const wrapper = mount(LocalGraphPanel, { props: { centerTitle: 'Current Note', neighbors } })
    await wrapper.findAll('[data-testid="local-graph-neighbor"]')[1].trigger('click')
    expect(wrapper.emitted('select-neighbor')![0]).toEqual([3])
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm run test -- LocalGraphPanel.spec.ts`
Expected: FAIL with "Failed to resolve import './components/LocalGraphPanel.vue'".

- [ ] **Step 3: Write minimal implementation**

Add to `frontend/src/services/types.ts`, right after the existing `OutgoingLink` interface (`:37-44`):

```ts
export interface LocalGraphNeighbor {
  id: number
  title: string
  path: string
  direction: 'backlink' | 'outgoing'
}
```

```vue
<!-- frontend/src/components/LocalGraphPanel.vue -->
<template>
  <div class="local-graph-panel">
    <div v-if="neighbors.length === 0" class="local-graph-empty">
      <p>No connections yet.</p>
    </div>
    <svg v-else class="local-graph-svg" :viewBox="`0 0 ${width} ${height}`">
      <line
        v-for="neighbor in positionedNeighbors"
        :key="`edge-${neighbor.id}`"
        :x1="centerX"
        :y1="centerY"
        :x2="neighbor.x"
        :y2="neighbor.y"
        class="local-graph-edge"
        :class="`local-graph-edge-${neighbor.direction}`"
      />

      <g class="local-graph-node-group local-graph-center" data-testid="local-graph-center">
        <circle :cx="centerX" :cy="centerY" r="20" class="local-graph-node-circle local-graph-center-circle" />
        <text :x="centerX" :y="centerY + 34" text-anchor="middle" class="local-graph-node-label">{{ centerTitle }}</text>
      </g>

      <g
        v-for="neighbor in positionedNeighbors"
        :key="neighbor.id"
        class="local-graph-node-group"
        data-testid="local-graph-neighbor"
        @click="$emit('select-neighbor', neighbor.id)"
      >
        <circle :cx="neighbor.x" :cy="neighbor.y" r="14" class="local-graph-node-circle" />
        <text :x="neighbor.x" :y="neighbor.y + 26" text-anchor="middle" class="local-graph-node-label">{{ neighbor.title }}</text>
      </g>
    </svg>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import type { LocalGraphNeighbor } from '../services/types'

const props = defineProps<{
  centerTitle: string
  neighbors: LocalGraphNeighbor[]
}>()

defineEmits<{
  (e: 'select-neighbor', noteId: number): void
}>()

const width = 320
const height = 320
const centerX = width / 2
const centerY = height / 2
const radius = Math.min(width, height) * 0.35

const positionedNeighbors = computed(() => {
  const count = props.neighbors.length
  if (count === 0) return []
  return props.neighbors.map((neighbor, index) => {
    const angle = (index / count) * 2 * Math.PI - Math.PI / 2
    return {
      ...neighbor,
      x: centerX + radius * Math.cos(angle),
      y: centerY + radius * Math.sin(angle),
    }
  })
})
</script>

<style scoped>
.local-graph-panel {
  padding: var(--space-3) var(--space-4);
}

.local-graph-empty {
  color: var(--color-text-muted);
  font-size: 0.875rem;
}

.local-graph-svg {
  width: 100%;
  height: auto;
}

.local-graph-edge {
  stroke: var(--color-border-strong);
  stroke-width: 1.5;
}

.local-graph-edge-backlink {
  stroke-dasharray: none;
}

.local-graph-edge-outgoing {
  stroke-dasharray: 4 2;
}

.local-graph-node-group {
  cursor: pointer;
}

.local-graph-center {
  cursor: default;
}

.local-graph-node-circle {
  fill: var(--color-surface);
  stroke: var(--color-action);
  stroke-width: 2;
}

.local-graph-center-circle {
  fill: var(--color-action);
  stroke: var(--color-border-strong);
}

.local-graph-node-group:not(.local-graph-center):hover .local-graph-node-circle {
  fill: var(--color-action);
  stroke: var(--color-border-strong);
}

.local-graph-node-label {
  fill: var(--color-text);
  font-size: 0.75rem;
  font-family: var(--font-sans);
}
</style>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm run test -- LocalGraphPanel.spec.ts`
Expected: PASS, all 4 cases green.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/services/types.ts frontend/src/components/LocalGraphPanel.vue frontend/src/LocalGraphPanel.spec.ts
git commit -m "feat: add LocalGraphPanel component"
```

---

### Task 2: Wire the local graph drawer into `NoteEditor.vue`

**Files:**
- Modify: `frontend/src/components/NoteEditor.vue`
- Modify: `frontend/src/NoteEditor.spec.ts`

**Interfaces:**
- Consumes: `LocalGraphNeighbor` type from Task 1 (`../services/types`), `LocalGraphPanel` from Task 1 (`./LocalGraphPanel.vue`), pre-existing `note.backlinks`, `outgoingLinks` ref, and `select-note` emit (`NoteEditor.vue:479`).
- Produces: nothing consumed by later tasks — this is the last implementation task.

- [ ] **Step 1: Write the failing test**

First, add `getOutgoingLinks` to the existing named import from `./services/api` near the top of `frontend/src/NoteEditor.spec.ts` (it's already in the `vi.mock` object with a default `mockResolvedValue([])`, but not yet imported by name for per-test overriding):

```ts
import { getNoteComments, setNoteProperty, deleteNoteProperty, addNoteComment, getNote, getOutgoingLinks } from './services/api'
```

Then append a new `describe` block at the end of the file:

```ts
describe('NoteEditor local graph', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    document.body.insertAdjacentHTML('beforeend', '<div id="app-right-drawer"></div>')
  })

  afterEach(() => {
    document.getElementById('app-right-drawer')?.remove()
  })

  it('does not render the drawer until toggled open', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
    })
    await flushPromises()

    expect(document.querySelector('[data-testid="local-graph-drawer"]')).toBeNull()
    wrapper.unmount()
  })

  it('opens the drawer and shows backlinks + resolved outgoing links as neighbors, excluding unresolved and deduping mutual links', async () => {
    ;(getOutgoingLinks as unknown as ReturnType<typeof vi.fn>).mockResolvedValue([
      { id: 2, path: 'ideas.md', title: 'Ideas', target_ref: 'Ideas', target_block: null, resolved: true },
      { id: null, path: null, title: null, target_ref: 'Missing', target_block: null, resolved: false },
    ])
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({
          backlinks: [
            { id: 2, path: 'ideas.md', title: 'Ideas' },
            { id: 3, path: 'projects.md', title: 'Projects' },
          ],
        }),
        allNotes: [],
        workspaceId: 1,
      },
      attachTo: document.body,
    })
    await flushPromises()

    await wrapper.find('[data-testid="local-graph-drawer-btn"]').trigger('click')
    const drawer = document.querySelector('[data-testid="local-graph-drawer"]')
    expect(drawer).not.toBeNull()

    const neighborNodes = drawer!.querySelectorAll('[data-testid="local-graph-neighbor"]')
    expect(neighborNodes).toHaveLength(2)

    wrapper.unmount()
  })

  it('clicking a neighbor node emits select-note with its id', async () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ backlinks: [{ id: 2, path: 'ideas.md', title: 'Ideas' }] }),
        allNotes: [],
        workspaceId: 1,
      },
      attachTo: document.body,
    })
    await flushPromises()
    await wrapper.find('[data-testid="local-graph-drawer-btn"]').trigger('click')

    ;(document.querySelector('[data-testid="local-graph-neighbor"]') as HTMLElement).click()
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('select-note')![0]).toEqual([2])

    wrapper.unmount()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm run test -- NoteEditor.spec.ts`
Expected: FAIL — no `[data-testid="local-graph-drawer-btn"]` in the rendered output.

- [ ] **Step 3: Write minimal implementation**

Add the import, right after the existing `import OutlinePanel from './OutlinePanel.vue'` (around `NoteEditor.vue:464`):

```ts
import LocalGraphPanel from './LocalGraphPanel.vue'
import type { LocalGraphNeighbor } from '../services/types'
```

Add state and the neighbor computed, right after the existing `const isOutlineDrawerOpen = ref(false)` (around `NoteEditor.vue:662`):

```ts
const isLocalGraphDrawerOpen = ref(false)

const localGraphNeighbors = computed<LocalGraphNeighbor[]>(() => {
  const seen = new Set<number>()
  const neighbors: LocalGraphNeighbor[] = []

  for (const backlink of props.note.backlinks || []) {
    if (seen.has(backlink.id)) continue
    seen.add(backlink.id)
    neighbors.push({ id: backlink.id, title: backlink.title, path: backlink.path, direction: 'backlink' })
  }

  for (const link of outgoingLinks.value) {
    if (!link.resolved || link.id === null) continue
    if (seen.has(link.id)) continue
    seen.add(link.id)
    neighbors.push({ id: link.id, title: link.title ?? link.path ?? '', path: link.path ?? '', direction: 'outgoing' })
  }

  return neighbors
})
```

(`outgoingLinks` is the pre-existing ref at `NoteEditor.vue:754`; `computed` is already imported from `vue`.)

Add the toggle button in the template, right after the existing Outline button (`NoteEditor.vue:105-113`), before the Comments button:

```html
<button
  class="btn-attach"
  data-testid="local-graph-drawer-btn"
  title="Local Graph"
  :aria-expanded="isLocalGraphDrawerOpen"
  @click="isLocalGraphDrawerOpen = !isLocalGraphDrawerOpen"
>
  <span>🕸️</span>
</button>
```

Add the teleported drawer, right after the existing Outline drawer's `</Teleport>` (`NoteEditor.vue:403`), before the Backlinks Panel comment:

```html
<!-- Local Graph Drawer: teleported to the same right-drawer mount point as
     Outline/Comments, showing the note's immediate neighbors (backlinks +
     resolved outgoing links) as a small radial graph (G.3, #289). -->
<Teleport to="#app-right-drawer">
  <aside
    v-if="isLocalGraphDrawerOpen"
    class="local-graph-drawer"
    data-testid="local-graph-drawer"
  >
    <div class="local-graph-drawer-header">
      <h3>Local Graph</h3>
      <button
        type="button"
        class="drawer-close-btn"
        data-testid="local-graph-drawer-close-btn"
        aria-label="Close local graph"
        @click="isLocalGraphDrawerOpen = false"
      >&times;</button>
    </div>
    <LocalGraphPanel
      :center-title="note.title"
      :neighbors="localGraphNeighbors"
      @select-neighbor="$emit('select-note', $event)"
    />
  </aside>
</Teleport>
```

Add CSS, right after the existing `.outline-drawer-header h3` rule near the end of the `<style>` block:

```css
.local-graph-drawer {
  position: fixed;
  top: 0;
  right: 0;
  height: 100vh;
  width: min(360px, 100vw);
  background: var(--color-surface);
  border-left: 1px solid var(--color-border);
  box-shadow: var(--shadow-float);
  z-index: 40;
  display: flex;
  flex-direction: column;
  overflow-y: auto;
}

@media (max-width: 480px) {
  .local-graph-drawer {
    width: 100vw;
  }
}

.local-graph-drawer-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--color-border);
}

.local-graph-drawer-header h3 {
  margin: 0;
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--color-text);
}
```

(`.drawer-close-btn` is already a shared class from the Comments/Outline drawers — reused as-is.)

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm run test -- NoteEditor.spec.ts`
Expected: PASS, including all pre-existing `NoteEditor.spec.ts` cases (regression check).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/NoteEditor.vue frontend/src/NoteEditor.spec.ts
git commit -m "feat: wire the local graph drawer into NoteEditor (G.3, #289)"
```

---

### Task 3: Full regression run and push

**Files:** none (verification task).

- [ ] **Step 1: Run the full frontend test suite**

Run: `./scripts/jt.sh npm test`
Expected: PASS across the whole frontend suite, not just the two files touched above.

- [ ] **Step 2: Run the full combined suite**

Run: `./scripts/jt.sh test`
Expected: backend + frontend both run. If the pre-existing, unrelated `ReleaseZipSecurityTest` failure (stray other-worktree paths leaking into the release zip scan — seen on the G.1/#292, G.2/#293, and G.5/#294 branches) reappears, that's expected and not a regression from this change; any other backend failure is not expected and must be investigated.

- [ ] **Step 3: Push the branch**

```bash
git push -u origin feature/local-graph
```

- [ ] **Step 4: Open a PR**

```bash
gh pr create --title "feat: add local graph panel (G.3, closes #289)" --body "$(cat <<'EOF'
## Summary
- New right-drawer panel shows the current note's immediate neighbors (backlinks + resolved outgoing links) as a small radial graph, centered on the current note
- Built entirely from data NoteEditor.vue already has (note.backlinks, the existing outgoingLinks fetch) — no new API calls
- Unresolved outgoing links excluded; a mutual link (both a backlink and an outgoing link) dedupes to a single node
- Clicking a neighbor node navigates to it, reusing the existing select-note emit (same mechanism BacklinksPanel/OutgoingLinksPanel already use) — a deviation from the design doc's originally proposed navigate-wikilink mechanism, made after finding select-note already established for this exact purpose

Closes #289. Source: docs/20260803-jotter-obsidian-ui-parity-audit.md §G.3, design: docs/superpowers/specs/2026-08-04-local-graph-panel-design.md, plan: docs/superpowers/plans/2026-08-04-local-graph-panel.md

## Test plan
- [x] Unit: LocalGraphPanel.spec.ts (4), NoteEditor.spec.ts local-graph cases (3) — full frontend suite passing
- [x] Backend: passing except the pre-existing unrelated ReleaseZipSecurityTest failure (same as G.1/#292, G.2/#293, G.5/#294)
- [ ] Manual: open a note with backlinks and outgoing links, toggle the local graph drawer, confirm the radial layout renders and clicking a neighbor navigates to it
EOF
)"
```

---

## Self-Review

**Spec coverage:** 1-hop-only + no-new-fetch (Task 2's computed reads `note.backlinks`/`outgoingLinks` directly), unresolved-links-excluded (Task 2's `!link.resolved || link.id === null` filter), new dedicated component not extending `GraphView.vue` (Task 1), centered radial layout distinct from `GraphView`'s all-on-one-circle approach (Task 1's `positionedNeighbors` computed, center fixed separately), direction-styled edges (Task 1's `local-graph-edge-<direction>` classes), mutual-link dedup (Task 2's `seen` Set), empty state (Task 1), right-drawer placement matching G.1's mechanical pattern (Task 2). Testing section of the spec is covered 1:1 across Tasks 1-2. Out-of-scope items (multi-hop, dangling nodes, force-directed layout, changes to `GraphView.vue`) are correctly absent from every task. The one deviation from the spec (select-note vs. navigate-wikilink) is called out explicitly in Global Constraints with its rationale, not silently substituted.

**Placeholder scan:** No TBD/TODO markers; every step has literal code.

**Type consistency:** `LocalGraphNeighbor` defined once in Task 1 (`services/types.ts`) and imported by name in Task 2 — no redefinition. `select-neighbor(noteId: number)` emit signature from Task 1 matches Task 2's `@select-neighbor="$emit('select-note', $event)"` handler (re-emitting the same `number` payload as the existing `select-note(noteId: number)` emit at `NoteEditor.vue:479`). `centerTitle`/`neighbors` props from Task 1 match Task 2's `:center-title="note.title"` / `:neighbors="localGraphNeighbors"` template usage.
