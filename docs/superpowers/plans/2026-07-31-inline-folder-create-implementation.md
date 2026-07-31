# Inline Per-Folder Quick Create Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a hover-reveal "+" button on each sidebar folder row that creates a new note directly inside that folder, auto-expanding the folder and selecting the new note.

**Architecture:** `NoteTreeNode.vue` emits a new `create-note-in-folder` event bubbled up through `Sidebar.vue` to `App.vue`, which prefixes an auto-generated filename with the folder path and delegates to the existing `handleCreateNote` (unchanged, already does create → refresh → select).

**Tech Stack:** Vue 3 `<script setup>` + TypeScript, Vitest, `@vue/test-utils`.

## Global Constraints

- No new API endpoint or backend change — reuses the existing note-create endpoint via `handleCreateNote` (spec §1).
- "+" appears only on folder rows, never on note rows and never on the root "All Notes" header (spec §2).
- No inline rename prompt after creation — filename follows the existing `untitled-<base36 timestamp>.md` pattern used by `CommandPalette`'s quick-create (spec §2, §3).
- Clicking "+" must not toggle the folder's own expand/collapse state via the parent row's click handler — it has its own `@click.stop` (spec §3).
- Clicking "+" on a collapsed folder must expand it (spec §3).

---

### Task 1: `NoteTreeNode.vue` — hover "+" button, new emit, auto-expand

**Files:**
- Modify: `frontend/src/components/NoteTreeNode.vue`
- Test: `frontend/src/NoteTreeNode.spec.ts`

**Interfaces:**
- Consumes: nothing new — pure template/script addition to the existing component.
- Produces: new emit `(e: 'create-note-in-folder', folderPath: string): void`, which `Sidebar.vue` (Task 2) listens for on both the recursive `<NoteTreeNode>` (nested folders) and the root-level `<NoteTreeNode>` in its own template.

- [ ] **Step 1: Write the failing tests**

Add to `frontend/src/NoteTreeNode.spec.ts` (existing file — these are new `it()` blocks inside the existing `describe('NoteTreeNode drag attributes', ...)` block is the wrong place; add a new sibling `describe`):

```typescript
describe('NoteTreeNode folder quick-create', () => {
  function makeFolderNode(overrides: Partial<TreeNode> = {}): TreeNode {
    return {
      type: 'folder',
      name: 'docs',
      fullPath: 'docs',
      children: [],
      ...overrides,
    } as TreeNode
  }

  it('emits create-note-in-folder with the folder\'s fullPath when + is clicked', async () => {
    const wrapper = mount(NoteTreeNode, {
      props: { node: makeFolderNode(), selectedNoteId: null, depth: 0 },
    })
    await wrapper.find('[data-testid="folder-create-note-btn"]').trigger('click')
    expect(wrapper.emitted('create-note-in-folder')).toEqual([['docs']])
  })

  it('does not toggle collapse when + is clicked on an expanded folder', async () => {
    const wrapper = mount(NoteTreeNode, {
      props: {
        node: makeFolderNode({
          children: [
            { type: 'file', note: { id: 1, path: 'docs/a.md', title: 'A', frontmatter: null, sort_position: null, updated_at: '2026-07-31T00:00:00Z' } },
          ],
        }),
        selectedNoteId: null,
        depth: 0,
      },
    })
    await wrapper.find('[data-testid="folder-create-note-btn"]').trigger('click')
    const children = wrapper.find('.folder-children')
    expect((children.element as HTMLElement).style.display).not.toBe('none')
  })

  it('expands a collapsed folder when + is clicked', async () => {
    const wrapper = mount(NoteTreeNode, {
      props: {
        node: makeFolderNode({
          children: [
            { type: 'file', note: { id: 1, path: 'docs/a.md', title: 'A', frontmatter: null, sort_position: null, updated_at: '2026-07-31T00:00:00Z' } },
          ],
        }),
        selectedNoteId: null,
        depth: 0,
      },
    })
    // Collapse it first (starts expanded by default)
    await wrapper.find('.folder-row').trigger('click')
    expect((wrapper.find('.folder-children').element as HTMLElement).style.display).toBe('none')

    await wrapper.find('[data-testid="folder-create-note-btn"]').trigger('click')
    expect((wrapper.find('.folder-children').element as HTMLElement).style.display).not.toBe('none')
    expect(wrapper.emitted('create-note-in-folder')).toEqual([['docs']])
  })

  it('bubbles a nested folder\'s + click up through the parent NoteTreeNode unchanged', async () => {
    const nested: TreeNode = makeFolderNode({
      name: 'archived',
      fullPath: 'docs/archived',
      children: [],
    })
    const wrapper = mount(NoteTreeNode, {
      props: { node: makeFolderNode({ children: [nested] }), selectedNoteId: null, depth: 0 },
    })
    const nestedBtn = wrapper.findAll('[data-testid="folder-create-note-btn"]')[1]
    await nestedBtn.trigger('click')
    expect(wrapper.emitted('create-note-in-folder')).toEqual([['docs/archived']])
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./scripts/jt.sh npm test -- NoteTreeNode.spec.ts`
Expected: FAIL — `[data-testid="folder-create-note-btn"]` does not exist yet.

