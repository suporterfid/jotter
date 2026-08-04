# Wikilink Hover Preview Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Hovering a `[[wikilink]]` in the rendered Markdown preview shows a popup with the target note's rendered content (or a "create note" affordance for unresolved links), closing gap G.2 of the Obsidian UI-parity audit (issue #287).

**Architecture:** `MarkdownPreview.vue` detects a debounced hover over a `.wikilink` anchor and emits the raw target string + the anchor's `DOMRect`. `NoteEditor.vue` resolves that target against `allNotes` (via a new shared `resolveWikilinkTarget` util, also used to de-duplicate `App.vue`'s existing inline matching logic), fetches and caches the resolved note's content, and renders a new pure `WikilinkPreviewPopup.vue` positioned from the given rect.

**Tech Stack:** Vue 3 `<script setup lang="ts">`, Vitest + `@vue/test-utils` (incl. fake timers for the hover debounce), `marked`-backed `renderMarkdown()`.

## Global Constraints

- Working directory: `/home/ubuntu/projects/web/iroh/jotter`, branch `feature/wikilink-hover-preview` (spec commit `27ae438` already on this branch).
- Frontend root: `frontend/`; source: `frontend/src/`; component tests are flat files at `frontend/src/<Name>.spec.ts`.
- Test runner: `./scripts/jt.sh npm run test -- <file>.spec.ts` for one frontend file, `./scripts/jt.sh test` for the full suite (frontend + backend).
- Commit style: lowercase `type: summary` (`feat:`), one commit per task, test + implementation together — matches `2659e74 feat: wire the outline drawer into NoteEditor (G.1, #286)` from the sibling G.1 branch.
- `NoteMeta`/`NoteDetail` types already exist at `frontend/src/services/types.ts:14-21` and `:62-66` — do not redefine them.
- `getNote(workspaceId: number, noteId: number): Promise<NoteDetail>` is already imported into `NoteEditor.vue` (`components/NoteEditor.vue:435`, used at `:799`) — no new import needed there, only a new mock entry in `NoteEditor.spec.ts`.

---

### Task 1: `resolveWikilinkTarget()` util, de-duplicating `App.vue`

**Files:**
- Create: `frontend/src/services/wikilinks.ts`
- Modify: `frontend/src/App.vue:886-900` (`handleWikilinkNavigation`)
- Test: `frontend/src/wikilinks.spec.ts`

**Interfaces:**
- Produces: `export function resolveWikilinkTarget(target: string, notes: NoteMeta[]): NoteMeta | undefined` — consumed by Task 4 (`NoteEditor.vue`) and by this task's own `App.vue` refactor.

- [ ] **Step 1: Write the failing test**

```ts
// frontend/src/wikilinks.spec.ts
import { describe, expect, it } from 'vitest'
import { resolveWikilinkTarget } from './services/wikilinks'
import type { NoteMeta } from './services/types'

function makeNote(overrides: Partial<NoteMeta> = {}): NoteMeta {
  return {
    id: 1,
    path: 'Projects/Jotter.md',
    title: 'Jotter',
    frontmatter: null,
    sort_position: null,
    updated_at: '2026-07-31T00:00:00Z',
    ...overrides,
  }
}

describe('resolveWikilinkTarget', () => {
  it('matches by title, case-insensitively', () => {
    const notes = [makeNote({ id: 1, title: 'Jotter' })]
    expect(resolveWikilinkTarget('jotter', notes)).toBe(notes[0])
  })

  it('matches by full path, case-insensitively', () => {
    const notes = [makeNote({ id: 1, path: 'Projects/Jotter.md', title: 'Something Else' })]
    expect(resolveWikilinkTarget('projects/jotter.md', notes)).toBe(notes[0])
  })

  it('matches by path with a .md suffix appended to the target, when title differs', () => {
    const notes = [makeNote({ id: 1, path: 'ideas.md', title: 'My Note' })]
    expect(resolveWikilinkTarget('ideas', notes)).toBe(notes[0])
  })

  it('returns undefined when nothing matches', () => {
    const notes = [makeNote()]
    expect(resolveWikilinkTarget('nonexistent', notes)).toBeUndefined()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm run test -- wikilinks.spec.ts`
Expected: FAIL with "Failed to resolve import './services/wikilinks'".

