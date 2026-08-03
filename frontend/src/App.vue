<template>
  <div class="app-layout">
    <button
      type="button"
      class="mobile-sidebar-toggle"
      aria-label="Toggle sidebar"
      @click="isMobileSidebarOpen = !isMobileSidebarOpen"
    >
      <svg viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
        <line x1="3" y1="6" x2="21" y2="6"></line>
        <line x1="3" y1="12" x2="21" y2="12"></line>
        <line x1="3" y1="18" x2="21" y2="18"></line>
      </svg>
    </button>
    <div
      v-if="isMobileSidebarOpen"
      class="mobile-sidebar-backdrop"
      @click="isMobileSidebarOpen = false"
    ></div>
    <!-- Left Sidebar: Notes & Search -->
    <Sidebar
      :notes="notes"
      :selected-note-id="activeNoteId"
      :current-user="currentUser"
      :notifications="notifications"
      :is-mobile-sidebar-open="isMobileSidebarOpen"
      :workspace-id="activeWorkspaceId"
      :workspaces="workspaces"
      :tenants="tenants"
      :active-tenant-id="activeTenantId"
      :frontend-version="APP_VERSION"
      :backend-version="backendVersion"
      :folder-positions="folderPositions"
      :reveal-folder-request="revealFolderRequest"
      @notes-reordered="refreshNotesList"
      @create-note-in-folder="handleCreateNoteInFolder"
      @select-note="handleSelectNote"
      @create-note="handleCreateNote"
      @create-note-from-template="handleCreateNoteFromTemplate"
      @delete-note="handleDeleteNote"
      @search="handleSearch"
      @logout="handleLogout"
      @mark-notification-read="handleMarkNotificationRead"
      @delete-notification="handleDeleteNotification"
      @toggle-attachments="handleToggleAttachments"
      @daily-note="handleDailyNote"
      @toggle-audit-log="handleToggleAuditLog"
      @import-workspace="handleImportWorkspace"
      @export-workspace="handleExportWorkspace"
      @toggle-link-report="handleToggleLinkReport"
      @publish-workspace="handlePublishWorkspace"
      @toggle-table-view="handleToggleTableView"
      @toggle-board-view="handleToggleBoardView"
      @toggle-calendar-view="handleToggleCalendarView"
      @switch-workspace="handleSwitchWorkspace"
      @switch-tenant="handleSwitchTenant"
      @toggle-admin-panel="isAdminPanelOpen = true"
    />

    <AdminPanel :is-open="isAdminPanelOpen" @close="handleCloseAdminPanel" />

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

      <!-- Table (Collections) View Mode -->
      <CollectionsTableView
        v-else-if="isTableViewActive"
        :page="collectionPage"
        :loading="collectionLoading"
        :sort-key="collectionSortKey"
        :sort-dir="collectionSortDir"
        @select-note="handleSelectNote"
        @page-change="handleCollectionPageChange"
        @filter-change="handleCollectionFilterChange"
        @sort="handleCollectionSort"
      />

      <!-- Board (Collections) View Mode -->
      <CollectionsBoardView
        v-else-if="isBoardViewActive"
        :page="collectionPage"
        :loading="collectionLoading"
        :group-property="collectionGroupProperty"
        @select-note="handleSelectNote"
        @page-change="handleCollectionPageChange"
        @group-change="handleCollectionGroupChange"
      />

      <!-- Calendar (Collections) View Mode -->
      <CollectionsCalendarView
        v-else-if="isCalendarViewActive"
        :page="collectionPage"
        :loading="collectionLoading"
        :date-property="collectionDateProperty"
        @select-note="handleSelectNote"
        @page-change="handleCollectionPageChange"
        @date-property-change="handleCollectionDatePropertyChange"
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
        @reveal-folder="handleRevealFolder"
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

    <!-- Right-drawer mount point (B.10): secondary surfaces (Comments
         first, more later) teleport their markup here so they slide over
         the note as an overlay instead of unmounting or pushing it. -->
    <div id="app-right-drawer"></div>

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
      <a
        v-if="successLink"
        :href="successLink"
        target="_blank"
        rel="noopener"
        class="success-banner-link"
        data-testid="success-banner-link"
      >Open</a>
      <button
        type="button"
        class="error-banner-dismiss"
        data-testid="success-banner-dismiss"
        aria-label="Dismiss message"
        @click="successMessage = null; successLink = null"
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
import CollectionsTableView from './components/CollectionsTableView.vue'
import CollectionsBoardView from './components/CollectionsBoardView.vue'
import CollectionsCalendarView from './components/CollectionsCalendarView.vue'
import AdminPanel from './components/AdminPanel.vue'
import {
  getWorkspaces,
  getTenants,
  getNotes,
  getFolderPositions,
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
  getLinkReport,
  publishWorkspace,
  getCollection,
  getNotifications,
  markNotificationRead,
  deleteNotification,
  getAuthConfig
} from './services/api'
import type { Workspace, Tenant, NoteMeta, NoteDetail, SearchResult, AuthUser, SearchFilters, AttachmentItem, AuditLogEntry, LinkReport, NotificationItem, CollectionPage, FolderPosition } from './services/types'
import { APP_VERSION } from './version'

