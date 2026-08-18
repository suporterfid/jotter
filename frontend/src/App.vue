<template>
  <div class="app-layout">
    <button
      type="button"
      class="mobile-sidebar-toggle"
      :aria-label="t('app.toggleSidebar')"
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
      :auth-provider="authProvider"
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
      @open-notification-target="handleOpenNotificationTarget"
      @toggle-attachments="handleToggleAttachments"
      @daily-note="handleDailyNote"
      @toggle-audit-log="handleToggleAuditLog"
      @toggle-trash="handleToggleTrash"
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
      @open-change-password="isChangePasswordOpen = true"
      @open-notification-preferences="openNotificationPreferences"
    />

    <AdminPanel :is-open="isAdminPanelOpen" @close="handleCloseAdminPanel" />

    <ChangePasswordModal v-if="isChangePasswordOpen" @close="isChangePasswordOpen = false" />

    <NotificationPreferences
      :is-open="isNotificationPreferencesOpen"
      :unsubscribe-type="notificationPreferencesUnsubscribeType"
      @close="closeNotificationPreferences"
    />

    <TabStrip
      v-if="showTabStrip"
      :tabs="openTabsList"
      :active-id="activeNoteId"
      @select-tab="handleSelectNote"
      @close-tab="handleCloseTab"
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

      <!-- Trash View Mode -->
      <TrashPanel
        v-else-if="isTrashActive"
        :notes="trashNotes"
        :loading="trashLoading"
        @restore-note="handleRestoreTrashNote"
        @permanently-delete-note="handlePermanentlyDeleteTrashNote"
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
      <div v-else-if="isBoardViewActive" class="board-view-wrapper">
        <BoardSwitcher
          :boards="boards"
          :active-board-id="activeBoardId"
          @select-board="handleSelectBoard"
          @create-board="handleCreateBoard"
          @rename-board="handleRenameBoard"
          @delete-board="handleDeleteBoard"
        />
        <CollectionsBoardView
          :page="collectionPage"
          :loading="collectionLoading"
          :group-property="collectionGroupProperty"
          :swimlane-property="collectionSwimlaneProperty"
          :configurable="activeBoardId !== null"
          :column-config="activeBoardColumnConfig"
          :show-archived="showArchivedNotes"
          @select-note="handleSelectNote"
          @page-change="handleCollectionPageChange"
          @group-change="handleCollectionGroupChange"
          @swimlane-change="handleCollectionSwimlaneChange"
          @move-card="handleCollectionMoveCard"
          @create-card="handleCollectionCreateCard"
          @reorder-columns="handleReorderColumns"
          @toggle-column-collapse="handleToggleColumnCollapse"
          @update-column="handleUpdateColumn"
          @toggle-archive="handleToggleArchive"
          @archived-filter-change="handleArchivedFilterChange"
        />
      </div>

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
          <h2>{{ t('app.noNoteSelected') }}</h2>
          <p>{{ t('app.noNoteSelectedDesc') }}</p>
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
        :aria-label="t('app.dismissError')"
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
      >{{ t('app.open') }}</a>
      <button
        type="button"
        class="error-banner-dismiss"
        data-testid="success-banner-dismiss"
        :aria-label="t('app.dismissMessage')"
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
import { ref, computed, watch, onMounted } from 'vue'
import { useI18n } from 'vue-i18n'
import i18n from './i18n'
import Sidebar from './components/Sidebar.vue'
import NoteEditor from './components/NoteEditor.vue'
import SearchResults from './components/SearchResults.vue'
import LoginModal from './components/LoginModal.vue'
import CommandPalette from './components/CommandPalette.vue'
import GraphView from './components/GraphView.vue'
import AttachmentsPanel from './components/AttachmentsPanel.vue'
import AuditLogViewer from './components/AuditLogViewer.vue'
import TrashPanel from './components/TrashPanel.vue'
import LinkReportViewer from './components/LinkReportViewer.vue'
import CollectionsTableView from './components/CollectionsTableView.vue'
import CollectionsBoardView from './components/CollectionsBoardView.vue'
import CollectionsCalendarView from './components/CollectionsCalendarView.vue'
import AdminPanel from './components/AdminPanel.vue'
import ChangePasswordModal from './components/ChangePasswordModal.vue'
import NotificationPreferences from './components/NotificationPreferences.vue'
import {
  getWorkspaces,
  getTenants,
  getNotes,
  getFolderPositions,
  getNote,
  createNote,
  updateNote,
  deleteNote,
  getTrash,
  restoreTrashNote,
  permanentlyDeleteTrashNote,
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
  setNoteProperty,
  getNotifications,
  markNotificationRead,
  deleteNotification,
  getAuthConfig,
  getBoards,
  createBoard,
  updateBoard,
  deleteBoard
} from './services/api'
import type { Workspace, Tenant, NoteMeta, TrashNoteMeta, NoteDetail, SearchResult, AuthUser, SearchFilters, AttachmentItem, AuditLogEntry, LinkReport, NotificationItem, CollectionPage, FolderPosition, Board, BoardColumnConfig, NotificationType } from './services/types'
import BoardSwitcher from './components/BoardSwitcher.vue'
import { isArchived } from './services/collectionUtils'
import { APP_VERSION } from './version'
import { resolveWikilinkTarget } from './services/wikilinks'
import TabStrip from './components/TabStrip.vue'
import { useOpenTabs } from './composables/useOpenTabs'

