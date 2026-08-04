<template>
  <div class="collections-board-view">
    <div class="panel-header">
      <h2>Board View</h2>
      <span class="count-badge">{{ page.total }}</span>
    </div>
    <p class="panel-hint">Notes grouped by a property, arranged as columns.</p>

    <form class="group-bar" @submit.prevent="applyGroupProperty">
      <input
        v-model="groupPropertyInput"
        type="text"
        placeholder="Property to group by (e.g. status)"
        aria-label="Group by property name"
        data-testid="board-group-property"
        class="group-input"
        :list="'board-property-options'"
      />
      <datalist id="board-property-options">
        <option v-for="col in propertyColumns" :key="col" :value="col" />
      </datalist>
      <button type="submit" class="btn-group-apply" data-testid="board-group-apply">Group</button>
    </form>

    <div v-if="loading" class="panel-empty">
      <p>Loading notes…</p>
    </div>

    <div v-else-if="page.data.length === 0" class="panel-empty">
      <p>No notes match this view.</p>
    </div>

    <div v-else-if="!groupProperty" class="panel-empty">
      <p>Choose a property above to group notes into columns.</p>
    </div>

    <div v-else class="board-scroll">
      <div v-for="column in columns" :key="column.key" class="board-column" data-testid="board-column">
        <div class="board-column-header">
          <span class="board-column-title">{{ column.label }}</span>
          <span class="count-badge">{{ column.notes.length }}</span>
        </div>
        <div
          class="board-column-body"
          data-testid="board-column-body"
          :data-column-key="column.key"
          :ref="(el) => setColumnBodyRef(column.key, el)"
        >
          <button
            v-for="note in column.notes"
            :key="note.id"
            type="button"
            class="board-card"
            data-testid="board-card"
            :data-note-id="note.id"
            @click="$emit('select-note', note.id)"
          >
            <span class="board-card-title">{{ note.title || note.path }}</span>
            <span class="board-card-path">{{ note.path }}</span>
          </button>
        </div>
        <form
          v-if="addCardColumnKey === column.key"
          class="board-add-card-form"
          data-testid="board-add-card-form"
          @submit.prevent="submitAddCard(column.key)"
        >
          <input
            v-model="addCardTitle"
            type="text"
            placeholder="Card title"
            aria-label="New card title"
            data-testid="board-add-card-input"
            class="add-card-input"
            autofocus
          />
          <button type="submit" class="btn-add-card-confirm">Add</button>
          <button type="button" class="btn-add-card-cancel" @click="cancelAddCard">Cancel</button>
        </form>
        <button
          v-else
          type="button"
          class="btn-add-card"
          data-testid="board-add-card-button"
          @click="openAddCard(column.key)"
        >
          + Add card
        </button>
      </div>
    </div>

    <div v-if="page.last_page > 1" class="pagination-bar">
      <button
        type="button"
        class="btn-page"
        data-testid="collection-prev-page"
        :disabled="page.current_page <= 1"
        @click="$emit('page-change', page.current_page - 1)"
      >
        Previous
      </button>
      <span class="page-indicator">Page {{ page.current_page }} of {{ page.last_page }}</span>
      <button
        type="button"
        class="btn-page"
        data-testid="collection-next-page"
        :disabled="page.current_page >= page.last_page"
        @click="$emit('page-change', page.current_page + 1)"
      >
        Next
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch, nextTick, onBeforeUnmount, onMounted } from 'vue'
import Sortable from 'sortablejs'
import type { CollectionPage } from '../services/types'
import {
  formatPropertyValue,
  propertyColumns as computePropertyColumns,
  resolveCardMove,
  UNGROUPED_LABEL,
} from '../services/collectionUtils'

const props = defineProps<{
  page: CollectionPage
  loading?: boolean
  groupProperty?: string | null
}>()

const emit = defineEmits<{
  (e: 'select-note', noteId: number): void
  (e: 'page-change', page: number): void
  (e: 'group-change', property: string): void
  (e: 'move-card', noteId: number, newValue: string): void
  (e: 'create-card', title: string, columnValue: string): void
}>()

const groupPropertyInput = ref(props.groupProperty || '')

watch(() => props.groupProperty, (value) => {
  groupPropertyInput.value = value || ''
})

function applyGroupProperty() {
  emit('group-change', groupPropertyInput.value.trim())
}

const propertyColumns = computed(() => computePropertyColumns(props.page))

const columns = computed(() => {
  if (!props.groupProperty) return []
  const groups = new Map<string, typeof props.page.data>()
  for (const note of props.page.data) {
    const label = formatPropertyValue(note, props.groupProperty)
    const key = label === '—' ? UNGROUPED_LABEL : label
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key)!.push(note)
  }
  const sortedKeys = Array.from(groups.keys()).sort((a, b) => {
    if (a === UNGROUPED_LABEL) return 1
    if (b === UNGROUPED_LABEL) return -1
    return a.localeCompare(b)
  })
  return sortedKeys.map(key => ({ key, label: key, notes: groups.get(key)! }))
})

// Drag-and-drop between columns (#299): one Sortable instance per column
// body, sharing a group so cards can move between them. onEnd resolves the
// move via the pure resolveCardMove() rather than deciding inline, so the
// decision logic is unit-testable without a real Sortable instance.
const columnBodyRefs = new Map<string, HTMLElement>()

function setColumnBodyRef(key: string, el: unknown) {
  if (el instanceof HTMLElement) {
    columnBodyRefs.set(key, el)
  } else {
    columnBodyRefs.delete(key)
  }
}

