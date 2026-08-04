# Wikilink Transclusion Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** `![[Note Title]]` in a note's Markdown renders the referenced note's content inline in the preview, closing gap G.5 of the Obsidian UI-parity audit (issue #288).

**Architecture:** `services/markdown.ts` gains a `renderEmbeds()` pass (mirroring the existing `renderCallouts` string-splice-before-`marked.parse()` pattern) that turns `![[Target]]` into a status-tagged `<div class="embed-block">`, driven by a caller-supplied synchronous `resolveEmbed` callback. `NoteEditor.vue` owns a reactive content cache, watches the note body for embed targets, fetches uncached ones via the existing `getNote`, and supplies `resolveEmbed` — implementing "resolved / loading / unresolved / circular" as a pure state read with zero side effects in the render path itself.

**Tech Stack:** Vue 3 `<script setup lang="ts">` (incl. `reactive()` for cache-driven computed invalidation), Vitest + `@vue/test-utils`, `marked`-backed `renderMarkdown()`, PHPUnit for the backend registry mirror.

## Global Constraints

- Working directory: `/home/ubuntu/projects/web/iroh/jotter`, branch `feature/wikilink-transclusion` (spec commit `f1a61a3` already on this branch).
- Frontend root: `frontend/`; source: `frontend/src/`; component tests are flat files at `frontend/src/<Name>.spec.ts`.
- Test runner: `./scripts/jt.sh npm run test -- <file>.spec.ts` for one frontend file, `./scripts/jt.sh artisan test tests/Unit/<File>.php` for one backend file, `./scripts/jt.sh test` for the full combined suite.
- Commit style: lowercase `type: summary` (`feat:`), one commit per task, test + implementation together.
- **Whole-note embeds only** — `#Heading`/`#^blockId` fragments are captured but ignored; do not add section-scoped extraction.
- **No recursive nesting** — an embedded note's own `![[...]]` is rendered via a plain `renderMarkdown(content)` call with no `resolveEmbed` argument, so it stays literal. Do not thread a resolver into that inner call.
- `resolveWikilinkTarget(target: string, notes: NoteMeta[]): NoteMeta | undefined` already exists at `frontend/src/services/wikilinks.ts` (added for G.2) — reuse it, do not reimplement matching logic.
- `getNote(workspaceId: number, noteId: number): Promise<NoteDetail>` is already imported into `NoteEditor.vue` (`components/NoteEditor.vue:451`) — no new import needed there.

---

### Task 1: Register the `embed` block type (frontend + backend registries)

**Files:**
- Modify: `frontend/src/services/blockRegistry.ts`
- Modify: `frontend/src/blockRegistry.spec.ts`
- Modify: `app/Domain/Vault/BlockRegistry.php`
- Modify: `tests/Unit/BlockRegistryTest.php`

**Interfaces:**
- Produces: `getClientAllowedTags()` now includes `'div'` (already did, via `callout`) and `getClientAllowedAttributes()` now includes `'data-embed-status'`/`'data-embed-target'` — required by Task 3's `renderEmbeds` before `DOMPurify.sanitize` will keep those attributes. `BlockRegistry.php` gets the same entry purely for cross-file parity (this file has no runtime consumer today — confirmed by grep, only its own test and a stale `dist/unwrapped` build artifact reference it — but every other block type is mirrored here, so a new one should be too).

- [ ] **Step 1: Write the failing tests**

Add to `frontend/src/blockRegistry.spec.ts`'s existing `'defines all required block types in client registry'` test:

```ts
    expect(blockDefinitions).toHaveProperty('embed')
```

And to its `'derives client allowed tags and attributes matching server definitions'` test:

```ts
    expect(attrs).toContain('data-embed-status')
    expect(attrs).toContain('data-embed-target')
```

Add to `tests/Unit/BlockRegistryTest.php`'s `test_block_registry_defines_all_required_blocks`:

```php
        $this->assertArrayHasKey('embed', $defs);
```

And to `test_allowed_tags_and_attributes_derivation`:

```php
        $this->assertContains('data-embed-status', $attrs);
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./scripts/jt.sh npm run test -- blockRegistry.spec.ts`
Expected: FAIL — `blockDefinitions` has no `embed` key, `attrs` doesn't contain `data-embed-status`.

Run: `./scripts/jt.sh artisan test tests/Unit/BlockRegistryTest.php`
Expected: FAIL — same reasons, PHP side.

- [ ] **Step 3: Write minimal implementation**

In `frontend/src/services/blockRegistry.ts`, add a new entry to `blockDefinitions`, right after the existing `divider` entry:

```ts
  embed: {
    name: 'Embed',
    syntax: '![[Note Title]]',
    allowed_tags: ['div'],
    allowed_attributes: ['class', 'data-embed-status', 'data-embed-target'],
    slash_menu: { label: 'Embed Note', icon: 'layout' },
  },
```

In `app/Domain/Vault/BlockRegistry.php`, add the mirrored entry to `definitions()`, right after its own `'divider' => [...]` entry:

```php
            'embed' => [
                'name' => 'Embed',
                'syntax' => '![[Note Title]]',
                'allowed_tags' => ['div'],
                'allowed_attributes' => ['class', 'data-embed-status', 'data-embed-target'],
                'slash_menu' => ['label' => 'Embed Note', 'icon' => 'layout'],
            ],
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./scripts/jt.sh npm run test -- blockRegistry.spec.ts`
Expected: PASS.

Run: `./scripts/jt.sh artisan test tests/Unit/BlockRegistryTest.php`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/services/blockRegistry.ts frontend/src/blockRegistry.spec.ts app/Domain/Vault/BlockRegistry.php tests/Unit/BlockRegistryTest.php
git commit -m "feat: register the embed block type in both block registries"
```

---

### Task 2: `parseEmbedTargets()` util

**Files:**
- Modify: `frontend/src/services/wikilinks.ts`
- Modify: `frontend/src/wikilinks.spec.ts`

**Interfaces:**
- Produces: `export const EMBED_PATTERN: RegExp` (matches `![[Target]]`, with an optional `#fragment` captured-but-ignored) and `export function parseEmbedTargets(markdown: string): string[]` (unique, trimmed targets, in first-occurrence order) — both consumed by Task 3 (`markdown.ts` imports `EMBED_PATTERN`) and Task 5 (`NoteEditor.vue` imports `parseEmbedTargets`).

- [ ] **Step 1: Write the failing test**

Append to `frontend/src/wikilinks.spec.ts`:

```ts
import { parseEmbedTargets } from './services/wikilinks'

describe('parseEmbedTargets', () => {
  it('returns an empty array when there are no embeds', () => {
    expect(parseEmbedTargets('no embeds here')).toEqual([])
  })

  it('extracts a single embed target', () => {
    expect(parseEmbedTargets('See ![[Ideas]] below.')).toEqual(['Ideas'])
  })

  it('extracts multiple distinct embed targets in order', () => {
    expect(parseEmbedTargets('![[Ideas]] and ![[Projects/Jotter]]')).toEqual(['Ideas', 'Projects/Jotter'])
  })

  it('dedupes repeated embed targets', () => {
    expect(parseEmbedTargets('![[Ideas]] again: ![[Ideas]]')).toEqual(['Ideas'])
  })

  it('strips a #heading fragment from the target', () => {
    expect(parseEmbedTargets('![[Ideas#Section]]')).toEqual(['Ideas'])
  })

  it('does not match a plain [[wikilink]] (no ! prefix)', () => {
    expect(parseEmbedTargets('[[Ideas]]')).toEqual([])
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm run test -- wikilinks.spec.ts`
Expected: FAIL with "does not provide an export named 'parseEmbedTargets'".

- [ ] **Step 3: Write minimal implementation**

Append to `frontend/src/services/wikilinks.ts`:

```ts
export const EMBED_PATTERN = /!\[\[([^\]|#]+)(?:#[^\]|]*)?\]\]/g

/**
 * Extracts the unique embed targets (![[Target]]) referenced by a note's
 * raw markdown, for NoteEditor.vue to resolve+fetch ahead of render.
 */
export function parseEmbedTargets(markdown: string): string[] {
  const targets = new Set<string>()
  for (const match of markdown.matchAll(EMBED_PATTERN)) {
    targets.add(match[1].trim())
  }
  return Array.from(targets)
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm run test -- wikilinks.spec.ts`
Expected: PASS, all 10 cases green (4 pre-existing `resolveWikilinkTarget` + 6 new).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/services/wikilinks.ts frontend/src/wikilinks.spec.ts
git commit -m "feat: add parseEmbedTargets util and EMBED_PATTERN"
```

---

### Task 3: `renderEmbeds()` in `markdown.ts`

**Files:**
- Modify: `frontend/src/services/markdown.ts`
- Modify: `frontend/src/markdown.spec.ts`

**Interfaces:**
- Consumes: `EMBED_PATTERN` from Task 2 (`./wikilinks`).
- Produces: `export interface EmbedResolution { status: 'resolved' | 'loading' | 'unresolved' | 'circular'; html?: string }` and `renderMarkdown(markdownText: string, resolveEmbed?: (target: string) => EmbedResolution): string` (second parameter added to the existing function) — both consumed by Task 4 (`MarkdownPreview.vue`) and Task 5 (`NoteEditor.vue`'s own `renderMarkdown(cachedContent)` call for embedded content, with no resolver).

- [ ] **Step 1: Write the failing test**

Append to `frontend/src/markdown.spec.ts`'s existing `describe('Markdown rendering & XSS security', ...)` block:

```ts
  it('renders a resolved embed inline via the resolveEmbed callback', () => {
    const md = 'Before.\n\n![[Ideas]]\n\nAfter.'
    const html = renderMarkdown(md, (target) => {
      expect(target).toBe('Ideas')
      return { status: 'resolved', html: '<p>Idea body.</p>' }
    })
    expect(html).toContain('data-embed-status="resolved"')
    expect(html).toContain('data-embed-target="Ideas"')
    expect(html).toContain('Idea body.')
  })

  it('renders a loading placeholder for a loading embed', () => {
    const html = renderMarkdown('![[Ideas]]', () => ({ status: 'loading' }))
    expect(html).toContain('data-embed-status="loading"')
    expect(html).toContain('Loading embed')
  })

  it('renders an unresolved-note message for an unresolved embed', () => {
    const html = renderMarkdown('![[Missing]]', () => ({ status: 'unresolved' }))
    expect(html).toContain('data-embed-status="unresolved"')
    expect(html).toContain('Missing')
  })

  it('renders a circular-embed guard message', () => {
    const html = renderMarkdown('![[Self]]', () => ({ status: 'circular' }))
    expect(html).toContain('data-embed-status="circular"')
    expect(html).toContain('Cannot embed a note within itself')
  })

  it('leaves ![[...]] completely literal when no resolveEmbed is given', () => {
    const html = renderMarkdown('![[Ideas]]')
    expect(html).toContain('![[Ideas]]')
    expect(html).not.toContain('class="wikilink"')
  })

  it('still renders a plain [[Note]] link unaffected by the embed lookbehind', () => {
    const html = renderMarkdown('[[Ideas]]')
    expect(html).toContain('class="wikilink"')
    expect(html).toContain('data-target="Ideas"')
  })
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm run test -- markdown.spec.ts`
Expected: FAIL — `renderMarkdown` currently accepts one argument only, and `![[Ideas]]` today renders as a stray `!` plus a `<a class="wikilink">` link, not a `data-embed-status` div.

- [ ] **Step 3: Write minimal implementation**

Add the import, alongside the existing `blockRegistry` import in `frontend/src/services/markdown.ts`:

```ts
import { EMBED_PATTERN } from './wikilinks'
```

Update `renderWikilinks` to skip `![[...]]` (only the regex line changes, body unchanged):

```ts
export function renderWikilinks(text: string): string {
  // Pattern: [[target]] or [[target|alias]] — the (?<!!) lookbehind skips
  // ![[...]] embeds (handled separately by renderEmbeds), so an embed's
  // inner [[...]] is never also turned into a plain link.
  return text.replace(/(?<!!)\[\[([^\]|#]+)(?:#[^\]|]+)?(?:\|([^\]]+))?\]\]/g, (_match, target, alias) => {
    const cleanTarget = target.trim()
    const label = (alias || target).trim()
    const safeTarget = DOMPurify.sanitize(cleanTarget)
    const safeLabel = DOMPurify.sanitize(label)
    return `<a class="wikilink" data-target="${safeTarget}" href="#/note/${encodeURIComponent(safeTarget)}">${safeLabel}</a>`
  })
}
```

Add `renderEmbeds` and the `EmbedResolution` type, right after `renderWikilinks`:

```ts
export interface EmbedResolution {
  status: 'resolved' | 'loading' | 'unresolved' | 'circular'
  html?: string
}

/**
 * Splices ![[Target]] embeds into <div class="embed-block"> blocks, using
 * the caller-supplied resolveEmbed callback to decide each embed's content.
 * Runs before renderWikilinks so the negative lookbehind there never has to
 * see an embed's [[...]] portion. When resolveEmbed is omitted this is a
 * no-op — the source of v1's non-recursive nesting: an embedded note's own
 * ![[...]] syntax, rendered via a plain renderMarkdown() call with no
 * resolver, is left completely literal.
 */
function renderEmbeds(text: string, resolveEmbed?: (target: string) => EmbedResolution): string {
  if (!resolveEmbed) return text
  return text.replace(EMBED_PATTERN, (_match, target) => {
    const cleanTarget = target.trim()
    const safeTarget = DOMPurify.sanitize(cleanTarget)
    const resolution = resolveEmbed(cleanTarget)

    if (resolution.status === 'resolved' && resolution.html !== undefined) {
      return `<div class="embed-block" data-embed-status="resolved" data-embed-target="${safeTarget}">${resolution.html}</div>`
    }
    if (resolution.status === 'loading') {
      return `<div class="embed-block" data-embed-status="loading" data-embed-target="${safeTarget}">Loading embed…</div>`
    }
    if (resolution.status === 'circular') {
      return `<div class="embed-block" data-embed-status="circular" data-embed-target="${safeTarget}">Cannot embed a note within itself.</div>`
    }
    return `<div class="embed-block" data-embed-status="unresolved" data-embed-target="${safeTarget}">Note not found: '${safeTarget}'</div>`
  })
}
```

Update `renderMarkdown` to accept the optional second parameter and call `renderEmbeds` first:

```ts
export function renderMarkdown(markdownText: string, resolveEmbed?: (target: string) => EmbedResolution): string {
  if (!markdownText) return ''

  const headings = parseHeadings(markdownText)

  const withEmbeds = renderEmbeds(markdownText, resolveEmbed)
  const withWikilinks = renderWikilinks(withEmbeds)
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

(`parseHeadings(markdownText)` still runs against the *original* text, not `withEmbeds` — headings inside an embedded note must not appear in this note's own outline, unchanged from the G.1 behavior.)

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm run test -- markdown.spec.ts`
Expected: PASS, all 15 cases green (9 pre-existing + 6 new), including the pre-existing "parses wikilinks into data-target anchor elements" case (confirms the lookbehind didn't break plain links).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/services/markdown.ts frontend/src/markdown.spec.ts
git commit -m "feat: add renderEmbeds() for ![[note]] transclusion"
```

---

### Task 4: Thread `resolveEmbed` through `MarkdownPreview.vue`

**Files:**
- Modify: `frontend/src/components/MarkdownPreview.vue`
- Modify: `frontend/src/MarkdownPreview.spec.ts`

**Interfaces:**
- Consumes: `EmbedResolution` type from Task 3 (`../services/markdown`).
- Produces: `MarkdownPreview` now accepts an optional `resolveEmbed?: (target: string) => EmbedResolution` prop, passed straight into `renderMarkdown()`. Consumed by Task 5 (`NoteEditor.vue`).

- [ ] **Step 1: Write the failing test**

Append to `frontend/src/MarkdownPreview.spec.ts`:

```ts
describe('MarkdownPreview embeds', () => {
  it('threads a resolveEmbed prop into the rendered output', () => {
    const wrapper = mount(MarkdownPreview, {
      props: {
        content: 'Before.\n\n![[Ideas]]',
        resolveEmbed: () => ({ status: 'resolved', html: '<p>Idea body.</p>' }),
      },
    })
    expect(wrapper.html()).toContain('data-embed-status="resolved"')
    expect(wrapper.html()).toContain('Idea body.')
  })

  it('leaves an embed literal when no resolveEmbed prop is given', () => {
    const wrapper = mount(MarkdownPreview, {
      props: { content: '![[Ideas]]' },
    })
    expect(wrapper.text()).toContain('![[Ideas]]')
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm run test -- MarkdownPreview.spec.ts`
Expected: FAIL — `resolveEmbed` prop isn't declared, `renderMarkdown` is called with only `props.content`, so the embed never resolves.

- [ ] **Step 3: Write minimal implementation**

In `frontend/src/components/MarkdownPreview.vue`, update the import and props (only these two lines of the `<script setup>` block change):

```ts
import { renderMarkdown, type EmbedResolution } from '../services/markdown'
```

```ts
const props = defineProps<{
  content: string
  resolveEmbed?: (target: string) => EmbedResolution
}>()
```

Update `renderedContent`:

```ts
const renderedContent = computed(() => renderMarkdown(props.content || '', props.resolveEmbed))
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm run test -- MarkdownPreview.spec.ts`
Expected: PASS, all 6 cases green (4 pre-existing hover cases + 2 new).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/MarkdownPreview.vue frontend/src/MarkdownPreview.spec.ts
git commit -m "feat: thread resolveEmbed prop through MarkdownPreview"
```

---

### Task 5: Wire embed resolution + fetch/cache into `NoteEditor.vue`

**Files:**
- Modify: `frontend/src/components/NoteEditor.vue`
- Modify: `frontend/src/NoteEditor.spec.ts`

**Interfaces:**
- Consumes: `parseEmbedTargets` from Task 2 (`../services/wikilinks`), `renderMarkdown`/`EmbedResolution` from Task 3 (`../services/markdown`), the `resolve-embed` prop from Task 4 (already on `MarkdownPreview`), pre-existing `resolveWikilinkTarget` and `getNote`.
- Produces: nothing consumed by later tasks — this is the last implementation task.

- [ ] **Step 1: Write the failing test**

Append to `frontend/src/NoteEditor.spec.ts`:

```ts
describe('NoteEditor wikilink embeds', () => {
  const allNotes = [
    { id: 2, path: 'ideas.md', title: 'Ideas', frontmatter: null, sort_position: null, updated_at: '2026-07-31T00:00:00Z' },
  ]

  beforeEach(() => {
    vi.clearAllMocks()
    ;(getNote as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({
      id: 2, path: 'ideas.md', title: 'Ideas', frontmatter: null, sort_position: null,
      updated_at: '2026-07-31T00:00:00Z', content: 'Idea body.', backlinks: [],
    })
  })

  it('fetches and renders a resolved embed', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: 'Before.\n\n![[Ideas]]' }), allNotes, workspaceId: 1 },
    })
    await flushPromises()

    expect(getNote).toHaveBeenCalledTimes(1)
    expect(wrapper.html()).toContain('data-embed-status="resolved"')
    expect(wrapper.text()).toContain('Idea body.')

    wrapper.unmount()
  })

  it('renders the circular guard for a note embedding its own id, without calling getNote', async () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ id: 1, content: 'See ![[Test Note]].' }),
        allNotes: [
          { id: 1, path: 'test-note.md', title: 'Test Note', frontmatter: null, sort_position: null, updated_at: '2026-07-31T00:00:00Z' },
        ],
        workspaceId: 1,
      },
    })
    await flushPromises()

    expect(getNote).not.toHaveBeenCalled()
    expect(wrapper.html()).toContain('data-embed-status="circular"')

    wrapper.unmount()
  })

  it('renders the unresolved message for an embed with no matching note, without calling getNote', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: '![[Missing]]' }), allNotes: [], workspaceId: 1 },
    })
    await flushPromises()

    expect(getNote).not.toHaveBeenCalled()
    expect(wrapper.html()).toContain('data-embed-status="unresolved"')

    wrapper.unmount()
  })
})
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh npm run test -- NoteEditor.spec.ts`
Expected: FAIL — no `data-embed-status` in the rendered output; `getNote` never called for the first case.

- [ ] **Step 3: Write minimal implementation**

Update the `vue` import to add `reactive`, in `frontend/src/components/NoteEditor.vue`:

```ts
import { ref, reactive, watch, computed, nextTick, onUnmounted, onMounted } from 'vue'
```

Add imports, right after the existing `import { resolveWikilinkTarget } from '../services/wikilinks'`:

```ts
import { parseEmbedTargets } from '../services/wikilinks'
import { renderMarkdown, type EmbedResolution } from '../services/markdown'
```

(This makes the wikilinks import block read as two lines — `resolveWikilinkTarget` and `parseEmbedTargets` — or combine them into one `import { resolveWikilinkTarget, parseEmbedTargets } from '../services/wikilinks'` line; either is fine, keep them adjacent.)

Add state, the fetch-triggering watcher, and `resolveEmbed`, right after the existing `handleUnhoverWikilink` function (before `const unlinkedMentions = ref<UnlinkedMention[]>([])`):

```ts
const embedContentCache = reactive(new Map<number, string>())

