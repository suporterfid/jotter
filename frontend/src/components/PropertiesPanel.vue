<template>
  <aside class="properties-panel" :class="{ 'panel-collapsed': collapsed }" :aria-label="t('propertiesPanel.title')">
    <PanelHeader :title="t('propertiesPanel.title')" :count="properties.length" :collapsed="collapsed" @toggle="toggle">
      <template #icon>
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none">
          <path d="M3 6h18M7 12h10M11 18h2"></path>
        </svg>
      </template>
    </PanelHeader>

    <div v-show="!collapsed" class="properties-body">
    <div v-if="properties.length === 0" class="properties-empty">
      <p>{{ t('propertiesPanel.empty') }}</p>
    </div>

    <ul v-else class="properties-list">
      <li v-for="prop in properties" :key="prop.name" class="property-item" data-testid="property-item">
        <div class="property-info">
          <span class="property-name">{{ prop.name }}</span>

          <template v-if="editingName === prop.name">
            <input
              v-if="prop.type === 'string' || prop.type === 'list'"
              v-model="editDraft"
              type="text"
              class="property-value-input"
              data-testid="property-value-edit-input"
              autofocus
              @keydown.enter="commitEdit(prop)"
              @keydown.escape="cancelEdit"
              @blur="commitEdit(prop)"
            />
            <input
              v-else-if="prop.type === 'numeric'"
              v-model="editDraft"
              type="number"
              class="property-value-input"
              data-testid="property-value-edit-input"
              autofocus
              @keydown.enter="commitEdit(prop)"
              @keydown.escape="cancelEdit"
              @blur="commitEdit(prop)"
            />
            <input
              v-else-if="prop.type === 'datetime'"
              v-model="editDraft"
              type="datetime-local"
              class="property-value-input"
              data-testid="property-value-edit-input"
              autofocus
              @keydown.enter="commitEdit(prop)"
              @keydown.escape="cancelEdit"
              @blur="commitEdit(prop)"
            />
            <label v-else-if="prop.type === 'boolean'" class="property-checkbox-label">
              <input
                v-model="editDraftBool"
                type="checkbox"
                data-testid="property-value-edit-input"
                autofocus
                @change="commitEdit(prop)"
                @keydown.escape="cancelEdit"
              />
              <span>{{ editDraftBool ? t('propertiesPanel.true') : t('propertiesPanel.false') }}</span>
            </label>
            <span v-else class="property-value">{{ formatValue(prop) }}</span>
          </template>
          <!-- json-typed properties (only reachable via direct frontmatter edits,
               never via this panel's Add form) aren't a safe in-place edit
               surface — arbitrary nested structure, no single-input match. -->
          <button
            v-else-if="prop.type !== 'json'"
            type="button"
            class="property-value-btn"
            data-testid="property-value-btn"
            :aria-label="t('propertiesPanel.edit', { name: prop.name })"
            @click="startEdit(prop)"
          >{{ formatValue(prop) }}</button>
          <span v-else class="property-value">{{ formatValue(prop) }}</span>
        </div>
        <button
          class="btn-delete-property"
          data-testid="property-delete-btn"
          :aria-label="t('propertiesPanel.delete', { name: prop.name })"
          :title="t('propertiesPanel.deleteTooltip')"
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
        :placeholder="t('propertiesPanel.namePlaceholder')"
        :aria-label="t('propertiesPanel.newPropertyName')"
        data-testid="property-name-input"
        class="property-form-input"
        list="known-property-names"
        required
        @focus="loadKnownProperties"
        @change="applyKnownPropertyType"
      />
      <datalist id="known-property-names">
        <option v-for="p in knownProperties" :key="p.name" :value="p.name" />
      </datalist>
      <select v-model="newType" :aria-label="t('propertiesPanel.typeLabel')" data-testid="property-type-select" class="property-form-select">
        <option value="string">{{ t('propertiesPanel.typeText') }}</option>
        <option value="numeric">{{ t('propertiesPanel.typeNumber') }}</option>
        <option value="boolean">{{ t('propertiesPanel.typeCheckbox') }}</option>
        <option value="datetime">{{ t('propertiesPanel.typeDatetime') }}</option>
        <option value="list">{{ t('propertiesPanel.typeList') }}</option>
      </select>

      <input
        v-if="newType === 'string' || newType === 'list'"
        v-model="newValueText"
        type="text"
        :placeholder="t('propertiesPanel.valuePlaceholder')"
        :aria-label="t('propertiesPanel.newPropertyValue')"
        data-testid="property-value-input"
        class="property-form-input"
      />
      <input
        v-else-if="newType === 'numeric'"
        v-model="newValueText"
        type="number"
        :placeholder="t('propertiesPanel.valuePlaceholder')"
        :aria-label="t('propertiesPanel.newPropertyValue')"
        data-testid="property-value-input"
        class="property-form-input"
      />
      <input
        v-else-if="newType === 'datetime'"
        v-model="newValueText"
        type="datetime-local"
        :aria-label="t('propertiesPanel.newPropertyValue')"
        data-testid="property-value-input"
        class="property-form-input"
      />
      <label v-else-if="newType === 'boolean'" class="property-checkbox-label">
        <input v-model="newValueBool" type="checkbox" :aria-label="t('propertiesPanel.newPropertyValue')" data-testid="property-value-input" />
        <span>{{ newValueBool ? t('propertiesPanel.true') : t('propertiesPanel.false') }}</span>
      </label>

      <button type="submit" class="btn-add-property" data-testid="property-add-btn">{{ t('propertiesPanel.add') }}</button>
    </form>
    </div>
  </aside>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import PanelHeader from './PanelHeader.vue'
import { useCollapsiblePanel } from '../composables/useCollapsiblePanel'
import { getWorkspaceProperties } from '../services/api'
import type { NoteProperty, NotePropertyType } from '../services/types'

const { t } = useI18n()

const props = defineProps<{
  properties: NoteProperty[]
  workspaceId?: number | null
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

// Autocomplete for the Add-property name field, sourced from every
// property name/type already in use anywhere in the workspace -- helps
// avoid typos and accidental near-duplicate property names.
const knownProperties = ref<Pick<NoteProperty, 'name' | 'type'>[]>([])

async function loadKnownProperties() {
  if (!props.workspaceId) return
  try {
    knownProperties.value = await getWorkspaceProperties(props.workspaceId)
  } catch (err) {
    console.error('Failed to load known workspace properties:', err)
    knownProperties.value = []
  }
}

watch(() => props.workspaceId, loadKnownProperties, { immediate: true })

function applyKnownPropertyType() {
  const match = knownProperties.value.find((p) => p.name === newName.value)
  if (match) newType.value = match.type
}

// In-place value editing (#258): the value itself is the editing surface,
// matching its existing type — no separate edit form, and type is never
// re-stated or changeable here (it's chosen once via the Add form above).
const editingName = ref<string | null>(null)
const editDraft = ref('')
const editDraftBool = ref(false)

function startEdit(prop: NoteProperty) {
  editingName.value = prop.name
  if (prop.type === 'boolean') {
    editDraftBool.value = Boolean(prop.value)
  } else if (prop.type === 'list') {
    editDraft.value = Array.isArray(prop.value) ? prop.value.join(', ') : ''
  } else {
    editDraft.value = prop.value === null || prop.value === undefined ? '' : String(prop.value)
  }
}

function cancelEdit() {
  editingName.value = null
}

function commitEdit(prop: NoteProperty) {
  // Unmounting the edit input (which happens at the end of this function,
  // via editingName.value = null) fires a native blur event in a real
  // browser, re-invoking this handler through @blur — same re-entrant-call
  // shape as NoteEditor.vue's confirmEditingIcon. Guard against it.
  if (editingName.value !== prop.name) return

  let value: unknown
  switch (prop.type) {
    case 'numeric':
      value = Number(editDraft.value)
      break
    case 'boolean':
      value = editDraftBool.value
      break
    case 'list':
      value = editDraft.value.split(',').map(s => s.trim()).filter(Boolean)
      break
    default:
      value = editDraft.value
  }

  emit('add-property', prop.name, value)
  editingName.value = null
}

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

.properties-panel.panel-collapsed {
  padding-bottom: 0;
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
  border-radius: var(--radius-sm);
  padding: var(--space-2) var(--space-3);
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.property-item:hover {
  background: var(--color-hover);
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

.property-value {
  color: var(--color-text-muted);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.property-value-btn {
  background: transparent;
  border: none;
  padding: 0;
  color: var(--color-text-muted);
  font-size: inherit;
  font-family: inherit;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  cursor: pointer;
  border-radius: var(--radius-sm);
  transition: color var(--duration-fast) var(--ease-standard);
}

.property-value-btn:hover {
  color: var(--color-text);
  text-decoration: underline;
}

.property-value-input {
  background: var(--color-canvas);
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-sm);
  padding: 0 var(--space-1);
  color: var(--color-text);
  font-size: inherit;
  min-width: 80px;
  min-height: 24px;
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
