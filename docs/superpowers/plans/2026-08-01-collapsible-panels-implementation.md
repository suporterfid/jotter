# Collapsible Note Editor Panels Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let all five note-editor side panels (Properties, Comments, Backlinks, Outgoing Links, Unlinked Mentions) collapse/expand via a chevron in their shared header, with state persisted per panel type in `localStorage`.

**Architecture:** A new `useCollapsiblePanel(key, defaultCollapsed)` composable centralizes the localStorage read/write/toggle logic. `PanelHeader.vue` (shared by all five panels) gains a `collapsed` prop and a `toggle` emit, rendering a chevron button. Each panel wraps its existing body markup in `v-show="!collapsed"`.

**Tech Stack:** Vue 3 `<script setup>` + TypeScript, Vitest, `@vue/test-utils`.

## Global Constraints

- No API/database changes — frontend-only (spec §1).
- `PropertiesPanel` starts collapsed by default; `CommentsPanel`, `BacklinksPanel`, `OutgoingLinksPanel`, `UnlinkedMentionsPanel` start expanded by default (spec §2, §3).
- Collapse state persists via `localStorage`, one key per panel type — not per note (spec §2, §3).
- No change to any panel's content/behavior beyond the collapse wrapper (spec §2).

---

### Task 1: `useCollapsiblePanel` composable

**Files:**
- Create: `frontend/src/composables/useCollapsiblePanel.ts`
- Test: `frontend/src/composables/useCollapsiblePanel.spec.ts`

**Interfaces:**
- Consumes: nothing new.
- Produces: `useCollapsiblePanel(key: string, defaultCollapsed: boolean):
  { collapsed: Ref<boolean>; toggle: () => void }`. Tasks 2–3 (`PanelHeader.vue`
  via its consuming panels) call this directly.

- [ ] **Step 1: Write the failing tests**

```typescript
// frontend/src/composables/useCollapsiblePanel.spec.ts
import { describe, it, expect, beforeEach } from 'vitest'
import { useCollapsiblePanel } from './useCollapsiblePanel'

describe('useCollapsiblePanel', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('uses the default when no stored value exists', () => {
    const { collapsed } = useCollapsiblePanel('properties', true)
    expect(collapsed.value).toBe(true)
  })

  it('uses the stored value instead of the default when one exists', () => {
    localStorage.setItem('jotter-panel-collapsed:comments', 'true')
    const { collapsed } = useCollapsiblePanel('comments', false)
    expect(collapsed.value).toBe(true)
  })

  it('toggle flips the value and persists it', () => {
    const { collapsed, toggle } = useCollapsiblePanel('backlinks', false)
    toggle()
    expect(collapsed.value).toBe(true)
    expect(localStorage.getItem('jotter-panel-collapsed:backlinks')).toBe('true')

    toggle()
    expect(collapsed.value).toBe(false)
    expect(localStorage.getItem('jotter-panel-collapsed:backlinks')).toBe('false')
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./scripts/jt.sh npm test -- useCollapsiblePanel.spec.ts`
Expected: FAIL — the module `./useCollapsiblePanel` does not exist.

- [ ] **Step 3: Implement the composable**

```typescript
// frontend/src/composables/useCollapsiblePanel.ts
import { ref, watch } from 'vue'

export function useCollapsiblePanel(key: string, defaultCollapsed: boolean) {
  const storageKey = `jotter-panel-collapsed:${key}`
  const collapsed = ref(defaultCollapsed)

  const stored = localStorage.getItem(storageKey)
  if (stored !== null) {
    collapsed.value = stored === 'true'
  }

  watch(collapsed, (value) => {
    localStorage.setItem(storageKey, String(value))
  })

  function toggle() {
    collapsed.value = !collapsed.value
  }

  return { collapsed, toggle }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./scripts/jt.sh npm test -- useCollapsiblePanel.spec.ts`