- [ ] **Step 3: Write minimal implementation**

```ts
// frontend/src/services/wikilinks.ts
import type { NoteMeta } from './types'

/**
 * Resolves a raw wikilink target string (from [[target]] / data-target)
 * against the workspace's note list. Shared by App.vue's click-to-navigate
 * handler and NoteEditor.vue's hover-preview handler so both agree on what
 * a wikilink points to.
 */
export function resolveWikilinkTarget(target: string, notes: NoteMeta[]): NoteMeta | undefined {
  const targetLower = target.toLowerCase().trim()
  return notes.find(n =>
    n.title.toLowerCase() === targetLower ||
    n.path.toLowerCase() === targetLower ||
    n.path.toLowerCase() === `${targetLower}.md`
  )
}
```

Then refactor `App.vue`'s `handleWikilinkNavigation` (currently at `App.vue:886-900`) to use it:

```ts
// App.vue — add to the existing import block, alongside the other ./services/* imports
import { resolveWikilinkTarget } from './services/wikilinks'
```

```ts
// App.vue — replace the existing handleWikilinkNavigation body
async function handleWikilinkNavigation(target: string) {
  const match = resolveWikilinkTarget(target, notes.value)

  if (match) {
    await handleSelectNote(match.id)
  } else {
    const targetLower = target.toLowerCase().trim()
    const newPath = targetLower.endsWith('.md') ? targetLower : `${targetLower}.md`
    await handleCreateNote(newPath)
  }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm run test -- wikilinks.spec.ts`
Expected: PASS, all 4 cases green.

Then run the full `App.spec.ts` file to confirm the refactor didn't regress existing behavior (no test there targets `handleWikilinkNavigation` directly, but this catches any mount-level breakage):

Run: `./scripts/jt.sh npm run test -- App.spec.ts`
Expected: PASS, all pre-existing cases green.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/services/wikilinks.ts frontend/src/wikilinks.spec.ts frontend/src/App.vue
git commit -m "feat: add resolveWikilinkTarget util, de-duplicated into App.vue"
```

---

### Task 2: Debounced hover detection in `MarkdownPreview.vue`

**Files:**
- Modify: `frontend/src/components/MarkdownPreview.vue`
- Test: `frontend/src/MarkdownPreview.spec.ts`

**Interfaces:**
- Produces: `MarkdownPreview` now emits `hover-wikilink(target: string, rect: DOMRect)` (after a 300ms hover over a `.wikilink` anchor) and `unhover-wikilink()` (on `mouseout` of a `.wikilink`, or on scroll of the preview itself). Consumed by Task 4 (`NoteEditor.vue`).

- [ ] **Step 1: Write the failing test**

```ts
// frontend/src/MarkdownPreview.spec.ts
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi, afterEach } from 'vitest'
import MarkdownPreview from './components/MarkdownPreview.vue'

