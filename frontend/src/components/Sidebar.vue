<template>
  <aside class="sidebar" :class="{ 'mobile-open': isMobileSidebarOpen, 'sidebar-collapsed': sidebarCollapsed }">
    <!-- Floating expand affordance: only reachable/visible once the
         sidebar itself has collapsed to zero width, and only above the
         mobile breakpoint (mobile has its own hamburger toggle). -->
    <button
      v-if="sidebarCollapsed"
      type="button"
      class="sidebar-expand-btn"
      aria-label="Expand sidebar"
      data-testid="sidebar-expand-btn"
      @click="toggleSidebarCollapsed"
    >
      <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
        <polyline points="9 18 15 12 9 6"></polyline>
      </svg>
    </button>

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
      <TenantSwitcher
        v-if="(props.tenants ?? []).length > 1"
        :tenants="props.tenants ?? []"
        :active-tenant-id="props.activeTenantId ?? null"
        @switch="(id) => emit('switch-tenant', id)"
      />
      <WorkspaceSwitcher
        :workspaces="props.workspaces"
        :active-workspace-id="props.workspaceId ?? null"
        @switch="(id) => emit('switch-workspace', id)"
      />
      <div class="header-actions">
        <div class="more-menu-wrapper">
          <button
            class="btn-icon"
            data-testid="notifications-btn"
            title="Notifications"
            :aria-expanded="showNotifications"
            aria-haspopup="true"
            @click="showNotifications = !showNotifications"
          >
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
              <path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"></path>
              <path d="M13.73 21a2 2 0 0 1-3.46 0"></path>
            </svg>
            <span v-if="unreadNotificationCount > 0" class="notification-badge" data-testid="notification-badge">
              {{ unreadNotificationCount > 9 ? '9+' : unreadNotificationCount }}
            </span>
          </button>
          <div v-if="showNotifications" class="more-menu-backdrop" @click="showNotifications = false"></div>
          <div v-if="showNotifications" class="more-menu notifications-menu" role="region" aria-label="Notifications">
            <p v-if="notifications.length === 0" class="notifications-empty">No notifications yet.</p>
            <div
              v-for="notification in notifications"
              :key="notification.id"
              class="notification-item"
              :class="{ unread: !notification.read_at }"
              data-testid="notification-item"
            >
              <div class="notification-body" @click="!notification.read_at && $emit('mark-notification-read', notification.id)">
                <span class="notification-title">{{ notification.title }}</span>
                <span v-if="notification.data?.comment_snippet" class="notification-snippet">{{ notification.data.comment_snippet }}</span>
                <span class="notification-time">{{ formatNotificationTime(notification.created_at) }}</span>
              </div>
              <button
                class="btn-delete-notification"
                data-testid="notification-delete-btn"
                :aria-label="`Dismiss notification: ${notification.title}`"
                title="Dismiss"
                @click="$emit('delete-notification', notification.id)"
              >
                <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
                  <line x1="18" y1="6" x2="6" y2="18"></line>
                  <line x1="6" y1="6" x2="18" y2="18"></line>
                </svg>
              </button>
            </div>
          </div>
        </div>
        <div class="more-menu-wrapper">
          <button
            class="btn-icon"
            data-testid="more-actions-btn"
            title="More actions"
            :aria-expanded="showMoreMenu"
            aria-haspopup="true"
            @click="showMoreMenu = !showMoreMenu"
          >
            <svg viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="12" cy="5" r="1.5"></circle>
              <circle cx="12" cy="12" r="1.5"></circle>
              <circle cx="12" cy="19" r="1.5"></circle>
            </svg>
          </button>
          <div v-if="showMoreMenu" class="more-menu-backdrop" @click="showMoreMenu = false"></div>
          <div v-if="showMoreMenu" class="more-menu" role="menu">
            <button
              class="more-menu-item"
              data-testid="attachments-btn"
              role="menuitem"
              @click="closeMoreMenuAnd(() => $emit('toggle-attachments'))"
            >
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48"></path>
              </svg>
              <span>Attachments</span>
            </button>
            <button
              class="more-menu-item"
              data-testid="daily-note-btn"
              role="menuitem"
              @click="closeMoreMenuAnd(() => $emit('daily-note'))"
            >
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
              </svg>
              <span>Today's Daily Note</span>
            </button>
            <button
              class="more-menu-item"
              data-testid="audit-log-btn"
              role="menuitem"
              @click="closeMoreMenuAnd(() => $emit('toggle-audit-log'))"
            >
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                <polyline points="14 2 14 8 20 8"></polyline>
                <line x1="9" y1="13" x2="15" y2="13"></line>
                <line x1="9" y1="17" x2="13" y2="17"></line>
              </svg>
              <span>Audit Log</span>
            </button>
            <button
              class="more-menu-item"
              data-testid="import-workspace-btn"
              role="menuitem"
              @click="closeMoreMenuAnd(() => { showImportModal = true })"
            >
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="17 8 12 3 7 8"></polyline>
                <line x1="12" y1="3" x2="12" y2="15"></line>
              </svg>
              <span>Import Workspace…</span>
            </button>
            <button
              class="more-menu-item"
              data-testid="export-workspace-btn"
              role="menuitem"
              @click="closeMoreMenuAnd(() => $emit('export-workspace'))"
            >
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"></path>
                <polyline points="7 10 12 15 17 10"></polyline>
                <line x1="12" y1="15" x2="12" y2="3"></line>
              </svg>
              <span>Export Workspace</span>
            </button>
            <button
              class="more-menu-item"
              data-testid="link-report-btn"
              role="menuitem"
              @click="closeMoreMenuAnd(() => $emit('toggle-link-report'))"
            >
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M10.29 3.86L1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z"></path>
                <line x1="12" y1="9" x2="12" y2="13"></line>
                <line x1="12" y1="17" x2="12.01" y2="17"></line>
              </svg>
              <span>Broken Links &amp; Orphans</span>
            </button>
            <button
              class="more-menu-item"
              data-testid="publish-workspace-btn"
              role="menuitem"
              @click="closeMoreMenuAnd(() => $emit('publish-workspace'))"
            >
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"></circle>
                <line x1="2" y1="12" x2="22" y2="12"></line>
                <path d="M12 2a15.3 15.3 0 0 1 4 10 15.3 15.3 0 0 1-4 10 15.3 15.3 0 0 1-4-10 15.3 15.3 0 0 1 4-10z"></path>
              </svg>
              <span>Publish Static Site</span>
            </button>
            <button
              class="more-menu-item"
              data-testid="table-view-btn"
              role="menuitem"
              @click="closeMoreMenuAnd(() => $emit('toggle-table-view'))"
            >
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="3" y1="9" x2="21" y2="9"></line>
                <line x1="9" y1="9" x2="9" y2="21"></line>
              </svg>
              <span>Table View</span>
            </button>
            <button
              class="more-menu-item"
              data-testid="board-view-btn"
              role="menuitem"
              @click="closeMoreMenuAnd(() => $emit('toggle-board-view'))"
            >
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="3" width="7" height="18" rx="1"></rect>
                <rect x="14" y="3" width="7" height="10" rx="1"></rect>
              </svg>
              <span>Board View</span>
            </button>
            <button
              class="more-menu-item"
              data-testid="calendar-view-btn"
              role="menuitem"
              @click="closeMoreMenuAnd(() => $emit('toggle-calendar-view'))"
            >
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"></rect>
                <line x1="16" y1="2" x2="16" y2="6"></line>
                <line x1="8" y1="2" x2="8" y2="6"></line>
                <line x1="3" y1="10" x2="21" y2="10"></line>
              </svg>
              <span>Calendar View</span>
            </button>
            <button
              class="more-menu-item"
              data-testid="sidebar-collapse-btn"
              role="menuitem"
              @click="closeMoreMenuAnd(toggleSidebarCollapsed)"
            >
              <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                <polyline points="15 18 9 12 15 6"></polyline>
              </svg>
              <span>Collapse Sidebar</span>
            </button>
          </div>
        </div>
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
        <option value="manual">Manual</option>
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

      <div v-else class="notes-list" ref="rootList" data-folder-path="">
        <NoteTreeNode
          v-for="node in noteTree"
          :key="node.type === 'folder' ? `f:${node.fullPath}` : `n:${node.note.id}`"
          :node="node"
          :selected-note-id="selectedNoteId"
          :depth="0"
          :reveal-path="props.revealFolderRequest?.path ?? null"
          @select-note="$emit('select-note', $event)"
          @delete-note="$emit('delete-note', $event)"
          @create-note-in-folder="$emit('create-note-in-folder', $event)"
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
        <template v-if="availableTemplates.length > 0">
          <label for="new-note-template-select" class="modal-label">Start from a template (optional):</label>
          <select
            id="new-note-template-select"
            v-model="newNoteTemplatePath"
            data-testid="create-note-template-select"
            class="modal-input"
          >
            <option value="">Blank note</option>
            <option v-for="tpl in availableTemplates" :key="tpl.id" :value="tpl.path">
              {{ tpl.title || tpl.path }}
            </option>
          </select>
        </template>
        <div class="modal-actions">
          <button type="button" class="btn-secondary" @click="showNewNoteModal = false">Cancel</button>
          <button type="submit" class="btn-primary" data-testid="create-note-submit" :disabled="!newNotePath.trim()">Create</button>
        </div>
      </form>
    </div>

    <!-- Import Workspace Dialog -->
    <div v-if="showImportModal" class="modal-overlay" @click.self="closeImportModal">
      <form class="modal-card" @submit.prevent="handleImportSubmit">
        <h3>Import Workspace Archive</h3>
        <p class="modal-desc">Upload a <code>.zip</code> export (Markdown notes + attachments) to import into this workspace.</p>
        <input
          ref="importFileInputRef"
          data-testid="import-file-input"
          type="file"
          accept=".zip"
          class="modal-input"
          required
          @change="onImportFileSelected"
        />
        <label class="modal-checkbox-label">
          <input v-model="importOverwrite" type="checkbox" data-testid="import-overwrite-checkbox" />
          <span>Overwrite existing notes with the same path</span>
        </label>
        <div class="modal-actions">
          <button type="button" class="btn-secondary" @click="closeImportModal">Cancel</button>
          <button type="submit" class="btn-primary" data-testid="import-submit-btn" :disabled="!importFile">Import</button>
        </div>
      </form>
    </div>

    <!-- User Profile Footer -->
    <div v-if="currentUser" class="sidebar-footer">
      <div class="user-badge" data-testid="user-profile">
        <span class="user-name">{{ currentUser.name }}</span>
        <span class="user-email">{{ currentUser.email }}</span>
      </div>
      <div class="sidebar-footer-actions">
        <ThemeToggle />
        <button class="btn-logout" data-testid="logout-btn" title="Sign Out" @click="$emit('logout')">
          <svg viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
            <path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"></path>
            <polyline points="16 17 21 12 16 7"></polyline>
            <line x1="21" y1="12" x2="9" y2="12"></line>
          </svg>
        </button>
      </div>
    </div>

    <div class="sidebar-version" data-testid="app-version">
      v {{ props.frontendVersion }}
      <span
        v-if="props.backendVersion && props.backendVersion !== props.frontendVersion"
        class="sidebar-version-mismatch"
        data-testid="app-version-mismatch"
      >
        (backend: {{ props.backendVersion }})
      </span>
    </div>
  </aside>
