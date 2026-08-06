<template>
  <div class="outline-panel">
    <div v-if="headings.length === 0" class="outline-empty">
      <p>{{ t('outlinePanel.noHeadings') }}</p>
    </div>
    <ul v-else class="outline-list">
      <li
        v-for="heading in headings"
        :key="`${heading.line}-${heading.id}`"
        class="outline-item"
        data-testid="outline-item"
        :style="{ paddingLeft: `${(heading.level - 1) * 12}px` }"
      >
        <button
          type="button"
          class="outline-item-btn"
          data-testid="outline-item-btn"
          @click="$emit('jump-to-heading', heading)"
        >{{ heading.text }}</button>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { HeadingEntry } from '../services/outline'

const { t } = useI18n()

defineProps<{
  headings: HeadingEntry[]
}>()

defineEmits<{
  (e: 'jump-to-heading', heading: HeadingEntry): void
}>()
</script>

<style scoped>
.outline-panel {
  padding: var(--space-3) var(--space-4);
}

.outline-empty {
  color: var(--color-text-muted);
  font-size: 0.875rem;
}

.outline-list {
  list-style: none;
  margin: 0;
  padding: 0;
}

.outline-item-btn {
  display: block;
  width: 100%;
  text-align: left;
  background: transparent;
  border: none;
  padding: var(--space-1) var(--space-2);
  border-radius: var(--radius-sm);
  color: var(--color-text);
  font-size: 0.875rem;
  cursor: pointer;
}

.outline-item-btn:hover {
  background: var(--color-hover);
}
</style>
