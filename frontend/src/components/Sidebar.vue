<template>
  <aside class="sidebar">
    <!-- Header -->
    <div class="sidebar-header">
      <div class="brand">
        <svg class="brand-icon" viewBox="0 0 24 24" width="22" height="22" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
          <polyline points="14 2 14 8 20 8"></polyline>
          <line x1="16" y1="13" x2="8" y2="13"></line>
          <line x1="16" y1="17" x2="8" y2="17"></line>
          <polyline points="10 9 9 9 8 9"></polyline>
        </svg>
        <span class="brand-title">Jotter</span>
      </div>
      <div class="header-actions">
        <button class="btn-icon" data-testid="attachments-btn" title="Attachments" @click="$emit('toggle-attachments')">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
          </svg>
        </button>
        <button class="btn-icon" data-testid="new-note-btn" title="New Note" @click="showNewNoteModal = true">
          <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
            <line x1="12" y1="5" x2="12" y2="19"></line>
            <line x1="5" y1="12" x2="19" y2="12"></line>
          </svg>
        </button>
      </div>
    </div>

    <!-- Search Input -->
    <div class="search-box">
      <svg class="search-icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
        <circle cx="11" cy="11" r="8"></circle>
        <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
      </svg>
      <input 
        v-model="searchQuery" 
        type="text" 
        placeholder="Search notes or filter..." 
        class="search-input"
        @input="onSearchInput"
        @keydown.esc="clearSearch"
      />
      <button v-if="searchQuery" class="clear-btn" @click="clearSearch">×</button>
    </div>

    <!-- Tag Cloud Explorer -->
    <div v-if="availableTags.length > 0" class="tag-cloud-bar">
      <button 
        class="tag-pill" 
        :class="{ active: activeTag === null }"
        @click="activeTag = null"
      >
        All
      </button>
      <button 
        v-for="tag in availableTags" 
        :key="tag"
        class="tag-pill" 
        :class="{ active: activeTag === tag }"
        @click="activeTag = activeTag === tag ? null : tag"
      >
        #{{ tag }}
      </button>
    </div>

    <!-- Controls Bar: Sort Selector -->
    <div class="sidebar-sort-bar">
      <label for="sidebar-sort-select" class="sort-label">Sort:</label>
      <select id="sidebar-sort-select" v-model="sortBy" class="sort-select" aria-label="Sort notes by">
        <option value="recent">Recently Modified</option>
        <option value="name">Alphabetical</option>
        <option value="path">Vault Path</option>
      </select>
    </div>

    <!-- Notes List / Filtered List -->
    <div class="notes-container">
      <div class="section-label">
        <span>{{ activeTag ? `#${activeTag}` : searchQuery ? 'Filtered Notes' : 'All Notes' }}</span>
        <span class="note-count">{{ filteredAndSortedNotes.length }}</span>
      </div>

      <div v-if="filteredAndSortedNotes.length === 0" class="notes-empty">
        <p>No notes found.</p>
        <button class="btn-create-inline" @click="showNewNoteModal = true">Create a note</button>
      </div>

      <div v-else class="notes-list">
        <NoteTreeNode
          v-for="node in noteTree"
          :key="node.type === 'folder' ? `f:${node.fullPath}` : `n:${node.note.id}`"
          :node="node"
          :selected-note-id="selectedNoteId"
          :depth="0"
          @select-note="$emit('select-note', $event)"
          @delete-note="$emit('delete-note', $event)"
        />
      </div>
    </div>

    <!-- New Note Dialog -->
    <div v-if="showNewNoteModal" class="modal-overlay" @click.self="showNewNoteModal = false">
      <form class="modal-card" @submit.prevent="handleCreateNote">
        <h3>Create New Note</h3>
        <p class="modal-desc">Enter the relative vault path (e.g. <code>inbox/my-note.md</code>):</p>
        <input 
          v-model="newNotePath"
          data-testid="create-note-input"
          type="text"
          placeholder="note-title.md"
          class="modal-input"
          required
        />
        <div class="modal-actions">
          <button type="button" class="btn-secondary" @click="showNewNoteModal = false">Cancel</button>
          <button type="submit" class="btn-primary" data-testid="create-note-submit" :disabled="!newNotePath.trim()">Create</button>
        </div>
      </form>
    </div>

    <!-- User Profile Footer -->
    <div v-if="currentUser" class="sidebar-footer">
      <div class="user-badge" data-testid="user-profile">
        <span class="user-name">{{ currentUser.name }}</span>
        <span class="user-email">{{ currentUser.email }}</span>
      </div>
      <button class="btn-logout" data-testid="logout-btn" title="Sign Out" @click="$emit('logout')">
        <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
          <polyline points="16 17 21 12 16 7"></polyline>
          <line x1="21" y1="12" x2="9" y2="12"></line>
        </svg>
      </button>
    </div>
  </aside>
