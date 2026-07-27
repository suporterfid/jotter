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
      <button class="btn-icon" data-testid="new-note-btn" title="New Note" @click="showNewNoteModal = true">
        <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
          <line x1="12" y1="5" x2="12" y2="19"></line>
          <line x1="5" y1="12" x2="19" y2="12"></line>
        </svg>
      </button>
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
      <span class="sort-label">Sort:</span>
      <select v-model="sortBy" class="sort-select">
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

      <ul v-else class="notes-list">
        <li 
          v-for="note in filteredAndSortedNotes" 
          :key="note.id"
          class="note-item"
          :class="{ active: selectedNoteId === note.id }"
          @click="$emit('select-note', note.id)"
        >
          <div class="note-info">
            <span class="note-title">{{ note.title || note.path }}</span>
            <span class="note-path">{{ note.path }}</span>
          </div>
          <button 
            class="btn-delete" 
            title="Delete note"
            @click.stop="$emit('delete-note', note.id)"
          >
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="3 6 5 6 21 6"></polyline>
              <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
            </svg>
          </button>
        </li>
      </ul>
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
  background: var(--bg-sidebar, #16161a);
  border-right: 1px solid var(--border-color, #2d2d38);
  display: flex;
  flex-direction: column;
  height: 100%;
}

.sidebar-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid var(--border-color, #2d2d38);
}

.brand {
  display: flex;
  align-items: center;
  gap: 0.625rem;
  color: var(--text-light, #f8fafc);
  font-weight: 700;
  font-size: 1.125rem;
}

.brand-icon {
  color: var(--accent-color, #818cf8);
}

.btn-icon {
  background: transparent;
  border: none;
  color: var(--text-muted, #94a3b8);
  padding: 0.4rem;
  border-radius: 0.375rem;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: all 0.15s ease;
}

.btn-icon:hover {
  background: var(--bg-hover, #2d3748);
  color: var(--text-light, #f8fafc);
}

.search-box {
  position: relative;
  margin: 1rem 1.25rem 0.5rem 1.25rem;
}

.search-icon {
  position: absolute;
  left: 0.75rem;
  top: 50%;
  transform: translateY(-50%);
  color: var(--text-dim, #64748b);
}

.search-input {
  width: 100%;
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid var(--border-color, #2d2d38);
  border-radius: 0.5rem;
  padding: 0.5rem 2rem 0.5rem 2.25rem;
  color: var(--text-light, #f8fafc);
  font-size: 0.875rem;
  outline: none;
  transition: all 0.15s ease;
}

.search-input:focus {
  border-color: var(--accent-color, #818cf8);
  box-shadow: 0 0 0 2px rgba(129, 140, 248, 0.2);
}

.clear-btn {
  position: absolute;
  right: 0.5rem;
  top: 50%;
  transform: translateY(-50%);
  background: none;
  border: none;
  color: var(--text-dim, #64748b);
  cursor: pointer;
  font-size: 1.1rem;
}

.tag-cloud-bar {
  display: flex;
  align-items: center;
  gap: 0.4rem;
  padding: 0.4rem 1.25rem;
  overflow-x: auto;
  scrollbar-width: none;
}

.tag-cloud-bar::-webkit-scrollbar {
  display: none;
}

.tag-pill {
  background: rgba(255, 255, 255, 0.05);
  border: 1px solid rgba(255, 255, 255, 0.1);
  color: #94a3b8;
  padding: 0.15rem 0.5rem;
  border-radius: 1rem;
  font-size: 0.725rem;
  cursor: pointer;
  white-space: nowrap;
  transition: all 0.15s ease;
}

.tag-pill:hover {
  background: rgba(99, 102, 241, 0.15);
  color: #e2e8f0;
}

.tag-pill.active {
  background: rgba(99, 102, 241, 0.3);
  color: #818cf8;
  border-color: #818cf8;
  font-weight: 600;
}

.sidebar-sort-bar {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.25rem 1.25rem 0.5rem 1.25rem;
  font-size: 0.75rem;
  color: #64748b;
}

.sort-label {
  font-weight: 500;
}

.sort-select {
  background: rgba(255, 255, 255, 0.04);
  border: 1px solid var(--border-color, rgba(255, 255, 255, 0.08));
  color: #cbd5e1;
  border-radius: 0.375rem;
  padding: 0.2rem 0.4rem;
  font-size: 0.725rem;
  outline: none;
  cursor: pointer;
}

.sort-select:focus {
  border-color: #818cf8;
}

.notes-container {
  flex: 1;
  overflow-y: auto;
  padding: 0.5rem 0.75rem;
}

.section-label {
  display: flex;
  justify-content: space-between;
  align-items: center;
  padding: 0.5rem 0.5rem 0.25rem 0.5rem;
  font-size: 0.75rem;
  font-weight: 600;
  color: var(--text-dim, #64748b);
  text-transform: uppercase;
  letter-spacing: 0.05em;
}

.note-count {
  background: rgba(255, 255, 255, 0.05);
  padding: 0.1rem 0.4rem;
  border-radius: 0.25rem;
}

.notes-empty {
  padding: 2rem 1rem;
  text-align: center;
  color: var(--text-dim, #64748b);
  font-size: 0.875rem;
}

.btn-create-inline {
  margin-top: 0.75rem;
  background: var(--accent-color, #818cf8);
  color: white;
  border: none;
  padding: 0.4rem 0.8rem;
  border-radius: 0.375rem;
  font-size: 0.8125rem;
  cursor: pointer;
}

.notes-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 0.25rem;
}

.note-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 0.625rem 0.75rem;
  border-radius: 0.375rem;
  cursor: pointer;
  transition: all 0.15s ease;
  color: var(--text-muted, #94a3b8);
}

.note-item:hover {
  background: rgba(255, 255, 255, 0.05);
  color: var(--text-light, #f8fafc);
}

.note-item.active {
  background: rgba(129, 140, 248, 0.15);
  color: var(--accent-color, #818cf8);
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
  color: var(--text-dim, #64748b);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.btn-delete {
  background: transparent;
  border: none;
  color: var(--text-dim, #64748b);
  padding: 0.25rem;
  border-radius: 0.25rem;
  cursor: pointer;
  opacity: 0;
  transition: all 0.15s ease;
}

.note-item:hover .btn-delete {
  opacity: 1;
}

.btn-delete:hover {
  color: #f87171;
  background: rgba(248, 113, 113, 0.1);
}

.modal-overlay {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.7);
  backdrop-filter: blur(4px);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
}

.modal-card {
  background: var(--bg-surface, #1e1e24);
  border: 1px solid var(--border-color, #2d2d38);
  border-radius: 0.75rem;
  padding: 1.5rem;
  width: 360px;
  box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5);
}

.modal-card h3 {
  margin: 0 0 0.5rem 0;
  color: var(--text-light, #f8fafc);
}

.modal-desc {
  font-size: 0.875rem;
  color: var(--text-muted, #94a3b8);
  margin-bottom: 1rem;
}

.modal-input {
  width: 100%;
  background: #111115;
  border: 1px solid var(--border-color, #2d2d38);
  border-radius: 0.375rem;
  padding: 0.5rem 0.75rem;
  color: var(--text-light, #f8fafc);
  margin-bottom: 1.25rem;
  outline: none;
}

.modal-actions {
  display: flex;
  justify-content: flex-end;
  gap: 0.75rem;
}

.btn-secondary {
  background: transparent;
  border: 1px solid var(--border-color, #2d2d38);
  color: var(--text-muted, #94a3b8);
  padding: 0.5rem 1rem;
  border-radius: 0.375rem;
  cursor: pointer;
}

.btn-primary {
  background: var(--accent-color, #818cf8);
  color: white;
  border: none;
  padding: 0.5rem 1rem;
  border-radius: 0.375rem;
  cursor: pointer;
  font-weight: 500;
}

.btn-primary:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.sidebar-footer {
  padding: 0.85rem 1rem;
  border-top: 1px solid var(--border-color, #2d2d38);
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: rgba(0, 0, 0, 0.2);
}

.user-badge {
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.user-name {
  font-size: 0.85rem;
  font-weight: 600;
  color: var(--text-light, #f8fafc);
  white-space: nowrap;
  text-overflow: ellipsis;
  overflow: hidden;
}

.user-email {
  font-size: 0.75rem;
  color: var(--text-dim, #64748b);
  white-space: nowrap;
  text-overflow: ellipsis;
  overflow: hidden;
}

.btn-logout {
  background: transparent;
  border: none;
  color: var(--text-dim, #64748b);
  cursor: pointer;
  padding: 0.35rem;
  border-radius: 4px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: color 0.2s, background-color 0.2s;
}

.btn-logout:hover {
  color: #fca5a5;
  background: rgba(239, 68, 68, 0.1);
}
</style>
