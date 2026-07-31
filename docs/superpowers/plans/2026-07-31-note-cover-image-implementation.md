# Note Cover Image Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let a note have a cover image banner, set via file upload or a pasted URL, displayed above the editor's title/icon header.

**Architecture:** A `cover` front-matter key holds a URL string, excluded from `NoteProperty` projection exactly like `icon` already is (one-line backend change). A new `CoverImageModal.vue` component collects the URL (via the existing attachment-upload endpoint or a pasted URL); `NoteEditor.vue` renders the banner (or an "Add cover" affordance) and wires the modal to the existing `setNoteProperty`/`deleteNoteProperty` functions.

**Tech Stack:** Laravel 8.2+/PHP, MySQL, PHPUnit; Vue 3 `<script setup>` + TypeScript, Vitest, `@vue/test-utils`.

## Global Constraints

- No new backend endpoint — reuses `POST /workspaces/{w}/attachments` (upload) and the existing note-property endpoints (spec §1, §3).
- `cover` must never appear as a queryable `NoteProperty` row — excluded in `NotePropertyProjector` alongside `tags`/`icon` (spec §3).
- No cover/icon visual coupling — the page icon (feature 1) keeps its current position, untouched by this feature (spec §2, §4).
- No cropping/repositioning UI, no built-in cover art gallery (spec §2).

---

### Task 1: Backend — exclude `cover` from property projection

**Files:**
- Modify: `app/Domain/Vault/NotePropertyProjector.php:16`
- Test: `tests/Feature/NotePropertyProjectionTest.php`

**Interfaces:**
- Consumes: nothing new.
- Produces: notes with a `cover` front-matter key no longer get a
  matching `NoteProperty` row, while the raw value stays in
  `Note.frontmatter` — verified by this task's test, consumed by no
  later backend task (Tasks 2–3 are frontend-only).

- [ ] **Step 1: Write the failing test**

Add to `tests/Feature/NotePropertyProjectionTest.php`, as a new method
right after `test_icon_frontmatter_key_is_excluded_from_property_projection`
(mirrors it exactly, swapping `icon` for `cover`):

```php
    public function test_cover_frontmatter_key_is_excluded_from_property_projection(): void
    {
        $tenant = Tenant::create(['slug' => 'cover-test', 'name' => 'Cover Test']);
        $vaultPath = storage_path('app/vaults/prop_cover_'.uniqid());
        @mkdir($vaultPath, 0755, true);

        $workspace = Workspace::create([
            'tenant_id' => $tenant->id,
            'slug' => 'cover-test',
            'name' => 'Cover Test Workspace',
            'vault_path' => $vaultPath,
        ]);

        $storage = new VaultStorage();
        $note = $storage->write($workspace, 'covered.md', <<<'MARKDOWN'
---
title: Covered Note
cover: "https://example.com/banner.jpg"
status: "active"
---
# Covered Note Body
MARKDOWN);

        // cover must not be projected as a queryable NoteProperty row...
        $this->assertDatabaseMissing('note_properties', [
            'note_id' => $note->id,
            'name' => 'cover',
        ]);

        // ...but a sibling ordinary key on the same note still is, proving
        // the exclusion is scoped to `cover` specifically.
        $this->assertDatabaseHas('note_properties', [
            'note_id' => $note->id,
            'name' => 'status',
            'type' => 'string',
            'value_string' => 'active',
        ]);

        // ...and the frontmatter column itself still carries the raw value,
        // since that's what the frontend reads to render the cover.
        $this->assertSame('https://example.com/banner.jpg', $note->fresh()->frontmatter['cover']);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `./scripts/jt.sh test --filter=test_cover_frontmatter_key_is_excluded_from_property_projection`
Expected: FAIL — a `note_properties` row named `cover` exists (the
`assertDatabaseMissing` assertion fails), since nothing excludes it yet.

- [ ] **Step 3: Add the exclusion**

In `app/Domain/Vault/NotePropertyProjector.php`, change:

```php
            if ($key === 'tags' || $key === 'icon' || $value === null) {
                continue;
            }
```

to:

```php
            if ($key === 'tags' || $key === 'icon' || $key === 'cover' || $value === null) {
                continue;
            }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `./scripts/jt.sh test --filter=NotePropertyProjectionTest`
Expected: PASS — both the new `cover` test and the pre-existing `icon`
test (and every other test in this file) pass.