</template>

<script setup lang="ts">
import { ref, computed } from 'vue'
import type { NoteMeta, AuthUser } from '../services/types'
import NoteTreeNode from './NoteTreeNode.vue'
import type { TreeFolder, TreeNode } from './NoteTreeNode.vue'

const props = defineProps<{
  notes: NoteMeta[]
  selectedNoteId: number | null
  currentUser?: AuthUser | null
}>()

const emit = defineEmits<{
  (e: 'select-note', noteId: number): void
  (e: 'create-note', path: string): void
  (e: 'delete-note', noteId: number): void
  (e: 'search', query: string): void
  (e: 'logout'): void
  (e: 'toggle-attachments'): void
}>()

const searchQuery = ref('')
const activeTag = ref<string | null>(null)
const sortBy = ref<'recent' | 'name' | 'path'>('recent')
const showNewNoteModal = ref(false)
const newNotePath = ref('')

const availableTags = computed(() => {
  const tagSet = new Set<string>()
  for (const n of props.notes) {
    if (n.frontmatter?.tags && Array.isArray(n.frontmatter.tags)) {
      for (const t of n.frontmatter.tags) {
        if (typeof t === 'string') tagSet.add(t.replace(/^#/, ''))
      }
    }
    const matches = (n.title + ' ' + n.path).match(/#([a-zA-Z0-9_-]+)/g)
    if (matches) {
      for (const m of matches) tagSet.add(m.replace(/^#/, ''))
    }
  }
  return Array.from(tagSet)
})

const filteredAndSortedNotes = computed(() => {
  let list = props.notes

  if (activeTag.value) {
    const tag = activeTag.value.toLowerCase()
    list = list.filter(n => {
      if (n.frontmatter?.tags && Array.isArray(n.frontmatter.tags)) {
        if (n.frontmatter.tags.some(t => String(t).toLowerCase().replace(/^#/, '') === tag)) return true
      }
      return (n.title + ' ' + n.path).toLowerCase().includes(`#${tag}`)
    })
  }

  if (searchQuery.value.trim()) {
    const q = searchQuery.value.toLowerCase()
    list = list.filter(n => n.title.toLowerCase().includes(q) || n.path.toLowerCase().includes(q))
  }

  return [...list].sort((a, b) => {
    if (sortBy.value === 'recent') {
      return (b.updated_at || '').localeCompare(a.updated_at || '')
    }
    if (sortBy.value === 'name') {
      return (a.title || a.path).localeCompare(b.title || b.path)
    }
    return a.path.localeCompare(b.path)
  })
})

function buildTree(notes: NoteMeta[]): TreeNode[] {
  const root: TreeFolder = { type: 'folder', name: '', fullPath: '', children: [] }
  const folders = new Map<string, TreeFolder>([['', root]])

  function getFolder(path: string): TreeFolder {
    const existing = folders.get(path)
    if (existing) return existing
    const lastSlash = path.lastIndexOf('/')
    const parentPath = lastSlash === -1 ? '' : path.slice(0, lastSlash)
    const name = lastSlash === -1 ? path : path.slice(lastSlash + 1)
    const parent = getFolder(parentPath)
    const folder: TreeFolder = { type: 'folder', name, fullPath: path, children: [] }
    parent.children.push(folder)
    folders.set(path, folder)
    return folder
  }

  for (const note of notes) {
    const lastSlash = note.path.lastIndexOf('/')
    const folderPath = lastSlash === -1 ? '' : note.path.slice(0, lastSlash)
    getFolder(folderPath).children.push({ type: 'file', note })
  }

  function sortChildren(folder: TreeFolder) {
    const subfolders = folder.children.filter((c): c is TreeFolder => c.type === 'folder')
    const files = folder.children.filter((c) => c.type === 'file')
    subfolders.sort((a, b) => a.name.localeCompare(b.name))
    subfolders.forEach(sortChildren)
    folder.children = [...subfolders, ...files]
  }
  sortChildren(root)

  return root.children
}

const noteTree = computed(() => buildTree(filteredAndSortedNotes.value))

function onSearchInput() {
  emit('search', searchQuery.value)
}

function clearSearch() {
  searchQuery.value = ''
  emit('search', '')
}

function handleCreateNote() {
  const path = newNotePath.value.trim()
  if (!path) return
  emit('create-note', path.endsWith('.md') ? path : `${path}.md`)
  newNotePath.value = ''
  showNewNoteModal.value = false
}
</script>

<style scoped>
.sidebar {
  width: 280px;
  min-width: 280px;
  background: var(--color-surface);
  border-right: 1px solid var(--color-border);
  display: flex;
  flex-direction: column;
  height: 100%;
}

.sidebar-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-4) var(--space-4);
  border-bottom: 1px solid var(--color-border);
}

.brand {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  color: var(--color-text);
  font-weight: 700;
  font-size: 1.125rem;
}

.brand-icon {
  color: var(--color-action);
}

.header-actions {
  display: flex;
  align-items: center;
  gap: var(--space-1);
}

.btn-icon {
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
  min-width: 44px;
  min-height: 44px;
}

.btn-icon:hover {
  background: var(--color-surface-emphasis);
  color: var(--color-text);
}

.search-box {
  position: relative;
  margin: var(--space-4) var(--space-4) var(--space-2);
}

.search-icon {
  position: absolute;
  left: var(--space-3);
  top: 50%;
  transform: translateY(-50%);
  color: var(--color-text-muted);
  pointer-events: none;
}

.search-input {
  width: 100%;
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--space-2) var(--space-8) var(--space-2) var(--space-8);
  color: var(--color-text);
  font-size: 0.875rem;
  transition: border-color var(--duration-fast) var(--ease-standard);
}

.search-input:focus {
  border-color: var(--color-action);
  box-shadow: 0 0 0 2px color-mix(in srgb, var(--color-action) 25%, transparent);
}

.clear-btn {
  position: absolute;
  right: var(--space-2);
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  color: var(--color-text-muted);
  cursor: pointer;
  font-size: 1.1rem;
  padding: var(--space-1);
  line-height: 1;
}

.tag-cloud-bar {
  display: flex;
  align-items: center;
  gap: var(--space-1);
  padding: var(--space-1) var(--space-4);
  overflow-x: auto;
  scrollbar-width: none;
}

.tag-cloud-bar::-webkit-scrollbar {
  display: none;
}

.tag-pill {
  background: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-text-muted);
  padding: 0.15rem 0.5rem;
  border-radius: var(--radius-pill);
  font-size: 0.75rem;
  cursor: pointer;
  white-space: nowrap;
  transition: background-color var(--duration-fast) var(--ease-standard),
              color var(--duration-fast) var(--ease-standard),
              border-color var(--duration-fast) var(--ease-standard);
  min-height: 24px;
}

.tag-pill:hover {
  background: var(--color-surface-emphasis);
  color: var(--color-text);
}

.tag-pill.active {
  background: color-mix(in srgb, var(--color-action) 25%, transparent);
  color: var(--color-action);
  border-color: var(--color-action);
  font-weight: 600;
}

.sidebar-sort-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-1) var(--space-4) var(--space-2);
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.sort-label {
  font-weight: 500;
}

.sort-select {
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  color: var(--color-text);
  border-radius: var(--radius-sm);
  padding: 0.2rem 0.4rem;
  font-size: 0.75rem;
  cursor: pointer;
  transition: border-color var(--duration-fast) var(--ease-standard);
}

.sort-select:focus {
  border-color: var(--color-action);
}

.notes-container {
  flex: 1;
  overflow-y: auto;
  padding: var(--space-2) var(--space-3);
}

.section-label {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: var(--space-2) var(--space-2) var(--space-1);
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--color-text-muted);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.note-count {
  background: var(--color-surface-emphasis);
  padding: 0.1rem 0.4rem;
  border-radius: var(--radius-sm);
}

.notes-empty {
  padding: var(--space-8) var(--space-4);
  text-align: center;
  color: var(--color-text-muted);
  font-size: 0.875rem;
}

.btn-create-inline {
  margin-top: var(--space-3);
  background: var(--color-action);
  color: var(--color-neutral-0);
  border: none;
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-sm);
  font-size: 0.875rem;
  cursor: pointer;
  min-height: 36px;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.btn-create-inline:hover {
  background: var(--color-action-hover);
}

.notes-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
}

.note-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-sm);
  cursor: pointer;
  transition: background-color var(--duration-fast) var(--ease-standard),
              color var(--duration-fast) var(--ease-standard);
  color: var(--color-text-muted);
}