watch(editableContent, (content) => {
  const targets = parseEmbedTargets(content)
  targets.forEach(async (target) => {
    const match = resolveWikilinkTarget(target, props.allNotes)
    if (!match || match.id === props.note.id) return
    if (embedContentCache.has(match.id)) return
    if (!props.workspaceId) return

    try {
      const detail = await getNote(props.workspaceId, match.id)
      embedContentCache.set(match.id, detail.content)
    } catch {
      // Passive affordance — a failed fetch just leaves the embed on its loading state.
    }
  })
}, { immediate: true })

function resolveEmbed(target: string): EmbedResolution {
  const match = resolveWikilinkTarget(target, props.allNotes)
  if (!match) return { status: 'unresolved' }
  if (match.id === props.note.id) return { status: 'circular' }

  const content = embedContentCache.get(match.id)
  if (content === undefined) return { status: 'loading' }
  return { status: 'resolved', html: renderMarkdown(content) }
}
```

Update the `MarkdownPreview` usage in the template to pass the new prop:

```html
<MarkdownPreview
  :content="editableContent"
  @navigate-wikilink="$emit('navigate-wikilink', $event)"
  @hover-wikilink="handleHoverWikilink"
  @unhover-wikilink="handleUnhoverWikilink"
  :resolve-embed="resolveEmbed"