- [ ] **Step 5: Commit**

```bash
git add app/Domain/Vault/NotePropertyProjector.php tests/Feature/NotePropertyProjectionTest.php
git commit -m "feat: exclude cover frontmatter key from property projection"
```

---

### Task 2: `CoverImageModal.vue` — upload/URL picker

**Files:**
- Create: `frontend/src/components/CoverImageModal.vue`
- Test: `frontend/src/CoverImageModal.spec.ts`

**Interfaces:**
- Consumes: `uploadAttachment(workspaceId: number, file: File):
  Promise<AttachmentItem>` (existing, confirmed at
  `frontend/src/services/api.ts:312`, `AttachmentItem` has a `url: string`
  field per `frontend/src/services/types.ts:178-186`).
- Produces: props `{ workspaceId: number }`; emits
  `(e: 'set-cover', url: string): void` and `(e: 'close'): void`. Task 3
  (`NoteEditor.vue`) mounts this component and listens for both.

- [ ] **Step 1: Write the failing tests**

```typescript
// frontend/src/CoverImageModal.spec.ts
import { mount } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import CoverImageModal from './components/CoverImageModal.vue'

vi.mock('./services/api', () => ({
  uploadAttachment: vi.fn(),
}))

import { uploadAttachment } from './services/api'

describe('CoverImageModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('starts on the Upload tab', () => {
    const wrapper = mount(CoverImageModal, { props: { workspaceId: 1 } })
    expect(wrapper.find('[data-testid="cover-upload-input"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="cover-url-input"]').exists()).toBe(false)
  })

  it('switches to the URL tab', async () => {
    const wrapper = mount(CoverImageModal, { props: { workspaceId: 1 } })
    await wrapper.find('[data-testid="cover-url-tab-btn"]').trigger('click')
    expect(wrapper.find('[data-testid="cover-url-input"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="cover-upload-input"]').exists()).toBe(false)
  })

  it('disables the URL tab\'s submit button for an empty input', async () => {
    const wrapper = mount(CoverImageModal, { props: { workspaceId: 1 } })
    await wrapper.find('[data-testid="cover-url-tab-btn"]').trigger('click')
    const submitBtn = wrapper.find('[data-testid="cover-url-submit-btn"]')
    expect(submitBtn.attributes('disabled')).toBeDefined()
    await wrapper.find('[data-testid="cover-url-input"]').setValue('https://example.com/x.jpg')
    expect(wrapper.find('[data-testid="cover-url-submit-btn"]').attributes('disabled')).toBeUndefined()
  })

  it('emits set-cover with the typed URL and does not call uploadAttachment', async () => {
    const wrapper = mount(CoverImageModal, { props: { workspaceId: 1 } })
    await wrapper.find('[data-testid="cover-url-tab-btn"]').trigger('click')
    await wrapper.find('[data-testid="cover-url-input"]').setValue('https://example.com/x.jpg')
    await wrapper.find('[data-testid="cover-url-submit-btn"]').trigger('click')
    expect(wrapper.emitted('set-cover')).toEqual([['https://example.com/x.jpg']])
    expect(uploadAttachment).not.toHaveBeenCalled()
  })

  it('uploads the selected file and emits set-cover with the resulting url', async () => {
    vi.mocked(uploadAttachment).mockResolvedValue({
      id: 1, workspace_id: 1, path: 'covers/x.jpg', mime: 'image/jpeg', size: 100,
      created_at: '2026-07-31T00:00:00Z', url: 'https://app/attachments/covers/x.jpg',
    })
    const wrapper = mount(CoverImageModal, { props: { workspaceId: 1 } })
    const input = wrapper.find('[data-testid="cover-upload-input"]')
    const file = new File(['x'], 'x.jpg', { type: 'image/jpeg' })
    Object.defineProperty(input.element, 'files', { value: [file] })
    await input.trigger('change')
    await wrapper.vm.$nextTick()
    expect(uploadAttachment).toHaveBeenCalledWith(1, file)
    expect(wrapper.emitted('set-cover')).toEqual([['https://app/attachments/covers/x.jpg']])
  })

  it('emits close when the overlay background is clicked', async () => {
    const wrapper = mount(CoverImageModal, { props: { workspaceId: 1 } })
    await wrapper.find('.modal-overlay').trigger('click.self')
    expect(wrapper.emitted('close')).toBeTruthy()
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./scripts/jt.sh npm test -- CoverImageModal.spec.ts`
Expected: FAIL — the component file doesn't exist yet.