- [ ] **Step 3: Restructure the folder row and add the button**

`.folder-row` is currently a `<button>` (toggles expand/collapse on click).
A `<button>` cannot contain another `<button>` per HTML semantics, so the
new "+" button must be a **sibling** of `.folder-row`, not nested inside
it. Wrap both in a new flex container.

In `frontend/src/components/NoteTreeNode.vue`, change:

```html
  <div
    v-if="node.type === 'folder'"
    class="tree-folder"
    data-item-type="folder"
    :data-item-path="node.fullPath"
  >
    <button
      type="button"
      class="folder-row"
      :style="{ paddingLeft: `${depth * 14 + 8}px` }"
      :aria-expanded="expanded"
      @click="expanded = !expanded"
    >
      <svg
        class="chevron"
        :class="{ collapsed: !expanded }"
        viewBox="0 0 24 24"
        width="12"
        height="12"
        fill="none"
        stroke="currentColor"
        stroke-width="2.5"
      >
        <polyline points="9 6 15 12 9 18"></polyline>
      </svg>
      <svg class="folder-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"></path>
      </svg>
      <span class="folder-name">{{ node.name }}</span>
      <span class="folder-count">{{ noteCount }}</span>
    </button>
    <div v-show="expanded" class="folder-children" ref="childrenListRef" :data-folder-path="node.fullPath">
```

to:

```html
  <div
    v-if="node.type === 'folder'"
    class="tree-folder"
    data-item-type="folder"
    :data-item-path="node.fullPath"
  >
    <div class="folder-row-wrapper">
      <button
        type="button"
        class="folder-row"
        :style="{ paddingLeft: `${depth * 14 + 8}px` }"
        :aria-expanded="expanded"
        @click="expanded = !expanded"
      >
        <svg
          class="chevron"
          :class="{ collapsed: !expanded }"
          viewBox="0 0 24 24"
          width="12"
          height="12"
          fill="none"
          stroke="currentColor"
          stroke-width="2.5"
        >
          <polyline points="9 6 15 12 9 18"></polyline>
        </svg>
        <svg class="folder-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V7z"></path>
        </svg>
        <span class="folder-name">{{ node.name }}</span>
        <span class="folder-count">{{ noteCount }}</span>
      </button>
      <button
        type="button"
        class="btn-create-in-folder"
        data-testid="folder-create-note-btn"
        title="Create note in this folder"
        aria-label="Create note in this folder"
        @click.stop="createNoteInFolder"
      >
        <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="12" y1="5" x2="12" y2="19"></line>
          <line x1="5" y1="12" x2="19" y2="12"></line>
        </svg>
      </button>
    </div>
    <div v-show="expanded" class="folder-children" ref="childrenListRef" :data-folder-path="node.fullPath">
```

(The `.folder-children` div and everything inside it — the recursive
`<NoteTreeNode>` list — is unchanged in this step; Task 1 Step 4 adds the
bubble-up wiring to it.)

- [ ] **Step 4: Add the emit, handler, and bubble-up wiring**

Change the `defineEmits` call:

```typescript
defineEmits<{
  (e: 'select-note', noteId: number): void
  (e: 'delete-note', noteId: number): void
}>()
```

to:

```typescript
const emit = defineEmits<{
  (e: 'select-note', noteId: number): void
  (e: 'delete-note', noteId: number): void
  (e: 'create-note-in-folder', folderPath: string): void
}>()
```

Add this function next to `noteIcon` (or any other computed/function in
the `<script setup>` block):

```typescript
function createNoteInFolder() {
  expanded.value = true
  if (props.node.type === 'folder') {
    emit('create-note-in-folder', props.node.fullPath)
  }
}
```

Update the recursive `<NoteTreeNode>` invocation inside `.folder-children`
to bubble the new event up:

```html
      <NoteTreeNode
        v-for="child in node.children"
        :key="child.type === 'folder' ? `f:${child.fullPath}` : `n:${child.note.id}`"
        :node="child"
        :selected-note-id="selectedNoteId"
        :depth="depth + 1"
        @select-note="$emit('select-note', $event)"
        @delete-note="$emit('delete-note', $event)"
        @create-note-in-folder="$emit('create-note-in-folder', $event)"
      />
```

