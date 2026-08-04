# Page Icon (Emoji) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a per-note emoji icon (Notion-style), shown read-only in the sidebar tree and editable in the note editor's title bar, stored as a plain front-matter key that is excluded from the typed `NoteProperty` projection.

**Architecture:** Zero new API endpoints. `icon` is written/read through the existing generic `setNoteProperty`/`deleteNoteProperty` front-matter write path and the existing `frontmatter` field already returned on every `NoteMeta`/`NoteDetail`. The only backend change is a one-line exclusion in `NotePropertyProjector` (mirroring the existing `tags` exclusion) so `icon` never becomes a queryable `NoteProperty` row. `NoteTreeNode.vue` renders it read-only; `NoteEditor.vue` gets the only edit affordance (click to open an inline text input, native OS emoji picker, `Enter`/`Escape`/hover-`×` to confirm/cancel/clear).

**Tech Stack:** Laravel (PHP 8.2+), Vue 3 `<script setup>` + TypeScript, Vitest + `@vue/test-utils`, PHPUnit.

## Global Constraints

- Presentation + a small backend indexing change only. No new API endpoints, no database migration, no change to the Markdown-on-disk invariant (spec §1) — an icon is just another front-matter key.
- No in-app emoji picker (grid/categories/search) — the user pastes an emoji via their OS's native picker into a plain `<input type="text">`.
- No rendering in `SearchResults.vue`, `BacklinksPanel.vue`, or `OutgoingLinksPanel.vue` in this pass — only `NoteTreeNode.vue` and `NoteEditor.vue`.
- No cover image feature — separate, not part of this plan.
- `icon` must never appear in `PropertiesPanel.vue`'s rendered list (mirrors how `tags` is already excluded).
- All commands run through the `jt` Docker wrappers per `CLAUDE.md`: `./scripts/jt.sh npm -- test` / `./scripts/jt.sh npm -- test -- <name>` for frontend, `./scripts/jt.sh test` for the full suite, `./scripts/jt.sh artisan test --filter=<Name>` for a single PHP test class.
- Icon slot always renders something (emoji or a fallback document-glyph SVG) — fixed width, so title position never shifts when an icon is added/removed.
- 44×44px minimum touch target on the editor's icon button (existing product-wide rule).

---

## Task 1: Exclude `icon` from `NotePropertyProjector`, verify via PHPUnit

**Files:**
- Modify: `app/Domain/Vault/NotePropertyProjector.php`
- Test: `tests/Feature/NotePropertyProjectionTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: guarantees that a note with `icon` in its front-matter never gets a corresponding `note_properties` row — every later frontend task relies on this (it's what keeps `note.properties` clean, with zero filtering needed on the frontend).

- [ ] **Step 1: Write the failing test**

Add this test method to `tests/Feature/NotePropertyProjectionTest.php`, inside the `NotePropertyProjectionTest` class (after the existing `test_unquoted_yaml_date_is_projected_as_datetime_not_numeric` method):

```php
    public function test_icon_frontmatter_key_is_excluded_from_property_projection(): void
    {
        $tenant = Tenant::create(['slug' => 'icon-test', 'name' => 'Icon Test']);
        $vaultPath = storage_path('app/vaults/prop_icon_'.uniqid());
        @mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'icon-test',
            'name' => 'Icon Test Workspace',
            'vault_path' => $vaultPath,
        ]);

        $storage = new VaultStorage();
        $note = $storage->write($workspace, 'iconed.md', <<<'MARKDOWN'
---
title: Iconed Note
icon: "📄"
status: "active"
---
# Iconed Note Body
MARKDOWN);

        // icon must not be projected as a queryable NoteProperty row...
        $this->assertDatabaseMissing('note_properties', [
            'note_id' => $note->id,
            'name' => 'icon',
        ]);

        // ...but a sibling ordinary key on the same note still is, proving
        // the exclusion is scoped to `icon` specifically, not a projection
        // regression.
        $this->assertDatabaseHas('note_properties', [
            'note_id' => $note->id,
            'name' => 'status',
            'type' => 'string',
            'value_string' => 'active',
        ]);

        // ...and the frontmatter column itself still carries the raw value,
        // since that's what the frontend reads to render the icon.
        $this->assertSame('📄', $note->fresh()->frontmatter['icon']);
    }