let sortableInstances: Sortable[] = []

function destroySortables() {
  sortableInstances.forEach(s => s.destroy())
  sortableInstances = []
}

function initSortables() {
  destroySortables()
  for (const el of columnBodyRefs.values()) {
    sortableInstances.push(
      Sortable.create(el, {
        group: 'board-column',
        animation: 150,
        ghostClass: 'board-card-ghost',
        // Native HTML5 drag-and-drop never fires from touch input, so the
        // fallback (mouse/touch-event-based) simulation is required for
        // touch support, same as the sidebar's note-tree drag-and-drop.
        forceFallback: true,
        onEnd(evt) {
          const from = evt.from as HTMLElement
          const to = evt.to as HTMLElement
          const item = evt.item as HTMLElement
          const move = resolveCardMove(from, to, item)
          if (move) emit('move-card', move.noteId, move.newValue)
        },
      })
    )
  }
}

onMounted(() => {
  nextTick(initSortables)
})

watch(columns, () => {
  nextTick(initSortables)
})

onBeforeUnmount(destroySortables)

// Card creation from the board (#300): one column has its add-card form
// open at a time, tracked by column key.
const addCardColumnKey = ref<string | null>(null)
const addCardTitle = ref('')

function openAddCard(key: string) {
  addCardColumnKey.value = key
  addCardTitle.value = ''
}

function cancelAddCard() {
  addCardColumnKey.value = null
  addCardTitle.value = ''
}

function submitAddCard(columnKey: string) {
  const title = addCardTitle.value.trim()
  if (!title) return
  emit('create-card', title, columnKey === UNGROUPED_LABEL ? '' : columnKey)
  cancelAddCard()
}
</script>

<style scoped>
.collections-board-view {
  flex: 1;
  overflow-y: auto;
  padding: var(--space-6);
  display: flex;
  flex-direction: column;
}

.panel-header {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin-bottom: var(--space-2);
}

.panel-header h2 {
  font-family: var(--font-sans);
  font-size: 1.25rem;
  color: var(--color-text);
}

.count-badge {
  background: var(--color-surface-emphasis);
  color: var(--color-text-muted);
  padding: 0.1rem 0.5rem;
  border-radius: var(--radius-pill);
  font-size: 0.8rem;
  font-weight: 600;
}

.panel-hint {
  font-size: 0.8125rem;
  color: var(--color-text-muted);
  margin-bottom: var(--space-4);
}

.group-bar {
  display: flex;
  gap: var(--space-2);
  align-items: center;
  margin-bottom: var(--space-4);
}

.group-input {
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-1) var(--space-2);
  color: var(--color-text);
  font-size: 0.8125rem;
  min-height: 32px;
  min-width: 220px;
}

.btn-group-apply,
.btn-page {
  background: var(--color-action);
  color: var(--color-neutral-0);
  border: none;
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.8125rem;
  min-height: 32px;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.btn-group-apply:hover,
.btn-page:hover:not(:disabled) {
  background: var(--color-action-hover);
}

.btn-page:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.panel-empty {
  color: var(--color-text-muted);
  padding: var(--space-8) 0;
}

.board-scroll {
  display: flex;
  gap: var(--space-4);
  overflow-x: auto;
  flex: 1;
  align-items: flex-start;
  padding-bottom: var(--space-2);
}

.board-column {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  min-width: 240px;
  max-width: 280px;
  flex-shrink: 0;
  display: flex;
  flex-direction: column;
  max-height: 100%;
}

.board-column-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  padding: var(--space-3);
  border-bottom: 1px solid var(--color-border);
}

.board-column-title {
  font-weight: 600;
  font-size: 0.85rem;
  color: var(--color-text);
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.board-column-body {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: var(--space-3);
  overflow-y: auto;
}

.board-card {
  display: flex;
  flex-direction: column;
  gap: 2px;
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-2);
  text-align: left;
  cursor: pointer;
  font: inherit;
  color: inherit;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.board-card:hover {
  background: var(--color-surface-emphasis);
}

.board-card-ghost {
  background: var(--color-hover);
  opacity: 0.6;
}

.btn-add-card {
  background: none;
  border: 1px dashed var(--color-border);
  border-radius: var(--radius-sm);
  color: var(--color-text-muted);
  padding: var(--space-2);
  margin: 0 var(--space-3) var(--space-3);
  cursor: pointer;
  font-size: 0.8125rem;
  text-align: left;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.btn-add-card:hover {
  background: var(--color-surface-emphasis);
  color: var(--color-text);
}

.board-add-card-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: 0 var(--space-3) var(--space-3);
}

.add-card-input {
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-1) var(--space-2);
  color: var(--color-text);
  font-size: 0.8125rem;
  min-height: 32px;
}

.btn-add-card-confirm,
.btn-add-card-cancel {
  background: var(--color-action);
  color: var(--color-neutral-0);
  border: none;
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.8125rem;
  min-height: 28px;
}

.btn-add-card-cancel {
  background: var(--color-surface-emphasis);
  color: var(--color-text-muted);
}

.board-card-title {
  font-weight: 500;
  font-size: 0.8125rem;
  color: var(--color-text);
}

.board-card-path {
  color: var(--color-text-muted);
  font-family: var(--font-mono, monospace);
  font-size: 0.7rem;
}

.pagination-bar {
  display: flex;
  align-items: center;
  justify-content: center;
  gap: var(--space-3);
  margin-top: var(--space-4);
}

.page-indicator {
  font-size: 0.8125rem;
  color: var(--color-text-muted);
}
</style>
