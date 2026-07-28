<template>
  <div class="app-layout">
    <!-- Left Sidebar: Notes & Search -->
    <Sidebar
      :notes="notes"
      :selected-note-id="activeNoteId"
      :current-user="currentUser"
      @select-note="handleSelectNote"
      @create-note="handleCreateNote"
      @delete-note="handleDeleteNote"
      @search="handleSearch"
      @logout="handleLogout"
    />

    <!-- Main Content Area -->
    <main class="main-content">
      <!-- Graph View Mode -->
      <GraphView
        v-if="isGraphViewActive"
        :notes="notes"
        :active-note-id="activeNoteId"
        @select-note="handleSelectNoteFromGraph"
        @close="isGraphViewActive = false"
      />

      <!-- Search View Mode -->
      <SearchResults
        v-else-if="isSearchActive"
        :query="searchQuery"
        :results="searchResults"
        @select-note="handleSelectNote"
      />

      <!-- Active Note Editor -->
      <NoteEditor
        v-else-if="activeNoteDetail"
        :note="activeNoteDetail"
        :all-notes="notes"
        :workspace-id="activeWorkspaceId || undefined"
        @update-note="handleUpdateNote"
        @select-note="handleSelectNote"
        @navigate-wikilink="handleWikilinkNavigation"
      />

      <!-- Empty State -->
      <div v-else class="empty-workspace-state">
        <div class="empty-card">
          <svg class="empty-icon" viewBox="0 0 24 24" width="48" height="48" fill="none" stroke="currentColor" stroke-width="1.5">
            <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
            <polyline points="14 2 14 8 20 8"></polyline>
          </svg>
          <h2>No Note Selected</h2>
          <p>Select a note from the sidebar or create a new Markdown document to begin editing.</p>
        </div>
      </div>
    </main>

    <!-- Login Modal -->
    <LoginModal
      :show="showLoginModal"
      @login-success="handleLoginSuccess"
    />

    <!-- Command Palette (Cmd + K) -->
    <CommandPalette
      :notes="notes"
      @select-note="handleSelectNote"
      @create-note="() => handleCreateNote(`untitled-${Date.now().toString(36)}.md`)"
      @search="() => handleSearch(' ')"
      @toggle-graph="isGraphViewActive = !isGraphViewActive"
      @logout="handleLogout"
    />
  </div>
</template>

<script setup lang="ts">
import { ref, onMounted } from 'vue'
import Sidebar from './components/Sidebar.vue'
import NoteEditor from './components/NoteEditor.vue'
import SearchResults from './components/SearchResults.vue'
import LoginModal from './components/LoginModal.vue'
import CommandPalette from './components/CommandPalette.vue'
import GraphView from './components/GraphView.vue'
import {
  getWorkspaces,
  getNotes,
  getNote,
  createNote,
  updateNote,
  deleteNote,
  searchNotes,
  getMe,
  logout,
  setUnauthenticatedHandler
} from './services/api'
import type { Workspace, NoteMeta, NoteDetail, SearchResult, AuthUser } from './services/types'

const workspaces = ref<Workspace[]>([])
const activeWorkspaceId = ref<number>(1)
const notes = ref<NoteMeta[]>([])
const activeNoteId = ref<number | null>(null)
const activeNoteDetail = ref<NoteDetail | null>(null)

const currentUser = ref<AuthUser | null>(null)
const showLoginModal = ref(false)
const isGraphViewActive = ref(false)

const isSearchActive = ref(false)
const searchQuery = ref('')
const searchResults = ref<SearchResult[]>([])

async function handleSelectNoteFromGraph(noteId: number) {
  isGraphViewActive.value = false
  await handleSelectNote(noteId)
}

onMounted(async () => {
  setUnauthenticatedHandler(() => {
    showLoginModal.value = true
  })

  try {
    const user = await getMe()
    currentUser.value = user
  } catch {
    showLoginModal.value = true
  }

  await initWorkspace()
})

async function initWorkspace() {
  try {
    const list = await getWorkspaces()
    workspaces.value = list
    if (list.length > 0) {
      activeWorkspaceId.value = list[0].id
    }
    await refreshNotesList()
  } catch (err) {
    console.error('Failed to initialize workspace:', err)
  }
}

async function handleLoginSuccess(user: AuthUser) {
  currentUser.value = user
  showLoginModal.value = false
  await initWorkspace()
}

async function handleLogout() {
  try {
    await logout()
  } catch {
    // Ignore logout errors
  } finally {
    currentUser.value = null
    notes.value = []
    activeNoteId.value = null
    activeNoteDetail.value = null
    showLoginModal.value = true
  }
}

async function refreshNotesList() {
  if (!activeWorkspaceId.value) return
  try {
    const list = await getNotes(activeWorkspaceId.value)
    notes.value = list

    if (activeNoteId.value) {
      const exists = list.some(n => n.id === activeNoteId.value)
      if (exists) {
        await loadActiveNote(activeNoteId.value)
      } else if (list.length > 0) {
        await handleSelectNote(list[0].id)
      } else {
        activeNoteId.value = null
        activeNoteDetail.value = null
      }
    } else if (list.length > 0) {
      await handleSelectNote(list[0].id)
    }
  } catch {
    // Failed requests will trigger unauthenticated handler if 401
  }
}

