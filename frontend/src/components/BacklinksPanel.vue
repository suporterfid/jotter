<template>
  <aside class="backlinks-panel" aria-label="Backlinks">
    <div class="backlinks-header">
      <div class="header-title">
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none">
          <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"></path>
          <path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"></path>
        </svg>
        <span>Backlinks</span>
      </div>
      <span class="count-badge">{{ backlinks.length }}</span>
    </div>

    <div v-if="backlinks.length === 0" class="backlinks-empty">
      <p>No notes link to this document yet.</p>
    </div>

    <ul v-else class="backlinks-list">
      <li 
        v-for="link in backlinks" 
        :key="link.id" 
        class="backlink-item"
        @click="$emit('select-note', link.id)"
      >
        <div class="backlink-title">{{ link.title || link.path }}</div>
        <div class="backlink-path">{{ link.path }}</div>
      </li>
    </ul>
  </aside>
</template>

<script setup lang="ts">
import type { Backlink } from '../services/types'

defineProps<{
  backlinks: Backlink[]
}>()

defineEmits<{
  (e: 'select-note', noteId: number): void
}>()
</script>

<style scoped>
.backlinks-panel {
  background: var(--bg-surface, #1e1e24);
  border-top: 1px solid var(--border-color, #2d2d38);
  padding: 1rem;
  font-size: 0.875rem;
  color: var(--text-main, #e2e8f0);
}

.backlinks-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  margin-bottom: 0.75rem;
  font-weight: 600;
  color: var(--text-muted, #94a3b8);
  text-transform: uppercase;
  letter-spacing: 0.05em;
  font-size: 0.75rem;
}

.header-title {
  display: flex;
  align-items: center;
  gap: 0.5rem;
}

.count-badge {
  background: var(--bg-hover, #2d3748);
  color: var(--accent-color, #818cf8);
  padding: 0.125rem 0.5rem;
  border-radius: 9999px;
  font-size: 0.75rem;
}

.backlinks-empty {
  color: var(--text-dim, #64748b);
  font-style: italic;
  padding: 0.5rem 0;
}

.backlinks-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: 0.5rem;
}

.backlink-item {
  background: rgba(255, 255, 255, 0.03);
  border: 1px solid var(--border-color, #2d2d38);
  border-radius: 0.375rem;
  padding: 0.5rem 0.75rem;
  cursor: pointer;
  transition: all 0.15s ease;
}

.backlink-item:hover {
  background: rgba(129, 140, 248, 0.1);
  border-color: var(--accent-color, #818cf8);
}

.backlink-title {
  font-weight: 500;
  color: var(--text-light, #f8fafc);
}

.backlink-path {
  font-size: 0.75rem;
  color: var(--text-dim, #64748b);
  margin-top: 0.125rem;
}
</style>