const workspaces = ref<Workspace[]>([])
const activeWorkspaceId = ref<number>(1)
const tenants = ref<Tenant[]>([])
const activeTenantId = ref<number | null>(null)
const notes = ref<NoteMeta[]>([])
const folderPositions = ref<FolderPosition[]>([])
const activeNoteId = ref<number | null>(null)
const activeNoteDetail = ref<NoteDetail | null>(null)

const currentUser = ref<AuthUser | null>(null)
const backendVersion = ref<string | null>(null)
const showLoginModal = ref(false)
const isMobileSidebarOpen = ref(false)
const isGraphViewActive = ref(false)
const revealFolderRequest = ref<{ path: string; nonce: number } | null>(null)

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

const isAdminPanelOpen = ref(false)
const linkReportLoading = ref(false)

const notifications = ref<NotificationItem[]>([])

const isTableViewActive = ref(false)
const isBoardViewActive = ref(false)
const isCalendarViewActive = ref(false)
const collectionPage = ref<CollectionPage>({ data: [], current_page: 1, last_page: 1, per_page: 50, total: 0 })
const collectionLoading = ref(false)
const collectionFilterProperty = ref('')
const collectionFilterValue = ref('')
const collectionSortKey = ref<string | null>(null)
const collectionSortDir = ref<'asc' | 'desc'>('asc')
const collectionGroupProperty = ref<string | null>(null)
const collectionDateProperty = ref<string | null>(null)

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
const successLink = ref<string | null>(null)

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

  getAuthConfig()
    .then((config) => { backendVersion.value = config.version })
    .catch(() => {})

  await initWorkspace()
})

const WORKSPACE_STORAGE_KEY = 'jotter-active-workspace-id'
const TENANT_STORAGE_KEY = 'jotter-active-tenant-id'

async function initWorkspace() {
  try {
    const tenantList = await getTenants()
    tenants.value = tenantList

    let scopeTenantId: number | undefined

    if (tenantList.length > 1) {
      const storedTenant = localStorage.getItem(TENANT_STORAGE_KEY)
      const storedTenantId = storedTenant !== null ? Number(storedTenant) : null
      const storedTenantIsValid = storedTenantId !== null && tenantList.some((t) => t.id === storedTenantId)

      if (storedTenantIsValid) {
        activeTenantId.value = storedTenantId as number
      } else {
        activeTenantId.value = tenantList[0].id
        localStorage.setItem(TENANT_STORAGE_KEY, String(tenantList[0].id))
      }

      scopeTenantId = activeTenantId.value
    }

    const list = await getWorkspaces(scopeTenantId)
    workspaces.value = list

    const stored = localStorage.getItem(WORKSPACE_STORAGE_KEY)
    const storedId = stored !== null ? Number(stored) : null
    const storedIsValid = storedId !== null && list.some((ws) => ws.id === storedId)

    if (storedIsValid) {
      activeWorkspaceId.value = storedId as number
    } else if (list.length > 0) {
      activeWorkspaceId.value = list[0].id
      localStorage.setItem(WORKSPACE_STORAGE_KEY, String(list[0].id))
    }

    await refreshNotesList()
    await refreshNotifications()
  } catch (err) {
    console.error('Failed to initialize workspace:', err)
  }
}

async function handleSwitchWorkspace(workspaceId: number) {
  activeWorkspaceId.value = workspaceId
  localStorage.setItem(WORKSPACE_STORAGE_KEY, String(workspaceId))
  await refreshNotesList()
  await refreshNotifications()
}

