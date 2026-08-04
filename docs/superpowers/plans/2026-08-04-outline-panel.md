# Outline / TOC Panel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add an Outline/TOC drawer to `NoteEditor.vue` that lists the current note's Markdown headings and jumps to the clicked heading, closing gap G.1 of the Obsidian UI-parity audit (issue #286).

**Architecture:** A pure `parseHeadings()` util (`services/outline.ts`) is the single source of truth for heading text/level/line/id, consumed both by a new `OutlinePanel.vue` list component (mounted in a right-drawer, mirroring the existing Comments-drawer pattern from #262) and by `services/markdown.ts`'s renderer (to stamp matching `id` attributes on rendered `<h1>`-`<h6>` tags, so drawer clicks can scroll the preview).

**Tech Stack:** Vue 3 `<script setup lang="ts">`, Vitest + `@vue/test-utils`, `marked` v18, DOMPurify.

## Global Constraints

- Working directory: `/home/ubuntu/projects/web/iroh/jotter`, branch `feature/outline-panel` (spec commit `f4b22b4` already on this branch).
- Frontend root: `frontend/`; source: `frontend/src/`; component tests are flat files at `frontend/src/<Name>.spec.ts` (not colocated with components), except `components/PanelHeader.spec.ts` which is colocated — new component tests in this plan follow the flat convention (matches `CommentsPanel.spec.ts`, `markdown.spec.ts`).
- Test runner: `docker compose` via the project's `jt` wrapper — `./scripts/jt.sh test` runs frontend + backend from the repo root; `./scripts/jt.sh npm run test -- <file>.spec.ts` runs one frontend file (confirmed: `frontend/package.json`'s `"test": "vitest run"`).
- Commit style: lowercase `type: summary` (`feat:`, `docs:`, `fix:`), no issue-number suffix required mid-branch (added by GitHub on PR merge). One commit per task, test + implementation together, matching this repo's existing history (e.g. `2bd21f4 fix: make the note title real editable page content (#257) (#272)`).

---

### Task 1: `parseHeadings()` util

**Files:**
- Create: `frontend/src/services/outline.ts`
- Test: `frontend/src/outline.spec.ts`

**Interfaces:**
- Produces: `interface HeadingEntry { level: number; text: string; line: number; id: string }` and `export function parseHeadings(markdown: string): HeadingEntry[]` — both consumed by Task 2 (`markdown.ts`), Task 3 (`OutlinePanel.vue` props), and Task 4 (`NoteEditor.vue`).

- [ ] **Step 1: Write the failing test**