- [ ] **Step 3: Write the component**

```html
<!-- frontend/src/components/CoverImageModal.vue -->
<template>
  <div class="modal-overlay" @click.self="$emit('close')">
    <div class="modal-card">
      <h3>Set cover image</h3>
      <div class="cover-tabs">
        <button
          type="button"
          class="cover-tab-btn"
          data-testid="cover-upload-tab-btn"
          :class="{ active: activeTab === 'upload' }"
          @click="activeTab = 'upload'"
        >Upload</button>
        <button
          type="button"
          class="cover-tab-btn"
          data-testid="cover-url-tab-btn"
          :class="{ active: activeTab === 'url' }"
          @click="activeTab = 'url'"
        >URL</button>
      </div>

      <div v-if="activeTab === 'upload'">
        <p class="modal-desc">Choose an image file to upload as the cover.</p>
        <input
          type="file"
          accept="image/*"
          class="modal-input"
          data-testid="cover-upload-input"
          @change="handleFileSelected"
        />
      </div>

      <div v-else>
        <p class="modal-desc">Paste a direct image URL.</p>
        <input
          v-model="urlDraft"
          type="url"
          class="modal-input"
          data-testid="cover-url-input"
          placeholder="https://example.com/banner.jpg"
        />
        <div class="modal-actions">
          <button type="button" class="btn-secondary" @click="$emit('close')">Cancel</button>
          <button
            type="button"
            class="btn-primary"
            data-testid="cover-url-submit-btn"
            :disabled="urlDraft.trim() === ''"
            @click="$emit('set-cover', urlDraft.trim())"
          >Set cover</button>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { uploadAttachment } from '../services/api'

const props = defineProps<{
  workspaceId: number
}>()

const emit = defineEmits<{
  (e: 'set-cover', url: string): void
  (e: 'close'): void
}>()

const activeTab = ref<'upload' | 'url'>('upload')
const urlDraft = ref('')

async function handleFileSelected(event: Event) {
  const input = event.target as HTMLInputElement
  const file = input.files?.[0]
  if (!file) return
  try {
    const attachment = await uploadAttachment(props.workspaceId, file)
    emit('set-cover', attachment.url)
  } catch (err) {
    console.error('Cover image upload failed:', err)
  }
}
</script>

<style scoped>
.modal-overlay {
  position: fixed;
  inset: 0;
  background: var(--color-overlay);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
}

.modal-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  padding: var(--space-6);
  width: 360px;
  box-shadow: var(--shadow-float);
}

.modal-card h3 {
  margin: 0 0 var(--space-4);
  color: var(--color-text);
  font-size: 1.125rem;
  font-weight: 600;
}

.cover-tabs {
  display: flex;
  gap: var(--space-2);
  margin-bottom: var(--space-4);
}

.cover-tab-btn {
  flex: 1;
  background: transparent;
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-2);
  color: var(--color-text-muted);
  cursor: pointer;
  font-size: 0.875rem;
  transition: border-color var(--duration-fast) var(--ease-standard),
              color var(--duration-fast) var(--ease-standard);
}

.cover-tab-btn.active {
  border-color: var(--color-action);
  color: var(--color-action);
}

.modal-desc {
  font-size: 0.875rem;
  color: var(--color-text-muted);
  margin-bottom: var(--space-4);
}

.modal-input {
  width: 100%;
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-2) var(--space-3);
  color: var(--color-text);
  margin-bottom: var(--space-4);
  font-size: 0.875rem;
  transition: border-color var(--duration-fast) var(--ease-standard);
}

.modal-input:focus {
  border-color: var(--color-action);
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: var(--space-3);
}

.btn-secondary {
  background: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-text-muted);
  padding: var(--space-2) var(--space-4);
  border-radius: var(--radius-sm);
  cursor: pointer;
  min-height: 36px;
  font-size: 0.875rem;
  transition: border-color var(--duration-fast) var(--ease-standard),
              color var(--duration-fast) var(--ease-standard);
}

.btn-secondary:hover {
  border-color: var(--color-border-strong);
  color: var(--color-text);
}

.btn-primary {
  background: var(--color-action);
  color: var(--color-neutral-0);
  border: none;
  padding: var(--space-2) var(--space-4);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-weight: 500;
  font-size: 0.875rem;
  min-height: 36px;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.btn-primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `./scripts/jt.sh npm test -- CoverImageModal.spec.ts`
Expected: PASS, all 6 tests.

- [ ] **Step 5: Commit**

```bash
git add frontend/src/components/CoverImageModal.vue frontend/src/CoverImageModal.spec.ts
git commit -m "feat: add CoverImageModal for uploading or pasting a note cover image"
```

---

### Task 3: `NoteEditor.vue` — cover banner and modal wiring

**Files:**
- Modify: `frontend/src/components/NoteEditor.vue`
- Test: `frontend/src/NoteEditor.spec.ts`

**Interfaces:**
- Consumes: `CoverImageModal` (Task 2), `setNoteProperty`/
  `deleteNoteProperty` (existing, already imported in this file).
- Produces: nothing consumed by later tasks — final wiring point.

- [ ] **Step 1: Write the failing tests**

Add to `frontend/src/NoteEditor.spec.ts`, as a new sibling `describe`:

```typescript
describe('NoteEditor cover image', () => {
  it('renders the Add cover button when no cover is set', () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    expect(wrapper.find('[data-testid="add-cover-btn"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="editor-cover-image"]').exists()).toBe(false)
  })

  it('renders the cover image when frontmatter.cover is set', () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ frontmatter: { cover: 'https://example.com/banner.jpg' } }),
        allNotes: [],
        workspaceId: 1,
      },
    })
    const img = wrapper.find('[data-testid="editor-cover-image"]')
    expect(img.exists()).toBe(true)
    expect(img.attributes('src')).toBe('https://example.com/banner.jpg')
    expect(wrapper.find('[data-testid="add-cover-btn"]').exists()).toBe(false)
  })

  it('opens the cover modal when Add cover is clicked', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    await wrapper.find('[data-testid="add-cover-btn"]').trigger('click')
    expect(wrapper.findComponent({ name: 'CoverImageModal' }).exists()).toBe(true)
  })

  it('sets the cover property when the modal emits set-cover', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    await wrapper.find('[data-testid="add-cover-btn"]').trigger('click')
    await wrapper.findComponent({ name: 'CoverImageModal' }).vm.$emit('set-cover', 'https://example.com/banner.jpg')
    expect(setNoteProperty).toHaveBeenCalledWith(1, 1, 'cover', 'https://example.com/banner.jpg')
  })

  it('clears the cover property when Remove is clicked', async () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ frontmatter: { cover: 'https://example.com/banner.jpg' } }),
        allNotes: [],
        workspaceId: 1,
      },
    })
    await wrapper.find('[data-testid="remove-cover-btn"]').trigger('click')
    expect(deleteNoteProperty).toHaveBeenCalledWith(1, 1, 'cover')
  })
})
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `./scripts/jt.sh npm test -- NoteEditor.spec.ts`
Expected: FAIL — none of the cover-related testids or behavior exist yet.

