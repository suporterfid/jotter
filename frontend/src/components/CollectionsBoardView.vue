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
      <input
        v-model="swimlanePropertyInput"
        type="text"
        placeholder="Swimlane by property (optional)"
        aria-label="Swimlane by property name"
        data-testid="board-swimlane-property"
        class="group-input"
        :list="'board-property-options'"
        @change="applySwimlaneProperty"
      />
      <select
        v-if="allTags.length > 0"
        v-model="tagFilter"
        aria-label="Filter by tag"
        data-testid="board-tag-filter"
        class="tag-filter-select"
      >
        <option value="">All tags</option>
        <option v-for="tag in allTags" :key="tag" :value="tag">{{ tag }}</option>
      </select>
      <label class="show-archived-label">
        <input
          type="checkbox"
          :checked="showArchived"
          data-testid="board-show-archived"
          @change="$emit('archived-filter-change', ($event.target as HTMLInputElement).checked)"
        />
        Show archived
      </label>
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

    <div v-else class="board-rows">
    <div v-for="row in displayRows" :key="row.key" class="board-row" data-testid="board-row">
      <div v-if="row.label !== null" class="board-row-label" data-testid="board-row-label">{{ row.label }}</div>
      <div class="board-scroll">
      <div
        v-for="(column, index) in row.columns"
        :key="column.key"
        class="board-column"
        :style="column.color ? { borderTopColor: column.color, borderTopWidth: '3px' } : {}"
        data-testid="board-column"
      >
        <div class="board-column-header">
          <template v-if="configurable">
            <button
              type="button"
              class="btn-column-reorder"
              data-testid="board-column-move-left"
              :disabled="index === 0"
              @click="moveColumn(column.key, -1)"
            >◀</button>
          </template>
          <button
            v-if="configurable"
            type="button"
            class="btn-column-collapse"
            data-testid="board-column-collapse-toggle"
            @click="$emit('toggle-column-collapse', column.key)"
          >{{ column.collapsed ? '▸' : '▾' }}</button>
          <span class="board-column-title">{{ column.label }}</span>
          <span
            class="count-badge"
            :class="{ 'count-badge-over-limit': column.wipLimit !== null && column.notes.length > column.wipLimit }"
            data-testid="board-column-wip-warning"
            v-if="column.wipLimit !== null && column.notes.length > column.wipLimit"
          >{{ column.notes.length }}/{{ column.wipLimit }}</span>
          <span v-else class="count-badge">{{ column.notes.length }}</span>
          <button
            v-if="configurable"
            type="button"
            class="btn-column-edit"
            data-testid="board-column-edit-button"
            @click="openColumnEdit(column)"
          >⚙</button>
          <button
            v-if="configurable"
            type="button"
            class="btn-column-reorder"
            data-testid="board-column-move-right"
            :disabled="index === row.columns.length - 1"
            @click="moveColumn(column.key, 1)"
          >▶</button>
        </div>
        <form
          v-if="editingColumnKey === column.key"
          class="board-column-edit-form"
          data-testid="board-column-edit-form"
          @submit.prevent="submitColumnEdit(column.key)"
        >
          <input
            v-model="columnEditLabel"
            type="text"
            placeholder="Column label"
            aria-label="Column label"
            data-testid="board-column-label-input"
            class="column-edit-input"
          />
          <select v-model="columnEditColor" aria-label="Column color" data-testid="board-column-color-select" class="column-edit-input">
            <option value="">No color</option>
            <option value="#ef4444">Red</option>
            <option value="#f59e0b">Amber</option>
            <option value="#22c55e">Green</option>
            <option value="#3b82f6">Blue</option>
            <option value="#a855f7">Purple</option>
          </select>
          <input
            v-model="columnEditWipLimit"
            type="number"
            min="0"
            placeholder="WIP limit"
            aria-label="WIP limit"
            data-testid="board-column-wip-input"
            class="column-edit-input"
          />
          <label class="auto-archive-label">
            <input
              v-model="columnEditAutoArchive"
              type="checkbox"
              data-testid="board-column-auto-archive-checkbox"
            />
            Auto-archive cards dropped here
          </label>
          <button type="submit" class="btn-add-card-confirm">Save</button>
          <button type="button" class="btn-add-card-cancel" @click="editingColumnKey = null">Cancel</button>
        </form>
        <div
          v-if="!column.collapsed"
          class="board-column-body"
          data-testid="board-column-body"
          :data-column-key="column.key"
          :data-row-key="row.key"
          :ref="(el) => setColumnBodyRef(row.key, column.key, el)"
        >
          <div v-for="note in column.notes" :key="note.id" class="board-card-wrapper">
          <button
            type="button"
            class="board-card"
            data-testid="board-card"
            :data-note-id="note.id"
            @click="$emit('select-note', note.id)"
          >
            <img
              v-if="coverImageUrl(note)"
              class="board-card-cover"
              data-testid="board-card-cover"
              :src="coverImageUrl(note)!"
              alt=""
            />
            <span class="board-card-title-row">
              <span class="board-card-title">{{ note.title || note.path }}</span>
              <span
                v-if="isArchived(note)"
                class="board-card-archived-badge"
                data-testid="board-card-archived-badge"
              >Archived</span>
            </span>
            <span class="board-card-path">{{ note.path }}</span>
            <span v-if="noteTagNames(note).length > 0" class="board-card-tags">
              <span
                v-for="tag in noteTagNames(note)"
                :key="tag"
                class="board-card-tag"
                data-testid="board-card-tag"
              >{{ tag }}</span>
            </span>
            <span class="board-card-meta">
              <span v-if="firstDateProperty(note)" class="board-card-due" data-testid="board-card-due">
                {{ firstDateProperty(note)!.value }}
              </span>
              <span
                v-if="note.checklist_total && note.checklist_total > 0"
                class="board-card-checklist"
                data-testid="board-card-checklist"
              >
                ☑ {{ note.checklist_done ?? 0 }}/{{ note.checklist_total }}
              </span>
              <span
                v-if="note.comments_count && note.comments_count > 0"
                class="board-card-comments"
                data-testid="board-card-comments"
              >
                💬 {{ note.comments_count }}
              </span>
            </span>
          </button>
          <button
            type="button"
            class="board-card-archive-toggle"
            data-testid="board-card-archive-toggle"
            :aria-label="isArchived(note) ? 'Unarchive' : 'Archive'"
            :title="isArchived(note) ? 'Unarchive' : 'Archive'"
            @click="$emit('toggle-archive', note.id)"
          >{{ isArchived(note) ? '📤' : '📦' }}</button>
          </div>
        </div>
        <form
          v-if="addCardRowKey === row.key && addCardColumnKey === column.key"
          class="board-add-card-form"
          data-testid="board-add-card-form"
          @submit.prevent="submitAddCard(row.key, column.key)"
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
          @click="openAddCard(row.key, column.key)"
        >
          + Add card
        </button>
      </div>
      </div>
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
import type { BoardColumnConfig, CollectionPage } from '../services/types'
import {
  allTagNames,
  applyColumnConfig,
  coverImageUrl,
  filterNotesByTag,
  firstDateProperty,
  groupNotesIntoColumns,
  groupNotesIntoSwimlaneRows,
  isArchived,
  noteTagNames,
  propertyColumns as computePropertyColumns,
  resolveCardMove,
  UNGROUPED_LABEL,
} from '../services/collectionUtils'

