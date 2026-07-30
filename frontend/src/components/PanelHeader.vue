<template>
  <div class="panel-header">
    <div class="panel-header-title">
      <slot name="icon" />
      <span>{{ title }}</span>
    </div>
    <span v-if="count !== undefined" class="panel-header-count">{{ count }}</span>
  </div>
</template>

<script setup lang="ts">
defineProps<{
  title: string
  count?: number
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
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-size: 0.75rem;
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
</style>
