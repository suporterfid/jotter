<template>
  <div class="collections-table-view">
    <div class="panel-header">
      <h2>Table View</h2>
      <span class="count-badge">{{ page.total }}</span>
    </div>
    <p class="panel-hint">All notes in this workspace with their typed properties as columns.</p>

    <form class="filter-bar" @submit.prevent="$emit('filter-change', filterProperty.trim(), filterValue.trim())">
      <input
        v-model="filterProperty"
        type="text"
        placeholder="Property name"
        aria-label="Filter by property name"
        data-testid="collection-filter-property"
        class="filter-input"
      />
      <input
        v-model="filterValue"
        type="text"
        placeholder="Value (optional)"
        aria-label="Filter by property value"
        data-testid="collection-filter-value"
        class="filter-input"
      />
      <button type="submit" class="btn-filter-apply" data-testid="collection-filter-apply">Filter</button>
      <button
        v-if="filterProperty || filterValue"
        type="button"
        class="btn-filter-clear"
        data-testid="collection-filter-clear"
        @click="clearFilter"
      >
        Clear
      </button>
    </form>

    <div v-if="loading" class="panel-empty">
      <p>Loading notes…</p>
    </div>

    <div v-else-if="page.data.length === 0" class="panel-empty">
      <p>No notes match this view.</p>
    </div>

    <div v-else class="table-scroll">
      <table class="collections-table">
        <thead>
          <tr>
            <th scope="col">
              <button type="button" class="sort-btn" @click="$emit('sort', 'title')">
                Title <span v-if="sortKey === 'title'">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
              </button>
            </th>
            <th scope="col">Path</th>
            <th v-for="col in propertyColumns" :key="col" scope="col">
              <button type="button" class="sort-btn" @click="$emit('sort', col)">
                {{ col }} <span v-if="sortKey === col">{{ sortDir === 'asc' ? '▲' : '▼' }}</span>
              </button>
            </th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="note in sortedNotes"
            :key="note.id"
            class="note-row"
            data-testid="collection-row"
            @click="$emit('select-note', note.id)"
          >
            <td class="note-title-cell">{{ note.title || note.path }}</td>
            <td class="note-path-cell">{{ note.path }}</td>
            <td v-for="col in propertyColumns" :key="col">{{ formatPropertyValue(note, col) }}</td>
          </tr>
        </tbody>
      </table>
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
import { computed, ref } from 'vue'
import type { CollectionPage, CollectionNote } from '../services/types'
import { rawValue, formatPropertyValue, propertyColumns as computePropertyColumns } from '../services/collectionUtils'

const props = defineProps<{
  page: CollectionPage
  loading?: boolean
  sortKey?: string | null
  sortDir?: 'asc' | 'desc'
}>()

const emit = defineEmits<{
  (e: 'select-note', noteId: number): void
  (e: 'page-change', page: number): void
  (e: 'filter-change', property: string, value: string): void
  (e: 'sort', key: string): void
}>()

const filterProperty = ref('')
const filterValue = ref('')

function clearFilter() {
  filterProperty.value = ''
  filterValue.value = ''
  emit('filter-change', '', '')
}

const propertyColumns = computed(() => computePropertyColumns(props.page))

function sortableValue(note: CollectionNote, key: string): string | number | null {
  if (key === 'title') return (note.title || note.path).toLowerCase()
  const prop = note.properties.find(p => p.name === key)
  if (!prop) return null
  const value = rawValue(prop)
  if (typeof value === 'number' || typeof value === 'string') return value
  if (typeof value === 'boolean') return value ? 1 : 0
  return null
}

const sortedNotes = computed(() => {
  if (!props.sortKey) return props.page.data
  const dir = props.sortDir === 'desc' ? -1 : 1
  return [...props.page.data].sort((a, b) => {
    const va = sortableValue(a, props.sortKey!)
    const vb = sortableValue(b, props.sortKey!)
    // Notes missing this property always sort to the end, regardless of
    // direction — treating a missing value as the numeric minimum (the
    // previous behavior) meant it jumped to the front on ascending sort.
    if (va === null && vb === null) return 0
    if (va === null) return 1
    if (vb === null) return -1
    if (va < vb) return -1 * dir
    if (va > vb) return 1 * dir
    return 0
  })
})
</script>

<style scoped>
.collections-table-view {
  flex: 1;
  overflow-y: auto;
  padding: var(--space-6);
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

.filter-bar {
  display: flex;
  gap: var(--space-2);
  align-items: center;
  margin-bottom: var(--space-4);
}

.filter-input {
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-1) var(--space-2);
  color: var(--color-text);
  font-size: 0.8125rem;
  min-height: 32px;
}

.btn-filter-apply,
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

.btn-filter-apply:hover,
.btn-page:hover:not(:disabled) {
  background: var(--color-action-hover);
}

.btn-page:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}

.btn-filter-clear {
  background: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-text-muted);
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.8125rem;
  min-height: 32px;
}

.panel-empty {
  color: var(--color-text-muted);
  padding: var(--space-8) 0;
}

.table-scroll {
  overflow-x: auto;
}

.collections-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8125rem;
}

.collections-table th {
  text-align: left;
  padding: 0;
  color: var(--color-text-muted);
  font-weight: 600;
  text-transform: uppercase;
  font-size: 0.7rem;
  letter-spacing: 0.05em;
  border-bottom: 1px solid var(--color-border);
  white-space: nowrap;
}

.sort-btn {
  background: transparent;
  border: none;
  color: inherit;
  font: inherit;
  text-transform: inherit;
  letter-spacing: inherit;
  cursor: pointer;
  padding: var(--space-2) var(--space-3);
  min-height: 36px;
}

.note-row td {
  padding: var(--space-2) var(--space-3);
  border-bottom: 1px solid var(--color-border);
  color: var(--color-text);
  white-space: nowrap;
}

.note-row {
  cursor: pointer;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.note-row:hover {
  background: var(--color-surface-emphasis);
}

.note-title-cell {
  font-weight: 500;
}

.note-path-cell {
  color: var(--color-text-muted);
  font-family: var(--font-mono, monospace);
  font-size: 0.75rem;
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