- [ ] **Step 3: Add the computed, state, and handlers**

In `frontend/src/components/NoteEditor.vue`'s `<script setup>`, add the
import:

```typescript
import CoverImageModal from './CoverImageModal.vue'
```

Add this computed and state right after `const noteIcon = computed(...)`:

```typescript
const coverUrl = computed(() => {
  const cover = props.note.frontmatter?.cover
  return typeof cover === 'string' && cover.trim() !== '' ? cover : null
})

const isEditingCover = ref(false)

async function setCover(url: string) {
  isEditingCover.value = false
  if (!props.workspaceId) return
  try {
    await setNoteProperty(props.workspaceId, props.note.id, 'cover', url)
    emit('select-note', props.note.id)
  } catch (err) {
    console.error('Failed to set cover image:', err)
  }
}

async function clearCover() {
  if (!props.workspaceId) return
  try {
    await deleteNoteProperty(props.workspaceId, props.note.id, 'cover')
    emit('select-note', props.note.id)
  } catch (err) {
    console.error('Failed to clear cover image:', err)
  }
}
```

- [ ] **Step 4: Add the template**

Find the opening of the template (`<div class="editor-container">` and
the `<header class="editor-bar">` right after it):

```html
<template>
  <div class="editor-container">
    <!-- Top Action Bar -->
    <header class="editor-bar">
```