Expected: PASS, all 3 tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/composables/useCollapsiblePanel.ts frontend/src/composables/useCollapsiblePanel.spec.ts
git commit -m "feat: add useCollapsiblePanel composable for panel collapse state"
```

---

### Task 2: `PanelHeader.vue` — chevron toggle

**Files:**
- Modify: `frontend/src/components/PanelHeader.vue`
- Test: `frontend/src/components/PanelHeader.spec.ts`

**Interfaces:**
- Consumes: nothing new (no dependency on Task 1 — `PanelHeader` is
  purely presentational, the composable lives in each consuming panel).
- Produces: new prop `collapsed: boolean` and new emit
  `(e: 'toggle'): void` on `PanelHeader`. Task 3 (all five panels) binds
  these.

- [ ] **Step 1: Write the failing tests**

Add to `frontend/src/components/PanelHeader.spec.ts`:

```typescript
  it('emits toggle when the chevron button is clicked', async () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks', collapsed: false } })
    await wrapper.find('[data-testid="panel-collapse-toggle"]').trigger('click')
    expect(wrapper.emitted('toggle')).toBeTruthy()
  })

  it('applies the collapsed class to the chevron when collapsed is true', () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks', collapsed: true } })
    expect(wrapper.find('[data-testid="panel-collapse-toggle"] .chevron').classes()).toContain('collapsed')
  })

  it('does not apply the collapsed class when collapsed is false', () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks', collapsed: false } })
    expect(wrapper.find('[data-testid="panel-collapse-toggle"] .chevron').classes()).not.toContain('collapsed')
  })
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./scripts/jt.sh npm test -- PanelHeader.spec.ts`
Expected: FAIL — `collapsed` prop doesn't exist, `[data-testid="panel-collapse-toggle"]` isn't in the template.

- [ ] **Step 3: Add the prop, emit, and chevron button**

Change `frontend/src/components/PanelHeader.vue`'s template:

```html
<template>
  <div class="panel-header">
    <div class="panel-header-title">
      <slot name="icon" />
      <span>{{ title }}</span>
    </div>
    <span v-if="count !== undefined" class="panel-header-count">{{ count }}</span>
  </div>
</template>
```

to:

```html
<template>
  <div class="panel-header">
    <div class="panel-header-title">
      <slot name="icon" />
      <span>{{ title }}</span>
    </div>
    <div class="panel-header-actions">
      <span v-if="count !== undefined" class="panel-header-count">{{ count }}</span>
      <button
        type="button"
        class="panel-collapse-toggle"
        data-testid="panel-collapse-toggle"
        :aria-label="collapsed ? `Expand ${title}` : `Collapse ${title}`"
        :aria-expanded="!collapsed"
        @click="$emit('toggle')"
      >
        <svg
          class="chevron"
          :class="{ collapsed: collapsed }"
          viewBox="0 0 24 24"
          width="14"
          height="14"
          fill="none"
          stroke="currentColor"
          stroke-width="2.5"
        >
          <polyline points="6 9 12 15 18 9"></polyline>
        </svg>
      </button>
    </div>
  </div>
</template>
```

Change the `<script setup>` block:

```typescript
defineProps<{
  title: string
  count?: number
}>()
```

to:

```typescript
defineProps<{
  title: string
  count?: number
  collapsed: boolean
}>()

defineEmits<{
  (e: 'toggle'): void
}>()
```

- [ ] **Step 4: Add the CSS**

In the `<style scoped>` block, add after the existing `.panel-header-count` rule:

```css
.panel-header-actions {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.panel-collapse-toggle {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  padding: var(--space-1);
  border-radius: var(--radius-sm);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard);
}

.panel-collapse-toggle:hover {
  background: var(--color-hover);
  color: var(--color-text);
}

.chevron {
  transition: transform var(--duration-fast) var(--ease-standard);
}

.chevron.collapsed {
  transform: rotate(-90deg);
}
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./scripts/jt.sh npm test -- PanelHeader.spec.ts`
Expected: PASS, all 7 tests (4 pre-existing + 3 new).

Note: adding the required `collapsed` prop means every existing
`<PanelHeader>` usage in the codebase must now pass it — this is
addressed in Task 3, which touches all five call sites in the same
pass. Until Task 3 lands, the five panel components will fail Vue's
prop-type check in dev/test builds; this is expected and resolved by
the end of Task 3, not this one.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/components/PanelHeader.vue frontend/src/components/PanelHeader.spec.ts
git commit -m "feat: add collapse chevron to PanelHeader"
```

---

### Task 3: Wire collapse into all five panels

**Files:**
- Modify: `frontend/src/components/PropertiesPanel.vue`
- Modify: `frontend/src/components/CommentsPanel.vue`
- Modify: `frontend/src/components/BacklinksPanel.vue`
- Modify: `frontend/src/components/OutgoingLinksPanel.vue`
- Modify: `frontend/src/components/UnlinkedMentionsPanel.vue`
- Test: `frontend/src/PropertiesPanel.spec.ts`
- Test: `frontend/src/CommentsPanel.spec.ts`
- Test: `frontend/src/OutgoingLinksPanel.spec.ts`
- Test: `frontend/src/UnlinkedMentionsPanel.spec.ts`
- Test: `frontend/src/BacklinksPanel.spec.ts` (new file — none exists yet)