- [ ] **Step 5: Add the hover-reveal CSS**

In the `<style scoped>` block, change `.folder-row`'s `width: 100%` to
`flex: 1; min-width: 0;` (it now sits inside a flex wrapper instead of
being the full-width element itself):

```css
.folder-row {
  width: 100%;
  display: flex;
```

to:

```css
.folder-row {
  flex: 1;
  min-width: 0;
  display: flex;
```

Add a new `.folder-row-wrapper` rule right before `.folder-row` and a
`.btn-create-in-folder` rule right after `.folder-count`'s rule:

```css
.folder-row-wrapper {
  display: flex;
  align-items: center;
}
```

```css
.btn-create-in-folder {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  padding: var(--space-1);
  border-radius: var(--radius-sm);
  cursor: pointer;
  opacity: 0;
  flex-shrink: 0;
  margin-right: var(--space-1);
  transition: color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard),
              opacity var(--duration-fast) var(--ease-standard);
  min-width: 28px;
  min-height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.folder-row-wrapper:hover .btn-create-in-folder {
  opacity: 1;
}

@media (max-width: 768px) {
  .btn-create-in-folder {
    opacity: 1;
    min-width: 44px;
    min-height: 44px;
  }
}

.btn-create-in-folder:hover {
  color: var(--color-action);
  background: var(--color-hover);
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `./scripts/jt.sh npm test -- NoteTreeNode.spec.ts`
Expected: PASS — the 4 new tests plus the pre-existing tests in this file
all green.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/components/NoteTreeNode.vue frontend/src/NoteTreeNode.spec.ts
git commit -m "feat: add hover + button to create a note inside a sidebar folder"
```

---

### Task 2: `Sidebar.vue` — bubble the event to the root

**Files:**
- Modify: `frontend/src/components/Sidebar.vue`
- Test: `frontend/src/Sidebar.spec.ts`

**Interfaces:**
- Consumes: `create-note-in-folder` emit from `NoteTreeNode` (Task 1).
- Produces: `Sidebar` re-emits the same `create-note-in-folder` event one
  level up, for `App.vue` (Task 3) to listen to.

- [ ] **Step 1: Write the failing test**

Add to `frontend/src/Sidebar.spec.ts` (existing file, add as a new `it()`
inside the existing `describe('Sidebar manual sort mode', ...)` block, or
as its own sibling `describe` — use a sibling block since this isn't
about sort mode):

```typescript
describe('Sidebar folder quick-create', () => {
  it('bubbles create-note-in-folder from a root-level folder up to its own emit', async () => {
    const notes: NoteMeta[] = [
      makeNote({ id: 1, path: 'docs/a.md', title: 'A' }),
    ]
    const wrapper = mount(Sidebar, {
      props: { notes, selectedNoteId: null, workspaceId: 1, folderPositions: [] },
    })
    await wrapper.find('[data-testid="folder-create-note-btn"]').trigger('click')
    expect(wrapper.emitted('create-note-in-folder')).toEqual([['docs']])
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm test -- Sidebar.spec.ts`
Expected: FAIL — `Sidebar` doesn't declare or wire the `create-note-in-folder` emit yet.

- [ ] **Step 3: Add the emit and wire the root `<NoteTreeNode>`**

Change the `defineEmits` call (find the line ending `(e: 'notes-reordered'): void` and add one more entry):

```typescript
  (e: 'notes-reordered'): void
}>()
```

to:

```typescript
  (e: 'notes-reordered'): void
  (e: 'create-note-in-folder', folderPath: string): void
}>()
```

Update the root-level `<NoteTreeNode>` in the template:

```html
        <NoteTreeNode
          v-for="node in noteTree"
          :key="node.type === 'folder' ? `f:${node.fullPath}` : `n:${node.note.id}`"
          :node="node"
          :selected-note-id="selectedNoteId"
          :depth="0"
          @select-note="$emit('select-note', $event)"
          @delete-note="$emit('delete-note', $event)"
        />
```

to:

```html
        <NoteTreeNode
          v-for="node in noteTree"
          :key="node.type === 'folder' ? `f:${node.fullPath}` : `n:${node.note.id}`"
          :node="node"
          :selected-note-id="selectedNoteId"
          :depth="0"
          @select-note="$emit('select-note', $event)"
          @delete-note="$emit('delete-note', $event)"
          @create-note-in-folder="$emit('create-note-in-folder', $event)"
        />
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm test -- Sidebar.spec.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/Sidebar.vue frontend/src/Sidebar.spec.ts
git commit -m "feat: bubble create-note-in-folder up through Sidebar"
```

---

### Task 3: `App.vue` — handler that prefixes the path and reuses `handleCreateNote`

