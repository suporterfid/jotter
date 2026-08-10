<template>
  <label class="theme-toggle">
    <span class="theme-toggle__label">{{ t('theme.label') }}</span>
    <select
    v-model="preference"
    class="theme-toggle__select"
    :aria-label="t('theme.label')"
  >
    <option value="system">{{ t('theme.system') }}</option>
    <option value="light">{{ t('theme.light') }}</option>
    <option value="dark">{{ t('theme.dark') }}</option>
  </select>
  </label>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import { useTheme } from '../composables/useTheme'

const { t } = useI18n()
const { preference } = useTheme()
</script>

<style scoped>
.theme-toggle {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  gap: var(--space-2);
  padding: var(--space-1);
  border-radius: var(--radius-sm);
  display: flex;
  align-items: center;
  justify-content: center;
  min-width: 44px;
  min-height: 44px;
}

.theme-toggle__label {
  font-size: var(--text-caption);
}

.theme-toggle__select {
  min-block-size: 32px;
  max-inline-size: 9rem;
  border: 1px solid var(--color-border-strong);
  border-radius: var(--radius-sm);
  background: var(--color-bg-elevated);
  color: var(--color-text-primary);
  padding-inline: var(--space-2);
}
</style>