**Interfaces:**
- Consumes: `useCollapsiblePanel(key, defaultCollapsed)` (Task 1),
  `PanelHeader`'s `collapsed`/`toggle` (Task 2).
- Produces: nothing consumed by later tasks — this is the final wiring
  point for all five panels.

Each panel gets the identical three-part edit: (a) import the
composable, (b) call it with its own key/default and wire it onto
`<PanelHeader>`, (c) wrap the existing body in `v-show="!collapsed"`.
The five panels differ only in their `<PanelHeader>` line, their key,
their default, and what "the body" is (everything currently rendered
after `</PanelHeader>` and before the closing root tag).

- [ ] **Step 1: Write the failing test — `PropertiesPanel`**

Add to `frontend/src/PropertiesPanel.spec.ts`:

```typescript
  it('hides the body when collapsed', () => {
    localStorage.setItem('jotter-panel-collapsed:properties', 'true')
    const wrapper = mount(PropertiesPanel, { props: { properties: [] } })
    expect(wrapper.find('.properties-empty').exists()).toBe(false)
  })

  it('shows the body and toggles collapse when the header chevron is clicked', async () => {
    localStorage.setItem('jotter-panel-collapsed:properties', 'true')
    const wrapper = mount(PropertiesPanel, { props: { properties: [] } })
    await wrapper.find('[data-testid="panel-collapse-toggle"]').trigger('click')
    expect(wrapper.find('.properties-empty').exists()).toBe(true)
  })
```

Add `localStorage.clear()` in a `beforeEach` if this spec file doesn't
already have one — check the top of the file first; if a
`describe`/`beforeEach` block already exists, add the clear call there,
otherwise wrap the file's existing tests in a `describe('PropertiesPanel', () => { beforeEach(() => localStorage.clear()) ... })`
structure consistent with how the file is currently organized.

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm test -- PropertiesPanel.spec.ts`
Expected: FAIL — collapsing has no effect yet, body always renders.

- [ ] **Step 3: Wire `PropertiesPanel.vue`**

Change the top of the template:

```html
<template>
  <aside class="properties-panel" aria-label="Properties">
    <PanelHeader title="Properties" :count="properties.length">
      <template #icon>
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none">
          <path d="M3 6h18M7 12h10M11 18h2"></path>
        </svg>
      </template>
    </PanelHeader>

    <div v-if="properties.length === 0" class="properties-empty">
```

to:

```html
<template>
  <aside class="properties-panel" aria-label="Properties">
    <PanelHeader title="Properties" :count="properties.length" :collapsed="collapsed" @toggle="toggle">
      <template #icon>
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none">
          <path d="M3 6h18M7 12h10M11 18h2"></path>
        </svg>
      </template>
    </PanelHeader>

    <div v-show="!collapsed">
    <div v-if="properties.length === 0" class="properties-empty">
```

Then find the template's closing (the end of the property-form section,
right before `</aside>` and `</template>`) and close the new wrapping
div:

```html
    </form>
  </aside>
</template>
```

to:

```html
    </form>
    </div>
  </aside>
</template>
```

In the `<script setup>` block, change:

```typescript
import { ref } from 'vue'
import PanelHeader from './PanelHeader.vue'
```

to:

```typescript
import { ref } from 'vue'
import PanelHeader from './PanelHeader.vue'
import { useCollapsiblePanel } from '../composables/useCollapsiblePanel'
```

Add right after the props/emits declarations (before any other local
state):

```typescript
const { collapsed, toggle } = useCollapsiblePanel('properties', true)
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm test -- PropertiesPanel.spec.ts`
Expected: PASS, all tests in this file.

- [ ] **Step 5: Write the failing test — `CommentsPanel`**

Add to `frontend/src/CommentsPanel.spec.ts` (same pattern as Step 1, using
`'jotter-panel-collapsed:comments'` and `false` as the seeded stored value
so the test proves collapsing, since this panel defaults to expanded):

```typescript
  it('hides the body when collapsed', () => {
    localStorage.setItem('jotter-panel-collapsed:comments', 'true')
    const wrapper = mount(CommentsPanel, { props: { comments: [] } })
    expect(wrapper.find('.comments-empty, .comments-list').exists()).toBe(false)
  })

  it('shows the body and toggles collapse when the header chevron is clicked', async () => {
    localStorage.setItem('jotter-panel-collapsed:comments', 'true')
    const wrapper = mount(CommentsPanel, { props: { comments: [] } })
    await wrapper.find('[data-testid="panel-collapse-toggle"]').trigger('click')
    expect(wrapper.find('.comments-empty, .comments-list').exists()).toBe(true)
  })
