<template>
  <aside class="comments-panel" :class="{ 'panel-collapsed': collapsed }" :aria-label="t('commentsPanel.title')">
    <PanelHeader :title="t('commentsPanel.title')" :count="comments.length" :collapsed="collapsed" @toggle="toggle">
      <template #icon>
        <svg class="icon" viewBox="0 0 24 24" width="16" height="16" stroke="currentColor" stroke-width="2" fill="none">
          <path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"></path>
        </svg>
      </template>
    </PanelHeader>

    <div v-show="!collapsed" class="comments-body">
    <p v-if="errorMessage" class="comments-error" role="alert">{{ errorMessage }}</p>

    <div v-if="comments.length === 0" class="comments-empty">
      <p>{{ t('commentsPanel.empty') }}</p>
    </div>

    <ul v-else class="comments-list">
      <li v-for="comment in comments" :key="comment.id" class="comment-item" data-testid="comment-item">
        <div class="comment-meta">
          <span class="comment-author">{{ comment.actor_name }}</span>
          <span v-if="comment.anchor_line" class="comment-anchor">{{ t('commentsPanel.line', { line: comment.anchor_line }) }}</span>
          <span class="comment-date">{{ formatDate(comment.created_at) }}</span>
        </div>
        <p class="comment-content">{{ comment.content }}</p>
        <button
          class="btn-delete-comment"
          data-testid="comment-delete-btn"
          :aria-label="t('commentsPanel.delete', { name: comment.actor_name })"
          :title="t('commentsPanel.deleteTooltip')"
          @click="$emit('delete-comment', comment.id)"
        >
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="3 6 5 6 21 6"></polyline>
            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
          </svg>
        </button>
      </li>
    </ul>

    <form class="comment-form" @submit.prevent="handleSubmit">
      <textarea
        v-model="newContent"
        :placeholder="t('commentsPanel.placeholder')"
        :aria-label="t('commentsPanel.newComment')"
        data-testid="comment-input"
        class="comment-form-textarea"
        rows="2"
      ></textarea>
      <button type="submit" class="btn-add-comment" data-testid="comment-submit-btn" :disabled="!newContent.trim()">
        {{ t('commentsPanel.comment') }}
      </button>
    </form>
    </div>
  </aside>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import PanelHeader from './PanelHeader.vue'
import { useCollapsiblePanel } from '../composables/useCollapsiblePanel'
import type { NoteComment } from '../services/types'

const { t, locale } = useI18n()

defineProps<{
  comments: NoteComment[]
  errorMessage?: string | null
}>()

const emit = defineEmits<{
  (e: 'add-comment', content: string): void
  (e: 'delete-comment', commentId: number): void
}>()

const { collapsed, toggle } = useCollapsiblePanel('comments', false)

const newContent = ref('')

function formatDate(iso: string): string {
  try {
    return new Intl.DateTimeFormat(locale.value, {
      year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    }).format(new Date(iso))
  } catch {
    return iso
  }
}

function handleSubmit() {
  const content = newContent.value.trim()
  if (!content) return
  emit('add-comment', content)
  newContent.value = ''
}
</script>

<style scoped>
.comments-panel {
  background: var(--color-surface);
  border-top: 1px solid var(--color-border);
  padding: var(--space-4);
  font-size: 0.875rem;
  color: var(--color-text);
}

.comments-panel.panel-collapsed {
  padding-bottom: 0;
}

.comments-error {
  color: var(--color-status-danger);
  font-size: 0.8125rem;
  margin-bottom: var(--space-2);
}

.comments-empty {
  color: var(--color-text-muted);
  font-style: italic;
  padding: var(--space-2) 0;
}

.comments-list {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}

.comment-item {
  position: relative;
  border-radius: var(--radius-sm);
  padding: var(--space-2) var(--space-3);
  padding-inline-end: var(--space-8);
}

.comment-meta {
  display: flex;
  align-items: baseline;
  gap: var(--space-2);
  margin-bottom: 0.2rem;
}

.comment-author {
  font-weight: 600;
  color: var(--color-text);
}

.comment-anchor {
  font-size: 0.7rem;
  color: var(--color-text-muted);
  background: var(--color-canvas);
  border-radius: var(--radius-sm);
  padding: 0 0.375rem;
}

.comment-date {
  font-size: 0.7rem;
  color: var(--color-text-muted);
}

.comment-content {
  color: var(--color-text);
  white-space: pre-wrap;
  word-break: break-word;
}

.btn-delete-comment {
  position: absolute;
  inset-block-start: var(--space-2);
  inset-inline-end: var(--space-2);
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  padding: var(--space-1);
  border-radius: var(--radius-sm);
  cursor: pointer;
  min-width: 28px;
  min-height: 28px;
  display: flex;
  align-items: center;
  justify-content: center;
  transition: color var(--duration-fast) var(--ease-standard),
              background-color var(--duration-fast) var(--ease-standard);
}

.btn-delete-comment:hover {
  color: var(--color-status-danger);
  background: color-mix(in srgb, var(--color-status-danger) 12%, transparent);
}

.comment-form {
  display: flex;
  flex-direction: column;
  gap: var(--space-2);
}

.comment-form-textarea {
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-2);
  color: var(--color-text);
  font-size: 0.8125rem;
  font-family: inherit;
  resize: vertical;
}

.btn-add-comment {
  align-self: flex-end;
  background: var(--color-action);
  color: var(--color-neutral-0);
  border: none;
  padding: var(--space-1) var(--space-3);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-size: 0.8125rem;
  min-height: 32px;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.btn-add-comment:hover:not(:disabled) {
  background: var(--color-action-hover);
}

.btn-add-comment:disabled {
  opacity: 0.5;
  cursor: not-allowed;
}
</style>
