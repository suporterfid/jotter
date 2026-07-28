<template>
  <Teleport to="body">
    <Transition name="fade">
      <div v-if="isOpen" class="command-palette-backdrop" @click.self="close">
        <div class="command-palette-modal">
          <!-- Input Header -->
          <div class="palette-header">
            <svg class="search-icon" viewBox="0 0 24 24" width="20" height="20" fill="none" stroke="currentColor" stroke-width="2">
              <circle cx="11" cy="11" r="8"></circle>
              <line x1="21" y1="21" x2="16.65" y2="16.65"></line>
            </svg>
            <input
              ref="searchInputRef"
              v-model="query"
              type="text"
              class="palette-input"
              placeholder="Type a command or search notes... (Esc to close)"
              @keydown.down.prevent="navigate(1)"
              @keydown.up.prevent="navigate(-1)"
              @keydown.enter.prevent="selectActive"
              @keydown.esc.prevent="close"
            />
            <kbd class="shortcut-badge">ESC</kbd>
          </div>

          <!-- Options List -->
          <div class="palette-results">
            <!-- Quick Actions Group -->
            <div v-if="filteredActions.length > 0" class="group-section">
              <div class="group-label">Quick Actions</div>
              <div
                v-for="(action, index) in filteredActions"
                :key="action.id"
                :class="['palette-item', { active: selectedIndex === index }]"
                @click="executeAction(action)"
                @mouseenter="selectedIndex = index"
              >
                <span class="item-icon">{{ action.icon }}</span>
                <span class="item-title">{{ action.title }}</span>
                <kbd v-if="action.shortcut" class="item-kbd">{{ action.shortcut }}</kbd>
              </div>
            </div>

            <!-- Notes Matching Group -->
            <div v-if="filteredNotes.length > 0" class="group-section">
              <div class="group-label">Notes ({{ filteredNotes.length }})</div>
              <div
                v-for="(note, nIndex) in filteredNotes"
                :key="note.id"
                :class="['palette-item', { active: selectedIndex === filteredActions.length + nIndex }]"
                @click="selectNoteItem(note)"
                @mouseenter="selectedIndex = filteredActions.length + nIndex"
              >
                <svg class="item-icon-svg" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2">
                  <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
                  <polyline points="14 2 14 8 20 8"></polyline>
                </svg>
                <span class="item-title">{{ note.title || note.path }}</span>
                <span class="item-path">{{ note.path }}</span>
              </div>
            </div>

            <!-- Empty Results State -->
            <div v-if="filteredActions.length === 0 && filteredNotes.length === 0" class="no-results">
              No matching commands or notes found for "{{ query }}"
            </div>
          </div>

          <!-- Footer Hints -->
          <div class="palette-footer">
            <span class="hint-item"><kbd>↑</kbd> <kbd>↓</kbd> Navigate</span>
            <span class="hint-item"><kbd>↵</kbd> Select</span>
            <span class="hint-item"><kbd>esc</kbd> Dismiss</span>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>

<script setup lang="ts">
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue'
import type { NoteMeta } from '../services/types'

const props = defineProps<{
  notes: NoteMeta[]
}>()

const emit = defineEmits<{
  (e: 'select-note', noteId: number): void
  (e: 'create-note'): void
  (e: 'search'): void
  (e: 'toggle-graph'): void
  (e: 'logout'): void
}>()

const isOpen = ref(false)
const query = ref('')
const selectedIndex = ref(0)
const searchInputRef = ref<HTMLInputElement | null>(null)

interface ActionItem {
  id: string
  title: string
  icon: string
  shortcut?: string
  action: () => void
}

const actions: ActionItem[] = [
  {
    id: 'create',
    title: 'Create New Note',
    icon: '📝',
    shortcut: 'N',
    action: () => emit('create-note')
  },
  {
    id: 'graph',
    title: 'Open Relationship Graph View',
    icon: '🕸️',
    shortcut: 'G',
    action: () => emit('toggle-graph')
  },
  {
    id: 'search',
    title: 'Fulltext Search Notes',
    icon: '🔍',
    action: () => emit('search')
  },
  {
    id: 'logout',
    title: 'Log Out',
    icon: '🚪',
    action: () => emit('logout')
  }
]

const filteredActions = computed(() => {
  if (!query.value.trim()) return actions
  const q = query.value.toLowerCase()
  return actions.filter(a => a.title.toLowerCase().includes(q))
})

const filteredNotes = computed(() => {
  if (!query.value.trim()) return props.notes.slice(0, 8)
  const q = query.value.toLowerCase()
  return props.notes
    .filter(n => (n.title && n.title.toLowerCase().includes(q)) || n.path.toLowerCase().includes(q))
    .slice(0, 10)
})