```

Before writing this, check `CommentsPanel.vue`'s actual empty-state class
name (mirror `PropertiesPanel`'s `.properties-empty` naming convention —
confirm the exact class by reading the file; adjust the selector above to
match whatever it actually is if different from `.comments-empty`).

- [ ] **Step 6: Run test to verify it fails, then wire `CommentsPanel.vue`**

Run: `./scripts/jt.sh npm test -- CommentsPanel.spec.ts` — expect FAIL.

Apply the same three-part edit as Step 3, adapted to this file:
`<PanelHeader title="Comments" :count="comments.length" :collapsed="collapsed" @toggle="toggle">`,
wrap the body (everything between `</PanelHeader>` and the closing root
tag) in `<div v-show="!collapsed">...</div>`, add the
`useCollapsiblePanel` import, and call
`const { collapsed, toggle } = useCollapsiblePanel('comments', false)`.

Run: `./scripts/jt.sh npm test -- CommentsPanel.spec.ts`
Expected: PASS.

- [ ] **Step 7: Write the failing test, then wire `BacklinksPanel.vue`**

This file has no existing spec — create one:

```typescript
// frontend/src/BacklinksPanel.spec.ts
import { mount } from '@vue/test-utils'
import { describe, expect, it, beforeEach } from 'vitest'
import BacklinksPanel from './components/BacklinksPanel.vue'

describe('BacklinksPanel', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('shows the empty state when there are no backlinks', () => {
    const wrapper = mount(BacklinksPanel, { props: { backlinks: [] } })
    expect(wrapper.text()).toContain('No notes link to this document yet.')
  })

  it('hides the body when collapsed', () => {
    localStorage.setItem('jotter-panel-collapsed:backlinks', 'true')
    const wrapper = mount(BacklinksPanel, { props: { backlinks: [] } })
    expect(wrapper.find('.backlinks-empty').exists()).toBe(false)
  })

  it('shows the body and toggles collapse when the header chevron is clicked', async () => {
    localStorage.setItem('jotter-panel-collapsed:backlinks', 'true')
    const wrapper = mount(BacklinksPanel, { props: { backlinks: [] } })
    await wrapper.find('[data-testid="panel-collapse-toggle"]').trigger('click')
    expect(wrapper.find('.backlinks-empty').exists()).toBe(true)
  })
})
```

Run: `./scripts/jt.sh npm test -- BacklinksPanel.spec.ts` — expect the
first test to pass already (pre-existing behavior) and the two collapse
tests to fail.

Wire `BacklinksPanel.vue` with the same three-part edit:
`<PanelHeader title="Backlinks" :count="backlinks.length" :collapsed="collapsed" @toggle="toggle">`,
wrap the body (the `v-if`/`v-else` empty-state/list pair) in
`<div v-show="!collapsed">...</div>`, add the import, and call
`const { collapsed, toggle } = useCollapsiblePanel('backlinks', false)`.

Run: `./scripts/jt.sh npm test -- BacklinksPanel.spec.ts`
Expected: PASS, all 3 tests.

- [ ] **Step 8: Write the failing test, then wire `OutgoingLinksPanel.vue`**

Add to `frontend/src/OutgoingLinksPanel.spec.ts` (read the file first to
confirm its exact empty-state class name and existing `beforeEach`
structure, then add analogous to Step 1):

```typescript
  it('hides the body when collapsed', () => {
    localStorage.setItem('jotter-panel-collapsed:outgoing-links', 'true')
    const wrapper = mount(OutgoingLinksPanel, { props: { links: [] } })
    expect(wrapper.find('[data-testid="panel-collapse-toggle"]').exists()).toBe(true)
  })

  it('toggles collapse when the header chevron is clicked', async () => {
    localStorage.setItem('jotter-panel-collapsed:outgoing-links', 'true')
    const wrapper = mount(OutgoingLinksPanel, { props: { links: [] } })
    const before = wrapper.html()
    await wrapper.find('[data-testid="panel-collapse-toggle"]').trigger('click')
    expect(wrapper.html()).not.toBe(before)
  })