.note-item:hover {
  background: var(--color-surface-emphasis);
  color: var(--color-text);
}

.note-item.active {
  background: color-mix(in srgb, var(--color-action) 20%, transparent);
  color: var(--color-action);
  font-weight: 600;
}

.note-info {
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.note-title {
  font-size: 0.875rem;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.note-path {
  font-size: 0.75rem;
  color: var(--color-text-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.btn-delete {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  padding: var(--space-1);
  border-radius: var(--radius-sm);
  cursor: pointer;
  opacity: 0;
  transition: color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard),
              opacity var(--duration-fast) var(--ease-standard);
  min-width: 28px;
  min-height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
}

.note-item:hover .btn-delete {
  opacity: 1;
}

.btn-delete:hover {
  color: var(--color-status-danger);
  background: color-mix(in srgb, var(--color-status-danger) 12%, transparent);
}

/* Modal overlay — solid, no blur */
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
  box-shadow: var(--shadow-lg);
}

.modal-card h3 {
  margin: 0 0 var(--space-2);
  color: var(--color-text);
  font-size: 1.125rem;
  font-weight: 600;
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

.btn-primary:hover:not(:disabled) {
  background: var(--color-action-hover);
}

.btn-primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.sidebar-footer {
  padding: var(--space-3) var(--space-4);
  border-top: 1px solid var(--color-border);
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--color-surface-emphasis);
}

.user-badge {
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.user-name {
  font-size: 0.875rem;
  font-weight: 600;
  color: var(--color-text);
  white-space: nowrap;
  text-overflow: ellipsis;
  overflow: hidden;
}

.user-email {
  font-size: 0.75rem;
  color: var(--color-text-muted);
  white-space: nowrap;
  text-overflow: ellipsis;
  overflow: hidden;
}

.btn-logout {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  cursor: pointer;
  padding: var(--space-1);
  border-radius: var(--radius-sm);
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 36px;
  min-height: 36px;
  transition: color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard);
}

.btn-logout:hover {
  color: var(--color-status-danger);
  background: color-mix(in srgb, var(--color-status-danger) 12%, transparent);
}
</style>