const totalItemsCount = computed(() => filteredActions.value.length + filteredNotes.value.length)

watch(query, () => {
  selectedIndex.value = 0
})

function open() {
  isOpen.value = true
  query.value = ''
  selectedIndex.value = 0
  nextTick(() => {
    searchInputRef.value?.focus()
  })
}

function close() {
  isOpen.value = false
}

function navigate(delta: number) {
  if (totalItemsCount.value === 0) return
  selectedIndex.value = (selectedIndex.value + delta + totalItemsCount.value) % totalItemsCount.value
}

function selectActive() {
  if (selectedIndex.value < filteredActions.value.length) {
    const action = filteredActions.value[selectedIndex.value]
    if (action) executeAction(action)
  } else {
    const noteIndex = selectedIndex.value - filteredActions.value.length
    const note = filteredNotes.value[noteIndex]
    if (note) selectNoteItem(note)
  }
}

function executeAction(action: ActionItem) {
  close()
  action.action()
}

function selectNoteItem(note: NoteMeta) {
  close()
  emit('select-note', note.id)
}

function handleGlobalKeydown(e: KeyboardEvent) {
  if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
    e.preventDefault()
    if (isOpen.value) {
      close()
    } else {
      open()
    }
  }
}

onMounted(() => {
  window.addEventListener('keydown', handleGlobalKeydown)
})

onUnmounted(() => {
  window.removeEventListener('keydown', handleGlobalKeydown)
})

defineExpose({ open, close })
</script>

<style scoped>
/* Solid overlay — no backdrop-filter */
.command-palette-backdrop {
  position: fixed;
  inset: 0;
  background: var(--color-overlay);
  z-index: 9999;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding-top: 10vh;
}

.command-palette-modal {
  width: min(90vw, 640px);
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  box-shadow: var(--shadow-lg);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.palette-header {
  display: flex;
  align-items: center;
  padding: var(--space-4);
  border-bottom: 1px solid var(--color-border);
  gap: var(--space-3);
}

.search-icon {
  color: var(--color-action);
  flex-shrink: 0;
}

.palette-input {
  flex: 1;
  background: transparent;
  border: none;
  color: var(--color-text);
  font-size: 1.05rem;
  font-family: inherit;
  /* No outline:none — global :focus-visible handles ring */
}

.shortcut-badge {
  background: var(--color-surface-emphasis);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-sm);
  padding: 0.15rem 0.4rem;
  font-size: 0.7rem;
  color: var(--color-text-muted);
  font-family: var(--font-mono);
}

.palette-results {
  max-height: 380px;
  overflow-y: auto;
  padding: var(--space-2);
}

.group-section {
  margin-bottom: var(--space-2);
}

.group-label {
  padding: var(--space-1) var(--space-3);
  font-size: 0.75rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: var(--color-text-muted);
}

.palette-item {
  display: flex;
  align-items: center;
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-md);
  cursor: pointer;
  gap: var(--space-3);
  transition: background-color var(--duration-fast) var(--ease-standard),
              color var(--duration-fast) var(--ease-standard);
  color: var(--color-text-muted);
  min-height: 44px;
}

.palette-item.active {
  background: color-mix(in srgb, var(--color-action) 20%, transparent);
  color: var(--color-text);
}

.item-icon {
  font-size: 1.1rem;
}

.item-icon-svg {
  color: var(--color-action);
  flex-shrink: 0;
}

.item-title {
  flex: 1;
  font-size: 0.925rem;
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.item-path {
  font-size: 0.775rem;
  color: var(--color-text-muted);
  font-family: var(--font-mono);
}

.item-kbd {
  background: var(--color-surface-emphasis);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: 0.1rem 0.35rem;
  font-size: 0.7rem;
  color: var(--color-text-muted);
  font-family: var(--font-mono);
}

.no-results {
  padding: var(--space-8);
  text-align: center;
  color: var(--color-text-muted);
  font-size: 0.9rem;
}

.palette-footer {
  display: flex;
  align-items: center;
  gap: var(--space-4);
  padding: var(--space-2) var(--space-4);
  background: var(--color-surface-emphasis);
  border-top: 1px solid var(--color-border);
  font-size: 0.75rem;
  color: var(--color-text-muted);
}

.hint-item {
  display: flex;
  align-items: center;
  gap: var(--space-1);
}

.hint-item kbd {
  background: var(--color-surface);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-sm);
  padding: 0.05rem 0.3rem;
  font-size: 0.65rem;
  color: var(--color-text-muted);
  font-family: var(--font-mono);
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity var(--duration-standard) var(--ease-standard);
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