</template>

<script setup lang="ts">
import { ref, computed, provide, onMounted, onBeforeUnmount, watch, useTemplateRef, nextTick } from 'vue'
import type { NoteMeta, AuthUser, NotificationItem, FolderPosition, SortItem, Workspace, Tenant } from '../services/types'
import NoteTreeNode from './NoteTreeNode.vue'
import type { TreeFolder, TreeNode } from './NoteTreeNode.vue'
import ThemeToggle from './ThemeToggle.vue'
import WorkspaceSwitcher from './WorkspaceSwitcher.vue'
import TenantSwitcher from './TenantSwitcher.vue'
import { moveNote, reorderNoteTree } from '../services/api'
import { createNoteTreeSortable, type NoteTreeSortableHandle } from '../composables/useNoteTreeSortable'
import { useCollapsiblePanel } from '../composables/useCollapsiblePanel'

const props = defineProps<{
  notes: NoteMeta[]
  selectedNoteId: number | null
  currentUser?: AuthUser | null
  notifications?: NotificationItem[]
  isMobileSidebarOpen?: boolean
  workspaceId?: number | null
  workspaces: Workspace[]
  folderPositions?: FolderPosition[]
  revealFolderRequest?: { path: string; nonce: number } | null
  frontendVersion: string
  backendVersion?: string | null
  tenants?: Tenant[]
  activeTenantId?: number | null
}>()