describe('MarkdownPreview wikilink hover', () => {
  afterEach(() => {
    vi.useRealTimers()
  })

  it('emits hover-wikilink with the target and a rect after a 300ms hover', async () => {
    const wrapper = mount(MarkdownPreview, {
      props: { content: 'See [[Ideas]] for more.' },
    })
    vi.useFakeTimers()
    const link = wrapper.get('a.wikilink')
    await link.trigger('mouseover')

    expect(wrapper.emitted('hover-wikilink')).toBeUndefined()
    await vi.advanceTimersByTimeAsync(300)

    const emitted = wrapper.emitted('hover-wikilink')
    expect(emitted).toHaveLength(1)
    expect(emitted![0][0]).toBe('Ideas')
    expect(typeof (emitted![0][1] as DOMRect).top).toBe('number')
  })

  it('cancels the pending hover if mouseout fires before 300ms', async () => {
    const wrapper = mount(MarkdownPreview, {
      props: { content: 'See [[Ideas]] for more.' },
    })
    vi.useFakeTimers()
    const link = wrapper.get('a.wikilink')
    await link.trigger('mouseover')
    await link.trigger('mouseout')
    await vi.advanceTimersByTimeAsync(300)

    expect(wrapper.emitted('hover-wikilink')).toBeUndefined()
  })

  it('emits unhover-wikilink on mouseout after a successful hover', async () => {
    const wrapper = mount(MarkdownPreview, {
      props: { content: 'See [[Ideas]] for more.' },
    })
    vi.useFakeTimers()
    const link = wrapper.get('a.wikilink')
    await link.trigger('mouseover')
    await vi.advanceTimersByTimeAsync(300)
    await link.trigger('mouseout')

    expect(wrapper.emitted('unhover-wikilink')).toHaveLength(1)
  })

  it('emits unhover-wikilink when the preview scrolls', async () => {
    const wrapper = mount(MarkdownPreview, {
      props: { content: 'See [[Ideas]] for more.' },
    })
    await wrapper.get('.markdown-preview').trigger('scroll')

    expect(wrapper.emitted('unhover-wikilink')).toHaveLength(1)
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm run test -- MarkdownPreview.spec.ts`
Expected: FAIL — no `hover-wikilink`/`unhover-wikilink` events emitted (undefined).

- [ ] **Step 3: Write minimal implementation**

```vue
<!-- frontend/src/components/MarkdownPreview.vue — replace the <template> root tag's
     attributes and the <script setup> block -->
<template>
  <div 
    class="markdown-preview prose prose-invert" 
    v-html="renderedContent"
    @click="handlePreviewClick"
    @change="handleCheckboxChange"
    @mouseover="handleWikilinkMouseOver"
    @mouseout="handleWikilinkMouseOut"
    @scroll="handleScroll"
  ></div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount } from 'vue'
import { renderMarkdown } from '../services/markdown'

const props = defineProps<{
  content: string
}>()

const emit = defineEmits<{
  (e: 'navigate-wikilink', target: string): void
  (e: 'toggle-task', itemText: string, isChecked: boolean): void
  (e: 'hover-wikilink', target: string, rect: DOMRect): void
  (e: 'unhover-wikilink'): void
}>()

const renderedContent = computed(() => renderMarkdown(props.content || ''))

function handlePreviewClick(event: MouseEvent) {
  const target = event.target as HTMLElement

  // Copy code button click
  const copyBtn = target.closest('.copy-code-btn') as HTMLButtonElement | null
  if (copyBtn) {
    const pre = copyBtn.closest('.code-block-wrapper')?.querySelector('pre')
    if (pre) {
      navigator.clipboard.writeText(pre.textContent || '')
      copyBtn.textContent = 'Copied!'
      setTimeout(() => { copyBtn.textContent = 'Copy' }, 2000)
    }
    return
  }

  // Wikilink click
  const link = target.closest('a.wikilink') as HTMLAnchorElement | null
  if (link) {
    event.preventDefault()
    const wikilinkTarget = link.getAttribute('data-target')
    if (wikilinkTarget) {
      emit('navigate-wikilink', wikilinkTarget)
    }
  }
}

function handleCheckboxChange(event: Event) {
  const input = event.target as HTMLInputElement | null
  if (input && input.type === 'checkbox') {
    const li = input.closest('li')
    if (li) {
      const isChecked = input.checked
      const text = li.textContent?.trim() || ''
      emit('toggle-task', text, isChecked)
    }
  }
}

// Hover preview (G.2): debounced so a mouse merely passing over a link on
// its way elsewhere doesn't trigger a fetch — only a genuine pause does.
let hoverTimer: ReturnType<typeof setTimeout> | null = null

function clearHoverTimer() {
  if (hoverTimer) {
    clearTimeout(hoverTimer)
    hoverTimer = null
  }
}

function handleWikilinkMouseOver(event: MouseEvent) {
  const target = event.target as HTMLElement
  const link = target.closest('a.wikilink') as HTMLAnchorElement | null
  if (!link) return
  const wikilinkTarget = link.getAttribute('data-target')
  if (!wikilinkTarget) return

  clearHoverTimer()
  hoverTimer = setTimeout(() => {
    hoverTimer = null
    emit('hover-wikilink', wikilinkTarget, link.getBoundingClientRect())
  }, 300)
}

function handleWikilinkMouseOut(event: MouseEvent) {
  const target = event.target as HTMLElement
  const link = target.closest('a.wikilink') as HTMLAnchorElement | null
  if (!link) return

  clearHoverTimer()
  emit('unhover-wikilink')
}

function handleScroll() {
  clearHoverTimer()
  emit('unhover-wikilink')
}

onBeforeUnmount(clearHoverTimer)
</script>
```

(Only the `<template>` root attributes and the `<script setup>` block change — the rest of the file, including all `<style scoped>` rules, is untouched.)

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm run test -- MarkdownPreview.spec.ts`
Expected: PASS, all 4 cases green.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/MarkdownPreview.vue frontend/src/MarkdownPreview.spec.ts
git commit -m "feat: emit debounced wikilink hover/unhover events from MarkdownPreview"
```

---

### Task 3: `WikilinkPreviewPopup.vue` component

**Files:**
- Create: `frontend/src/components/WikilinkPreviewPopup.vue`
- Test: `frontend/src/WikilinkPreviewPopup.spec.ts`

**Interfaces:**
- Consumes: `renderMarkdown(markdownText: string): string` from `../services/markdown` (pre-existing), `NoteMeta` type from `../services/types` (pre-existing).
- Produces: `WikilinkPreviewPopup` component — props `rect: DOMRect`, `note: NoteMeta | null`, `content: string | null`, `unresolvedTarget: string | null`; no emits. Consumed by Task 4 (`NoteEditor.vue`).

- [ ] **Step 1: Write the failing test**

```ts
// frontend/src/WikilinkPreviewPopup.spec.ts
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WikilinkPreviewPopup from './components/WikilinkPreviewPopup.vue'
import type { NoteMeta } from './services/types'

const note: NoteMeta = {
  id: 1,
  path: 'ideas.md',
  title: 'Ideas',
  frontmatter: null,
  sort_position: null,
  updated_at: '2026-07-31T00:00:00Z',
}

const rect = { top: 100, bottom: 120, left: 50, right: 150, width: 100, height: 20, x: 50, y: 100, toJSON: () => ({}) } as DOMRect

describe('WikilinkPreviewPopup', () => {
  it('renders rendered markdown content when resolved and loaded', () => {
    const wrapper = mount(WikilinkPreviewPopup, {
      props: { rect, note, content: '# Ideas\n\nSome body.', unresolvedTarget: null },
    })
    expect(wrapper.html()).toContain('<h1')
    expect(wrapper.text()).toContain('Some body.')
  })

  it('shows a loading state when resolved but content is still null', () => {
    const wrapper = mount(WikilinkPreviewPopup, {
      props: { rect, note, content: null, unresolvedTarget: null },
    })
    expect(wrapper.text()).toContain('Loading...')
  })

  it('shows the new-note affordance for an unresolved target', () => {
    const wrapper = mount(WikilinkPreviewPopup, {
      props: { rect, note: null, content: null, unresolvedTarget: 'Missing Note' },
    })
    expect(wrapper.text()).toContain('No note yet')
    expect(wrapper.text()).toContain('Missing Note')
  })

  it('positions using the given rect', () => {
    const wrapper = mount(WikilinkPreviewPopup, {
      props: { rect, note, content: 'text', unresolvedTarget: null },
    })
    const style = wrapper.get('[data-testid="wikilink-preview-popup"]').attributes('style')
    expect(style).toContain('top: 124px')
    expect(style).toContain('left: 50px')
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm run test -- WikilinkPreviewPopup.spec.ts`
Expected: FAIL with "Failed to resolve import './components/WikilinkPreviewPopup.vue'".

- [ ] **Step 3: Write minimal implementation**

```vue
<!-- frontend/src/components/WikilinkPreviewPopup.vue -->
<template>
  <div
    ref="popupRef"
    class="wikilink-preview-popup"
    data-testid="wikilink-preview-popup"
    :style="positionStyle"
  >
    <div v-if="unresolvedTarget" class="wikilink-preview-unresolved">
      No note yet — click to create '{{ unresolvedTarget }}'
    </div>
    <div v-else-if="content === null" class="wikilink-preview-loading">
      Loading...
    </div>
    <div v-else class="wikilink-preview-content" v-html="renderedContent"></div>
  </div>
</template>

<script setup lang="ts">
import { computed, onMounted, ref } from 'vue'
import { renderMarkdown } from '../services/markdown'
import type { NoteMeta } from '../services/types'

const props = defineProps<{
  rect: DOMRect
  note: NoteMeta | null
  content: string | null
  unresolvedTarget: string | null
}>()

const popupRef = ref<HTMLElement | null>(null)
const positionStyle = ref({
  top: `${props.rect.bottom + 4}px`,
  left: `${props.rect.left}px`,
})

const renderedContent = computed(() => (props.content ? renderMarkdown(props.content) : ''))

onMounted(() => {
  const el = popupRef.value
  if (!el) return
  const width = el.offsetWidth
  let left = props.rect.left
  if (left + width > window.innerWidth) {
    left = props.rect.right - width
  }
  positionStyle.value = { top: `${props.rect.bottom + 4}px`, left: `${left}px` }
})
</script>

<style scoped>
.wikilink-preview-popup {
  position: fixed;
  z-index: 50;
  width: 320px;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-float);
  padding: var(--space-3);
}

.wikilink-preview-content {
  max-height: 200px;
  overflow: hidden;
  font-size: 0.875rem;
  -webkit-mask-image: linear-gradient(to bottom, black 80%, transparent 100%);
  mask-image: linear-gradient(to bottom, black 80%, transparent 100%);
}

.wikilink-preview-loading,
.wikilink-preview-unresolved {
  color: var(--color-text-muted);
  font-size: 0.875rem;
}
</style>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm run test -- WikilinkPreviewPopup.spec.ts`
Expected: PASS, all 4 cases green.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/WikilinkPreviewPopup.vue frontend/src/WikilinkPreviewPopup.spec.ts
git commit -m "feat: add WikilinkPreviewPopup component"
```

---

### Task 4: Wire hover preview into `NoteEditor.vue`

**Files:**
- Modify: `frontend/src/components/NoteEditor.vue`
- Modify (add cases + a mock entry): `frontend/src/NoteEditor.spec.ts`

**Interfaces:**
- Consumes: `resolveWikilinkTarget` from Task 1 (`../services/wikilinks`), `hover-wikilink`/`unhover-wikilink` events from Task 2 (already wired to `MarkdownPreview` in the template), `WikilinkPreviewPopup` from Task 3 (`./WikilinkPreviewPopup.vue`), pre-existing `getNote` (already imported at `NoteEditor.vue:435`).
- Produces: nothing consumed by later tasks — this is the last implementation task.

- [ ] **Step 1: Write the failing test**

First, add `getNote` to the shared `vi.mock('./services/api', ...)` block near the top of `frontend/src/NoteEditor.spec.ts` (it's already imported by the component but not yet mocked, since no existing test path calls it):

```ts
vi.mock('./services/api', () => ({
  getNoteComments: vi.fn().mockResolvedValue([]),
  getUnlinkedMentions: vi.fn().mockResolvedValue([]),
  getOutgoingLinks: vi.fn().mockResolvedValue([]),
  setNoteProperty: vi.fn().mockResolvedValue({}),
  deleteNoteProperty: vi.fn().mockResolvedValue({}),
  addNoteComment: vi.fn().mockResolvedValue({
    id: 1, actor_name: 'Admin', content: 'placeholder', anchor_line: null, created_at: '2026-08-03T00:00:00Z',
  }),
  getNote: vi.fn(),
}))

import { getNoteComments, setNoteProperty, deleteNoteProperty, addNoteComment, getNote } from './services/api'
```

Then add a new `describe` block at the end of the file (after the `describe('NoteEditor outline drawer', ...)` block added by the G.1 plan):

```ts
describe('NoteEditor wikilink hover preview', () => {
  const allNotes = [
    { id: 2, path: 'ideas.md', title: 'Ideas', frontmatter: null, sort_position: null, updated_at: '2026-07-31T00:00:00Z' },
  ]

  beforeEach(() => {
    vi.clearAllMocks()
    ;(getNote as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({
      id: 2, path: 'ideas.md', title: 'Ideas', frontmatter: null, sort_position: null,
      updated_at: '2026-07-31T00:00:00Z', content: '# Ideas\n\nSome body.', backlinks: [],
    })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows the popup with fetched content after hovering a resolved wikilink', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: 'See [[Ideas]].' }), allNotes, workspaceId: 1 },
    })
    await flushPromises()
    vi.useFakeTimers()

    const link = wrapper.get('a.wikilink')
    await link.trigger('mouseover')
    await vi.advanceTimersByTimeAsync(300)

    expect(wrapper.find('[data-testid="wikilink-preview-popup"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Some body.')
    expect(getNote).toHaveBeenCalledTimes(1)

    wrapper.unmount()
  })

  it('caches fetched content so hovering the same link twice only fetches once', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: 'See [[Ideas]].' }), allNotes, workspaceId: 1 },
    })
    await flushPromises()
    vi.useFakeTimers()

    const link = wrapper.get('a.wikilink')
    await link.trigger('mouseover')
    await vi.advanceTimersByTimeAsync(300)
    await link.trigger('mouseout')

    await link.trigger('mouseover')
    await vi.advanceTimersByTimeAsync(300)

    expect(getNote).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('shows the new-note affordance for an unresolved target without calling getNote', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: 'See [[Missing]].' }), allNotes: [], workspaceId: 1 },
    })
    await flushPromises()
    vi.useFakeTimers()

    const link = wrapper.get('a.wikilink')
    await link.trigger('mouseover')
    await vi.advanceTimersByTimeAsync(300)

    expect(wrapper.text()).toContain('No note yet')
    expect(getNote).not.toHaveBeenCalled()

    wrapper.unmount()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm run test -- NoteEditor.spec.ts`
Expected: FAIL — no `[data-testid="wikilink-preview-popup"]` in the rendered output; `getNote` not called.

- [ ] **Step 3: Write minimal implementation**

Add imports, right after the existing `import { parseHeadings, type HeadingEntry } from '../services/outline'` (added by the G.1 branch):

```ts
import WikilinkPreviewPopup from './WikilinkPreviewPopup.vue'
import { resolveWikilinkTarget } from '../services/wikilinks'
```

Add state and handlers, right after the outline-drawer state block (after `jumpToHeading`'s closing brace):

```ts
interface HoveredWikilinkPreview {
  rect: DOMRect
  resolved: { note: NoteMeta; content: string | null } | null
  unresolvedTarget: string | null
}

const hoveredPreview = ref<HoveredWikilinkPreview | null>(null)
const noteContentCache = new Map<number, string>()

async function handleHoverWikilink(target: string, rect: DOMRect) {
  const match = resolveWikilinkTarget(target, props.allNotes)

  if (!match) {
    hoveredPreview.value = { rect, resolved: null, unresolvedTarget: target }
    return
  }

  const cached = noteContentCache.get(match.id)
  if (cached !== undefined) {
    hoveredPreview.value = { rect, resolved: { note: match, content: cached }, unresolvedTarget: null }
    return
  }

  hoveredPreview.value = { rect, resolved: { note: match, content: null }, unresolvedTarget: null }
  if (!props.workspaceId) return

  try {
    const detail = await getNote(props.workspaceId, match.id)
    noteContentCache.set(match.id, detail.content)
    // A hover the user has already left must not clobber a newer one.
    if (hoveredPreview.value?.resolved?.note.id === match.id) {
      hoveredPreview.value = { ...hoveredPreview.value, resolved: { note: match, content: detail.content } }
    }
  } catch {
    // Passive affordance — a failed fetch just leaves the popup on its loading state.
  }
}

function handleUnhoverWikilink() {
  hoveredPreview.value = null
}
```

(`NoteMeta` is already imported in this file's type-import line — `import type { NoteDetail, NoteMeta, NoteRevisionMeta, NoteComment, UnlinkedMention, OutgoingLink } from '../services/types'` — no change needed there.)

Wire the new events onto `MarkdownPreview` and dismiss on textarea scroll, updating the existing template block:

```html
<!-- Preview Area -->
<div v-show="viewMode !== 'edit'" class="preview-wrapper">
  <MarkdownPreview 
    :content="editableContent" 
    @navigate-wikilink="$emit('navigate-wikilink', $event)"
    @hover-wikilink="handleHoverWikilink"
    @unhover-wikilink="handleUnhoverWikilink"
  />
</div>
```

```html
<!-- on the existing <textarea ref="textareaRef" ...> element, add: -->
@scroll="handleUnhoverWikilink"
```

Render the popup, right after the `.preview-wrapper` closing `</div>` (still inside `.editor-body`):

```html
<WikilinkPreviewPopup
  v-if="hoveredPreview"
  :rect="hoveredPreview.rect"
  :note="hoveredPreview.resolved?.note ?? null"
  :content="hoveredPreview.resolved?.content ?? null"
  :unresolved-target="hoveredPreview.unresolvedTarget"
/>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm run test -- NoteEditor.spec.ts`
Expected: PASS, including all pre-existing `NoteEditor.spec.ts` cases (regression check — confirms the new `getNote` mock entry didn't break the pre-existing unlinked-mentions-preview path that already calls it at `NoteEditor.vue:799`).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/NoteEditor.vue frontend/src/NoteEditor.spec.ts
git commit -m "feat: wire wikilink hover preview into NoteEditor (G.2, #287)"
```

---

### Task 5: Full regression run and push

**Files:** none (verification task).

- [ ] **Step 1: Run the full frontend test suite**

Run: `./scripts/jt.sh npm test`
Expected: PASS across the whole frontend suite, not just the four files touched above.

- [ ] **Step 2: Run the full combined suite**

Run: `./scripts/jt.sh test`
Expected: backend + frontend both run. If the pre-existing, unrelated `ReleaseZipSecurityTest` failure (stray other-worktree paths leaking into the release zip scan — first seen on the G.1 branch, #292) reappears, that's expected and not a regression from this change; any other backend failure is not expected and must be investigated.

- [ ] **Step 3: Push the branch**

```bash
git push -u origin feature/wikilink-hover-preview
```

- [ ] **Step 4: Open a PR**

```bash
gh pr create --title "feat: add wikilink hover preview (G.2, closes #287)" --body "$(cat <<'EOF'
## Summary
- Hovering a [[wikilink]] in the rendered preview now shows a popup with the target note's rendered content, after a 300ms debounce
- Unresolved wikilinks (no matching note) show a "No note yet — click to create" affordance instead
- Extracted resolveWikilinkTarget() as a shared util, de-duplicating App.vue's existing inline wikilink-matching logic

Closes #287. Source: docs/20260803-jotter-obsidian-ui-parity-audit.md §G.2, design: docs/superpowers/specs/2026-08-04-wikilink-hover-preview-design.md, plan: docs/superpowers/plans/2026-08-04-wikilink-hover-preview.md

## Test plan
- [x] Unit: wikilinks.spec.ts (4), MarkdownPreview.spec.ts (4), WikilinkPreviewPopup.spec.ts (4), NoteEditor.spec.ts wikilink-hover-preview cases (3) — full frontend suite passing
- [ ] Manual: hover a wikilink pointing at an existing note (popup with rendered content appears after ~300ms), hover a wikilink pointing nowhere (create-note affordance appears), scroll the preview while a popup is open (it dismisses)
EOF
)"
```

---

## Self-Review

**Spec coverage:** Shared resolution util + `App.vue` de-dup (Task 1), debounced hover/unhover detection incl. scroll-dismiss (Task 2), popup rendering for all three content states — loaded / loading / unresolved — plus rect-based positioning (Task 3), state/caching/fetch/stale-response-guard/wiring in `NoteEditor.vue` (Task 4). Testing section of the spec is covered 1:1: `resolveWikilinkTarget` cases (Task 1), hover/cancel/unhover/scroll cases (Task 2), all four popup-render cases (Task 3), resolve+fetch/cache/unresolved cases (Task 4). Out-of-scope items (hover-into-popup, prefetching) are correctly absent from every task.

**Placeholder scan:** No TBD/TODO markers; every step has literal code.

**Type consistency:** `HoveredWikilinkPreview` defined once in Task 4 and used only there (not referenced by earlier tasks, so no cross-task drift risk). `resolveWikilinkTarget(target: string, notes: NoteMeta[]): NoteMeta | undefined` signature from Task 1 matches its Task 4 call site (`resolveWikilinkTarget(target, props.allNotes)`, `props.allNotes: NoteMeta[]`). `WikilinkPreviewPopup` props (`rect`/`note`/`content`/`unresolvedTarget`) match between Task 3's definition and Task 4's template usage. `hover-wikilink(target: string, rect: DOMRect)` / `unhover-wikilink()` emit signatures match between Task 2's `defineEmits` and Task 4's `@hover-wikilink="handleHoverWikilink"` handler signature.
