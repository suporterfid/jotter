<template>
  <aside class="activity-panel" :class="{ 'panel-collapsed': collapsed }" aria-label="Activity">
    <PanelHeader title="Activity" :count="entries.length" :collapsed="collapsed" @toggle="toggle">
      <template #icon>
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none">
          <circle cx="12" cy="12" r="10"></circle>
          <polyline points="12 6 12 12 16 14"></polyline>
        </svg>
      </template>
    </PanelHeader>

    <div v-show="!collapsed" class="activity-body">
      <div v-if="entries.length === 0" class="activity-empty">
        <p>No activity yet.</p>
      </div>

      <ul v-else class="activity-list">
        <li v-for="entry in entries" :key="entry.id" class="activity-item" data-testid="activity-item">
          <span class="activity-text">{{ describe(entry) }}</span>
          <span class="activity-date">{{ formatDate(entry.created_at) }}</span>
        </li>
      </ul>
    </div>
  </aside>
</template>

<script setup lang="ts">
import PanelHeader from './PanelHeader.vue'
import { useCollapsiblePanel } from '../composables/useCollapsiblePanel'
import type { NoteActivityEntry } from '../services/types'

defineProps<{
  entries: NoteActivityEntry[]
}>()

const { collapsed, toggle } = useCollapsiblePanel('activity', false)

function describe(entry: NoteActivityEntry): string {
  const meta = (entry.metadata ?? {}) as Record<string, unknown>
  switch (meta.action) {
    case 'property_set':
      return `Set ${meta.name} to ${meta.value}`
    case 'checklist_item_created':
      return `Added checklist item "${meta.text}"`
    case 'checklist_item_checked':
      return `Checked off "${meta.text}"`
    case 'checklist_item_unchecked':
      return `Unchecked "${meta.text}"`
    default:
      return entry.event
  }
}

function formatDate(iso: string | null): string {
  if (!iso) return ''
  try {
    return new Date(iso).toLocaleString(undefined, {
      year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    })
  } catch {
    return iso
  }
}
</script>

<style scoped>
.activity-panel {
  background: var(--color-surface);
  border-top: 1px solid var(--color-border);
  padding: var(--space-4);
  font-size: 0.875rem;
  color: var(--color-text);
}

.activity-panel.panel-collapsed {
  padding-bottom: 0;
}

.activity-empty {
  color: var(--color-text-muted);
  font-style: italic;
  padding: var(--space-2) 0;
}

.activity-list {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.activity-item {
  display: flex;
  flex-direction: column;
  gap: 0.2rem;
  padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-sm);
}

.activity-text {
  color: var(--color-text);
}

.activity-date {
  font-size: 0.7rem;
  color: var(--color-text-muted);
}
</style>