const emit = defineEmits<{
  (e: 'select-note', noteId: number): void
  (e: 'create-note', path: string): void
  (e: 'create-note-from-template', templatePath: string, targetPath: string): void
  (e: 'delete-note', noteId: number): void
  (e: 'search', query: string): void
  (e: 'logout'): void
  (e: 'mark-notification-read', notificationId: number): void
  (e: 'delete-notification', notificationId: number): void
  (e: 'toggle-attachments'): void
  (e: 'daily-note'): void
  (e: 'toggle-audit-log'): void
  (e: 'import-workspace', archive: File, overwrite: boolean): void
  (e: 'export-workspace'): void
  (e: 'toggle-link-report'): void
  (e: 'publish-workspace'): void
  (e: 'toggle-table-view'): void
  (e: 'toggle-board-view'): void
  (e: 'toggle-calendar-view'): void
  (e: 'notes-reordered'): void
  (e: 'create-note-in-folder', folderPath: string): void
  (e: 'switch-workspace', workspaceId: number): void
  (e: 'switch-tenant', tenantId: number): void
}>()

// Desktop sidebar collapse (#259): hands the full window to the page,
// matching Notion's `«` collapse. Self-contained in this component — the
// expand button below is `position: fixed`, so it stays reachable even
// when the sidebar's own width collapses to 0. Mobile's existing
// transform-based drawer (`.mobile-open`) is untouched; this is scoped to
// desktop widths only.
const { collapsed: sidebarCollapsed, toggle: toggleSidebarCollapsed } = useCollapsiblePanel('sidebar-desktop', false)

