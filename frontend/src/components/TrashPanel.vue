<template>
  <section class="trash-panel">
    <div class="panel-header">
      <div>
        <h2>{{ t('trashPanel.title') }}</h2>
        <p class="panel-hint">{{ t('trashPanel.hint') }}</p>
      </div>
      <span class="trash-count">{{ notes.length }}</span>
    </div>

    <div v-if="loading" class="panel-empty">
      <p>{{ t('trashPanel.loading') }}</p>
    </div>

    <div v-else-if="notes.length === 0" class="panel-empty">
      <p>{{ t('trashPanel.empty') }}</p>
    </div>

    <ul v-else class="trash-list">
      <li v-for="note in notes" :key="note.id" class="trash-row" data-testid="trash-note-row">
        <div class="trash-note-details">
          <strong class="trash-note-title">{{ note.title || '—' }}</strong>
          <span class="trash-note-path">{{ note.original_path || '—' }}</span>
          <span class="trash-note-date">{{ t('trashPanel.deletedAt', { date: formatDate(note.deleted_at) }) }}</span>
        </div>
        <div class="trash-actions">
          <button
            type="button"
            class="btn-secondary"
            data-testid="trash-restore-btn"
            @click="$emit('restore-note', note.id)"
          >
            {{ t('trashPanel.restore') }}
          </button>
          <button
            type="button"
            class="btn-danger"
            data-testid="trash-permanent-delete-btn"
            @click="confirmPermanentDelete(note.id)"
          >
            {{ t('trashPanel.permanentlyDelete') }}
          </button>
        </div>
      </li>
    </ul>
  </section>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { TrashNoteMeta } from '../services/types'

const { t, locale } = useI18n()

defineProps<{
  notes: TrashNoteMeta[]
  loading?: boolean
}>()

const emit = defineEmits<{
  (e: 'restore-note', noteId: number): void
  (e: 'permanently-delete-note', noteId: number): void
}>()

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  try {
    return new Intl.DateTimeFormat(locale.value, {
      year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(new Date(iso))
  } catch {
    return iso
  }
}

function confirmPermanentDelete(noteId: number) {
  if (window.confirm(t('app.confirmPermanentlyDeleteNote'))) {
    emit('permanently-delete-note', noteId)
  }
}
</script>

<style scoped>
.trash-panel {
  flex: 1;
  overflow-y: auto;
  padding: var(--space-6);
}

.panel-header {
  display: flex;
  align-items: flex-start;
  justify-content: space-between;
  gap: var(--space-3);
  margin-bottom: var(--space-5);
}

.panel-header h2 {
  font-family: var(--font-sans);
  font-size: 1.25rem;
  color: var(--color-text);
}

.panel-hint,
.trash-note-date {
  color: var(--color-text-muted);
  font-size: 0.875rem;
}

.panel-hint {
  margin-top: var(--space-1);
}

.trash-count {
  background: var(--color-surface-emphasis);
  color: var(--color-text-muted);
  padding: 0.1rem 0.5rem;
  border-radius: var(--radius-pill);
  font-size: 0.8rem;
  font-weight: 600;
}

.panel-empty {
  color: var(--color-text-muted);
  padding: var(--space-8) 0;
}

.trash-list {
  display: grid;
  gap: var(--space-3);
  list-style: none;
  max-width: 60rem;
}

.trash-row {
  display: flex;
  align-items: center;
  justify-content: space-between;
  gap: var(--space-4);
  padding: var(--space-4);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  background: var(--color-surface);
}

.trash-note-details {
  min-width: 0;
  display: grid;
  gap: var(--space-1);
}

.trash-note-title,
.trash-note-path {
  overflow: hidden;
  text-overflow: ellipsis;
  white-space: nowrap;
}

.trash-note-title {
  color: var(--color-text);
}

.trash-note-path {
  color: var(--color-text-muted);
  font-family: var(--font-mono);
  font-size: 0.875rem;
}

.trash-actions {
  display: flex;
  flex-shrink: 0;
  gap: var(--space-2);
}

.btn-secondary,
.btn-danger {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-2) var(--space-3);
  cursor: pointer;
  font: inherit;
}

.btn-secondary {
  color: var(--color-text);
  background: var(--color-surface-emphasis);
}

.btn-danger {
  color: var(--color-danger-text, var(--color-text));
  background: transparent;
}

@media (max-width: 720px) {
  .trash-row {
    align-items: stretch;
    flex-direction: column;
  }

  .trash-actions {
    flex-wrap: wrap;
  }
}
</style>