const props = defineProps<{
  page: CollectionPage
  loading?: boolean
  groupProperty?: string | null
  swimlaneProperty?: string | null
  configurable?: boolean
  columnConfig?: BoardColumnConfig[] | null
  showArchived?: boolean
}>()

const emit = defineEmits<{
  (e: 'select-note', noteId: number): void
  (e: 'page-change', page: number): void
  (e: 'group-change', property: string): void
  (e: 'swimlane-change', property: string): void
  (e: 'move-card', noteId: number, newValue: string): void
  (e: 'create-card', title: string, columnValue: string, rowValue: string | null): void
  (e: 'reorder-columns', keys: string[]): void
  (e: 'toggle-column-collapse', key: string): void
  (e: 'update-column', key: string, attrs: { label: string; color: string | null; wip_limit: number | null; auto_archive: boolean }): void
  (e: 'toggle-archive', noteId: number): void
  (e: 'archived-filter-change', showArchived: boolean): void
}>()

const groupPropertyInput = ref(props.groupProperty || '')
const swimlanePropertyInput = ref(props.swimlaneProperty || '')

watch(() => props.groupProperty, (value) => {
  groupPropertyInput.value = value || ''
})

watch(() => props.swimlaneProperty, (value) => {
  swimlanePropertyInput.value = value || ''
})