const searchQuery = ref('')
const activeTag = ref<string | null>(null)
const sortBy = ref<'recent' | 'name' | 'path' | 'manual'>('recent')
const showNewNoteModal = ref(false)
const newNotePath = ref('')
const newNoteTemplatePath = ref('')
const showImportModal = ref(false)
const showMoreMenu = ref(false)
const showNotifications = ref(false)
const notifications = computed(() => props.notifications ?? [])
const unreadNotificationCount = computed(() => notifications.value.filter(n => !n.read_at).length)

function formatNotificationTime(iso: string): string {
  try {
    return new Date(iso).toLocaleString(undefined, {
      month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    })
  } catch {
    return iso
  }
}

function closeMoreMenuAnd(action: () => void) {
  showMoreMenu.value = false
  action()
}
const importFile = ref<File | null>(null)
const importOverwrite = ref(false)
const importFileInputRef = ref<HTMLInputElement | null>(null)

const availableTemplates = computed(() =>
  props.notes.filter(n => n.path.startsWith('_templates/'))
)

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
    if (sortBy.value === 'manual') {
      return 0
    }
    return a.path.localeCompare(b.path)
  })
})

function buildTree(notes: NoteMeta[], manual: boolean, folderPositionMap: Map<string, number>): TreeNode[] {
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

  function positionOf(node: TreeNode): number | null {
    if (node.type === 'folder') return folderPositionMap.get(node.fullPath) ?? null
    return node.note.sort_position ?? null
  }

  function displayName(node: TreeNode): string {
    return node.type === 'folder' ? node.name : (node.note.title || node.note.path)
  }

  function sortChildrenAlphabetical(folder: TreeFolder) {
    const subfolders = folder.children.filter((c): c is TreeFolder => c.type === 'folder')
    const files = folder.children.filter((c) => c.type === 'file')
    subfolders.sort((a, b) => a.name.localeCompare(b.name))
    subfolders.forEach(sortChildrenAlphabetical)
    folder.children = [...subfolders, ...files]
  }

  function sortChildrenManual(folder: TreeFolder) {
    const positioned = folder.children.filter((c) => positionOf(c) !== null)
    const unpositioned = folder.children.filter((c) => positionOf(c) === null)

    positioned.sort((a, b) => positionOf(a)! - positionOf(b)!)
    unpositioned.sort((a, b) => displayName(a).localeCompare(displayName(b)))

    folder.children = [...positioned, ...unpositioned]
    folder.children.forEach((c) => { if (c.type === 'folder') sortChildrenManual(c) })
  }

  if (manual) {
    sortChildrenManual(root)
  } else {
    sortChildrenAlphabetical(root)
  }

  return root.children
}

