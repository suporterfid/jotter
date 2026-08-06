<template>
  <aside class="checklist-panel" :class="{ 'panel-collapsed': collapsed }" :aria-label="t('checklistPanel.title')">
    <PanelHeader :title="t('checklistPanel.title')" :count="items.length" :collapsed="collapsed" @toggle="toggle">
      <template #icon>
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none">
          <polyline points="9 11 12 14 22 4"></polyline>
          <path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"></path>
        </svg>
      </template>
    </PanelHeader>

    <div v-show="!collapsed" class="checklist-body">
      <p v-if="items.length > 0" class="checklist-progress">{{ doneCount }}/{{ items.length }}</p>

      <div v-if="items.length === 0" class="checklist-empty">
        <p>{{ t('checklistPanel.empty') }}</p>
      </div>

      <ul v-else class="checklist-list">
        <li v-for="item in items" :key="item.id" class="checklist-item" data-testid="checklist-item">
          <input
            type="checkbox"
            :checked="item.done"
            data-testid="checklist-item-checkbox"
            :aria-label="item.done ? t('checklistPanel.markNotDone', { text: item.text }) : t('checklistPanel.markDone', { text: item.text })"
            @click="$emit('toggle-item', item.id)"
          />
          <span class="checklist-item-text" :class="{ 'checklist-item-done': item.done }">{{ item.text }}</span>
          <button
            class="btn-delete-checklist-item"
            data-testid="checklist-item-delete-btn"
            :aria-label="t('checklistPanel.delete', { text: item.text })"
            :title="t('checklistPanel.deleteTooltip')"
            @click="$emit('delete-item', item.id)"
          >
            <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
              <polyline points="3 6 5 6 21 6"></polyline>
              <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
            </svg>
          </button>
        </li>
      </ul>

      <form class="checklist-item-form" data-testid="checklist-item-form" @submit.prevent="handleSubmit">
        <input
          v-model="newText"
          type="text"
          :placeholder="t('checklistPanel.addPlaceholder')"
          :aria-label="t('checklistPanel.newItem')"
          data-testid="checklist-item-input"
          class="checklist-item-input"
        />
        <button type="submit" class="btn-add-checklist-item" data-testid="checklist-item-submit-btn" :disabled="!newText.trim()">
          {{ t('checklistPanel.add') }}
        </button>
      </form>
    </div>
  </aside>
</template>

<script setup lang="ts">
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PanelHeader from './PanelHeader.vue'
import { useCollapsiblePanel } from '../composables/useCollapsiblePanel'
import type { NoteChecklistItem } from '../services/types'

const { t } = useI18n()

const props = defineProps<{
  items: NoteChecklistItem[]
}>()

const emit = defineEmits<{
  (e: 'add-item', text: string): void
  (e: 'toggle-item', itemId: number): void
  (e: 'delete-item', itemId: number): void
}>()

const { collapsed, toggle } = useCollapsiblePanel('checklist', false)

const newText = ref('')

const doneCount = computed(() => props.items.filter(i => i.done).length)

function handleSubmit() {
  const text = newText.value.trim()
  if (!text) return
  emit('add-item', text)
  newText.value = ''
}
</script>

<style scoped>
.checklist-panel {
  background: var(--color-surface);
  border-top: 1px solid var(--color-border);
  padding: var(--space-4);
  font-size: 0.875rem;
  color: var(--color-text);
}

.checklist-panel.panel-collapsed {
  padding-bottom: 0;
}

.checklist-progress {
  color: var(--color-text-muted);
  font-size: 0.8125rem;
  margin-bottom: var(--space-2);
}

.checklist-empty {
  color: var(--color-text-muted);
  font-style: italic;
  padding: var(--space-2) 0;
}

.checklist-list {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  margin-bottom: var(--space-3);
}

.checklist-item {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-sm);
}

.checklist-item-text {
  flex: 1;
  color: var(--color-text);
}

.checklist-item-done {
  text-decoration: line-through;
  color: var(--color-text-muted);
}

.btn-delete-checklist-item {
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
}

.btn-delete-checklist-item:hover {
  color: var(--color-status-danger);
  background: color-mix(in srgb, var(--color-status-danger) 12%, transparent);
}

.checklist-item-form {
  display: flex;
  gap: var(--space-2);
}

.checklist-item-input {
  flex: 1;
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-1) var(--space-2);
  color: var(--color-text);
  font-size: 0.8125rem;
}

.btn-add-checklist-item {
  background: var(--color-action);
  color: var(--color-neutral-0);
  border: none;
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.8125rem;
  min-height: 32px;
}

.btn-add-checklist-item:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