async function handleSwitchTenant(tenantId: number) {
  try {
    activeTenantId.value = tenantId
    localStorage.setItem(TENANT_STORAGE_KEY, String(tenantId))

    const list = await getWorkspaces(tenantId)
    workspaces.value = list

    if (list.length > 0) {
      activeWorkspaceId.value = list[0].id
      localStorage.setItem(WORKSPACE_STORAGE_KEY, String(list[0].id))
    }

    await refreshNotesList()
    await refreshNotifications()
  } catch (err) {
    console.error('Failed to switch tenant:', err)
  }
}

async function handleCloseAdminPanel() {
  isAdminPanelOpen.value = false
  // A workspace may have just been created/archived in the panel; refresh
  // so the switcher reflects it without requiring a full page reload.
  try {
    const list = await getWorkspaces(activeTenantId.value ?? undefined)
    workspaces.value = list
  } catch (err) {
    console.error('Failed to refresh workspaces:', err)
  }
}

async function refreshNotifications() {
  if (!activeWorkspaceId.value) return
  try {
    notifications.value = await getNotifications(activeWorkspaceId.value)
  } catch (err) {
    console.error('Failed to load notifications:', err)
  }
}

async function handleMarkNotificationRead(notificationId: number) {
  if (!activeWorkspaceId.value) return
  try {
    const updated = await markNotificationRead(activeWorkspaceId.value, notificationId)
    const idx = notifications.value.findIndex(n => n.id === notificationId)
    if (idx !== -1) notifications.value[idx] = updated
  } catch (err) {
    console.error('Failed to mark notification as read:', err)
  }
}

async function handleDeleteNotification(notificationId: number) {
  if (!activeWorkspaceId.value) return
  try {
    await deleteNotification(activeWorkspaceId.value, notificationId)
    notifications.value = notifications.value.filter(n => n.id !== notificationId)
  } catch (err) {
    console.error('Failed to delete notification:', err)
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
    const [list, positions] = await Promise.all([
      getNotes(activeWorkspaceId.value),
      getFolderPositions(activeWorkspaceId.value),
    ])
    notes.value = list
    folderPositions.value = positions

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

    // Keep the sidebar tree's copy of this note (frontmatter, title, path)
    // in sync — loadActiveNote only refetches the single note detail, and
    // the tree renders from the separate `notes` list, which would
    // otherwise go stale after any frontmatter-driven change (e.g. the
    // page icon) until the next full notes-list refetch.
    const index = notes.value.findIndex(n => n.id === noteId)
    if (index !== -1) {
      notes.value[index] = {
        id: detail.id,
        path: detail.path,
        title: detail.title,
        frontmatter: detail.frontmatter,
        sort_position: detail.sort_position,
        updated_at: detail.updated_at,
      }
    }
  } catch (err) {
    console.error('Failed to load note detail:', err)
  }
}

async function handleSelectNote(noteId: number) {
  isMobileSidebarOpen.value = false
  isSearchActive.value = false
  isAttachmentsActive.value = false
  isAuditLogActive.value = false
  isLinkReportActive.value = false
  isTableViewActive.value = false
  isBoardViewActive.value = false
  isCalendarViewActive.value = false
  await loadActiveNote(noteId)
}

async function handleToggleAttachments() {
  isAttachmentsActive.value = !isAttachmentsActive.value
  if (isAttachmentsActive.value) {
    isSearchActive.value = false
    isAuditLogActive.value = false
    isLinkReportActive.value = false
    isTableViewActive.value = false
    isBoardViewActive.value = false
    isCalendarViewActive.value = false
    await refreshAttachments()
  }
}

async function handleToggleAuditLog() {
  isAuditLogActive.value = !isAuditLogActive.value
  if (isAuditLogActive.value) {
    isSearchActive.value = false
    isAttachmentsActive.value = false
    isLinkReportActive.value = false
    isTableViewActive.value = false
    isBoardViewActive.value = false
    isCalendarViewActive.value = false
    await refreshAuditLog()
  }
}

async function handleToggleLinkReport() {
  isLinkReportActive.value = !isLinkReportActive.value
  if (isLinkReportActive.value) {
    isSearchActive.value = false
    isAttachmentsActive.value = false
    isAuditLogActive.value = false
    isTableViewActive.value = false
    isBoardViewActive.value = false
    isCalendarViewActive.value = false
    await refreshLinkReport()
  }
}

