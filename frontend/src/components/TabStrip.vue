<template>
  <div v-if="tabs.length > 0" class="tab-strip" role="tablist">
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
      <span class="tab-strip-title">{{ tab.title }}</span>
      <button
        type="button"
        class="tab-strip-close-btn"
        data-testid="tab-strip-close-btn"
        :aria-label="`Close ${tab.title}`"
        @click.stop="$emit('close-tab', tab.id)"
      >&times;</button>
    </div>
  </div>
</template>

<script setup lang="ts">
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
.tab-strip {
  display: flex;
  align-items: center;
  gap: 2px;
  padding: 0 var(--space-2);
  background: var(--color-surface);
  border-bottom: 1px solid var(--color-border);
  overflow-x: auto;
}

.tab-strip-item {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  padding: var(--space-2) var(--space-3);
  border-radius: var(--radius-sm) var(--radius-sm) 0 0;
  cursor: pointer;
  color: var(--color-text-muted);
  font-size: 0.8125rem;
  white-space: nowrap;
  max-width: 200px;
}

.tab-strip-item:hover {
  background: var(--color-hover);
}

.tab-strip-item.active {
  background: var(--color-canvas);
  color: var(--color-text);
  font-weight: 500;
}

.tab-strip-title {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.tab-strip-close-btn {
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