const folderPositionMap = computed(
  () => new Map((props.folderPositions ?? []).map((fp) => [fp.folder_path, fp.sort_position]))
)

const noteTree = computed(() =>
  buildTree(filteredAndSortedNotes.value, sortBy.value === 'manual', folderPositionMap.value)
)

async function handleReorder(folderPath: string, items: SortItem[]) {
  if (!props.workspaceId) return
  try {
    await reorderNoteTree(props.workspaceId, folderPath, items)
    emit('notes-reordered')
  } catch (err) {
    console.error('Failed to reorder note tree:', err)
  }
}

async function handleReparentNote(noteId: number, newPath: string, destFolderPath: string, items: SortItem[]) {
  if (!props.workspaceId) return
  try {
    await moveNote(props.workspaceId, noteId, newPath)
    await reorderNoteTree(props.workspaceId, destFolderPath, items)
    emit('notes-reordered')
  } catch (err) {
    console.error('Failed to move note:', err)
  }
}

const isManualMode = computed(() => sortBy.value === 'manual')
provide('noteTreeDragCallbacks', { onReorder: handleReorder, onReparentNote: handleReparentNote })
provide('noteTreeManualMode', isManualMode)

const rootListRef = useTemplateRef<HTMLElement>('rootList')
let rootSortable: NoteTreeSortableHandle | null = null

onMounted(() => {
  if (!rootListRef.value) return
  rootSortable = createNoteTreeSortable(rootListRef.value, !isManualMode.value, {
    onReorder: handleReorder,
    onReparentNote: handleReparentNote,
  })
})

watch(isManualMode, (manual) => {
  rootSortable?.setDisabled(!manual)
})

onBeforeUnmount(() => {
  rootSortable?.destroy()
})

watch(
  () => props.revealFolderRequest,
  async (request) => {
    if (!request) return
    await nextTick()
    const el = rootListRef.value?.querySelector<HTMLElement>(
      `[data-item-type="folder"][data-item-path="${request.path}"]`,
    )
    if (!el) return
    el.scrollIntoView?.({ block: 'center', behavior: 'smooth' })
    el.classList.add('folder-row-highlight')
    setTimeout(() => el.classList.remove('folder-row-highlight'), 1500)
  },
)

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
  const targetPath = path.endsWith('.md') ? path : `${path}.md`

  if (newNoteTemplatePath.value) {
    emit('create-note-from-template', newNoteTemplatePath.value, targetPath)
  } else {
    emit('create-note', targetPath)
  }

  newNotePath.value = ''
  newNoteTemplatePath.value = ''
  showNewNoteModal.value = false
}

function onImportFileSelected(event: Event) {
  const target = event.target as HTMLInputElement
  importFile.value = target.files?.[0] ?? null
}

function closeImportModal() {
  showImportModal.value = false
  importFile.value = null
  importOverwrite.value = false
  if (importFileInputRef.value) importFileInputRef.value.value = ''
}

function handleImportSubmit() {
  if (!importFile.value) return
  emit('import-workspace', importFile.value, importOverwrite.value)
  closeImportModal()
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
  transition: width var(--duration-standard) var(--ease-standard),
              min-width var(--duration-standard) var(--ease-standard);
}

/* `.sidebar-expand-btn` below is `position: fixed` specifically so it
   still works once `.sidebar` clips to zero width here. That only holds
   because nothing in this ancestor chain sets `transform`/`filter`/
   `perspective`/`will-change` at this breakpoint (mobile's own
   `transform: translateX(...)` below is gated to max-width: 768px) —
   any of those would create a new containing block and trap the fixed
   button inside the zero-width box. Don't add one here without also
   reworking the expand button's positioning. */
@media (min-width: 769px) {
  .sidebar.sidebar-collapsed {
    width: 0;
    min-width: 0;
    border-right: none;
    overflow: hidden;
  }
}