```

- [ ] **Step 2: Run it to verify it fails**

Run: `./scripts/jt.sh artisan test --filter=test_icon_frontmatter_key_is_excluded_from_property_projection`
Expected: FAIL — the `assertDatabaseMissing` assertion fails because `icon` is currently projected like any other string property.

- [ ] **Step 3: Add the exclusion**

In `app/Domain/Vault/NotePropertyProjector.php`, find:
```php
        foreach ($frontmatter as $key => $value) {
            if ($key === 'tags' || $value === null) {
                continue;
            }
```
Replace with:
```php
        foreach ($frontmatter as $key => $value) {
            if ($key === 'tags' || $key === 'icon' || $value === null) {
                continue;
            }
```

- [ ] **Step 4: Run the test again to verify it passes**

Run: `./scripts/jt.sh artisan test --filter=test_icon_frontmatter_key_is_excluded_from_property_projection`
Expected: PASS.

- [ ] **Step 5: Run the full PHP suite to confirm nothing else broke**

Run: `./scripts/jt.sh artisan test`
Expected: all tests PASS (this touches a shared projector used by every note write — confirm no regression in the rest of `NotePropertyProjectionTest` or any other property-related test).

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Vault/NotePropertyProjector.php tests/Feature/NotePropertyProjectionTest.php
git commit -m "feat: exclude icon frontmatter key from NoteProperty projection"
```

---

## Task 2: Render the icon (read-only) in `NoteTreeNode.vue`

**Files:**
- Modify: `frontend/src/components/NoteTreeNode.vue`

**Interfaces:**
- Consumes: `node.note.frontmatter?.icon` — `NoteMeta.frontmatter` is `Record<string, unknown> | null` (`frontend/src/services/types.ts`), already populated by the notes-list endpoint; no prop changes needed, `NoteTreeNode` already receives full `NoteMeta` objects via its existing `TreeFile`/`TreeNode` types.
- Produces: nothing consumed by later tasks — this is a self-contained, read-only rendering change.

- [ ] **Step 1: Add the icon element to the note-item template**

In `frontend/src/components/NoteTreeNode.vue`, find (the `.note-item` branch, before `.note-info`):
```html
  <div
    v-else
    class="note-item"
    :class="{ active: selectedNoteId === node.note.id }"
    :style="{ paddingLeft: `${depth * 14 + 8}px` }"
    @click="$emit('select-note', node.note.id)"
  >
    <div class="note-info">
```
Replace with:
```html
  <div
    v-else
    class="note-item"
    :class="{ active: selectedNoteId === node.note.id }"
    :style="{ paddingLeft: `${depth * 14 + 8}px` }"
    @click="$emit('select-note', node.note.id)"
  >
    <span v-if="noteIcon" class="note-icon" data-testid="note-icon-emoji">{{ noteIcon }}</span>
    <svg v-else class="note-icon note-icon-fallback" data-testid="note-icon-fallback" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="1.5">
      <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
      <polyline points="14 2 14 8 20 8"></polyline>
    </svg>
    <div class="note-info">
```

- [ ] **Step 2: Compute `noteIcon` in the script block**

In `frontend/src/components/NoteTreeNode.vue`, find:
```typescript
const noteCount = computed(() => (props.node.type === 'folder' ? countNotes(props.node) : 0))
```
Add immediately after it:
```typescript

const noteIcon = computed(() => {
  if (props.node.type !== 'file') return null
  const icon = props.node.note.frontmatter?.icon
  return typeof icon === 'string' && icon.trim() !== '' ? icon : null
})
```

- [ ] **Step 3: Add the CSS**

In `frontend/src/components/NoteTreeNode.vue`, find:
```css
.note-info {
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
```
Replace with:
```css
.note-icon {
  flex-shrink: 0;
  width: 16px;
  height: 16px;
  display: flex;
  align-items: center;
  justify-content: center;
  font-size: 0.875rem;
  line-height: 1;
}

.note-icon-fallback {
  color: var(--color-text-muted);
}

.note-info {
  display: flex;
  flex-direction: column;
  overflow: hidden;
}
```

- [ ] **Step 4: Run the frontend test suite and design-token guard**

Run: `./scripts/jt.sh npm -- test`
Expected: PASS (no existing spec targets `NoteTreeNode.vue` directly — this is a template/script-only addition, verified manually in Task 4's App-level check and via the design-token guard here).

Run: `./scripts/check-design-tokens.sh`
Expected: `✅ All Visual Identity CI Guards PASSED.` (the new CSS uses only `var(--color-text-muted)`, no raw literals).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/NoteTreeNode.vue
git commit -m "feat: render page icon (read-only) in the sidebar tree"
```

---

## Task 3: Icon display + edit/clear UI in `NoteEditor.vue`

**Files:**
- Modify: `frontend/src/components/NoteEditor.vue`

**Interfaces:**
- Consumes: `setNoteProperty(workspaceId, noteId, name, value): Promise<NoteDetail>` and `deleteNoteProperty(workspaceId, noteId, name): Promise<NoteDetail>` from `frontend/src/services/api.ts` (already imported in this file, per its existing `import { ..., setNoteProperty, deleteNoteProperty, ... } from '../services/api'` line — no new import needed). Reads `note.frontmatter?.icon` the same way Task 2 reads it on `NoteMeta`.
- Produces: on successful set/delete, emits `select-note` with `props.note.id` — the exact same refresh mechanism `handleAddProperty`/`handleDeleteProperty` already use, which `App.vue`'s existing `handleSelectNote` consumes to reload the active note and notes list. No new emit type.

- [ ] **Step 1: Add local state for the icon-editing UI**

In `frontend/src/components/NoteEditor.vue`, find:
```typescript
const viewMode = ref<'edit' | 'split' | 'preview'>('split')
```
Add immediately before it:
```typescript
const isEditingIcon = ref(false)
const iconDraft = ref('')

const noteIcon = computed(() => {
  const icon = props.note.frontmatter?.icon
  return typeof icon === 'string' && icon.trim() !== '' ? icon : null
})

function startEditingIcon() {
  iconDraft.value = noteIcon.value ?? ''
  isEditingIcon.value = true
}

function cancelEditingIcon() {
  isEditingIcon.value = false
  iconDraft.value = ''
}

async function confirmEditingIcon() {
  const trimmed = iconDraft.value.trim()
  if (!props.workspaceId) {
    cancelEditingIcon()
    return
  }
  try {
    if (trimmed === '') {
      await deleteNoteProperty(props.workspaceId, props.note.id, 'icon')
    } else {
      await setNoteProperty(props.workspaceId, props.note.id, 'icon', trimmed)
    }
    emit('select-note', props.note.id)
  } catch (err) {
    console.error('Failed to update page icon:', err)
  }
  isEditingIcon.value = false
  iconDraft.value = ''
}

async function clearIcon() {
  if (!props.workspaceId) return
  try {
    await deleteNoteProperty(props.workspaceId, props.note.id, 'icon')
    emit('select-note', props.note.id)
  } catch (err) {
    console.error('Failed to clear page icon:', err)
  }
}
```

`viewMode` and everything below it in the file is unchanged — this only inserts new declarations above it. `computed` is already imported in this file's existing `import { ref, watch, computed, nextTick } from 'vue'` line, so no import change is needed there either.

- [ ] **Step 2: Add the icon UI to the template**

In `frontend/src/components/NoteEditor.vue`, find:
```html
      <div class="note-meta-info">
        <h2 class="editor-title" data-testid="editor-title">{{ note.title || note.path }}</h2>
        <span class="editor-path" data-testid="editor-path">{{ note.path }}</span>
      </div>
```
Replace with:
```html
      <div class="note-meta-info">
        <div class="editor-title-row">
          <button
            v-if="!isEditingIcon"
            type="button"
            class="editor-icon-btn"
            :aria-label="noteIcon ? 'Change page icon' : 'Set page icon'"
            data-testid="editor-icon-btn"
            @click="startEditingIcon"
          >
            <span v-if="noteIcon" data-testid="editor-icon-emoji">{{ noteIcon }}</span>
            <svg v-else data-testid="editor-icon-fallback" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14 2 14 8 20 8"></polyline>
            </svg>
            <span v-if="noteIcon" class="editor-icon-clear" data-testid="editor-icon-clear" @click.stop="clearIcon">&times;</span>
          </button>
          <input
            v-else
            v-model="iconDraft"
            type="text"
            class="editor-icon-input"
            data-testid="editor-icon-input"
            autofocus
            @keydown.enter="confirmEditingIcon"
            @keydown.escape="cancelEditingIcon"
            @blur="confirmEditingIcon"
          />
          <h2 class="editor-title" data-testid="editor-title">{{ note.title || note.path }}</h2>
        </div>
        <span class="editor-path" data-testid="editor-path">{{ note.path }}</span>
      </div>
```

(`@blur="confirmEditingIcon"` means clicking away also confirms/clears rather than silently discarding — consistent with the spec's Enter behavior, since a blur with an empty draft calls the same delete path as an empty-Enter.)

- [ ] **Step 3: Add the CSS**

In `frontend/src/components/NoteEditor.vue`, find:
```css
.note-meta-info {
  display: flex;
  flex-direction: column;
}
```
Replace with:
```css
.note-meta-info {
  display: flex;
  flex-direction: column;
}

.editor-title-row {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.editor-icon-btn {
  position: relative;
  flex-shrink: 0;
  min-width: 44px;
  min-height: 44px;
  display: flex;
  align-items: center;
  justify-content: center;
  background: transparent;
  border: none;
  border-radius: var(--radius-sm);
  color: var(--color-text-muted);
  font-size: 1.25rem;
  cursor: pointer;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.editor-icon-btn:hover {
  background: var(--color-hover);
}

.editor-icon-clear {
  position: absolute;
  top: 0;
  right: 0;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  border-radius: var(--radius-pill);
  background: var(--color-surface-emphasis);
  color: var(--color-text-muted);
  font-size: 0.75rem;
  line-height: 1;
  opacity: 0;
  transition: opacity var(--duration-fast) var(--ease-standard);
}

.editor-icon-btn:hover .editor-icon-clear {
  opacity: 1;
}

.editor-icon-input {
  width: 44px;
  min-height: 44px;
  text-align: center;
  font-size: 1.25rem;
  background: var(--color-surface);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-sm);
  color: var(--color-text);
}
```

- [ ] **Step 4: Run the design-token guard**

Run: `./scripts/check-design-tokens.sh`
Expected: `✅ All Visual Identity CI Guards PASSED.` (every new color value above is a `var(--color-*)` reference).

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/NoteEditor.vue
git commit -m "feat: add page icon display and edit/clear UI to NoteEditor"
```

---

## Task 4: `NoteEditor.spec.ts` — test the icon behavior end to end

There is no existing spec file for `NoteEditor.vue` (unlike most other components, which each have a `frontend/src/<Name>.spec.ts`), and no existing spec mounts a component that itself calls into `services/api` on mount other than `App.spec.ts`/`LoginModal.spec.ts` — this task creates that file from scratch, following the same flat-file naming convention as `frontend/src/HistoryPanel.spec.ts` (`frontend/src/<Name>.spec.ts`, importing `./components/<Name>.vue`).

**Files:**
- Create: `frontend/src/NoteEditor.spec.ts`

**Interfaces:**
- Consumes: `NoteEditor.vue`'s props (`note: NoteDetail`, `allNotes: NoteMeta[]`, `workspaceId?: number`) and the `services/api` functions it calls on mount: `getNoteComments`, `getUnlinkedMentions`, `getOutgoingLinks` (all three fire immediately via the `watch(() => props.note, ..., { immediate: true })` block), plus `setNoteProperty`/`deleteNoteProperty` (the ones this task actually exercises).

- [ ] **Step 1: Write the failing tests**

Create `frontend/src/NoteEditor.spec.ts`:

```typescript
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import NoteEditor from './components/NoteEditor.vue'
import type { NoteDetail } from './services/types'

vi.mock('./services/api', () => ({
  getNoteComments: vi.fn().mockResolvedValue([]),
  getUnlinkedMentions: vi.fn().mockResolvedValue([]),
  getOutgoingLinks: vi.fn().mockResolvedValue([]),
  setNoteProperty: vi.fn().mockResolvedValue({}),
  deleteNoteProperty: vi.fn().mockResolvedValue({}),
}))

import { setNoteProperty, deleteNoteProperty } from './services/api'

function makeNote(overrides: Partial<NoteDetail> = {}): NoteDetail {
  return {
    id: 1,
    path: 'test-note.md',
    title: 'Test Note',
    frontmatter: null,
    updated_at: '2026-07-31T00:00:00Z',
    content: '# Test Note',
    backlinks: [],
    properties: [],
    ...overrides,
  }
}

describe('NoteEditor page icon', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders the fallback icon when no icon is set', () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    expect(wrapper.find('[data-testid="editor-icon-fallback"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="editor-icon-emoji"]').exists()).toBe(false)
  })

  it('renders the emoji when frontmatter.icon is set', () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ frontmatter: { icon: '📄' } }),
        allNotes: [],
        workspaceId: 1,
      },
    })
    expect(wrapper.find('[data-testid="editor-icon-emoji"]').text()).toBe('📄')
    expect(wrapper.find('[data-testid="editor-icon-fallback"]').exists()).toBe(false)
  })

  it('clicking the icon opens the input, typing an emoji and pressing Enter calls setNoteProperty', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    await wrapper.find('[data-testid="editor-icon-btn"]').trigger('click')
    const input = wrapper.find('[data-testid="editor-icon-input"]')
    expect(input.exists()).toBe(true)
    await input.setValue('🚀')
    await input.trigger('keydown.enter')
    expect(setNoteProperty).toHaveBeenCalledWith(1, 1, 'icon', '🚀')
  })

  it('pressing Enter with an empty draft calls deleteNoteProperty instead', async () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ frontmatter: { icon: '📄' } }),
        allNotes: [],
        workspaceId: 1,
      },
    })
    await wrapper.find('[data-testid="editor-icon-btn"]').trigger('click')
    const input = wrapper.find('[data-testid="editor-icon-input"]')
    await input.setValue('')
    await input.trigger('keydown.enter')
    expect(deleteNoteProperty).toHaveBeenCalledWith(1, 1, 'icon')
    expect(setNoteProperty).not.toHaveBeenCalled()
  })

  it('clicking the hover clear button calls deleteNoteProperty without opening the input', async () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ frontmatter: { icon: '📄' } }),
        allNotes: [],
        workspaceId: 1,
      },
    })
    await wrapper.find('[data-testid="editor-icon-clear"]').trigger('click')
    expect(deleteNoteProperty).toHaveBeenCalledWith(1, 1, 'icon')
    expect(wrapper.find('[data-testid="editor-icon-input"]').exists()).toBe(false)
  })

  it('pressing Escape closes the input without calling either API function', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    await wrapper.find('[data-testid="editor-icon-btn"]').trigger('click')
    const input = wrapper.find('[data-testid="editor-icon-input"]')
    await input.setValue('🚀')
    await input.trigger('keydown.escape')
    expect(setNoteProperty).not.toHaveBeenCalled()
    expect(deleteNoteProperty).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="editor-icon-input"]').exists()).toBe(false)
  })
})
```

- [ ] **Step 2: Run it to verify current state**

Run: `./scripts/jt.sh npm -- test -- NoteEditor`
Expected: the first two tests (fallback/emoji rendering) and the Escape test should already PASS if Task 3 landed correctly — this task's file is created *after* Task 3's UI exists, so this step is a verification pass, not a red-then-green cycle for those three. The Enter/clear tests should also PASS already, since Task 3 wired the real handlers. If any test FAILs here, it means a mismatch between this spec's assumptions and Task 3's actual implementation (e.g. a `data-testid` typo) — fix the mismatch in whichever of the two is wrong before proceeding, don't paper over it by changing the assertion to match a bug.

- [ ] **Step 3: Run the full frontend suite**

Run: `./scripts/jt.sh npm -- test`
Expected: PASS — confirms this new spec file doesn't collide with or break any other test (e.g. via shared mock state).

- [ ] **Step 4: Commit**

```bash
git add frontend/src/NoteEditor.spec.ts
git commit -m "test: add NoteEditor page icon coverage"
```

---

## Task 5: Full verification pass

**Files:** none (verification only — if this finds a regression, fix it in the relevant file from Tasks 1–4 and note the fix in this task's own commit).

- [ ] **Step 1: Run the complete test suite (Laravel + frontend)**

Run: `./scripts/jt.sh test`
Expected: all PHP and frontend tests PASS.

- [ ] **Step 2: Run the design-token guard**

Run: `./scripts/check-design-tokens.sh`
Expected: `✅ All Visual Identity CI Guards PASSED.`

- [ ] **Step 3: Manual spot-check via the dev server**

Run: `./scripts/jt.sh up`, open `http://localhost:8080` (or `${APP_PORT}`), log in, select a note.

Verify by hand:
- The note shows a document-outline fallback icon in both the sidebar row and the editor title, at a fixed size that doesn't shift the title when toggled.
- Clicking the editor's icon opens a small input; pasting an emoji (via the OS's native emoji picker) and pressing Enter sets it, and it now appears in both the editor title and the sidebar row for that note.
- Hovering the now-set icon in the editor reveals a small "×"; clicking it removes the icon, reverting to the fallback in both places.
- Opening the note's Properties panel (the `PropertiesPanel` section already in the editor) does **not** show an `icon` entry anywhere in its list.
- Pressing Escape while editing the icon closes the input without changing anything.

If any manual check fails, fix the specific file/rule responsible and re-run Steps 1–2 before proceeding.

- [ ] **Step 4: Commit (only if Step 3 required fixes; otherwise this task produces no diff and needs no commit)**

```bash
git add -A
git commit -m "fix: address issues found during page icon verification pass"
```