function applyGroupProperty() {
  emit('group-change', groupPropertyInput.value.trim())
}

function applySwimlaneProperty() {
  emit('swimlane-change', swimlanePropertyInput.value.trim())
}

const propertyColumns = computed(() => computePropertyColumns(props.page))

const tagFilter = ref('')
const allTags = computed(() => allTagNames(props.page))

const filteredNotes = computed(() => filterNotesByTag(props.page.data, tagFilter.value))

const columns = computed(() => {
  if (!props.groupProperty) return []
  const derived = groupNotesIntoColumns(filteredNotes.value, props.groupProperty)
  return applyColumnConfig(derived, props.columnConfig)
})

interface DisplayRow {
  key: string
  label: string | null
  columns: ReturnType<typeof applyColumnConfig>
}

const displayRows = computed<DisplayRow[]>(() => {
  if (!props.groupProperty) return []
  if (!props.swimlaneProperty) {
    return [{ key: '__all__', label: null, columns: columns.value }]
  }
  return groupNotesIntoSwimlaneRows(filteredNotes.value, props.swimlaneProperty).map(row => {
    const rowColumns = groupNotesIntoColumns(row.notes, props.groupProperty!)
    const byKey = new Map(rowColumns.map(c => [c.key, c.notes]))
    return {
      key: row.key,
      label: row.label,
      columns: columns.value.map(col => ({ ...col, notes: byKey.get(col.key) ?? [] })),
    }
  })
})

const editingColumnKey = ref<string | null>(null)
const columnEditLabel = ref('')
const columnEditColor = ref('')
const columnEditWipLimit = ref('')
const columnEditAutoArchive = ref(false)

function moveColumn(key: string, direction: -1 | 1) {
  const keys = columns.value.map(c => c.key)
  const idx = keys.indexOf(key)
  const swapIdx = idx + direction
  if (swapIdx < 0 || swapIdx >= keys.length) return
  ;[keys[idx], keys[swapIdx]] = [keys[swapIdx], keys[idx]]
  emit('reorder-columns', keys)
}

function openColumnEdit(column: { key: string; label: string; color: string | null; wipLimit: number | null; autoArchive: boolean }) {
  editingColumnKey.value = column.key
  columnEditLabel.value = column.label
  columnEditColor.value = column.color ?? ''
  columnEditWipLimit.value = column.wipLimit !== null ? String(column.wipLimit) : ''
  columnEditAutoArchive.value = column.autoArchive
}

function submitColumnEdit(key: string) {
  emit('update-column', key, {
    label: columnEditLabel.value.trim(),
    color: columnEditColor.value || null,
    wip_limit: columnEditWipLimit.value !== '' ? Number(columnEditWipLimit.value) : null,
    auto_archive: columnEditAutoArchive.value,
  })
  editingColumnKey.value = null
}

// Drag-and-drop between columns (#299): one Sortable instance per column
// body, keyed by "rowKey::columnKey" since swimlanes (#304) repeat the same
// column key across rows. Each row is its own Sortable group so a card can
// only move between columns within its own swimlane, never across rows.
// onEnd resolves the move via the pure resolveCardMove() rather than
// deciding inline, so the decision logic is unit-testable without a real
// Sortable instance.
const columnBodyRefs = new Map<string, HTMLElement>()

function setColumnBodyRef(rowKey: string, columnKey: string, el: unknown) {
  const compositeKey = `${rowKey}::${columnKey}`
  if (el instanceof HTMLElement) {
    columnBodyRefs.set(compositeKey, el)
  } else {
    columnBodyRefs.delete(compositeKey)
  }
}

let sortableInstances: Sortable[] = []

function destroySortables() {
  sortableInstances.forEach(s => s.destroy())
  sortableInstances = []
}

