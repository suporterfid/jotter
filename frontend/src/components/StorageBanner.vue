<template>
  <div
    v-if="message"
    class="storage-banner"
    :class="{ 'storage-banner--full': storage?.exceeded }"
    role="status"
    data-testid="storage-banner"
  >
    {{ message }}
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { WorkspaceStorage } from '../services/types'
import { formatBytes } from '../services/formatBytes'

const { t } = useI18n()

const props = defineProps<{ storage?: WorkspaceStorage | null }>()

// Workspace storage quota (#390): warn from JOTTER_WORKSPACE_QUOTA_WARN_PERCENT, say so when full.
const message = computed<string | null>(() => {
  const storage = props.storage
  if (!storage || storage.limit_bytes === null || !storage.warning) return null
  const values = { used: formatBytes(storage.used_bytes), limit: formatBytes(storage.limit_bytes), percent: Math.round(storage.percent ?? 0) }
  return storage.exceeded ? t('storage.full', values) : t('storage.nearlyFull', values)
})
</script>

<style scoped>
.storage-banner {
  padding: var(--space-1) var(--space-4);
  font-size: 0.8125rem;
  text-align: center;
  color: var(--color-warning-fg);
  background: var(--color-warning-bg);
  border-bottom: 1px solid var(--color-warning-border);
}

.storage-banner--full {
  color: var(--color-text);
  background: var(--color-bg-surface);
  border-bottom-color: var(--color-status-danger);
}
</style>
