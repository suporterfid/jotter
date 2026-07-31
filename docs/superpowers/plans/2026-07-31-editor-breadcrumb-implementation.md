# Editor Breadcrumb Trail Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Turn `NoteEditor.vue`'s plain-text `.editor-path` into clickable folder segments that reveal (expand + scroll to + highlight) that folder in the sidebar tree.

**Architecture:** `NoteEditor.vue` emits `reveal-folder` with the cumulative folder path of the clicked segment. `App.vue` stores it as a `{path, nonce}` request object and passes it down to `Sidebar.vue`, which forwards the path to every root `NoteTreeNode` (which expands itself if it's the target or an ancestor of it) and, after the DOM updates, scrolls the target folder row into view with a brief highlight.

**Tech Stack:** Vue 3 `<script setup>` + TypeScript, Vitest, `@vue/test-utils`.

## Global Constraints

- No new API endpoint or backend change (spec §1).
- Only folder segments are clickable; the file name segment stays plain text (spec §2, §3).
- No new sidebar "filter by folder" mode — reveal reuses each folder's existing `expanded` ref (spec §2).
- Revealing a folder must also open the mobile sidebar if it's closed (spec §3, `App.vue`).
- The `nonce` on the reveal request must change on every click, including repeated clicks on the same segment, so `Sidebar` re-triggers even when `path` is unchanged (spec §3).

---

### Task 1: `NoteTreeNode.vue` — `revealPath` prop expands ancestor/target folders

**Files:**
- Modify: `frontend/src/components/NoteTreeNode.vue`
- Test: `frontend/src/NoteTreeNode.spec.ts`

**Interfaces:**
- Consumes: nothing new.
- Produces: new prop `revealPath?: string | null` on `NoteTreeNode`, threaded through its own recursive `<NoteTreeNode>` invocation. Task 3 (`Sidebar.vue`) passes this prop to the root-level `<NoteTreeNode>` instances.

- [ ] **Step 1: Write the failing tests**

Add to `frontend/src/NoteTreeNode.spec.ts`, as a new sibling `describe` block:

```typescript
describe('NoteTreeNode revealPath', () => {
  function makeFolderNode(overrides: Partial<TreeNode> = {}): TreeNode {
    return {
      type: 'folder',
      name: 'docs',
      fullPath: 'docs',
      children: [],
      ...overrides,
    } as TreeNode
  }

  it('expands when revealPath equals its own fullPath', async () => {
    const wrapper = mount(NoteTreeNode, {
      props: { node: makeFolderNode(), selectedNoteId: null, depth: 0 },
    })
    await wrapper.find('.folder-row').trigger('click')
    expect((wrapper.find('.folder-children').element as HTMLElement).style.display).toBe('none')

    await wrapper.setProps({ revealPath: 'docs' })
    expect((wrapper.find('.folder-children').element as HTMLElement).style.display).not.toBe('none')
  })

  it('expands when revealPath is a nested descendant of its fullPath', async () => {
    const wrapper = mount(NoteTreeNode, {
      props: { node: makeFolderNode({ fullPath: 'docs' }), selectedNoteId: null, depth: 0 },
    })
    await wrapper.find('.folder-row').trigger('click')

    await wrapper.setProps({ revealPath: 'docs/archived' })
    expect((wrapper.find('.folder-children').element as HTMLElement).style.display).not.toBe('none')
  })

  it('does not expand for an unrelated folder path', async () => {
    const wrapper = mount(NoteTreeNode, {
      props: { node: makeFolderNode({ fullPath: 'other' }), selectedNoteId: null, depth: 0 },
    })
    await wrapper.find('.folder-row').trigger('click')

    await wrapper.setProps({ revealPath: 'docs' })
    expect((wrapper.find('.folder-children').element as HTMLElement).style.display).toBe('none')
  })

  it('does not falsely match a folder whose name is a string-prefix of another (docs vs docsx)', async () => {
    const wrapper = mount(NoteTreeNode, {
      props: { node: makeFolderNode({ fullPath: 'docsx' }), selectedNoteId: null, depth: 0 },
    })
    await wrapper.find('.folder-row').trigger('click')

    await wrapper.setProps({ revealPath: 'docs' })
    expect((wrapper.find('.folder-children').element as HTMLElement).style.display).toBe('none')
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./scripts/jt.sh npm test -- NoteTreeNode.spec.ts`
Expected: FAIL — Vue Test Utils' `setProps({ revealPath: ... })` throws or is a no-op since the prop doesn't exist yet, so `.folder-children` never becomes visible in any of the 4 new tests.

- [ ] **Step 3: Add the prop and watcher**

In `frontend/src/components/NoteTreeNode.vue`, change:

```typescript
const props = defineProps<{
  node: TreeNode
  selectedNoteId: number | null
  depth: number
}>()
```

to:

```typescript
const props = defineProps<{
  node: TreeNode
  selectedNoteId: number | null
  depth: number
  revealPath?: string | null
}>()
```

Add this function and `watch` call right after the `expanded` ref
declaration (`const expanded = ref(true)`):

```typescript
function isRevealTarget(revealPath: string, fullPath: string): boolean {
  return revealPath === fullPath || revealPath.startsWith(`${fullPath}/`)
}

watch(
  () => props.revealPath,
  (revealPath) => {
    if (props.node.type === 'folder' && revealPath && isRevealTarget(revealPath, props.node.fullPath)) {
      expanded.value = true
    }
  },
)
```

(`isRevealTarget` is exported implicitly through the module scope only for
this file's own use — no separate export needed, it's not consumed
outside this component.)

- [ ] **Step 4: Thread the prop through the recursive invocation**

Change the recursive `<NoteTreeNode>` block inside `.folder-children`:

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

to:

```html
      <NoteTreeNode
        v-for="child in node.children"
        :key="child.type === 'folder' ? `f:${child.fullPath}` : `n:${child.note.id}`"
        :node="child"
        :selected-note-id="selectedNoteId"
        :depth="depth + 1"
        :reveal-path="revealPath"
        @select-note="$emit('select-note', $event)"
        @delete-note="$emit('delete-note', $event)"
        @create-note-in-folder="$emit('create-note-in-folder', $event)"
      />
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `./scripts/jt.sh npm test -- NoteTreeNode.spec.ts`
Expected: PASS — the 4 new tests plus all pre-existing tests in this file.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/components/NoteTreeNode.vue frontend/src/NoteTreeNode.spec.ts
git commit -m "feat: expand folder in tree when it matches a revealPath"
```

---

### Task 2: `NoteEditor.vue` — clickable breadcrumb segments

**Files:**
- Modify: `frontend/src/components/NoteEditor.vue`
- Test: `frontend/src/NoteEditor.spec.ts`

**Interfaces:**
- Consumes: nothing new.
- Produces: new emit `(e: 'reveal-folder', folderPath: string): void`. Task 4 (`App.vue`) listens for it on `<NoteEditor>`.

- [ ] **Step 1: Write the failing tests**

Add to `frontend/src/NoteEditor.spec.ts`, as a new sibling `describe`:

```typescript
describe('NoteEditor breadcrumb', () => {
  it('renders a clickable segment per folder and a plain-text file name', () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ path: 'docs/archived/note.md' }), allNotes: [], workspaceId: 1 },
    })
    const segments = wrapper.findAll('[data-testid="editor-path-segment"]')
    expect(segments).toHaveLength(2)
    expect(segments[0].text()).toBe('docs')
    expect(segments[1].text()).toBe('archived')
    expect(wrapper.find('[data-testid="editor-path-filename"]').text()).toBe('note.md')
  })

  it('renders no folder segments for a root-level note', () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ path: 'note.md' }), allNotes: [], workspaceId: 1 },
    })
    expect(wrapper.findAll('[data-testid="editor-path-segment"]')).toHaveLength(0)
    expect(wrapper.find('[data-testid="editor-path-filename"]').text()).toBe('note.md')
  })

  it('emits reveal-folder with the cumulative path when a segment is clicked', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ path: 'docs/archived/note.md' }), allNotes: [], workspaceId: 1 },
    })
    const segments = wrapper.findAll('[data-testid="editor-path-segment"]')
    await segments[1].trigger('click')
    expect(wrapper.emitted('reveal-folder')).toEqual([['docs/archived']])
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./scripts/jt.sh npm test -- NoteEditor.spec.ts`
Expected: FAIL — `[data-testid="editor-path-segment"]` and
`[data-testid="editor-path-filename"]` don't exist yet (`.editor-path`
currently renders `note.path` as one plain string).

