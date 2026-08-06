<template>
  <div class="tenant-switcher">
    <select
      class="tenant-switcher-select"
      data-testid="tenant-switcher-select"
      :value="activeTenantId ?? undefined"
      :aria-label="t('tenantSwitcher.switchTenant')"
      @change="handleChange"
    >
      <option v-for="tenant in tenants" :key="tenant.id" :value="tenant.id">{{ tenant.name }}</option>
    </select>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { Tenant } from '../services/types'

const { t } = useI18n()

defineProps<{
  tenants: Tenant[]
  activeTenantId: number | null
}>()

const emit = defineEmits<{
  (e: 'switch', tenantId: number): void
}>()

function handleChange(event: Event) {
  const value = Number((event.target as HTMLSelectElement).value)
  emit('switch', value)
}
</script>

<style scoped>
.tenant-switcher {
  padding: 0 var(--space-2);
}

.tenant-switcher-select {
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

.tenant-switcher-select:hover {
  background: var(--color-hover);
}
</style>
