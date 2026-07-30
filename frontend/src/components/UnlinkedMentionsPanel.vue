<template>
  <aside class="unlinked-mentions-panel" aria-label="Unlinked mentions">
    <PanelHeader title="Unlinked Mentions" :count="mentions.length">
      <template #icon>
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none">
          <circle cx="12" cy="12" r="10"></circle>
          <line x1="12" y1="8" x2="12" y2="12"></line>
          <line x1="12" y1="16" x2="12.01" y2="16"></line>
        </svg>
      </template>
    </PanelHeader>

    <div v-if="mentions.length === 0" class="unlinked-mentions-empty">
      <p>No unlinked mentions found.</p>
    </div>

    <ul v-else class="unlinked-mentions-list">
      <li v-for="mention in mentions" :key="mention.id" class="mention-item">
        <div class="mention-main" @click="$emit('select-note', mention.id)">
          <div class="mention-title">{{ mention.title || mention.path }}</div>
          <div class="mention-snippet">{{ mention.snippet }}</div>
        </div>
        <button
          type="button"
          class="convert-link-btn"
          data-testid="convert-to-link-btn"
          :title="`Convert &quot;${mention.matched_phrase}&quot; into a wikilink`"
          @click="$emit('convert-to-link', mention)"
        >
          Link
        </button>
      </li>
    </ul>
  </aside>
</template>

<script setup lang="ts">
import PanelHeader from './PanelHeader.vue'
import type { UnlinkedMention } from '../services/types'

defineProps<{
  mentions: UnlinkedMention[]
}>()

defineEmits<{
  (e: 'select-note', noteId: number): void
  (e: 'convert-to-link', mention: UnlinkedMention): void
}>()
</script>

<style scoped>
.unlinked-mentions-panel {
  background: var(--color-surface);
  border-top: 1px solid var(--color-border);
  padding: var(--space-4);
  font-size: 0.875rem;
  color: var(--color-text);
}

.unlinked-mentions-empty {
  color: var(--color-text-muted);
  font-style: italic;
  padding: var(--space-2) 0;
}

.unlinked-mentions-list {
  list-style: none;
  padding: 0;
  margin: 0;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.mention-item {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  background: var(--color-surface-emphasis);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-2) var(--space-3);
}

.mention-main {
  flex: 1;
  min-width: 0;
  cursor: pointer;
}

.mention-main:hover .mention-title {
  color: var(--color-action);
}

.mention-title {
  font-weight: 500;
  color: var(--color-text);
}

.mention-snippet {
  font-size: 0.75rem;
  color: var(--color-text-muted);
  margin-top: 0.125rem;
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.convert-link-btn {
  flex-shrink: 0;
  background: var(--color-surface);
  color: var(--color-action);
  border: 1px solid var(--color-action);
  border-radius: var(--radius-sm);
  padding: 0.25rem 0.625rem;
  font-size: 0.75rem;
  font-weight: 500;
  cursor: pointer;
  min-height: 36px;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.convert-link-btn:hover {
  background: var(--color-action);
  color: var(--color-neutral-0);
}
</style>