.sidebar-expand-btn {
  position: fixed;
  top: var(--space-3);
  left: var(--space-3);
  z-index: 20;
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 32px;
  min-height: 32px;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  color: var(--color-text-muted);
  cursor: pointer;
  transition: color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard);
}

.sidebar-expand-btn:hover {
  color: var(--color-text);
  background: var(--color-hover);
}

@media (max-width: 768px) {
  .sidebar-expand-btn {
    display: none;
  }
}

@media (max-width: 768px) {
  .sidebar {
    position: fixed;
    inset: 0 auto 0 0;
    z-index: 40;
    width: min(85vw, 320px);
    min-width: 0;
    transform: translateX(-100%);
    transition: transform var(--duration-standard) var(--ease-standard);
  }

  .sidebar.mobile-open {
    transform: translateX(0);
  }
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

.more-menu-wrapper {
  position: relative;
}

.more-menu-backdrop {
  position: fixed;
  inset: 0;
  z-index: 90;
}

.more-menu {
  position: absolute;
  top: calc(100% + var(--space-1));
  right: 0;
  min-width: 200px;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  box-shadow: var(--shadow-float);
  padding: var(--space-1);
  z-index: 91;
  display: flex;
  flex-direction: column;
}

.more-menu-item {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  background: transparent;
  border: none;
  color: var(--color-text);
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.8125rem;
  text-align: left;
  min-height: 40px;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.more-menu-item:hover {
  background: var(--color-surface-emphasis);
}

.notification-badge {
  position: absolute;
  top: 2px;
  right: 2px;
  background: var(--color-status-danger);
  color: var(--color-text-inverse);
  font-size: 0.625rem;
  font-weight: 700;
  line-height: 1;
  padding: 0.15rem 0.3rem;
  border-radius: var(--radius-pill);
  min-width: 16px;
  text-align: center;
}

.notifications-menu {
  left: 0;
  right: auto;
  min-width: 280px;
  max-width: 340px;
  max-height: 400px;
  overflow-y: auto;
  padding: var(--space-2);
}

.notifications-empty {
  color: var(--color-text-muted);
  font-size: 0.8125rem;
  font-style: italic;
  padding: var(--space-3);
}

.notification-item {
  display: flex;
  align-items: flex-start;
  gap: var(--space-2);
  padding: var(--space-2);
  border-radius: var(--radius-sm);
}

.notification-item.unread {
  background: color-mix(in srgb, var(--color-action) 12%, transparent);
}

.notification-item:hover {
  background: var(--color-surface-emphasis);
}

.notification-body {
  flex: 1;
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  cursor: pointer;
  min-width: 0;
}

.notification-title {
  font-size: 0.8125rem;
  color: var(--color-text);
  font-weight: 500;
}

.notification-snippet {
  font-size: 0.75rem;
  color: var(--color-text-muted);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.notification-time {
  font-size: 0.7rem;
  color: var(--color-text-muted);
}

.btn-delete-notification {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  padding: var(--space-1);
  border-radius: var(--radius-sm);
  cursor: pointer;
  min-width: 24px;
  min-height: 24px;
  display: flex;
  align-items: center;
  justify-content: center;
  flex-shrink: 0;
  transition: color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard);
}

.btn-delete-notification:hover {
  color: var(--color-status-danger);
  background: color-mix(in srgb, var(--color-status-danger) 12%, transparent);
}

.btn-icon {
  position: relative;
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
  box-shadow: var(--shadow-float);
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

.modal-label {
  display: block;
  font-size: 0.8125rem;
  color: var(--color-text-muted);
  margin-bottom: var(--space-2);
}

.modal-checkbox-label {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-size: 0.8125rem;
  color: var(--color-text-muted);
  margin-top: var(--space-3);
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

.sidebar-footer-actions {
  display: flex;
  align-items: center;
  gap: var(--space-1);
}

.sidebar-version {
  padding: var(--space-1) var(--space-4) var(--space-2);
  font-size: 0.6875rem;
  color: var(--color-text-muted);
  text-align: center;
}

.sidebar-version-mismatch {
  color: var(--color-status-warning);
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

