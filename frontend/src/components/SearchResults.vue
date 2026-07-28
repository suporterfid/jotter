<template>
  <div class="search-results-panel">
    <div class="search-header">
      <span>Search Results for "{{ query }}"</span>
      <span class="count">{{ results.length }} matches</span>
    </div>

    <div v-if="results.length === 0" class="no-results">
      No notes found matching your search.
    </div>

    <div v-else class="results-list">
      <div 
        v-for="item in results" 
        :key="item.note_id" 
        class="result-card"
        @click="$emit('select-note', item.note_id)"
      >
        <div class="result-title">{{ item.title || item.path }}</div>
        <div class="result-path">{{ item.path }}</div>
        <div v-if="item.snippet" class="result-snippet" v-html="item.snippet"></div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import type { SearchResult } from '../services/types'

defineProps<{
  query: string
  results: SearchResult[]
}>()

defineEmits<{
  (e: 'select-note', noteId: number): void
}>()
</script>

<style scoped>
.search-results-panel {
  padding: var(--space-4);
  background: var(--color-canvas);
  height: 100%;
  overflow-y: auto;
}

.search-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 600;
  color: var(--color-text-muted);
  font-size: 0.875rem;
  margin-bottom: var(--space-4);
  padding-bottom: var(--space-2);
  border-bottom: 1px solid var(--color-border);
}

.count {
  background: var(--color-surface-emphasis);
  color: var(--color-action);
  padding: 0.125rem 0.5rem;
  border-radius: var(--radius-pill);
  font-size: 0.75rem;
}

.no-results {
  color: var(--color-text-muted);
  padding: var(--space-8) 0;
  text-align: center;
}

.results-list {
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.result-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--space-3);
  cursor: pointer;
  transition: border-color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard);
}

.result-card:hover {
  background: var(--color-surface-emphasis);
  border-color: var(--color-action);
}

.result-title {
  font-weight: 600;
  color: var(--color-text);
  font-size: 1rem;
}

.result-path {
  font-size: 0.75rem;
  color: var(--color-text-muted);
  margin-top: 0.125rem;
  margin-bottom: var(--space-2);
}

.result-snippet {
  font-size: 0.875rem;
  color: var(--color-text-muted);
  line-height: 1.4;
  background: var(--color-canvas);
  padding: var(--space-2);
  border-radius: var(--radius-sm);
}
</style>