async function handleToggleTableView() {
  isTableViewActive.value = !isTableViewActive.value
  if (isTableViewActive.value) {
    isSearchActive.value = false
    isAttachmentsActive.value = false
    isAuditLogActive.value = false
    isLinkReportActive.value = false
    isBoardViewActive.value = false
    isCalendarViewActive.value = false
    await refreshCollection()
  }
}

async function handleToggleBoardView() {
  isBoardViewActive.value = !isBoardViewActive.value
  if (isBoardViewActive.value) {
    isSearchActive.value = false
    isAttachmentsActive.value = false
    isAuditLogActive.value = false
    isLinkReportActive.value = false
    isTableViewActive.value = false
    isCalendarViewActive.value = false
    await refreshCollection()
  }
}

async function handleToggleCalendarView() {
  isCalendarViewActive.value = !isCalendarViewActive.value
  if (isCalendarViewActive.value) {
    isSearchActive.value = false
    isAttachmentsActive.value = false
    isAuditLogActive.value = false
    isLinkReportActive.value = false
    isTableViewActive.value = false
    isBoardViewActive.value = false
    await refreshCollection()
  }
}

async function refreshCollection(page = 1) {
  if (!activeWorkspaceId.value) return
  collectionLoading.value = true
  try {
    collectionPage.value = await getCollection(activeWorkspaceId.value, {
      property: collectionFilterProperty.value || undefined,
      value: collectionFilterValue.value || undefined,
      page
    })
  } catch (err) {
    console.error('Failed to load collection:', err)
  } finally {
    collectionLoading.value = false
  }
}

async function handleCollectionPageChange(page: number) {
  await refreshCollection(page)
}

async function handleCollectionFilterChange(propertyName: string, value: string) {
  collectionFilterProperty.value = propertyName
  collectionFilterValue.value = value
  await refreshCollection(1)
}

function handleCollectionSort(key: string) {
  if (collectionSortKey.value === key) {
    collectionSortDir.value = collectionSortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    collectionSortKey.value = key
    collectionSortDir.value = 'asc'
  }
}

function handleCollectionGroupChange(property: string) {
  collectionGroupProperty.value = property || null
}

function handleCollectionDatePropertyChange(property: string) {
  collectionDateProperty.value = property || null
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

async function handlePublishWorkspace() {
  if (!activeWorkspaceId.value) return
  try {
    const result = await publishWorkspace(activeWorkspaceId.value)
    successMessage.value = `Published ${result.notes_published} note(s) to the static site.`
    successLink.value = result.site_url
  } catch (err: any) {
    console.error('Failed to publish workspace:', err)
    errorMessage.value = `Failed to publish workspace: ${err.response?.data?.message || err.message || 'Unknown error'}`
  }
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

function handleCreateNoteInFolder(folderPath: string) {
  const fileName = `untitled-${Date.now().toString(36)}.md`
  const path = folderPath === '' ? fileName : `${folderPath}/${fileName}`
  return handleCreateNote(path)
}

function handleRevealFolder(folderPath: string) {
  revealFolderRequest.value = { path: folderPath, nonce: Date.now() }
  isMobileSidebarOpen.value = true
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
  isTableViewActive.value = false
  isBoardViewActive.value = false
  isCalendarViewActive.value = false
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
  font-family: var(--font-sans);
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

.mobile-sidebar-toggle {
  display: none;
}

.mobile-sidebar-backdrop {
  display: none;
}

@media (max-width: 768px) {
  .mobile-sidebar-toggle {
    display: flex;
    align-items: center;
    justify-content: center;
    position: fixed;
    top: var(--space-3);
    left: var(--space-3);
    z-index: 50;
    min-width: 44px;
    min-height: 44px;
    background: var(--color-surface);
    border: 1px solid var(--color-border);
    border-radius: var(--radius-md);
    color: var(--color-text);
    cursor: pointer;
  }

  .mobile-sidebar-backdrop {
    display: block;
    position: fixed;
    inset: 0;
    z-index: 30;
    background: var(--color-overlay);
  }
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
  color: var(--color-text-inverse);
  border-radius: var(--radius-sm, 6px);
  box-shadow: var(--shadow-float);
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
  color: var(--color-text-inverse);
  border-radius: var(--radius-sm, 6px);
  box-shadow: var(--shadow-float);
  font-size: 0.9rem;
}

.success-banner-link {
  color: var(--color-text-inverse);
  font-weight: 600;
  text-decoration: underline;
  white-space: nowrap;
  flex-shrink: 0;
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
  font-family: var(--font-sans);
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
