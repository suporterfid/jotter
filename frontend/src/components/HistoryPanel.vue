<template>
  <div class="modal-overlay" @click.self="$emit('close')">
    <div class="history-card" role="dialog" aria-modal="true" aria-labelledby="history-panel-title">
      <header class="history-header">
        <h3 id="history-panel-title">{{ t('historyPanel.title') }}</h3>
        <button type="button" class="btn-close" :aria-label="t('historyPanel.close')" @click="$emit('close')">&times;</button>
      </header>

      <div class="history-body">
        <div class="revision-list-pane">
          <div v-if="loading" class="pane-empty">{{ t('historyPanel.loading') }}</div>
          <div v-else-if="revisions.length === 0" class="pane-empty">
            {{ t('historyPanel.empty') }}
          </div>
          <ul v-else class="revision-list">
            <li
              v-for="revision in revisions"
              :key="revision.id"
              class="revision-item"
              :class="{ active: selectedRevisionId === revision.id }"
              data-testid="revision-item"
              @click="$emit('select-revision', revision.id)"
            >
              <span class="revision-date">{{ formatDate(revision.created_at) }}</span>
              <span class="revision-hash">{{ revision.content_hash.slice(0, 8) }}</span>
            </li>
          </ul>
        </div>

        <div class="revision-preview-pane">
          <div v-if="!selectedRevisionId" class="pane-empty">{{ t('historyPanel.selectToPreview') }}</div>
          <div v-else-if="previewLoading" class="pane-empty">{{ t('historyPanel.loadingPreview') }}</div>
          <template v-else-if="previewContent !== null">
            <pre class="revision-content" data-testid="revision-preview">{{ previewContent }}</pre>
            <div class="revision-actions">
              <button
                type="button"
                class="btn-primary"
                data-testid="revision-restore-btn"
                @click="$emit('restore-revision', selectedRevisionId)"
              >
                {{ t('historyPanel.restore') }}
              </button>
            </div>
          </template>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { NoteRevisionMeta } from '../services/types'

const { t, locale } = useI18n()

defineProps<{
  revisions: NoteRevisionMeta[]
  loading?: boolean
  selectedRevisionId: number | null
  previewContent: string | null
  previewLoading?: boolean
}>()

defineEmits<{
  (e: 'close'): void
  (e: 'select-revision', revisionId: number): void
  (e: 'restore-revision', revisionId: number): void
}>()

function formatDate(iso: string): string {
  try {
    return new Intl.DateTimeFormat(locale.value, {
      year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit'
    }).format(new Date(iso))
  } catch {
    return iso
  }
}
</script>

<style scoped>
.modal-overlay {
  position: fixed;
  inset: 0;
  background: var(--color-overlay);
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 100;
}

.history-card {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-lg);
  width: min(760px, 92vw);
  height: min(560px, 85vh);
  box-shadow: var(--shadow-float);
  display: flex;
  flex-direction: column;
  overflow: hidden;
}

.history-header {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: var(--space-4) var(--space-5);
  border-bottom: 1px solid var(--color-border);
}

.history-header h3 {
  font-size: 1.125rem;
  font-weight: 600;
  color: var(--color-text);
}

.btn-close {
  background: transparent;
  border: none;
  color: var(--color-text-muted);
  font-size: 1.25rem;
  cursor: pointer;
  min-width: 32px;
  min-height: 32px;
}

.btn-close:hover {
  color: var(--color-text);
}

.history-body {
  flex: 1;
  display: flex;
  overflow: hidden;
}

.revision-list-pane {
  width: 240px;
  min-width: 240px;
  border-inline-end: 1px solid var(--color-border);
  overflow-y: auto;
}

.revision-preview-pane {
  flex: 1;
  overflow-y: auto;
  padding: var(--space-4);
  display: flex;
  flex-direction: column;
}

.pane-empty {
  padding: var(--space-6) var(--space-4);
  color: var(--color-text-muted);
  font-size: 0.875rem;
}

.revision-list {
  list-style: none;
}

.revision-item {
  display: flex;
  flex-direction: column;
  gap: 0.1rem;
  padding: var(--space-3) var(--space-4);
  cursor: pointer;
  border-bottom: 1px solid var(--color-border);
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.revision-item:hover {
  background: var(--color-hover);
}

.revision-item.active {
  background: color-mix(in srgb, var(--color-action) 20%, transparent);
}

.revision-date {
  font-size: 0.8125rem;
  color: var(--color-text);
}

.revision-hash {
  font-size: 0.7rem;
  color: var(--color-text-muted);
  font-family: var(--font-mono, monospace);
}

.revision-content {
  flex: 1;
  overflow-y: auto;
  white-space: pre-wrap;
  word-break: break-word;
  font-family: var(--font-mono, monospace);
  font-size: 0.8125rem;
  color: var(--color-text);
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-3);
  margin-bottom: var(--space-4);
}

.revision-actions {
  display: flex;
  justify-content: flex-end;
}

.btn-primary {
  background: var(--color-action);
  color: var(--color-neutral-0);
  border: none;
  padding: var(--space-2) var(--space-4);
  border-radius: var(--radius-sm);
  cursor: pointer;
  font-weight: 500;
  font-size: 0.875rem;
  min-height: 36px;
  transition: background-color var(--duration-fast) var(--ease-standard);
}

.btn-primary:hover {
  background: var(--color-action-hover);
}
</style>
