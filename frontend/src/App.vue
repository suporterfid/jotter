<template>
  <div class="app-layout">
    <!-- Left Sidebar: Notes & Search -->
    <Sidebar
      :notes="notes"
      :selected-note-id="activeNoteId"
      :current-user="currentUser"
      @select-note="handleSelectNote"
      @create-note="handleCreateNote"
      @create-note-from-template="handleCreateNoteFromTemplate"
      @delete-note="handleDeleteNote"
      @search="handleSearch"
      @logout="handleLogout"
      @toggle-attachments="handleToggleAttachments"
      @daily-note="handleDailyNote"
      @toggle-audit-log="handleToggleAuditLog"
      @import-workspace="handleImportWorkspace"
      @export-workspace="handleExportWorkspace"
      @toggle-link-report="handleToggleLinkReport"
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

      <!-- Attachments View Mode -->
      <AttachmentsPanel
        v-else-if="isAttachmentsActive"
        :attachments="attachments"
        :loading="attachmentsLoading"
        @delete-attachment="handleDeleteAttachment"
      />

      <!-- Audit Log View Mode -->
      <AuditLogViewer
        v-else-if="isAuditLogActive"
        :entries="auditLogEntries"
        :loading="auditLogLoading"
      />

      <!-- Link Report View Mode -->
      <LinkReportViewer
        v-else-if="isLinkReportActive"
        :report="linkReport"
        :loading="linkReportLoading"
        @select-note="handleSelectNote"
      />

      <!-- Search View Mode -->
      <SearchResults
        v-else-if="isSearchActive"
        :query="searchQuery"
        :results="searchResults"
        :filters="searchFilters"
        :available-tags="availableTagsForSearch"
        @select-note="handleSelectNote"
        @update:filters="handleSearchFiltersChange"
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

    <!-- Error Banner -->
    <div v-if="errorMessage" class="error-banner" data-testid="error-banner" role="alert">
      <span>{{ errorMessage }}</span>
      <button
        type="button"
        class="error-banner-dismiss"
        data-testid="error-banner-dismiss"
        aria-label="Dismiss error"
        @click="errorMessage = null"
      >&times;</button>
    </div>

    <!-- Success Banner -->
    <div v-if="successMessage" class="success-banner" data-testid="success-banner" role="status">
      <span>{{ successMessage }}</span>
      <button
        type="button"
        class="error-banner-dismiss"
        data-testid="success-banner-dismiss"
        aria-label="Dismiss message"
        @click="successMessage = null"
      >&times;</button>
    </div>

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
import { ref, computed, onMounted } from 'vue'
import Sidebar from './components/Sidebar.vue'
import NoteEditor from './components/NoteEditor.vue'
import SearchResults from './components/SearchResults.vue'
import LoginModal from './components/LoginModal.vue'
import CommandPalette from './components/CommandPalette.vue'
import GraphView from './components/GraphView.vue'
import AttachmentsPanel from './components/AttachmentsPanel.vue'
import AuditLogViewer from './components/AuditLogViewer.vue'
import LinkReportViewer from './components/LinkReportViewer.vue'
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
  setUnauthenticatedHandler,
  getAttachments,
  deleteAttachment,
  createNoteFromTemplate,
  getOrCreateDailyNote,
  getAuditLogs,
  importWorkspaceArchive,
  getLinkReport
} from './services/api'
import type { Workspace, NoteMeta, NoteDetail, SearchResult, AuthUser, SearchFilters, AttachmentItem, AuditLogEntry, LinkReport } from './services/types'

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
const searchFilters = ref<SearchFilters>({})

const isAttachmentsActive = ref(false)
const attachments = ref<AttachmentItem[]>([])
const attachmentsLoading = ref(false)

const isAuditLogActive = ref(false)
const auditLogEntries = ref<AuditLogEntry[]>([])
const auditLogLoading = ref(false)

const isLinkReportActive = ref(false)
const linkReport = ref<LinkReport>({ broken_links: [], orphans: [] })
const linkReportLoading = ref(false)