const { t } = useI18n()

const workspaces = ref<Workspace[]>([])
const activeWorkspaceId = ref<number>(1)
const tenants = ref<Tenant[]>([])
const activeTenantId = ref<number | null>(null)
const notes = ref<NoteMeta[]>([])
const folderPositions = ref<FolderPosition[]>([])
const activeNoteId = ref<number | null>(null)
const activeNoteDetail = ref<NoteDetail | null>(null)

const { openNoteIds, loadTabs, saveTabs, openTab, closeTab } = useOpenTabs()

const openTabsList = computed(() =>
  openNoteIds.value
    .map(id => {
      const note = notes.value.find(n => n.id === id)
      return note ? { id, title: note.title || note.path } : null
    })
    .filter((tab): tab is { id: number; title: string } => tab !== null)
)

const showTabStrip = computed(() =>
  openNoteIds.value.length > 0 &&
  !isGraphViewActive.value &&
  !isAttachmentsActive.value &&
  !isAuditLogActive.value &&
  !isTrashActive.value &&
  !isLinkReportActive.value &&
  !isTableViewActive.value &&
  !isBoardViewActive.value &&
  !isCalendarViewActive.value &&
  !isSearchActive.value
)

watch([openNoteIds, activeNoteId], () => {
  if (!activeWorkspaceId.value) return
  saveTabs(activeWorkspaceId.value, activeNoteId.value)
}, { deep: true })

async function handleCloseTab(noteId: number) {
  const nextActiveId = closeTab(noteId, activeNoteId.value)
  if (nextActiveId === activeNoteId.value) return
  if (nextActiveId === null) {
    activeNoteId.value = null
    activeNoteDetail.value = null
  } else {
    await loadActiveNote(nextActiveId)
  }
}

const currentUser = ref<AuthUser | null>(null)
const backendVersion = ref<string | null>(null)
const authProvider = ref<string | null>(null)
const isChangePasswordOpen = ref(false)
const isNotificationPreferencesOpen = ref(false)
const notificationPreferencesUnsubscribeType = ref<NotificationType | null>(null)
const showLoginModal = ref(false)
const isMobileSidebarOpen = ref(false)
const isGraphViewActive = ref(false)
const revealFolderRequest = ref<{ path: string; nonce: number } | null>(null)

function openNotificationPreferences(): void {
  notificationPreferencesUnsubscribeType.value = null
  isNotificationPreferencesOpen.value = true
}

function closeNotificationPreferences(): void {
  isNotificationPreferencesOpen.value = false
  notificationPreferencesUnsubscribeType.value = null
}

function openNotificationPreferencesFromUrl(): void {
  const params = new URLSearchParams(window.location.search)
  if (params.get('notification-preferences') !== '1') return
  const unsubscribe = params.get('unsubscribe')
  const validTypes: NotificationType[] = ['mention', 'note_commented', 'comment_reply', 'note_edited', 'note_moved', 'note_deleted']
  notificationPreferencesUnsubscribeType.value = validTypes.includes(unsubscribe as NotificationType)
    ? unsubscribe as NotificationType
    : null
  isNotificationPreferencesOpen.value = true
}

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

const isTrashActive = ref(false)
const trashNotes = ref<TrashNoteMeta[]>([])
const trashLoading = ref(false)

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
const collectionSwimlaneProperty = ref<string | null>(null)
const showArchivedNotes = ref(false)
const collectionDateProperty = ref<string | null>(null)
const boards = ref<Board[]>([])
const activeBoardId = ref<number | null>(null)

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
    if (user) {
      i18n.global.locale.value = user.locale as 'pt-BR' | 'en'
    }
  } catch {
    showLoginModal.value = true
  }

  getAuthConfig()
    .then((config) => {
      backendVersion.value = config.version
      authProvider.value = config.provider
    })
    .catch(() => {})

  await initWorkspace()
  openNotificationPreferencesFromUrl()
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

    activeNoteId.value = loadTabs(activeWorkspaceId.value)
    await refreshNotesList()
    await refreshNotifications()
  } catch (err) {
    console.error('Failed to initialize workspace:', err)
  }
}