async function loadActiveNote(noteId: number) {
  if (!activeWorkspaceId.value) return
  try {
    const detail = await getNote(activeWorkspaceId.value, noteId)
    activeNoteId.value = noteId
    activeNoteDetail.value = detail
  } catch (err) {
    console.error('Failed to load note detail:', err)
  }
}

async function handleSelectNote(noteId: number) {
  isSearchActive.value = false
  await loadActiveNote(noteId)
}

async function handleCreateNote(path: string) {
  if (!activeWorkspaceId.value) return
  try {
    const initialContent = `# ${path.replace(/\.md$/, '').split('/').pop()}\n\nWrite your thoughts here...`
    const created = await createNote(activeWorkspaceId.value, path, initialContent)
    await refreshNotesList()
    await handleSelectNote(created.id)
  } catch (err) {
    console.error('Failed to create note:', err)
  }
}

async function handleUpdateNote(noteId: number, content: string) {
  if (!activeWorkspaceId.value) return
  try {
    await updateNote(activeWorkspaceId.value, noteId, content)
    await refreshNotesList()
  } catch (err) {
    console.error('Failed to update note:', err)
  }
}

async function handleDeleteNote(noteId: number) {
  if (!activeWorkspaceId.value) return
  if (!confirm('Are you sure you want to delete this note?')) return
  try {
    await deleteNote(activeWorkspaceId.value, noteId)
    if (activeNoteId.value === noteId) {
      activeNoteId.value = null
      activeNoteDetail.value = null
    }
    await refreshNotesList()
  } catch (err) {
    console.error('Failed to delete note:', err)
  }
}

async function handleSearch(query: string) {
  searchQuery.value = query
  if (!query.trim()) {
    isSearchActive.value = false
    searchResults.value = []
    return
  }
  if (!activeWorkspaceId.value) return
  try {
    const results = await searchNotes(activeWorkspaceId.value, query)
    searchResults.value = results
    isSearchActive.value = true
  } catch (err) {
    console.error('Search failed:', err)
  }
}

async function handleWikilinkNavigation(target: string) {
  const targetLower = target.toLowerCase().trim()
  
  const match = notes.value.find(n => 
    n.title.toLowerCase() === targetLower ||
    n.path.toLowerCase() === targetLower ||
    n.path.toLowerCase() === `${targetLower}.md`
  )

  if (match) {
    await handleSelectNote(match.id)
  } else {
    const newPath = targetLower.endsWith('.md') ? targetLower : `${targetLower}.md`
    await handleCreateNote(newPath)
  }
}
</script>

<style>
/* Legacy token aliases — kept only while components still reference --bg-*, --text-*, --accent-*.
   Remove this block once Issue #100 component migration is complete. */
:root {
  --bg-main: var(--color-canvas);
  --bg-sidebar: var(--color-surface);
  --bg-surface: var(--color-surface);
  --bg-glass: var(--color-surface-emphasis);
  --bg-hover: var(--color-action-hover);
  --border-color: var(--color-border);
  --border-glow: var(--color-border-strong);
  --text-light: var(--color-text);
  --text-main: var(--color-text);
  --text-muted: var(--color-text-muted);
  --text-dim: var(--color-text-muted);
  --accent-color: var(--color-action);
  --accent-gradient: linear-gradient(135deg, var(--color-action) 0%, var(--color-action-hover) 100%);
  --font-heading: var(--font-sans);
  --font-body: var(--font-sans);
}

/* Global reset */
*, *::before, *::after {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

/* Global focus ring — Issue #103 */
:focus-visible {
  outline: 2px solid var(--color-focus);
  outline-offset: 2px;
}

body {
  font-family: var(--font-body);
  background-color: var(--color-canvas);
  color: var(--color-text);
  height: 100vh;
  overflow: hidden;
  -webkit-font-smoothing: antialiased;
}

/* Scrollbars */
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: transparent; }
::-webkit-scrollbar-thumb {
  background: var(--color-border);
  border-radius: var(--radius-sm);
}
::-webkit-scrollbar-thumb:hover { background: var(--color-border-strong); }

.app-layout {
  display: flex;
  height: 100vh;
  width: 100vw;
  overflow: hidden;
}

.main-content {
  flex: 1;
  display: flex;
  flex-direction: column;
  height: 100%;
  overflow: hidden;
  background: var(--color-canvas);
}

/* Empty state */
.empty-workspace-state {
  flex: 1;
  display: flex;
  align-items: center;
  justify-content: center;
  background: transparent;
  color: var(--color-text-muted);
}

.empty-card {
  text-align: center;
  max-width: 420px;
  padding: var(--space-12) var(--space-8);
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: 0 12px 32px rgb(0 0 0 / 32%);
  transition: border-color var(--duration-standard) var(--ease-standard);
}

.empty-card:hover {
  border-color: var(--color-border-strong);
}

.empty-icon {
  color: var(--color-action);
  margin-bottom: var(--space-4);
}

.empty-card h2 {
  font-family: var(--font-heading);
  color: var(--color-text);
  font-size: 1.5rem;
  font-weight: 600;
  margin-bottom: var(--space-3);
}

.empty-card p {
  font-size: 1rem; /* was 0.925rem — spec §4 mandates ≥ 16px for prose */
  color: var(--color-text-muted);
  line-height: 1.6;
}
</style>