```ts
// frontend/src/outline.spec.ts
import { describe, expect, it } from 'vitest'
import { parseHeadings } from './services/outline'

describe('parseHeadings', () => {
  it('returns an empty array for content with no headings', () => {
    expect(parseHeadings('just text\nmore text')).toEqual([])
  })

  it('parses ATX headings at all 6 levels with 0-based line numbers', () => {
    const md = '# One\n## Two\n### Three\n#### Four\n##### Five\n###### Six'
    const headings = parseHeadings(md)
    expect(headings).toHaveLength(6)
    expect(headings[0]).toEqual({ level: 1, text: 'One', line: 0, id: 'one' })
    expect(headings[5]).toEqual({ level: 6, text: 'Six', line: 5, id: 'six' })
  })

  it('skips headings inside fenced code blocks (both ``` and ~~~ fences)', () => {
    const md = [
      '# Real Heading',
      '',
      '```',
      '# Not a heading',
      '```',
      '',
      '~~~',
      '## Also not a heading',
      '~~~',
      '',
      '## Also Real',
    ].join('\n')
    const headings = parseHeadings(md)
    expect(headings.map(h => h.text)).toEqual(['Real Heading', 'Also Real'])
  })

  it('dedupes colliding slugs with -2, -3 suffixes', () => {
    const md = '# Notes\n## Notes\n### Notes'
    const headings = parseHeadings(md)
    expect(headings.map(h => h.id)).toEqual(['notes', 'notes-2', 'notes-3'])
  })

  it('ignores lines that are only # characters with no text', () => {
    expect(parseHeadings('#\n##   \n# Real')).toEqual([
      { level: 1, text: 'Real', line: 2, id: 'real' },
    ])
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm run test -- outline.spec.ts`
Expected: FAIL with "Cannot find module './services/outline'" or similar.

- [ ] **Step 3: Write minimal implementation**

```ts
// frontend/src/services/outline.ts
export interface HeadingEntry {
  level: number
  text: string
  line: number
  id: string
}

const FENCE_RE = /^(```|~~~)/
const ATX_HEADING_RE = /^(#{1,6})\s+(.+?)\s*#*\s*$/

function slugify(text: string): string {
  return text
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

/**
 * Parses ATX headings (# .. ######) out of raw Markdown, skipping any
 * line inside a fenced code block. This is the single source of truth
 * for heading text/level/line/id — services/markdown.ts's renderer
 * stamps the same ids onto rendered <h1>-<h6> tags so outline clicks
 * can scroll the preview to a matching element.
 */
export function parseHeadings(markdown: string): HeadingEntry[] {
  const lines = markdown.split('\n')
  const headings: HeadingEntry[] = []
  const slugCounts = new Map<string, number>()
  let inFence = false

  lines.forEach((line, index) => {
    if (FENCE_RE.test(line.trim())) {
      inFence = !inFence
      return
    }
    if (inFence) return

    const match = ATX_HEADING_RE.exec(line)
    if (!match) return

    const level = match[1].length
    const text = match[2].trim()
    if (!text) return

    let slug = slugify(text) || 'section'
    const seen = slugCounts.get(slug) ?? 0
    slugCounts.set(slug, seen + 1)
    if (seen > 0) slug = `${slug}-${seen + 1}`

    headings.push({ level, text, line: index, id: slug })
  })

  return headings
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm run test -- outline.spec.ts`
Expected: PASS, all 5 cases green.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/services/outline.ts frontend/src/outline.spec.ts
git commit -m "feat: add parseHeadings util for the outline panel"
```

---

### Task 2: Heading `id` attributes in rendered Markdown

**Files:**
- Modify: `frontend/src/services/markdown.ts`
- Modify (add cases): `frontend/src/markdown.spec.ts`

**Interfaces:**
- Consumes: `parseHeadings(markdown: string): HeadingEntry[]` from Task 1 (`./outline`).
- Produces: `renderMarkdown()`'s output now includes `id="<slug>"` on every rendered `<h1>`-`<h6>` tag, matching `parseHeadings()`'s `id` field in document order. No new exports — Task 4 relies on this only indirectly (via `document.getElementById`).

- [ ] **Step 1: Write the failing test**

Add to the existing `describe('Markdown rendering & XSS security', ...)` block in `frontend/src/markdown.spec.ts` (after the existing "renders standard markdown elements" case):

```ts
  it('stamps matching ids on rendered headings for outline navigation', () => {
    const md = '# Notes\n\nSome text.\n\n## Notes'
    const html = renderMarkdown(md)
    expect(html).toContain('<h1 id="notes">')
    expect(html).toContain('<h2 id="notes-2">')
  })
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm run test -- markdown.spec.ts`
Expected: FAIL — output contains `<h1>Notes</h1>` (no `id`), not `<h1 id="notes">`.

- [ ] **Step 3: Write minimal implementation**

In `frontend/src/services/markdown.ts`, add the import and a post-processing helper, then call it in `renderMarkdown`:

```ts
// add near the top, with the other imports
import { parseHeadings, type HeadingEntry } from './outline'
```

```ts
// add below wrapCodeBlocks()
/**
 * Stamps id="<slug>" onto each rendered <h1>-<h6> tag, in document order,
 * using the same parseHeadings() ids the outline panel lists — so a
 * drawer click can scroll the preview to a matching element via
 * document.getElementById. headings must come from parseHeadings() run
 * against the *same* raw markdown passed to renderMarkdown, so counts
 * and order line up with marked's own heading output.
 */
function injectHeadingIds(html: string, headings: HeadingEntry[]): string {
  let index = 0
  return html.replace(/<h([1-6])>/g, (match, level) => {
    const heading = headings[index]
    index += 1
    if (!heading) return match
    return `<h${level} id="${heading.id}">`
  })
}
```

Update `renderMarkdown` to compute headings from the raw input and call the helper before sanitizing:

```ts
export function renderMarkdown(markdownText: string): string {
  if (!markdownText) return ''

  const headings = parseHeadings(markdownText)

  // Convert wikilinks and callouts
  const withWikilinks = renderWikilinks(markdownText)
  const withCallouts = renderCallouts(withWikilinks)

  // Convert markdown to HTML
  let rawHtml = marked.parse(withCallouts, { async: false }) as string

  // Wrap code blocks
  rawHtml = wrapCodeBlocks(rawHtml)

  // Stamp heading ids for outline navigation
  rawHtml = injectHeadingIds(rawHtml, headings)

  // Sanitize with DOMPurify ensuring derived tags and attributes from block registry are allowed
  return DOMPurify.sanitize(rawHtml, {
    ADD_ATTR: getClientAllowedAttributes(),
    ALLOWED_TAGS: getClientAllowedTags(),
    ALLOWED_ATTR: getClientAllowedAttributes(),
  })
}
```

(`id` is already in `getClientAllowedAttributes()`'s base list at `blockRegistry.ts:74`, so DOMPurify won't strip it — no change needed there.)

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm run test -- markdown.spec.ts`
Expected: PASS, including all pre-existing cases in the file (regression check).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/services/markdown.ts frontend/src/markdown.spec.ts
git commit -m "feat: stamp heading ids in rendered markdown for outline navigation"
```

---

### Task 3: `OutlinePanel.vue` component

**Files:**
- Create: `frontend/src/components/OutlinePanel.vue`
- Test: `frontend/src/OutlinePanel.spec.ts`

**Interfaces:**
- Consumes: `HeadingEntry` type from Task 1 (`../services/outline`).
- Produces: `OutlinePanel` component — prop `headings: HeadingEntry[]` (required), emits `jump-to-heading` with the clicked `HeadingEntry`. Consumed by Task 4 (`NoteEditor.vue`).

- [ ] **Step 1: Write the failing test**

```ts
// frontend/src/OutlinePanel.spec.ts
import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import OutlinePanel from './components/OutlinePanel.vue'
import type { HeadingEntry } from './services/outline'

const headings: HeadingEntry[] = [
  { level: 1, text: 'Intro', line: 0, id: 'intro' },
  { level: 2, text: 'Details', line: 4, id: 'details' },
]

describe('OutlinePanel', () => {
  it('shows the empty state when there are no headings', () => {
    const wrapper = mount(OutlinePanel, { props: { headings: [] } })
    expect(wrapper.text()).toContain('No headings in this note yet.')
  })

  it('renders one row per heading, indented by level', () => {
    const wrapper = mount(OutlinePanel, { props: { headings } })
    const items = wrapper.findAll('[data-testid="outline-item"]')
    expect(items).toHaveLength(2)
    expect(items[0].text()).toBe('Intro')
    expect(items[1].text()).toBe('Details')
    expect(items[1].attributes('style')).toContain('padding-left: 12px')
  })

  it('emits jump-to-heading with the clicked entry', async () => {
    const wrapper = mount(OutlinePanel, { props: { headings } })
    await wrapper.findAll('[data-testid="outline-item-btn"]')[1].trigger('click')
    expect(wrapper.emitted('jump-to-heading')![0]).toEqual([headings[1]])
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm run test -- OutlinePanel.spec.ts`
Expected: FAIL with "Failed to resolve import './components/OutlinePanel.vue'".

- [ ] **Step 3: Write minimal implementation**

```vue
<!-- frontend/src/components/OutlinePanel.vue -->
<template>
  <div class="outline-panel">
    <div v-if="headings.length === 0" class="outline-empty">
      <p>No headings in this note yet.</p>
    </div>
    <ul v-else class="outline-list">
      <li
        v-for="heading in headings"
        :key="`${heading.line}-${heading.id}`"
        class="outline-item"
        data-testid="outline-item"
        :style="{ paddingLeft: `${(heading.level - 1) * 12}px` }"
      >
        <button
          type="button"
          class="outline-item-btn"
          data-testid="outline-item-btn"
          @click="$emit('jump-to-heading', heading)"
        >{{ heading.text }}</button>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import type { HeadingEntry } from '../services/outline'

defineProps<{
  headings: HeadingEntry[]
}>()

defineEmits<{
  (e: 'jump-to-heading', heading: HeadingEntry): void
}>()
</script>

<style scoped>
.outline-panel {
  padding: var(--space-3) var(--space-4);
}

.outline-empty {
  color: var(--color-text-muted);
  font-size: 0.875rem;
}

.outline-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.outline-item-btn {
  display: block;
  width: 100%;
  text-align: left;
  background: transparent;
  border: none;
  padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-sm);
  color: var(--color-text);
  font-size: 0.875rem;
  cursor: pointer;
}

.outline-item-btn:hover {
  background: var(--color-hover);
}
</style>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm run test -- OutlinePanel.spec.ts`
Expected: PASS, all 3 cases green.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/OutlinePanel.vue frontend/src/OutlinePanel.spec.ts
git commit -m "feat: add OutlinePanel component"
```

---

### Task 4: Wire the outline drawer into `NoteEditor.vue`

**Files:**
- Modify: `frontend/src/components/NoteEditor.vue`
- Modify (add cases): `frontend/src/NoteEditor.spec.ts`

**Interfaces:**
- Consumes: `parseHeadings`/`HeadingEntry` from Task 1 (`../services/outline`), `OutlinePanel` from Task 3 (`./OutlinePanel.vue`).
- Produces: nothing consumed by later tasks — this is the last task.

- [ ] **Step 1: Write the failing test**

Add a new `describe` block to `frontend/src/NoteEditor.spec.ts`, right after the existing `describe('NoteEditor comments drawer', ...)` block (it ends around the "keeps the drawer open when switching to a different note" test):

```ts
describe('NoteEditor outline drawer', () => {
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

    expect(document.querySelector('[data-testid="outline-drawer"]')).toBeNull()
    wrapper.unmount()
  })

  it('opens the drawer via the outline toggle button and lists headings from the note content', async () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ content: '# Title\n\n## Section' }),
        allNotes: [],
        workspaceId: 1,
      },
      attachTo: document.body,
    })
    await flushPromises()

    await wrapper.find('[data-testid="outline-drawer-btn"]').trigger('click')
    const drawer = document.querySelector('[data-testid="outline-drawer"]')
    expect(drawer).not.toBeNull()
    expect(drawer!.textContent).toContain('Title')
    expect(drawer!.textContent).toContain('Section')

    ;(document.querySelector('[data-testid="outline-drawer-close-btn"]') as HTMLElement).click()
    await wrapper.vm.$nextTick()
    expect(document.querySelector('[data-testid="outline-drawer"]')).toBeNull()

    wrapper.unmount()
  })

  it('clicking a heading in edit/split mode moves the textarea caret to that heading and focuses it', async () => {
    const content = '# Title\n\nBody text.\n\n## Section'
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content }), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
    })
    await flushPromises()
    await wrapper.find('[data-testid="outline-drawer-btn"]').trigger('click')

    const items = document.querySelectorAll('[data-testid="outline-item-btn"]')
    expect(items).toHaveLength(2)
    ;(items[1] as HTMLElement).click()
    await wrapper.vm.$nextTick()

    const textarea = wrapper.find('[data-testid="markdown-textarea"]').element as HTMLTextAreaElement
    const expectedOffset = '# Title\n\nBody text.\n\n'.length
    expect(document.activeElement).toBe(textarea)
    expect(textarea.selectionStart).toBe(expectedOffset)

    wrapper.unmount()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm run test -- NoteEditor.spec.ts`
Expected: FAIL — no `[data-testid="outline-drawer-btn"]` in the rendered output.

- [ ] **Step 3: Write minimal implementation**

Add imports, right after the existing `import CoverImageModal from './CoverImageModal.vue'` / `import SlashMenu from './SlashMenu.vue'` block (around line 416-417):

```ts
import OutlinePanel from './OutlinePanel.vue'
import { parseHeadings, type HeadingEntry } from '../services/outline'
```

Add state and the jump handler, right after the existing `const isCommentsDrawerOpen = ref(false)` (around line 610):

```ts
const isOutlineDrawerOpen = ref(false)
const headings = computed<HeadingEntry[]>(() => parseHeadings(editableContent.value))

function jumpToHeading(heading: HeadingEntry) {
  if (viewMode.value === 'preview') {
    document.getElementById(heading.id)?.scrollIntoView({ behavior: 'smooth', block: 'start' })
    return
  }

  const lines = editableContent.value.split('\n')
  let offset = 0
  for (let i = 0; i < heading.line; i += 1) {
    offset += lines[i].length + 1
  }

  const textarea = textareaRef.value
  if (!textarea) return
  textarea.setSelectionRange(offset, offset)
  textarea.focus()
}
```

(`computed` and `ref` are already imported from `vue` at line 399; `editableContent`, `viewMode`, and `textareaRef` already exist as shown above — no further imports needed.)

Add the toggle button in the template, in `.editor-controls`, immediately before the existing comments-drawer button (around line 104, so it reads left-to-right as Outline → History → Comments → Attach):

```html
<button
  class="btn-attach"
  data-testid="outline-drawer-btn"
  title="Outline"
  :aria-expanded="isOutlineDrawerOpen"
  @click="isOutlineDrawerOpen = !isOutlineDrawerOpen"
>
  <span>📑</span>
</button>
```

Add the teleported drawer, right after the existing Comments `</Teleport>` block (after line 357), reusing the same shell markup as the Comments drawer:

```html
<!-- Outline Drawer: teleported to the same right-drawer mount point as
     Comments (#262), listing the note's headings for quick navigation
     (G.1, #286). -->
<Teleport to="#app-right-drawer">
  <aside
    v-if="isOutlineDrawerOpen"
    class="outline-drawer"
    data-testid="outline-drawer"
  >
    <div class="outline-drawer-header">
      <h3>Outline</h3>
      <button
        type="button"
        class="drawer-close-btn"
        data-testid="outline-drawer-close-btn"
        aria-label="Close outline"
        @click="isOutlineDrawerOpen = false"
      >&times;</button>
    </div>
    <OutlinePanel :headings="headings" @jump-to-heading="jumpToHeading" />
  </aside>
</Teleport>
```

Add CSS, right after the existing `.comments-drawer` / `.comments-drawer-header` rules near the end of the `<style>` block (after the `.drawer-close-btn:hover` rule, before `</style>`):

```css
.outline-drawer {
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
  .outline-drawer {
    width: 100vw;
  }
}

.outline-drawer-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-3) var(--space-4);
  border-bottom: 1px solid var(--color-border);
}

.outline-drawer-header h3 {
  margin: 0;
  font-size: 0.9375rem;
  font-weight: 600;
  color: var(--color-text);
}
```

(`.drawer-close-btn` is already a shared class from the Comments drawer — reused as-is, no duplicate needed.)

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm run test -- NoteEditor.spec.ts`
Expected: PASS, including all pre-existing `NoteEditor.spec.ts` cases (regression check — the file is large, this confirms the new button placement didn't break existing `.editor-controls` button-index-based assertions, if any).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/NoteEditor.vue frontend/src/NoteEditor.spec.ts
git commit -m "feat: wire the outline drawer into NoteEditor (G.1, #286)"
```

---

### Task 5: Full regression run and push

**Files:** none (verification task).

- [ ] **Step 1: Run the full frontend test suite**

Run: `./scripts/jt.sh test`
Expected: PASS — no regressions across the whole suite (backend + frontend), not just the four files touched above.

- [ ] **Step 2: Push the branch**

```bash
git push -u origin feature/outline-panel
```

- [ ] **Step 3: Open a PR**

```bash
gh pr create --title "feat: add outline/TOC panel (G.1, closes #286)" --body "$(cat <<'EOF'
## Summary
- Adds a headings outline/TOC drawer to the note editor, matching the existing Comments-drawer pattern (#262)
- Heading ids are now stamped on rendered Markdown (services/markdown.ts) so outline clicks can scroll the preview in preview-only mode; in edit/split mode the click moves the textarea caret instead

Closes #286. Source: docs/20260803-jotter-obsidian-ui-parity-audit.md §G.1, design: docs/superpowers/specs/2026-08-04-outline-panel-design.md

## Test plan
- [x] Unit: outline.spec.ts, markdown.spec.ts (heading-id cases), OutlinePanel.spec.ts, NoteEditor.spec.ts (outline-drawer cases)
- [ ] Manual: open a note with several headings, toggle the outline drawer, click a heading in split mode (caret jumps) and in preview mode (page scrolls)
EOF
)"
```

---

## Self-Review

**Spec coverage:** Architecture (Task 1+3+4), heading-id plumbing in `markdown.ts` (Task 2), click-to-scroll for both textarea and preview modes (Task 4), edge cases — empty note (`OutlinePanel` empty state, Task 3), duplicate headings (`parseHeadings` dedupe, Task 1), fenced code blocks (`parseHeadings` fence toggle, Task 1) — all covered. Testing section of the spec is covered 1:1 by Tasks 1, 2, 3, 4's test steps. Out-of-scope items (collapsing sub-trees, wikilink `#heading` navigation) are correctly not implemented anywhere in this plan.

**Placeholder scan:** No TBD/TODO markers; every step has literal code, not a description of code.

**Type consistency:** `HeadingEntry` defined once in Task 1 (`services/outline.ts`) and imported by name in Tasks 2, 3, 4 — no redefinition. `parseHeadings` signature (`(markdown: string): HeadingEntry[]`) used identically in Task 2's `injectHeadingIds` caller and Task 4's `headings` computed.