async function handleSwitchWorkspace(workspaceId: number) {
  activeWorkspaceId.value = workspaceId
  localStorage.setItem(WORKSPACE_STORAGE_KEY, String(workspaceId))
  activeNoteId.value = loadTabs(workspaceId)
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
        watching: detail.watching,
      }
    }
  } catch (err) {
    console.error('Failed to load note detail:', err)
  }
}

async function handleSelectNote(noteId: number) {
  openTab(noteId)
  isMobileSidebarOpen.value = false
  isSearchActive.value = false
  isAttachmentsActive.value = false
  isAuditLogActive.value = false
  isTrashActive.value = false
  isLinkReportActive.value = false
  isTableViewActive.value = false
  isBoardViewActive.value = false
  isCalendarViewActive.value = false
  await loadActiveNote(noteId)
}

async function handleOpenNotificationTarget(target: { noteId: number; targetKind: 'note' | 'trash' }) {
  if (target.targetKind === 'trash') {
    if (!isTrashActive.value) await handleToggleTrash()
    return
  }
  await handleSelectNote(target.noteId)
}

async function handleToggleAttachments() {
  isAttachmentsActive.value = !isAttachmentsActive.value
  if (isAttachmentsActive.value) {
    isSearchActive.value = false
    isAuditLogActive.value = false
    isTrashActive.value = false
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
    isTrashActive.value = false
    isLinkReportActive.value = false
    isTableViewActive.value = false
    isBoardViewActive.value = false
    isCalendarViewActive.value = false
    await refreshAuditLog()
  }
}

async function handleToggleTrash() {
  isTrashActive.value = !isTrashActive.value
  if (isTrashActive.value) {
    isSearchActive.value = false
    isAttachmentsActive.value = false
    isAuditLogActive.value = false
    isLinkReportActive.value = false
    isTableViewActive.value = false
    isBoardViewActive.value = false
    isCalendarViewActive.value = false
    await refreshTrash()
  }
}

async function handleToggleLinkReport() {
  isLinkReportActive.value = !isLinkReportActive.value
  if (isLinkReportActive.value) {
    isSearchActive.value = false
    isAttachmentsActive.value = false
    isAuditLogActive.value = false
    isTrashActive.value = false
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
    isTrashActive.value = false
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
    isTrashActive.value = false
    isCalendarViewActive.value = false
    await refreshCollection()
    await refreshBoards()
  }
}

async function refreshBoards() {
  if (!activeWorkspaceId.value) return
  try {
    boards.value = await getBoards(activeWorkspaceId.value)
  } catch (err) {
    console.error('Failed to load boards:', err)
  }
}

const activeBoardColumnConfig = computed(() => {
  const board = boards.value.find(b => b.id === activeBoardId.value)
  return board?.column_config ?? null
})

async function persistColumnConfig(config: BoardColumnConfig[]) {
  if (!activeWorkspaceId.value || activeBoardId.value === null) return
  try {
    const board = await updateBoard(activeWorkspaceId.value, activeBoardId.value, { column_config: config })
    const index = boards.value.findIndex(b => b.id === board.id)
    if (index !== -1) boards.value[index] = board
  } catch (err) {
    console.error('Failed to save column configuration:', err)
  }
}

function handleReorderColumns(keys: string[]) {
  const existing = activeBoardColumnConfig.value ?? []
  const byKey = new Map(existing.map(c => [c.key, c]))
  const config = keys.map(key => byKey.get(key) ?? { key })
  persistColumnConfig(config)
}

function handleToggleColumnCollapse(key: string) {
  const existing = activeBoardColumnConfig.value ?? []
  const found = existing.find(c => c.key === key)
  const config = found
    ? existing.map(c => (c.key === key ? { ...c, collapsed: !c.collapsed } : c))
    : [...existing, { key, collapsed: true }]
  persistColumnConfig(config)
}

function handleUpdateColumn(key: string, attrs: { label: string; color: string | null; wip_limit: number | null }) {
  const existing = activeBoardColumnConfig.value ?? []
  const found = existing.find(c => c.key === key)
  const config = found
    ? existing.map(c => (c.key === key ? { ...c, ...attrs } : c))
    : [...existing, { key, ...attrs }]
  persistColumnConfig(config)
}

