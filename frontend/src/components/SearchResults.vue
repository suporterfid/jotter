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
  padding: 1rem;
  background: var(--bg-surface, #1e1e24);
  height: 100%;
  overflow-y: auto;
}

.search-header {
  display: flex;
  justify-content: space-between;
  align-items: center;
  font-weight: 600;
  color: var(--text-muted, #94a3b8);
  font-size: 0.875rem;
  margin-bottom: 1rem;
  padding-bottom: 0.5rem;
  border-bottom: 1px solid var(--border-color, #2d2d38);
}

.count {
  background: var(--bg-hover, #2d3748);
  color: var(--accent-color, #818cf8);
  padding: 0.125rem 0.5rem;
  border-radius: 9999px;
  font-size: 0.75rem;
}

.no-results {
  color: var(--text-dim, #64748b);
  padding: 2rem 0;
  text-align: center;
}

.results-list {
  display: flex;
  flex-direction: column;
  gap: 0.75rem;
}

.result-card {
  background: rgba(255, 255, 255, 0.02);
  border: 1px solid var(--border-color, #2d2d38);
  border-radius: 0.5rem;
  padding: 0.875rem;
  cursor: pointer;
  transition: all 0.15s ease;
}

.result-card:hover {
  background: rgba(129, 140, 248, 0.08);
  border-color: var(--accent-color, #818cf8);
}

.result-title {
  font-weight: 600;
  color: var(--text-light, #f8fafc);
  font-size: 1rem;
}

.result-path {
  font-size: 0.75rem;
  color: var(--text-dim, #64748b);
  margin-top: 0.125rem;
  margin-bottom: 0.5rem;
}

.result-snippet {
  font-size: 0.875rem;
  color: var(--text-main, #cbd5e1);
  line-height: 1.4;
  background: rgba(0, 0, 0, 0.2);
  padding: 0.5rem;
  border-radius: 0.25rem;
}
</style>