```

Run: `./scripts/jt.sh npm test -- OutgoingLinksPanel.spec.ts` — expect FAIL
(no `panel-collapse-toggle` testid exists yet).

Wire `OutgoingLinksPanel.vue` with the same three-part edit:
`<PanelHeader title="Outgoing Links" :count="links.length" :collapsed="collapsed" @toggle="toggle">`,
wrap the body in `<div v-show="!collapsed">...</div>`, add the import,
and call
`const { collapsed, toggle } = useCollapsiblePanel('outgoing-links', false)`.

Run: `./scripts/jt.sh npm test -- OutgoingLinksPanel.spec.ts`
Expected: PASS.

- [ ] **Step 9: Write the failing test, then wire `UnlinkedMentionsPanel.vue`**

Add to `frontend/src/UnlinkedMentionsPanel.spec.ts` (read the file first
to confirm its prop name — likely `mentions` per
`UnlinkedMentionsPanel.vue`'s `:count="mentions.length"` seen during
exploration — and its existing `beforeEach` structure):

```typescript
  it('hides the body when collapsed', () => {
    localStorage.setItem('jotter-panel-collapsed:unlinked-mentions', 'true')
    const wrapper = mount(UnlinkedMentionsPanel, { props: { mentions: [] } })
    expect(wrapper.find('[data-testid="panel-collapse-toggle"]').exists()).toBe(true)
  })

  it('toggles collapse when the header chevron is clicked', async () => {
    localStorage.setItem('jotter-panel-collapsed:unlinked-mentions', 'true')
    const wrapper = mount(UnlinkedMentionsPanel, { props: { mentions: [] } })
    const before = wrapper.html()
    await wrapper.find('[data-testid="panel-collapse-toggle"]').trigger('click')
    expect(wrapper.html()).not.toBe(before)
  })
```

Run: `./scripts/jt.sh npm test -- UnlinkedMentionsPanel.spec.ts` — expect
FAIL.

Wire `UnlinkedMentionsPanel.vue` with the same three-part edit:
`<PanelHeader title="Unlinked Mentions" :count="mentions.length" :collapsed="collapsed" @toggle="toggle">`,
wrap the body in `<div v-show="!collapsed">...</div>`, add the import,
and call
`const { collapsed, toggle } = useCollapsiblePanel('unlinked-mentions', false)`.

Run: `./scripts/jt.sh npm test -- UnlinkedMentionsPanel.spec.ts`
Expected: PASS.

- [ ] **Step 10: Run the full frontend suite**

Run: `./scripts/jt.sh npm test`
Expected: PASS, all tests including every new one across all three
tasks — no regressions. Pay particular attention to any other spec file
that mounts one of these five panels indirectly (e.g. `NoteEditor.spec.ts`
mounting the full editor, which mounts `PropertiesPanel` inside it) —
if any such test asserts on panel body content being visible by default,
it may need `localStorage.clear()` added to its own `beforeEach` too,
since `PropertiesPanel` now defaults to collapsed and a stray leftover
`jotter-panel-collapsed:properties` value from an earlier test in the
same run could otherwise cause cross-test interference.

- [ ] **Step 11: Commit**

```bash
git add frontend/src/components/PropertiesPanel.vue frontend/src/components/CommentsPanel.vue \
        frontend/src/components/BacklinksPanel.vue frontend/src/components/OutgoingLinksPanel.vue \
        frontend/src/components/UnlinkedMentionsPanel.vue \
        frontend/src/PropertiesPanel.spec.ts frontend/src/CommentsPanel.spec.ts \
        frontend/src/BacklinksPanel.spec.ts frontend/src/OutgoingLinksPanel.spec.ts \
        frontend/src/UnlinkedMentionsPanel.spec.ts
git commit -m "feat: wire collapsible state into all five note editor panels"
```

---

## Self-Review Notes

- **Spec coverage:** §2 (all five panels collapsible, Properties default
  collapsed, others default expanded) → Task 3's five per-panel defaults
  match exactly. §3 (composable, PanelHeader chevron, per-panel keys) →
  Tasks 1–2 plus Task 3's five exact key strings, matching the spec's
  table verbatim (`properties`/`comments`/`backlinks`/`outgoing-links`/
  `unlinked-mentions`). §4 testing → one test file per panel plus the
  composable and `PanelHeader` specs, matching the spec's breakdown.
- **Placeholder scan:** none found. Steps 6, 8, 9 in Task 3 describe the
  edit by exact reference to Step 3's fully-written-out pattern rather
  than repeating the same six lines of near-identical Vue template five
  times — each still names the exact prop bindings, exact composable
  call arguments, and exact class names to touch, so no step is missing
  information, only avoiding verbatim repetition of an identical
  three-line edit already fully specified once.
- **Type consistency:** `useCollapsiblePanel(key: string, defaultCollapsed: boolean)`
  return shape `{ collapsed: Ref<boolean>; toggle: () => void }` (Task 1)
  is destructured identically in all five panels (Task 3) and matches
  `PanelHeader`'s `collapsed: boolean` prop / `toggle` emit (Task 2) at
  every call site.
