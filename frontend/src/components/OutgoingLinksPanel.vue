<template>
  <aside class="outgoing-links-panel" :class="{ 'panel-collapsed': collapsed }" :aria-label="t('outgoingLinksPanel.title')">
    <PanelHeader :title="t('outgoingLinksPanel.title')" :count="links.length" :collapsed="collapsed" @toggle="toggle">
      <template #icon>
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none">
          <line x1="7" y1="17" x2="17" y2="7"></line>
          <polyline points="7 7 17 7 17 17"></polyline>
        </svg>
      </template>
    </PanelHeader>

    <div v-show="!collapsed" class="outgoing-links-body">
    <div v-if="links.length === 0" class="outgoing-links-empty">
      <p>{{ t('outgoingLinksPanel.empty') }}</p>
    </div>

    <ul v-else class="outgoing-links-list">
      <li
        v-for="(link, index) in links"
        :key="`${link.target_ref}-${index}`"
        class="outgoing-link-item"
        :class="{ 'is-unresolved': !link.resolved }"
        @click="link.resolved && link.id !== null && $emit('select-note', link.id)"
      >
        <div class="outgoing-link-title">{{ link.title || link.target_ref }}</div>
        <div class="outgoing-link-meta">
          <span v-if="link.target_block" class="block-badge">^{{ link.target_block }}</span>
          <span v-if="!link.resolved" class="unresolved-badge">{{ t('outgoingLinksPanel.unresolved') }}</span>
          <span v-else class="outgoing-link-path">{{ link.path }}</span>
        </div>
      </li>
    </ul>
    </div>
  </aside>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import PanelHeader from './PanelHeader.vue'
import { useCollapsiblePanel } from '../composables/useCollapsiblePanel'
import type { OutgoingLink } from '../services/types'

const { t } = useI18n()

defineProps<{
  links: OutgoingLink[]
}>()

defineEmits<{
  (e: 'select-note', noteId: number): void
}>()

const { collapsed, toggle } = useCollapsiblePanel('outgoing-links', false)
</script>

<style scoped>
.outgoing-links-panel {
  background: var(--color-surface);
  border-top: 1px solid var(--color-border);
  padding: var(--space-4);
  font-size: 0.875rem;
  color: var(--color-text);
}

.outgoing-links-panel.panel-collapsed {
  padding-bottom: 0;
}

.outgoing-links-empty {
  color: var(--color-text-muted);
  font-style: italic;
  padding: var(--space-2) 0;
}

.outgoing-links-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.outgoing-link-item {
  border-radius: var(--radius-sm);
  padding: var(--space-2) var(--space-3);
  cursor: pointer;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.outgoing-link-item.is-unresolved {
  cursor: default;
  opacity: 0.75;
}

.outgoing-link-item:not(.is-unresolved):hover {
  background: var(--color-hover);
}

.outgoing-link-title {
  font-weight: 500;
  color: var(--color-text);
}

.outgoing-link-meta {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  font-size: 0.75rem;
  color: var(--color-text-muted);
  margin-top: 0.125rem;
}

.block-badge {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: 0 0.375rem;
  font-family: monospace;
}

.unresolved-badge {
  color: var(--color-status-warning);
}
</style>
