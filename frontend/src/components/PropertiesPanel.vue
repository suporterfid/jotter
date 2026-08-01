<template>
  <aside class="properties-panel" aria-label="Properties">
    <PanelHeader title="Properties" :count="properties.length" :collapsed="collapsed" @toggle="toggle">
      <template #icon>
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none">
          <path d="M3 6h18M7 12h10M11 18h2"></path>
        </svg>
      </template>
    </PanelHeader>

    <div v-show="!collapsed" class="properties-body">
    <div v-if="properties.length === 0" class="properties-empty">
      <p>No typed properties on this note yet.</p>
    </div>

    <ul v-else class="properties-list">
      <li v-for="prop in properties" :key="prop.name" class="property-item" data-testid="property-item">
        <div class="property-info">
          <span class="property-name">{{ prop.name }}</span>
          <span class="property-type">{{ prop.type }}</span>
          <span class="property-value">{{ formatValue(prop) }}</span>
        </div>
        <button
          class="btn-delete-property"
          data-testid="property-delete-btn"
          :aria-label="`Delete property ${prop.name}`"
          title="Delete property"
          @click="$emit('delete-property', prop.name)"
        >
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="3 6 5 6 21 6"></polyline>
            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
          </svg>
        </button>
      </li>
    </ul>

    <form class="property-form" @submit.prevent="handleSubmit">
      <input
        v-model="newName"
        type="text"
        placeholder="Property name"
        aria-label="New property name"
        data-testid="property-name-input"
        class="property-form-input"
        required
      />
      <select v-model="newType" aria-label="New property type" data-testid="property-type-select" class="property-form-select">
        <option value="string">Text</option>
        <option value="numeric">Number</option>
        <option value="boolean">Checkbox</option>
        <option value="datetime">Date/time</option>
        <option value="list">List (comma-separated)</option>
      </select>

      <input
        v-if="newType === 'string' || newType === 'list'"
        v-model="newValueText"
        type="text"
        placeholder="Value"
        aria-label="New property value"
        data-testid="property-value-input"
        class="property-form-input"
      />
      <input
        v-else-if="newType === 'numeric'"
        v-model="newValueText"
        type="number"
        placeholder="Value"
        aria-label="New property value"
        data-testid="property-value-input"
        class="property-form-input"
      />
      <input
        v-else-if="newType === 'datetime'"
        v-model="newValueText"
        type="datetime-local"
        aria-label="New property value"
        data-testid="property-value-input"
        class="property-form-input"
      />
      <label v-else-if="newType === 'boolean'" class="property-checkbox-label">
        <input v-model="newValueBool" type="checkbox" aria-label="New property value" data-testid="property-value-input" />
        <span>{{ newValueBool ? 'True' : 'False' }}</span>
      </label>

      <button type="submit" class="btn-add-property" data-testid="property-add-btn">Add</button>
    </form>
    </div>
  </aside>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import PanelHeader from './PanelHeader.vue'
import { useCollapsiblePanel } from '../composables/useCollapsiblePanel'
import type { NoteProperty, NotePropertyType } from '../services/types'

defineProps<{
  properties: NoteProperty[]
}>()

const emit = defineEmits<{
  (e: 'add-property', name: string, value: unknown): void
  (e: 'delete-property', name: string): void
}>()

const { collapsed, toggle } = useCollapsiblePanel('properties', true)

const newName = ref('')
const newType = ref<NotePropertyType>('string')
const newValueText = ref('')
const newValueBool = ref(false)

function formatValue(prop: NoteProperty): string {
  if (prop.value === null || prop.value === undefined) return '—'
  if (Array.isArray(prop.value)) return prop.value.join(', ')
  if (typeof prop.value === 'object') return JSON.stringify(prop.value)
  return String(prop.value)
}

function handleSubmit() {
  const name = newName.value.trim()
  if (!name) return

  let value: unknown
  switch (newType.value) {
    case 'numeric':
      value = Number(newValueText.value)
      break
    case 'boolean':
      value = newValueBool.value
      break
    case 'list':
      value = newValueText.value.split(',').map(s => s.trim()).filter(Boolean)
      break
    default:
      value = newValueText.value
  }

  emit('add-property', name, value)
  newName.value = ''
  newValueText.value = ''
  newValueBool.value = false
  newType.value = 'string'
}
</script>

<style scoped>
.properties-panel {
  background: var(--color-surface);
  border-top: 1px solid var(--color-border);
  padding: var(--space-4);
  font-size: 0.875rem;
  color: var(--color-text);
}

.properties-empty {
  color: var(--color-text-muted);
  font-style: italic;
  padding: var(--space-2) 0;
}

.properties-list {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}

.property-item {
  display: flex;
  align-items: center;
  justify-content: space-between;
  background: var(--color-surface-emphasis);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-2) var(--space-3);
}

.property-info {
  display: flex;
  align-items: baseline;
  gap: var(--space-2);
  overflow: hidden;
}

.property-name {
  font-weight: 600;
  color: var(--color-text);
}

.property-type {
  font-size: 0.7rem;
  color: var(--color-text-muted);
  background: var(--color-canvas);
  border-radius: var(--radius-sm);
  padding: 0 0.375rem;
}

.property-value {
  color: var(--color-text-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.btn-delete-property {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  padding: var(--space-1);
  border-radius: var(--radius-sm);
  cursor: pointer;
  min-width: 28px;
  min-height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard);
}

.btn-delete-property:hover {
  color: var(--color-status-danger);
  background: color-mix(in srgb, var(--color-status-danger) 12%, transparent);
}

.property-form {
  display: flex;
  flex-wrap: wrap;
  gap: var(--space-2);
  align-items: center;
}

.property-form-input,
.property-form-select {
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-1) var(--space-2);
  color: var(--color-text);
  font-size: 0.8125rem;
  min-height: 32px;
}

.property-checkbox-label {
  display: flex;
  align-items: center;
  gap: var(--space-1);
  font-size: 0.8125rem;
}

.btn-add-property {
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

.btn-add-property:hover {
  background: var(--color-action-hover);
}
</style>