Change it to add the cover banner / add-cover button, and the modal,
right before `<header class="editor-bar">`:

```html
<template>
  <div class="editor-container">
    <div v-if="coverUrl" class="editor-cover-wrapper">
      <img class="editor-cover-image" data-testid="editor-cover-image" :src="coverUrl" alt="" />
      <div class="editor-cover-actions">
        <button type="button" class="btn-cover-action" data-testid="change-cover-btn" @click="isEditingCover = true">Change</button>
        <button type="button" class="btn-cover-action" data-testid="remove-cover-btn" @click="clearCover">Remove</button>
      </div>
    </div>
    <button
      v-else
      type="button"
      class="add-cover-btn"
      data-testid="add-cover-btn"
      @click="isEditingCover = true"
    >Add cover</button>

    <CoverImageModal
      v-if="isEditingCover && workspaceId"
      :workspace-id="workspaceId"
      @set-cover="setCover"
      @close="isEditingCover = false"
    />

    <!-- Top Action Bar -->
    <header class="editor-bar">
```

- [ ] **Step 5: Add the CSS**

In the `<style scoped>` block, add (anywhere among the other rules — a
good spot is right before the existing `.editor-path` rule):

```css
.add-cover-btn {
  width: 100%;
  background: transparent;
  border: none;
  border-bottom: 1px solid var(--color-border);
  color: var(--color-text-muted);
  padding: var(--space-2);
  cursor: pointer;
  font-size: 0.8125rem;
  text-align: center;
  transition: background-color var(--duration-fast) var(--ease-standard),
              color var(--duration-fast) var(--ease-standard);
}

.add-cover-btn:hover {
  background: var(--color-hover);
  color: var(--color-action);
}

.editor-cover-wrapper {
  position: relative;
  width: 100%;
  height: 200px;
  overflow: hidden;
}

.editor-cover-image {
  width: 100%;
  height: 100%;
  object-fit: cover;
  display: block;
}

.editor-cover-actions {
  position: absolute;
  bottom: var(--space-3);
  right: var(--space-3);
  display: flex;
  gap: var(--space-2);
  opacity: 0;
  transition: opacity var(--duration-fast) var(--ease-standard);
}

.editor-cover-wrapper:hover .editor-cover-actions {
  opacity: 1;
}

.btn-cover-action {
  background: var(--color-overlay);
  color: var(--color-text-inverse);
  border: none;
  border-radius: var(--radius-sm);
  padding: var(--space-1) var(--space-3);
  cursor: pointer;
  font-size: 0.8125rem;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.btn-cover-action:hover {
  background: color-mix(in srgb, var(--color-overlay) 80%, black);
}
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `./scripts/jt.sh npm test -- NoteEditor.spec.ts`
Expected: PASS, all tests in this file including the 5 new ones.

- [ ] **Step 7: Run the full frontend suite**

Run: `./scripts/jt.sh npm test`
Expected: PASS, all tests — no regressions.

- [ ] **Step 8: Commit**

```bash
git add frontend/src/components/NoteEditor.vue frontend/src/NoteEditor.spec.ts
git commit -m "feat: add cover image banner to the note editor"
```

---

## Self-Review Notes

- **Spec coverage:** §3 (storage, exclusion, read/write/delete/reindex) →
  Task 1 (exclusion) + Task 3 (read/write/delete via existing
  `setNoteProperty`/`deleteNoteProperty`, reindex needs no code change
  since the exclusion is what `vault:reindex` re-runs). §4 (modal + editor
  UI, both tabs, hover change/remove) → Tasks 2–3. §5 testing (every
  bullet) → one test file per task, matching exactly. §2 non-goals (no
  cropping, no gallery, no icon coupling, no new upload endpoint) →
  enforced by simply not building them; `uploadAttachment` reused as-is,
  `NoteEditor.vue`'s icon code (`noteIcon`/`startEditingIcon`/etc.) is
  untouched by any step in this plan.
- **Placeholder scan:** none found.
- **Type consistency:** `CoverImageModal`'s `set-cover` emits a `string`
  (Task 2), consumed by `NoteEditor.vue`'s `setCover(url: string)` (Task
  3) — matching name and type. `coverUrl` computed reads
  `note.frontmatter?.cover` the same way `noteIcon` already reads
  `note.frontmatter?.icon`, keeping the two features' patterns identical
  without sharing code (intentional, per spec §2's decoupling decision).