/>
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh npm run test -- NoteEditor.spec.ts`
Expected: PASS, including all pre-existing `NoteEditor.spec.ts` cases (regression check).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/NoteEditor.vue frontend/src/NoteEditor.spec.ts
git commit -m "feat: wire wikilink transclusion into NoteEditor (G.5, #288)"
```

---

### Task 6: Full regression run and push

**Files:** none (verification task).

- [ ] **Step 1: Run the full frontend test suite**

Run: `./scripts/jt.sh npm test`
Expected: PASS across the whole frontend suite, not just the five files touched above.

- [ ] **Step 2: Run the full combined suite**

Run: `./scripts/jt.sh test`
Expected: backend + frontend both run. If the pre-existing, unrelated `ReleaseZipSecurityTest` failure (stray other-worktree paths leaking into the release zip scan — first seen on the G.1 branch, #292, and again on G.2, #293) reappears, that's expected and not a regression from this change; any other backend failure is not expected and must be investigated.

- [ ] **Step 3: Push the branch**

```bash
git push -u origin feature/wikilink-transclusion
```

- [ ] **Step 4: Open a PR**

```bash
gh pr create --title "feat: add wikilink transclusion / ![[note]] embeds (G.5, closes #288)" --body "$(cat <<'EOF'
## Summary
- ![[Note Title]] now renders the referenced note's content inline in the preview
- Unresolved embeds show "Note not found", self-embeds show a "Cannot embed a note within itself" guard, in-flight fetches show "Loading embed…"
- v1 scope: whole-note embeds only (#Heading/#^blockId fragments ignored), no recursive nesting (an embedded note's own ![[...]] stays literal) — see design doc for rationale
- Registered a new 'embed' block type in both the frontend and backend BlockRegistry (DOMPurify allowlist + free Slash-menu entry)

Closes #288. Source: docs/20260803-jotter-obsidian-ui-parity-audit.md §G.5, design: docs/superpowers/specs/2026-08-04-wikilink-transclusion-design.md, plan: docs/superpowers/plans/2026-08-04-wikilink-transclusion.md

## Test plan
- [x] Unit: blockRegistry.spec.ts + BlockRegistryTest.php (2), wikilinks.spec.ts parseEmbedTargets cases (6), markdown.spec.ts embed cases (6), MarkdownPreview.spec.ts embed cases (2), NoteEditor.spec.ts embed cases (3) — full frontend suite passing
- [x] Backend: passing except the pre-existing unrelated ReleaseZipSecurityTest failure (same as G.1/#292 and G.2/#293)
- [ ] Manual: embed a note that exists (renders inline), embed a note that doesn't exist (shows "Note not found"), embed the current note itself (shows the circular guard), confirm a plain [[wikilink]] still navigates normally
EOF
)"
```

---

## Self-Review

**Spec coverage:** Whole-note-only + fragment-ignored decision (Task 3's `EMBED_PATTERN`/`renderEmbeds`, fragment captured but discarded), non-recursive nesting (Task 3's `renderEmbeds` no-op when `resolveEmbed` is omitted, Task 5's `resolveEmbed` calling plain `renderMarkdown(content)`), notes-only scope (Task 5's `resolveEmbed` only ever resolves via `resolveWikilinkTarget` against `allNotes`), registry/DOMPurify allowlist (Task 1), lookbehind regression safety (Task 3's explicit plain-`[[Note]]` test), reactive cache correctly invalidating the render computed (Task 5's `reactive(new Map())`, matching the spec's reactivity section), circular-embed guard (Task 3 + Task 5), backend no-op beyond registry parity (Task 1 only touches the unconsumed declarative mirror, no `WikilinkExtractor.php` change anywhere in this plan, matching the spec's explicit "no backend change needed" for the extractor). Testing section of the spec is covered 1:1 across Tasks 1-5. Out-of-scope items (heading/block anchors, recursion, attachments, backend rendering) are correctly absent from every task.

**Placeholder scan:** No TBD/TODO markers; every step has literal code.

**Type consistency:** `EmbedResolution` defined once in Task 3 (`services/markdown.ts`) and imported by name in Tasks 4 and 5 — no redefinition. `resolveEmbed(target: string): EmbedResolution` signature from Task 5 matches the `resolveEmbed?: (target: string) => EmbedResolution` prop type declared in Task 4 and the `resolveEmbed?: (target: string) => EmbedResolution` second parameter of `renderMarkdown` from Task 3. `parseEmbedTargets(markdown: string): string[]` from Task 2 matches its Task 5 call site (`parseEmbedTargets(content)`, `content` being `editableContent`'s string value from the `watch` callback). `EMBED_PATTERN` exported once from Task 2's `wikilinks.ts`, imported by name in Task 3's `markdown.ts` — no duplicate regex definition.
