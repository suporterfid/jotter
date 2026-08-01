<template>
  <div class="workspace-switcher">
    <select
      class="workspace-switcher-select"
      data-testid="workspace-switcher-select"
      :value="activeWorkspaceId ?? undefined"
      aria-label="Switch workspace"
      @change="handleChange"
    >
      <option v-for="ws in workspaces" :key="ws.id" :value="ws.id">{{ ws.name }}</option>
    </select>
  </div>
</template>

<script setup lang="ts">
import type { Workspace } from '../services/types'

defineProps<{
  workspaces: Workspace[]
  activeWorkspaceId: number | null
}>()

const emit = defineEmits<{
  (e: 'switch', workspaceId: number): void
}>()

function handleChange(event: Event) {
  const value = Number((event.target as HTMLSelectElement).value)
  emit('switch', value)
}
</script>

<style scoped>
.workspace-switcher {
  padding: 0 var(--space-2);
}

.workspace-switcher-select {
  width: 100%;
  background: var(--color-surface);
  color: var(--color-text);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--space-2) var(--space-3);
  font-size: 0.8125rem;
  font-weight: 500;
  cursor: pointer;
  transition: border-color var(--duration-standard) var(--ease-standard),
              background-color var(--duration-standard) var(--ease-standard);
}

.workspace-switcher-select:hover {
  background: var(--color-hover);
}
</style>