**Files:**
- Modify: `frontend/src/App.vue`
- Test: `frontend/src/App.spec.ts`

**Interfaces:**
- Consumes: `create-note-in-folder` emit from `Sidebar` (Task 2);
  `handleCreateNote(path: string): Promise<void>` (existing, unchanged).
- Produces: nothing consumed by later tasks — this is the final wiring
  point.

- [ ] **Step 1: Write the failing tests**

Add to `frontend/src/App.spec.ts` (existing file). This file's `vi.mock('./services/api', ...)` block already mocks `createNote` as `vi.fn()` (no resolved value) — check it before adding, and give it a resolved value so `await handleSelectNote(created.id)` inside `handleCreateNote` doesn't throw on `created.id`:

```typescript
  createNote: vi.fn().mockResolvedValue({ id: 99, path: 'docs/untitled-x.md', title: '', frontmatter: null, sort_position: null, updated_at: '2026-07-31T00:00:00Z' }),
```

(replace the existing bare `createNote: vi.fn(),` line with this one).

Then add the test, importing `createNote` from the mocked module at the
top of the file alongside any other imported mocks:

```typescript
import { createNote } from './services/api'

describe('App folder quick-create', () => {
  it('prefixes the auto-generated filename with the folder path', async () => {
    const wrapper = mount(App)
    await wrapper.vm.$nextTick()
    const vm = wrapper.vm as any
    await vm.handleCreateNoteInFolder('docs')
    expect(createNote).toHaveBeenCalledWith(
      1,
      expect.stringMatching(/^docs\/untitled-[a-z0-9]+\.md$/),
      expect.any(String),
    )
  })

  it('creates at the vault root when folderPath is empty', async () => {
    const wrapper = mount(App)
    await wrapper.vm.$nextTick()
    const vm = wrapper.vm as any
    await vm.handleCreateNoteInFolder('')
    expect(createNote).toHaveBeenCalledWith(
      1,
      expect.stringMatching(/^untitled-[a-z0-9]+\.md$/),
      expect.any(String),
    )
  })
})
```

`handleCreateNoteInFolder` is reachable via `(wrapper.vm as any).handleCreateNoteInFolder(...)`
with no `defineExpose` needed — confirmed working precedent already in
this codebase: `frontend/src/CommandPalette.spec.ts:15-16` does
`const vm = wrapper.vm as any; vm.open()` against a plain `<script setup>`
component, calling a top-level function the same way.

- [ ] **Step 2: Run tests to verify they fail**

Run: `./scripts/jt.sh npm test -- App.spec.ts`
Expected: FAIL — `handleCreateNoteInFolder` is not defined, and `Sidebar` isn't wired to it yet.

- [ ] **Step 3: Add the handler and wire it on `<Sidebar>`**

Add this function in `frontend/src/App.vue`'s `<script setup>` block,
directly above `async function handleCreateNote(path: string) {`:

```typescript
function handleCreateNoteInFolder(folderPath: string) {
  const fileName = `untitled-${Date.now().toString(36)}.md`
  const path = folderPath === '' ? fileName : `${folderPath}/${fileName}`
  return handleCreateNote(path)
}
```

Wire it on the `<Sidebar>` element in the template:

```html
      @notes-reordered="refreshNotesList"
      @select-note="handleSelectNote"
```

to:

```html
      @notes-reordered="refreshNotesList"
      @create-note-in-folder="handleCreateNoteInFolder"
      @select-note="handleSelectNote"
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./scripts/jt.sh npm test -- App.spec.ts`
Expected: PASS.

- [ ] **Step 5: Run the full frontend suite**

Run: `./scripts/jt.sh npm test`
Expected: PASS, all tests including the new ones — no regressions.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/App.vue frontend/src/App.spec.ts
git commit -m "feat: wire per-folder quick-create through App.vue"
```

---

## Self-Review Notes

- **Spec coverage:** §2 in-scope bullets (hover "+" on folder rows only,
  create + auto-select + auto-expand) → Task 1 Steps 3–5. §2 out-of-scope
  bullets (no note-row "+", no root-header "+", no rename prompt, no new
  collision handling) → simply not built; nothing in any task adds them.
  §3's exact handler code for all three files → Tasks 1–3. §4 testing →
  one test file touched per task, matching the spec's per-file breakdown
  exactly.
- **Placeholder scan:** none found.
- **Type consistency:** `create-note-in-folder` emitted with a `string`
  payload (`folderPath`) consistently across `NoteTreeNode.vue` (Task 1),
  `Sidebar.vue` (Task 2, pure passthrough), and `App.vue`'s
  `handleCreateNoteInFolder(folderPath: string)` (Task 3) — same name,
  same type, at every hop.
