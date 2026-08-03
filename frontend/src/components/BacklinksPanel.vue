<template>
  <aside class="backlinks-panel" :class="{ 'panel-collapsed': collapsed }" aria-label="Backlinks">
    <PanelHeader title="Backlinks" :count="backlinks.length" :collapsed="collapsed" @toggle="toggle">
      <template #icon>
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none">
          <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
          <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
        </svg>
      </template>
    </PanelHeader>

    <div v-show="!collapsed" class="backlinks-body">
    <div v-if="backlinks.length === 0" class="backlinks-empty">
      <p>No notes link to this document yet.</p>
    </div>

    <ul v-else class="backlinks-list">
      <li
        v-for="link in backlinks"
        :key="link.id"
        class="backlink-item"
        @click="$emit('select-note', link.id)"
      >
        <div class="backlink-title">{{ link.title || link.path }}</div>
        <div class="backlink-path">{{ link.path }}</div>
      </li>
    </ul>
    </div>
  </aside>
</template>

<script setup lang="ts">
import PanelHeader from './PanelHeader.vue'
import { useCollapsiblePanel } from '../composables/useCollapsiblePanel'
import type { Backlink } from '../services/types'

defineProps<{
  backlinks: Backlink[]
}>()

defineEmits<{
  (e: 'select-note', noteId: number): void
}>()

const { collapsed, toggle } = useCollapsiblePanel('backlinks', false)
</script>

<style scoped>
.backlinks-panel {
  background: var(--color-surface);
  border-top: 1px solid var(--color-border);
  padding: var(--space-4);
  font-size: 0.875rem;
  color: var(--color-text);
}

.backlinks-panel.panel-collapsed {
  padding-bottom: 0;
}

.backlinks-empty {
  color: var(--color-text-muted);
  font-style: italic;
  padding: var(--space-2) 0;
}

.backlinks-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.backlink-item {
  background: var(--color-surface-emphasis);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-2) var(--space-3);
  cursor: pointer;
  transition: background-color var(--duration-fast) var(--ease-standard),
              border-color var(--duration-fast) var(--ease-standard);
}

.backlink-item:hover {
  border-color: var(--color-action);
  background: var(--color-hover);
}

.backlink-title {
  font-weight: 500;
  color: var(--color-text);
}

.backlink-path {
  font-size: 0.75rem;
  color: var(--color-text-muted);
  margin-top: 0.125rem;
}
</style>
