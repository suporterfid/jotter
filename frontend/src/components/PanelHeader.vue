<template>
  <div class="panel-header" :class="{ 'panel-header-collapsed': collapsed }">
    <div class="panel-header-title">
      <slot name="icon" />
      <span>{{ title }}</span>
    </div>
    <div class="panel-header-actions">
      <span v-if="count !== undefined" class="panel-header-count">{{ count }}</span>
      <button
        type="button"
        class="panel-collapse-toggle"
        data-testid="panel-collapse-toggle"
        :aria-label="collapsed ? `Expand ${title}` : `Collapse ${title}`"
        :aria-expanded="!collapsed"
        @click="$emit('toggle')"
      >
        <svg
          class="chevron"
          :class="{ collapsed: collapsed }"
          viewBox="0 0 24 24"
          width="14"
          height="14"
          fill="none"
          stroke="currentColor"
          stroke-width="2.5"
        >
          <polyline points="6 9 12 15 18 9"></polyline>
        </svg>
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  title: string
  count?: number
  collapsed: boolean
}>()

defineEmits<{
  (e: 'toggle'): void
}>()
</script>

<style scoped>
.panel-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: var(--space-3);
  font-weight: 600;
  color: var(--color-text-muted);
  font-size: 0.75rem;
}

.panel-header-collapsed {
  margin-bottom: 0;
}

.panel-header-title {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.panel-header-count {
  background: var(--color-surface-emphasis);
  color: var(--color-action);
  padding: 0.125rem 0.5rem;
  border-radius: var(--radius-pill);
  font-size: 0.75rem;
}

.panel-header-actions {
  display: flex;
  align-items: center;
  gap: var(--space-2);
}

.panel-collapse-toggle {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  padding: var(--space-1);
  border-radius: var(--radius-sm);
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard);
}

.panel-collapse-toggle:hover {
  background: var(--color-hover);
  color: var(--color-text);
}

.chevron {
  transition: transform var(--duration-fast) var(--ease-standard);
}

.chevron.collapsed {
  transform: rotate(-90deg);
}
</style>