function initSortables() {
  destroySortables()
  for (const [compositeKey, el] of columnBodyRefs.entries()) {
    const rowKey = compositeKey.split('::')[0]
    sortableInstances.push(
      Sortable.create(el, {
        group: `board-column-row-${rowKey}`,
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

watch(displayRows, () => {
  nextTick(initSortables)
})

onBeforeUnmount(destroySortables)

// Card creation from the board (#300): one column (within one row) has its
// add-card form open at a time.
const addCardRowKey = ref<string | null>(null)
const addCardColumnKey = ref<string | null>(null)
const addCardTitle = ref('')

function openAddCard(rowKey: string, columnKey: string) {
  addCardRowKey.value = rowKey
  addCardColumnKey.value = columnKey
  addCardTitle.value = ''
}

function cancelAddCard() {
  addCardRowKey.value = null
  addCardColumnKey.value = null
  addCardTitle.value = ''
}

function submitAddCard(rowKey: string, columnKey: string) {
  const title = addCardTitle.value.trim()
  if (!title) return
  const rowValue = rowKey === '__all__' ? null : rowKey === UNGROUPED_LABEL ? '' : rowKey
  emit('create-card', title, columnKey === UNGROUPED_LABEL ? '' : columnKey, rowValue)
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

.board-rows {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
  flex: 1;
  overflow-y: auto;
}

.board-row-label {
  font-weight: 600;
  font-size: 0.8rem;
  color: var(--color-text-muted);
  margin-bottom: var(--space-1);
}

.board-scroll {
  display: flex;
  gap: var(--space-4);
  overflow-x: auto;
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

.btn-column-reorder,
.btn-column-collapse,
.btn-column-edit {
  background: none;
  border: none;
  color: var(--color-text-muted);
  cursor: pointer;
  font-size: 0.75rem;
  padding: 0 var(--space-1);
}

.btn-column-reorder:disabled {
  opacity: 0.3;
  cursor: not-allowed;
}

.count-badge-over-limit {
  background: var(--color-danger, #d33);
  color: var(--color-neutral-0);
}

.board-column-edit-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: var(--space-3);
  border-bottom: 1px solid var(--color-border);
}

.column-edit-input {
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-1) var(--space-2);
  color: var(--color-text);
  font-size: 0.8125rem;
  min-height: 32px;
}

.board-column-title {
  font-weight: 600;
  font-size: 0.85rem;
  flex: 1;
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

.board-card-title-row {
  display: flex;
  align-items: center;
  gap: var(--space-1);
}

.board-card-title {
  flex: 1;
  font-weight: 500;
  font-size: 0.8125rem;
  color: var(--color-text);
}

.board-card-archived-badge {
  background: var(--color-surface-emphasis);
  color: var(--color-text-muted);
  font-size: 0.65rem;
  padding: 0.05rem 0.4rem;
  border-radius: var(--radius-pill);
}

.board-card-wrapper {
  position: relative;
}

.board-card-archive-toggle {
  position: absolute;
  top: var(--space-1);
  right: var(--space-1);
  background: none;
  border: none;
  cursor: pointer;
  font-size: 0.8rem;
  line-height: 1;
  padding: 2px;
}

.show-archived-label,
.auto-archive-label {
  display: flex;
  align-items: center;
  gap: var(--space-1);
  font-size: 0.8125rem;
  color: var(--color-text-muted);
}

.board-card-path {
  color: var(--color-text-muted);
  font-family: var(--font-mono, monospace);
  font-size: 0.7rem;
}

.board-card-cover {
  width: 100%;
  height: 96px;
  object-fit: cover;
  border-radius: var(--radius-sm);
  margin-bottom: var(--space-1);
}

.board-card-meta {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  margin-top: var(--space-1);
  font-size: 0.7rem;
  color: var(--color-text-muted);
}

.board-card-tags {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-1);
  margin-top: var(--space-1);
}

.board-card-tag {
  background: var(--color-surface-emphasis);
  color: var(--color-text-muted);
  padding: 0.05rem 0.4rem;
  border-radius: var(--radius-pill);
  font-size: 0.7rem;
}

.tag-filter-select {
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-1) var(--space-2);
  color: var(--color-text);
  font-size: 0.8125rem;
  min-height: 32px;
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