- [ ] **Step 3: Add the computed and emit**

In `frontend/src/components/NoteEditor.vue`, change the `defineEmits` call:

```typescript
const emit = defineEmits<{
  (e: 'update-note', noteId: number, content: string): void
  (e: 'select-note', noteId: number): void
  (e: 'navigate-wikilink', target: string): void
}>()
```

to:

```typescript
const emit = defineEmits<{
  (e: 'update-note', noteId: number, content: string): void
  (e: 'select-note', noteId: number): void
  (e: 'navigate-wikilink', target: string): void
  (e: 'reveal-folder', folderPath: string): void
}>()
```

Add this computed right after `const noteIcon = computed(...)`:

```typescript
const breadcrumbSegments = computed(() => {
  const parts = props.note.path.split('/')
  const fileName = parts[parts.length - 1]
  const folders = parts.slice(0, -1)
  return {
    folders: folders.map((name, index) => ({
      name,
      path: folders.slice(0, index + 1).join('/'),
    })),
    fileName,
  }
})
```

- [ ] **Step 4: Replace the template**

Change:

```html
        <span class="editor-path" data-testid="editor-path">{{ note.path }}</span>
```

to:

```html
        <span class="editor-path" data-testid="editor-path">
          <template v-for="folder in breadcrumbSegments.folders" :key="folder.path">
            <button
              type="button"
              class="editor-path-segment"
              data-testid="editor-path-segment"
              @click="emit('reveal-folder', folder.path)"
            >{{ folder.name }}</button>
            <span class="editor-path-separator">/</span>
          </template>
          <span data-testid="editor-path-filename">{{ breadcrumbSegments.fileName }}</span>
        </span>
```

