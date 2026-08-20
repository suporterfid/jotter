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

        <div v-if="revisions.length > 0" class="revision-comparison-pane">
          <div class="revision-compare-controls">
            <label>
              {{ t('historyPanel.compareFrom') }}
              <select v-model="compareFromValue" data-testid="revision-compare-from">
                <option v-for="revision in revisions" :key="revision.id" :value="String(revision.id)">
                  {{ formatDate(revision.created_at) }}
                </option>
              </select>
            </label>
            <label>
              {{ t('historyPanel.compareTo') }}
              <select v-model="compareToValue" data-testid="revision-compare-to">
                <option value="current">{{ t('historyPanel.current') }}</option>
                <option v-for="revision in revisions" :key="revision.id" :value="String(revision.id)">
                  {{ formatDate(revision.created_at) }}
                </option>
              </select>
            </label>
            <button
              type="button"
              class="btn-primary"
              data-testid="revision-compare-btn"
              :disabled="comparisonLoading || !compareFromValue"
              @click="requestComparison"
            >
              {{ t('historyPanel.compare') }}
            </button>
          </div>

          <div v-if="comparisonLoading" class="pane-empty">{{ t('historyPanel.loadingComparison') }}</div>
          <div v-else-if="comparison" class="revision-diff" data-testid="revision-diff">
            <p v-if="!comparison.changed" class="pane-empty">{{ t('historyPanel.noDifferences') }}</p>
            <div
              v-for="(line, index) in comparison.lines"
              :key="index + '-' + line.type"
              class="revision-diff-line"
              :class="'revision-diff-line-' + line.type"
              :data-diff-type="line.type"
            >
              <span class="revision-diff-line-number">{{ line.from_line ?? '·' }}</span>
              <span class="revision-diff-line-number">{{ line.to_line ?? '·' }}</span>
              <code>{{ line.text || ' ' }}</code>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import type { NoteRevisionMeta, NoteRevisionComparison } from '../services/types'

const { t, locale } = useI18n()

const emit = defineEmits<{
  (e: 'close'): void
  (e: 'select-revision', revisionId: number): void
  (e: 'restore-revision', revisionId: number): void
  (e: 'compare-revisions', fromRevisionId: number, toRevisionId: number | 'current'): void
}>()

const props = defineProps<{
  revisions: NoteRevisionMeta[]
  loading?: boolean
  selectedRevisionId: number | null
  previewContent: string | null
  previewLoading?: boolean
  comparison?: NoteRevisionComparison | null
  comparisonLoading?: boolean
}>()

const compareFromValue = ref(String(props.selectedRevisionId ?? props.revisions[0]?.id ?? ''))
const compareToValue = ref<number | 'current'>('current')

watch(() => props.selectedRevisionId, (revisionId) => {
  if (revisionId !== null) compareFromValue.value = String(revisionId)
})

function requestComparison(): void {
  const fromRevisionId = Number(compareFromValue.value)
  if (!Number.isInteger(fromRevisionId) || fromRevisionId < 1) return
  const toRevisionId = compareToValue.value === 'current' ? 'current' : Number(compareToValue.value)
  emit('compare-revisions', fromRevisionId, toRevisionId)
}

function formatDate(iso: string | null): string {
  if (!iso) return t('historyPanel.current')
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

.revision-comparison-pane {
  border-top: 1px solid var(--color-border);
  padding: var(--space-3) var(--space-4);
  max-height: 42%;
  overflow-y: auto;
}

.revision-compare-controls {
  display: flex;
  align-items: end;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}

.revision-compare-controls label {
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  color: var(--color-text-muted);
  font-size: 0.75rem;
}

.revision-compare-controls select {
  min-height: 32px;
  max-width: 150px;
  color: var(--color-text);
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
}

.revision-diff {
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  overflow: hidden;
}

.revision-diff-line {
  display: grid;
  grid-template-columns: 2.5rem 2.5rem minmax(0, 1fr);
  gap: var(--space-2);
  padding: 0.2rem var(--space-2);
  font-family: var(--font-mono, monospace);
  font-size: 0.75rem;
  white-space: pre-wrap;
  word-break: break-word;
}

.revision-diff-line-added {
  background: color-mix(in srgb, var(--color-success, #2f9e44) 18%, transparent);
}

.revision-diff-line-removed {
  background: color-mix(in srgb, var(--color-danger, #c92a2a) 18%, transparent);
}

.revision-diff-line-number {
  color: var(--color-text-muted);
  text-align: right;
  user-select: none;
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