async function handleSelectBoard(boardId: number | null) {
  activeBoardId.value = boardId
  const board = boards.value.find(b => b.id === boardId)
  collectionGroupProperty.value = board?.group_property || null
  collectionSwimlaneProperty.value = board?.swimlane_property || null
  collectionFilterProperty.value = board?.filter_property || ''
  collectionFilterValue.value = board?.filter_value || ''
  await refreshCollection(1)
}

async function handleCreateBoard(name: string) {
  if (!activeWorkspaceId.value) return
  try {
    const board = await createBoard(activeWorkspaceId.value, {
      name,
      group_property: collectionGroupProperty.value,
      swimlane_property: collectionSwimlaneProperty.value,
      filter_property: collectionFilterProperty.value || null,
      filter_value: collectionFilterValue.value || null,
    })
    boards.value.push(board)
    activeBoardId.value = board.id
  } catch (err) {
    console.error('Failed to create board:', err)
  }
}

async function handleRenameBoard(boardId: number, name: string) {
  if (!activeWorkspaceId.value) return
  try {
    const board = await updateBoard(activeWorkspaceId.value, boardId, { name })
    const index = boards.value.findIndex(b => b.id === boardId)
    if (index !== -1) boards.value[index] = board
  } catch (err) {
    console.error('Failed to rename board:', err)
  }
}

