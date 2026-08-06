<template>
  <div class="locale-toggle" role="group" aria-label="Language">
    <button
      type="button"
      class="locale-option"
      :class="{ active: locale === 'pt-BR' }"
      data-testid="locale-toggle-pt"
      @click="setLocale('pt-BR')"
    >
      PT
    </button>
    <button
      type="button"
      class="locale-option"
      :class="{ active: locale === 'en' }"
      data-testid="locale-toggle-en"
      @click="setLocale('en')"
    >
      EN
    </button>
  </div>
</template>

<script setup lang="ts">
import { useLocale } from '../composables/useLocale'

const { locale, setLocale } = useLocale()
</script>

<style scoped>
.locale-toggle {
  display: flex;
  gap: 2px;
  background: var(--color-hover);
  border-radius: var(--radius-sm);
  padding: 2px;
}

.locale-option {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  cursor: pointer;
  padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-sm);
  font-size: 0.75rem;
  font-weight: 600;
  min-width: 32px;
  min-height: 32px;
  transition: color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard);
}

.locale-option.active {
  background: var(--color-surface);
  color: var(--color-text);
}

.locale-option:hover:not(.active) {
  color: var(--color-text);
}
</style>
