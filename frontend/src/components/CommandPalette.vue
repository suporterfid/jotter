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
.command-palette-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.65);
  backdrop-filter: blur(8px);
  -webkit-backdrop-filter: blur(8px);
  z-index: 9999;
  display: flex;
  align-items: flex-start;
  justify-content: center;
  padding-top: 10vh;
}

.command-palette-modal {
  width: min(90vw, 640px);
  background: rgba(15, 23, 42, 0.92);
  backdrop-filter: blur(16px);
  -webkit-backdrop-filter: blur(16px);
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 1rem;
  box-shadow: 0 20px 50px rgba(0, 0, 0, 0.5);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}

.palette-header {
  display: flex;
  align-items: center;
  padding: 1rem 1.25rem;
  border-bottom: 1px solid rgba(255, 255, 255, 0.08);
  gap: 0.75rem;
}

.search-icon {
  color: var(--accent-color, #6366f1);
  flex-shrink: 0;
}

.palette-input {
  flex: 1;
  background: transparent;
  border: none;
  color: #f8fafc;
  font-size: 1.05rem;
  font-family: inherit;
  outline: none;
}

.shortcut-badge {
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.15);
  border-radius: 0.25rem;
  padding: 0.15rem 0.4rem;
  font-size: 0.7rem;
  color: #94a3b8;
  font-family: monospace;
}

.palette-results {
  max-height: 380px;
  overflow-y: auto;
  padding: 0.5rem;
}

.group-section {
  margin-bottom: 0.5rem;
}

.group-label {
  padding: 0.35rem 0.75rem;
  font-size: 0.725rem;
  font-weight: 600;
  text-transform: uppercase;
  letter-spacing: 0.05em;
  color: #64748b;
}

.palette-item {
  display: flex;
  align-items: center;
  padding: 0.65rem 0.85rem;
  border-radius: 0.5rem;
  cursor: pointer;
  gap: 0.75rem;
  transition: all 0.15s ease;
  color: #cbd5e1;
}

.palette-item.active {
  background: rgba(99, 102, 241, 0.2);
  color: #f8fafc;
}

.item-icon {
  font-size: 1.1rem;
}

.item-icon-svg {
  color: #818cf8;
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
  color: #64748b;
  font-family: monospace;
}

.item-kbd {
  background: rgba(255, 255, 255, 0.06);
  border: 1px solid rgba(255, 255, 255, 0.1);
  border-radius: 0.25rem;
  padding: 0.1rem 0.35rem;
  font-size: 0.7rem;
  color: #94a3b8;
  font-family: monospace;
}

.no-results {
  padding: 2rem;
  text-align: center;
  color: #64748b;
  font-size: 0.9rem;
}

.palette-footer {
  display: flex;
  align-items: center;
  gap: 1rem;
  padding: 0.65rem 1.25rem;
  background: rgba(0, 0, 0, 0.25);
  border-top: 1px solid rgba(255, 255, 255, 0.06);
  font-size: 0.75rem;
  color: #64748b;
}

.hint-item {
  display: flex;
  align-items: center;
  gap: 0.25rem;
}

.hint-item kbd {
  background: rgba(255, 255, 255, 0.08);
  border: 1px solid rgba(255, 255, 255, 0.12);
  border-radius: 0.2rem;
  padding: 0.05rem 0.3rem;
  font-size: 0.65rem;
  color: #94a3b8;
  font-family: monospace;
}

.fade-enter-active,
.fade-leave-active {
  transition: opacity 0.2s ease;
}

.fade-enter-from,
.fade-leave-to {
  opacity: 0;
}
</style>