async function handleDeleteBoard(boardId: number) {
  if (!activeWorkspaceId.value) return
  if (!confirm(t('app.confirmDeleteBoard'))) return
  try {
    await deleteBoard(activeWorkspaceId.value, boardId)
    boards.value = boards.value.filter(b => b.id !== boardId)
    if (activeBoardId.value === boardId) {
      activeBoardId.value = null
    }
  } catch (err) {
    console.error('Failed to delete board:', err)
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
    isTrashActive.value = false
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
      page,
      includeArchived: showArchivedNotes.value
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

async function handleCollectionGroupChange(property: string) {
  collectionGroupProperty.value = property || null
  if (activeWorkspaceId.value && activeBoardId.value !== null) {
    try {
      await updateBoard(activeWorkspaceId.value, activeBoardId.value, { group_property: collectionGroupProperty.value })
    } catch (err) {
      console.error('Failed to save board view:', err)
    }
  }
}

async function handleCollectionSwimlaneChange(property: string) {
  collectionSwimlaneProperty.value = property || null
  if (activeWorkspaceId.value && activeBoardId.value !== null) {
    try {
      await updateBoard(activeWorkspaceId.value, activeBoardId.value, { swimlane_property: collectionSwimlaneProperty.value })
    } catch (err) {
      console.error('Failed to save board view:', err)
    }
  }
}

async function handleCollectionCreateCard(title: string, columnValue: string, rowValue: string | null) {
  if (!activeWorkspaceId.value || !collectionGroupProperty.value) return
  try {
    const path = `untitled-${Date.now().toString(36)}.md`
    const created = await createNote(activeWorkspaceId.value, path, `# ${title}\n\nWrite your thoughts here...`)
    if (columnValue) {
      await setNoteProperty(activeWorkspaceId.value, created.id, collectionGroupProperty.value, columnValue)
    }
    if (rowValue && collectionSwimlaneProperty.value) {
      await setNoteProperty(activeWorkspaceId.value, created.id, collectionSwimlaneProperty.value, rowValue)
    }
    await refreshCollection(collectionPage.value.current_page)
  } catch (err) {
    console.error('Failed to create card:', err)
  }
}

async function handleCollectionMoveCard(noteId: number, newValue: string) {
  if (!activeWorkspaceId.value || !collectionGroupProperty.value) return
  try {
    await setNoteProperty(activeWorkspaceId.value, noteId, collectionGroupProperty.value, newValue)
    const destinationColumn = (activeBoardColumnConfig.value ?? []).find(c => c.key === newValue)
    if (destinationColumn?.auto_archive) {
      await setNoteProperty(activeWorkspaceId.value, noteId, 'archived', true)
    }
  } catch (err) {
    console.error('Failed to move card:', err)
  } finally {
    await refreshCollection(collectionPage.value.current_page)
  }
}

async function handleToggleArchive(noteId: number) {
  if (!activeWorkspaceId.value) return
  const note = collectionPage.value.data.find(n => n.id === noteId)
  if (!note) return
  try {
    await setNoteProperty(activeWorkspaceId.value, noteId, 'archived', !isArchived(note))
  } catch (err) {
    console.error('Failed to toggle archive:', err)
  } finally {
    await refreshCollection(collectionPage.value.current_page)
  }
}

async function handleArchivedFilterChange(value: boolean) {
  showArchivedNotes.value = value
  await refreshCollection(1)
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
    const parts = [
      t('app.importedCount', { count: result.extracted_count }),
      t('app.skippedCount', { count: result.skipped_count }),
    ]
    if (result.errors.length > 0) parts.push(t('app.errorCount', { count: result.errors.length }))
    successMessage.value = t('app.importComplete', { parts: parts.join(', ') })
    if (result.errors.length > 0) {
      console.error('Import errors:', result.errors)
    }
  } catch (err: any) {
    console.error('Failed to import workspace archive:', err)
    errorMessage.value = t('app.failedImportArchive', { message: err.response?.data?.message || err.message || t('app.unknownError') })
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
    successMessage.value = t('app.publishedNotes', { count: result.notes_published })
    successLink.value = result.site_url
  } catch (err: any) {
    console.error('Failed to publish workspace:', err)
    errorMessage.value = t('app.failedPublishWorkspace', { message: err.response?.data?.message || err.message || t('app.unknownError') })
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

async function refreshTrash() {
  if (!activeWorkspaceId.value) return
  trashLoading.value = true
  try {
    trashNotes.value = await getTrash(activeWorkspaceId.value)
  } catch (err) {
    console.error('Failed to load trash:', err)
  } finally {
    trashLoading.value = false
  }
}

async function handleRestoreTrashNote(noteId: number) {
  if (!activeWorkspaceId.value) return
  try {
    const restored = await restoreTrashNote(activeWorkspaceId.value, noteId)
    await refreshNotesList()
    await refreshTrash()
    await handleSelectNote(restored.id)
  } catch (err: any) {
    console.error('Failed to restore note:', err)
    errorMessage.value = err.response?.data?.message || t('app.failedRestoreNote')
  }
}

async function handlePermanentlyDeleteTrashNote(noteId: number) {
  if (!activeWorkspaceId.value) return
  try {
    await permanentlyDeleteTrashNote(activeWorkspaceId.value, noteId)
    trashNotes.value = trashNotes.value.filter(note => note.id !== noteId)
  } catch (err) {
    console.error('Failed to permanently delete note:', err)
    errorMessage.value = t('app.failedPermanentlyDeleteNote')
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
  if (!confirm(t('app.confirmDeleteAttachment', { name: attachment.path.split('/').pop() }))) return
  try {
    await deleteAttachment(activeWorkspaceId.value, attachment.id)
    attachments.value = attachments.value.filter(a => a.id !== attachment.id)
  } catch (err) {
    console.error('Failed to delete attachment:', err)
    errorMessage.value = t('app.failedDeleteAttachment')
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
    errorMessage.value = t('app.failedCreateNote', { message: err.response?.data?.message || err.message || t('app.unknownError') })
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
    errorMessage.value = t('app.failedCreateNoteFromTemplate', { message: err.response?.data?.message || err.message || t('app.unknownError') })
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
    errorMessage.value = t('app.failedOpenDailyNote', { message: err.response?.data?.message || err.message || t('app.unknownError') })
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
  if (!confirm(t('app.confirmDeleteNote'))) return
  try {
    await deleteNote(activeWorkspaceId.value, noteId)
    const nextActiveId = closeTab(noteId, activeNoteId.value)
    if (nextActiveId !== activeNoteId.value) {
      if (nextActiveId === null) {
        activeNoteId.value = null
        activeNoteDetail.value = null
      } else {
        await loadActiveNote(nextActiveId)
      }
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
  isTrashActive.value = false
  searchQuery.value = query
  await runSearch(query, searchFilters.value)
}

async function handleSearchFiltersChange(filters: SearchFilters) {
  searchFilters.value = filters
  await runSearch(searchQuery.value, filters)
}

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
</script>

<style>
.board-view-wrapper {
  flex: 1;
  display: flex;
  flex-direction: column;
  min-height: 0;
  padding: var(--space-6) var(--space-6) 0;
}

.board-view-wrapper > .collections-board-view {
  padding: 0;
}

/* Global reset */
*, *::before, *::after {
  box-sizing: border-box;
  margin: 0;
  padding: 0;
}

/* Global focus ring — Issue #103 */
:focus-visible {
  outline: 2px solid var(--color-focus-ring);
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
  inline-size: 100vw;
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
    inset-block-start: var(--space-3);
    inset-inline-start: var(--space-3);
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