const availableTagsForSearch = computed(() => {
  const tagSet = new Set<string>()
  for (const n of notes.value) {
    if (n.frontmatter?.tags && Array.isArray(n.frontmatter.tags)) {
      for (const t of n.frontmatter.tags) {
        if (typeof t === 'string') tagSet.add(t.replace(/^#/, ''))
      }
    }
  }
  return Array.from(tagSet).sort()
})

const errorMessage = ref<string | null>(null)
const successMessage = ref<string | null>(null)

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
  isAttachmentsActive.value = false
  isAuditLogActive.value = false
  isLinkReportActive.value = false
  await loadActiveNote(noteId)
}

async function handleToggleAttachments() {
  isAttachmentsActive.value = !isAttachmentsActive.value
  if (isAttachmentsActive.value) {
    isSearchActive.value = false
    isAuditLogActive.value = false
    isLinkReportActive.value = false
    await refreshAttachments()
  }
}

async function handleToggleAuditLog() {
  isAuditLogActive.value = !isAuditLogActive.value
  if (isAuditLogActive.value) {
    isSearchActive.value = false
    isAttachmentsActive.value = false
    isLinkReportActive.value = false
    await refreshAuditLog()
  }
}

async function handleToggleLinkReport() {
  isLinkReportActive.value = !isLinkReportActive.value
  if (isLinkReportActive.value) {
    isSearchActive.value = false
    isAttachmentsActive.value = false
    isAuditLogActive.value = false
    await refreshLinkReport()
  }
}

async function refreshLinkReport() {
  if (!activeWorkspaceId.value) return
  linkReportLoading.value = true
  try {
    linkReport.value = await getLinkReport(activeWorkspaceId.value)
  } catch (err) {
    console.error('Failed to load link report:', err)
  } finally {
    linkReportLoading.value = false
  }
}

async function handleImportWorkspace(archive: File, overwrite: boolean) {
  if (!activeWorkspaceId.value) return
  try {
    const result = await importWorkspaceArchive(activeWorkspaceId.value, archive, overwrite)
    await refreshNotesList()
    const parts = [`${result.extracted_count} note(s) imported`, `${result.skipped_count} skipped`]
    if (result.errors.length > 0) parts.push(`${result.errors.length} error(s)`)
    successMessage.value = `Import complete: ${parts.join(', ')}.`
    if (result.errors.length > 0) {
      console.error('Import errors:', result.errors)
    }
  } catch (err: any) {
    console.error('Failed to import workspace archive:', err)
    errorMessage.value = `Failed to import archive: ${err.response?.data?.message || err.message || 'Unknown error'}`
  }
}

function handleExportWorkspace() {
  if (!activeWorkspaceId.value) return
  window.location.href = `/api/workspaces/${activeWorkspaceId.value}/export`
}

async function refreshAuditLog() {
  if (!activeWorkspaceId.value) return
  auditLogLoading.value = true
  try {
    auditLogEntries.value = await getAuditLogs(activeWorkspaceId.value)
  } catch (err) {
    console.error('Failed to load audit log:', err)
  } finally {
    auditLogLoading.value = false
  }
}

async function refreshAttachments() {
  if (!activeWorkspaceId.value) return
  attachmentsLoading.value = true
  try {
    attachments.value = await getAttachments(activeWorkspaceId.value)
  } catch (err) {
    console.error('Failed to load attachments:', err)
  } finally {
    attachmentsLoading.value = false
  }
}

async function handleDeleteAttachment(attachment: AttachmentItem) {
  if (!activeWorkspaceId.value) return
  if (!confirm(`Delete "${attachment.path.split('/').pop()}"? This cannot be undone.`)) return
  try {
    await deleteAttachment(activeWorkspaceId.value, attachment.id)
    attachments.value = attachments.value.filter(a => a.id !== attachment.id)
  } catch (err) {
    console.error('Failed to delete attachment:', err)
    errorMessage.value = 'Failed to delete attachment.'
  }
}

async function handleCreateNote(path: string) {
  if (!activeWorkspaceId.value) return
  try {
    const initialContent = `# ${path.replace(/\.md$/, '').split('/').pop()}\n\nWrite your thoughts here...`
    const created = await createNote(activeWorkspaceId.value, path, initialContent)
    await refreshNotesList()
    await handleSelectNote(created.id)
  } catch (err: any) {
    console.error('Failed to create note:', err)
    errorMessage.value = `Failed to create note: ${err.response?.data?.message || err.message || 'Unknown error'}`
  }
}

async function handleCreateNoteFromTemplate(templatePath: string, targetPath: string) {
  if (!activeWorkspaceId.value) return
  try {
    const created = await createNoteFromTemplate(activeWorkspaceId.value, templatePath, targetPath)
    await refreshNotesList()
    await handleSelectNote(created.id)
  } catch (err: any) {
    console.error('Failed to create note from template:', err)
    errorMessage.value = `Failed to create note from template: ${err.response?.data?.message || err.message || 'Unknown error'}`
  }
}

async function handleDailyNote() {
  if (!activeWorkspaceId.value) return
  try {
    const note = await getOrCreateDailyNote(activeWorkspaceId.value)
    await refreshNotesList()
    await handleSelectNote(note.id)
  } catch (err: any) {
    console.error('Failed to open daily note:', err)
    errorMessage.value = `Failed to open daily note: ${err.response?.data?.message || err.message || 'Unknown error'}`
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

function hasActiveFilters(filters: SearchFilters): boolean {
  return Boolean(
    filters.title?.trim() ||
    filters.modifiedAfter ||
    filters.modifiedBefore ||
    (filters.tags && filters.tags.length > 0)
  )
}

async function runSearch(query: string, filters: SearchFilters) {
  if (!query.trim() && !hasActiveFilters(filters)) {
    isSearchActive.value = false
    searchResults.value = []
    return
  }
  if (!activeWorkspaceId.value) return
  try {
    const results = await searchNotes(activeWorkspaceId.value, query, filters)
    searchResults.value = results
    isSearchActive.value = true
  } catch (err) {
    console.error('Search failed:', err)
  }
}

async function handleSearch(query: string) {
  isAttachmentsActive.value = false
  isAuditLogActive.value = false
  isLinkReportActive.value = false
  searchQuery.value = query
  await runSearch(query, searchFilters.value)
}

async function handleSearchFiltersChange(filters: SearchFilters) {
  searchFilters.value = filters
  await runSearch(searchQuery.value, filters)
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

/* Error banner */
.error-banner {
  position: fixed;
  top: var(--space-md, 16px);
  left: 50%;
  transform: translateX(-50%);
  z-index: 1000;
  display: flex;
  align-items: center;
  gap: var(--space-sm, 8px);
  max-width: min(90vw, 480px);
  padding: var(--space-sm, 8px) var(--space-md, 16px);
  background: var(--color-status-danger);
  color: #ffffff;
  border-radius: var(--radius-sm, 6px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
  font-size: 0.9rem;
}

.success-banner {
  position: fixed;
  top: var(--space-md, 16px);
  left: 50%;
  transform: translateX(-50%);
  z-index: 1000;
  display: flex;
  align-items: center;
  gap: var(--space-sm, 8px);
  max-width: min(90vw, 480px);
  padding: var(--space-sm, 8px) var(--space-md, 16px);
  background: var(--color-status-success);
  color: #ffffff;
  border-radius: var(--radius-sm, 6px);
  box-shadow: 0 4px 12px rgba(0, 0, 0, 0.3);
  font-size: 0.9rem;
}

.error-banner-dismiss {
  background: transparent;
  border: none;
  color: inherit;
  font-size: 1.2rem;
  line-height: 1;
  cursor: pointer;
  padding: 0 var(--space-xs, 4px);
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
  box-shadow: var(--shadow-lg);
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