- [ ] **Step 5: Add the CSS**

In the `<style scoped>` block, find the existing `.editor-path` rule and
add these rules right after it:

```css
.editor-path-segment {
  background: transparent;
  border: none;
  padding: 0;
  color: var(--color-text-muted);
  font-size: inherit;
  cursor: pointer;
  text-decoration: underline;
  text-decoration-color: transparent;
  transition: color var(--duration-fast) var(--ease-standard),
              text-decoration-color var(--duration-fast) var(--ease-standard);
}

.editor-path-segment:hover {
  color: var(--color-action);
  text-decoration-color: var(--color-action);
}

.editor-path-separator {
  margin: 0 0.2em;
  color: var(--color-text-muted);
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `./scripts/jt.sh npm test -- NoteEditor.spec.ts`
Expected: PASS — the 3 new tests plus all pre-existing tests in this file.

- [ ] **Step 7: Commit**

```bash
git add frontend/src/components/NoteEditor.vue frontend/src/NoteEditor.spec.ts
git commit -m "feat: make editor breadcrumb folder segments clickable"
```

---

### Task 3: `Sidebar.vue` — reveal, scroll, and highlight

**Files:**
- Modify: `frontend/src/components/Sidebar.vue`
- Test: `frontend/src/Sidebar.spec.ts`

**Interfaces:**
- Consumes: `revealPath` prop on `NoteTreeNode` (Task 1).
- Produces: new prop `revealFolderRequest?: { path: string; nonce: number } | null` on `Sidebar`. Task 4 (`App.vue`) sets this prop.

- [ ] **Step 1: Write the failing test**

Add to `frontend/src/Sidebar.spec.ts`, as a new sibling `describe`:

```typescript
describe('Sidebar reveal folder', () => {
  it('expands a nested folder and its ancestor when revealFolderRequest is set', async () => {
    const notes: NoteMeta[] = [
      makeNote({ id: 1, path: 'docs/archived/inner.md' }),
    ]
    const wrapper = mount(Sidebar, {
      props: { notes, selectedNoteId: null, workspaceId: 1, folderPositions: [] },
    })

    // Both start expanded by default (NoteTreeNode's `expanded` ref defaults
    // to true) — collapse them first so the test proves reveal re-expands.
    const folderRows = wrapper.findAll('.folder-row')
    await folderRows[0].trigger('click')
    await folderRows[1].trigger('click')

    await wrapper.setProps({ revealFolderRequest: { path: 'docs/archived', nonce: 1 } })
    await wrapper.vm.$nextTick()

    const children = wrapper.findAll('.folder-children')
    for (const child of children) {
      expect((child.element as HTMLElement).style.display).not.toBe('none')
    }
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm test -- Sidebar.spec.ts`
Expected: FAIL — `Sidebar` doesn't declare `revealFolderRequest` or pass
anything down to `NoteTreeNode` yet, so both folders stay collapsed.

- [ ] **Step 3: Add the prop, pass-through, and scroll/highlight logic**

Change the `defineProps` call:

```typescript
const props = defineProps<{
  notes: NoteMeta[]
  selectedNoteId: number | null
  currentUser?: AuthUser | null
  notifications?: NotificationItem[]
  isMobileSidebarOpen?: boolean
  workspaceId?: number | null
  folderPositions?: FolderPosition[]
}>()
```

to:

```typescript
const props = defineProps<{
  notes: NoteMeta[]
  selectedNoteId: number | null
  currentUser?: AuthUser | null
  notifications?: NotificationItem[]
  isMobileSidebarOpen?: boolean
  workspaceId?: number | null
  folderPositions?: FolderPosition[]
  revealFolderRequest?: { path: string; nonce: number } | null
}>()
```

Update the root-level `<NoteTreeNode>`:

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

to:

```html
        <NoteTreeNode
          v-for="node in noteTree"
          :key="node.type === 'folder' ? `f:${node.fullPath}` : `n:${node.note.id}`"
          :node="node"
          :selected-note-id="selectedNoteId"
          :depth="0"
          :reveal-path="props.revealFolderRequest?.path ?? null"
          @select-note="$emit('select-note', $event)"
          @delete-note="$emit('delete-note', $event)"
          @create-note-in-folder="$emit('create-note-in-folder', $event)"
        />
```

Add the scroll/highlight watcher next to the existing `watch(isManualMode, ...)`
call — add this `import { nextTick }` to the existing Vue import line
(find `import { ref, computed, provide, onMounted, onBeforeUnmount, watch, useTemplateRef } from 'vue'`
and add `nextTick` to it), then add:

```typescript
watch(
  () => props.revealFolderRequest,
  async (request) => {
    if (!request) return
    await nextTick()
    const el = rootListRef.value?.querySelector<HTMLElement>(
      `[data-item-type="folder"][data-item-path="${request.path}"]`,
    )
    if (!el) return
    el.scrollIntoView({ block: 'center', behavior: 'smooth' })
    el.classList.add('folder-row-highlight')
    setTimeout(() => el.classList.remove('folder-row-highlight'), 1500)
  },
)
```

- [ ] **Step 4: Add the highlight CSS**

In the `<style scoped>` block, add (anywhere among the other rules):

```css
.folder-row-highlight {
  background: var(--color-hover);
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `./scripts/jt.sh npm test -- Sidebar.spec.ts`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/components/Sidebar.vue frontend/src/Sidebar.spec.ts
git commit -m "feat: reveal, scroll to, and highlight a folder from a breadcrumb click"
```

---

### Task 4: `App.vue` — wire `NoteEditor` to `Sidebar`

**Files:**
- Modify: `frontend/src/App.vue`
- Test: `frontend/src/App.spec.ts`

**Interfaces:**
- Consumes: `reveal-folder` emit from `NoteEditor` (Task 2);
  `revealFolderRequest` prop on `Sidebar` (Task 3).
- Produces: nothing consumed by later tasks — final wiring point.

- [ ] **Step 1: Write the failing test**

Add to `frontend/src/App.spec.ts`, as a new sibling `describe` (this file
already has a precedent for calling script-setup functions directly via
`(wrapper.vm as any).someFunction(...)`, established in this codebase at
`frontend/src/CommandPalette.spec.ts:15-16` and reused for
`handleCreateNoteInFolder` in this same file):

```typescript
describe('App reveal folder', () => {
  it('opens the mobile sidebar when a folder is revealed', async () => {
    const wrapper = mount(App)
    await wrapper.vm.$nextTick()
    const vm = wrapper.vm as any
    vm.handleRevealFolder('docs')
    await wrapper.vm.$nextTick()
    expect(vm.isMobileSidebarOpen).toBe(true)
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm test -- App.spec.ts`
Expected: FAIL — `handleRevealFolder` is not defined.

- [ ] **Step 3: Add the state, handler, and wiring**

Add this ref next to `const isMobileSidebarOpen = ref(false)`:

```typescript
const revealFolderRequest = ref<{ path: string; nonce: number } | null>(null)
```

Add this handler next to `handleCreateNoteInFolder`:

```typescript
function handleRevealFolder(folderPath: string) {
  revealFolderRequest.value = { path: folderPath, nonce: Date.now() }
  isMobileSidebarOpen.value = true
}
```

Wire it on `<Sidebar>` (add the prop and the listener):

```html
      :workspace-id="activeWorkspaceId"
      :folder-positions="folderPositions"
      @notes-reordered="refreshNotesList"
```

to:

```html
      :workspace-id="activeWorkspaceId"
      :folder-positions="folderPositions"
      :reveal-folder-request="revealFolderRequest"
      @notes-reordered="refreshNotesList"
```

Wire it on `<NoteEditor>`:

```html
      <NoteEditor
        v-else-if="activeNoteDetail"
        :note="activeNoteDetail"
        :all-notes="notes"
        :workspace-id="activeWorkspaceId || undefined"
        @update-note="handleUpdateNote"
        @select-note="handleSelectNote"
        @navigate-wikilink="handleWikilinkNavigation"
      />
```

to:

```html
      <NoteEditor
        v-else-if="activeNoteDetail"
        :note="activeNoteDetail"
        :all-notes="notes"
        :workspace-id="activeWorkspaceId || undefined"
        @update-note="handleUpdateNote"
        @select-note="handleSelectNote"
        @navigate-wikilink="handleWikilinkNavigation"
        @reveal-folder="handleRevealFolder"
      />
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm test -- App.spec.ts`
Expected: PASS.

- [ ] **Step 5: Run the full frontend suite**

Run: `./scripts/jt.sh npm test`
Expected: PASS, all tests including the new ones — no regressions.

- [ ] **Step 6: Commit**

```bash
git add frontend/src/App.vue frontend/src/App.spec.ts
git commit -m "feat: wire editor breadcrumb reveal-folder through App.vue"
```

---

## Self-Review Notes

- **Spec coverage:** §2 in-scope bullets (clickable folder segments,
  reveal-in-tree, mobile sidebar auto-open) → Tasks 1–4. §2 out-of-scope
  (no folder-filter mode, no title/icon change, no path truncation) →
  simply not built. §3's exact code for all four files → Tasks 1–4 in the
  same order. §4 testing → one test file per task, matching the spec's
  breakdown exactly, including the string-prefix edge case
  (`docsx` vs `docs`) called out explicitly in the spec's `NoteTreeNode`
  test bullet.
- **Placeholder scan:** none found.
- **Type consistency:** `revealPath?: string | null` on `NoteTreeNode`
  (Task 1) is fed by `Sidebar`'s `props.revealFolderRequest?.path ?? null`
  (Task 3), which itself is set from `App.vue`'s
  `revealFolderRequest = ref<{ path: string; nonce: number } | null>`
  (Task 4) — same shape at every hop. `reveal-folder` emitted with a
  `string` from `NoteEditor` (Task 2) and consumed by
  `handleRevealFolder(folderPath: string)` (Task 4) — matching name and
  type.
