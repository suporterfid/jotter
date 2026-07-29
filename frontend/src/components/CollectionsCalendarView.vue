<template>
  <div class="collections-calendar-view">
    <div class="panel-header">
      <h2>Calendar View</h2>
      <span class="count-badge">{{ page.total }}</span>
    </div>
    <p class="panel-hint">Notes grouped by a datetime property, in date order.</p>

    <form class="group-bar" @submit.prevent="applyDateProperty">
      <input
        v-model="datePropertyInput"
        type="text"
        placeholder="Datetime property (e.g. due_date)"
        aria-label="Group by datetime property"
        data-testid="calendar-date-property"
        class="group-input"
        :list="'calendar-property-options'"
      />
      <datalist id="calendar-property-options">
        <option v-for="col in propertyColumns" :key="col" :value="col" />
      </datalist>
      <button type="submit" class="btn-group-apply" data-testid="calendar-date-apply">Group</button>
    </form>

    <div v-if="loading" class="panel-empty">
      <p>Loading notes…</p>
    </div>

    <div v-else-if="page.data.length === 0" class="panel-empty">
      <p>No notes match this view.</p>
    </div>

    <div v-else-if="!dateProperty" class="panel-empty">
      <p>Choose a datetime property above to lay notes out by date.</p>
    </div>

    <div v-else class="calendar-scroll">
      <div v-for="day in days" :key="day.key" class="calendar-day" data-testid="calendar-day">
        <div class="calendar-day-header">
          <span class="calendar-day-title">{{ day.label }}</span>
          <span class="count-badge">{{ day.notes.length }}</span>
        </div>
        <div class="calendar-day-body">
          <button
            v-for="note in day.notes"
            :key="note.id"
            type="button"
            class="calendar-card"
            data-testid="calendar-card"
            @click="$emit('select-note', note.id)"
          >
            <span class="calendar-card-title">{{ note.title || note.path }}</span>
            <span class="calendar-card-path">{{ note.path }}</span>
          </button>
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
import { computed, ref, watch } from 'vue'
import type { CollectionNote, CollectionPage } from '../services/types'
import { findProperty, rawValue, propertyColumns as computePropertyColumns } from '../services/collectionUtils'

const props = defineProps<{
  page: CollectionPage
  loading?: boolean
  dateProperty?: string | null
}>()

const emit = defineEmits<{
  (e: 'select-note', noteId: number): void
  (e: 'page-change', page: number): void
  (e: 'date-property-change', property: string): void
}>()

const datePropertyInput = ref(props.dateProperty || '')

watch(() => props.dateProperty, (value) => {
  datePropertyInput.value = value || ''
})

function applyDateProperty() {
  emit('date-property-change', datePropertyInput.value.trim())
}

const propertyColumns = computed(() => computePropertyColumns(props.page))

const NO_DATE_LABEL = 'No date'

function dayKeyFor(note: CollectionNote, propertyName: string): string {
  const prop = findProperty(note, propertyName)
  if (!prop) return NO_DATE_LABEL
  const value = rawValue(prop)
  if (typeof value !== 'string') return NO_DATE_LABEL
  const parsed = new Date(value)
  if (Number.isNaN(parsed.getTime())) return NO_DATE_LABEL
  return parsed.toISOString().slice(0, 10)
}

const days = computed(() => {
  if (!props.dateProperty) return []
  const groups = new Map<string, CollectionNote[]>()
  for (const note of props.page.data) {
    const key = dayKeyFor(note, props.dateProperty)
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key)!.push(note)
  }
  const sortedKeys = Array.from(groups.keys()).sort((a, b) => {
    if (a === NO_DATE_LABEL) return 1
    if (b === NO_DATE_LABEL) return -1
    return a.localeCompare(b)
  })
  return sortedKeys.map(key => ({
    key,
    label: key === NO_DATE_LABEL ? NO_DATE_LABEL : key,
    notes: groups.get(key)!
  }))
})
</script>

<style scoped>
.collections-calendar-view {
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
  font-family: var(--font-heading);
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

.calendar-scroll {
  display: flex;
  flex-direction: column;
  gap: var(--space-4);
}

.calendar-day {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
}

.calendar-day-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-2);
  padding: var(--space-3);
  border-bottom: 1px solid var(--color-border);
}

.calendar-day-title {
  font-weight: 600;
  font-size: 0.85rem;
  color: var(--color-text);
  font-family: var(--font-mono, monospace);
}

.calendar-day-body {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  padding: var(--space-3);
}

.calendar-card {
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

.calendar-card:hover {
  background: var(--color-surface-emphasis);
}

.calendar-card-title {
  font-weight: 500;
  font-size: 0.8125rem;
  color: var(--color-text);
}

.calendar-card-path {
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
