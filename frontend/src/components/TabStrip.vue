<template>
  <div v-if="tabs.length > 0" class="tab-strip tab-strip-vertical" role="tablist">
    <div
      v-for="tab in tabs"
      :key="tab.id"
      class="tab-strip-item"
      :class="{ active: tab.id === activeId }"
      role="tab"
      :aria-selected="tab.id === activeId"
      data-testid="tab-strip-item"
      @click="$emit('select-tab', tab.id)"
    >
      <svg class="tab-strip-icon" viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
        <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
        <path d="M14 2v6h6"></path>
      </svg>
      <span class="tab-strip-title">{{ tab.title }}</span>
      <button
        type="button"
        class="tab-strip-close-btn"
        data-testid="tab-strip-close-btn"
        :aria-label="t('tabStrip.close', { title: tab.title })"
        @click.stop="$emit('close-tab', tab.id)"
      >&times;</button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'

const { t } = useI18n()

defineProps<{
  tabs: { id: number; title: string }[]
  activeId: number | null
}>()

defineEmits<{
  (e: 'select-tab', noteId: number): void
  (e: 'close-tab', noteId: number): void
}>()
</script>

<style scoped>
.tab-strip-vertical {
  display: flex;
  flex-direction: column;
  width: 200px;
  flex: 0 0 200px;
  padding: var(--space-2);
  gap: 2px;
  background: var(--color-surface);
  border-right: 1px solid var(--color-border);
  overflow-y: auto;
  height: 100%;
}

.tab-strip-item {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-sm);
  cursor: pointer;
  color: var(--color-text-muted);
  font-size: 0.8125rem;
}

.tab-strip-item:hover {
  background: var(--color-hover);
}

.tab-strip-item.active {
  background: var(--color-canvas);
  color: var(--color-text);
  font-weight: 500;
}

.tab-strip-icon {
  flex-shrink: 0;
}

.tab-strip-title {
  flex: 1;
  min-width: 0;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tab-strip-close-btn {
  flex-shrink: 0;
  background: transparent;
  border: none;
  color: inherit;
  padding: 0 var(--space-1);
  border-radius: var(--radius-sm);
  cursor: pointer;
  line-height: 1;
  font-size: 1rem;
}

.tab-strip-close-btn:hover {
  background: var(--color-surface-emphasis);
}
</style>
