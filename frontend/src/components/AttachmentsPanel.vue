<template>
  <div class="attachments-panel">
    <div class="panel-header">
      <h2>{{ t('attachmentsPanel.title') }}</h2>
      <span class="attachment-count">{{ attachments.length }}</span>
    </div>

    <div v-if="loading" class="panel-empty">
      <p>{{ t('attachmentsPanel.loading') }}</p>
    </div>

    <div v-else-if="attachments.length === 0" class="panel-empty">
      <p>{{ t('attachmentsPanel.empty') }}</p>
      <p class="panel-empty-hint">{{ t('attachmentsPanel.emptyHint') }}</p>
    </div>

    <ul v-else class="attachment-grid">
      <li v-for="attachment in attachments" :key="attachment.id" class="attachment-card">
        <a
          :href="attachment.url"
          target="_blank"
          rel="noopener"
          class="attachment-preview-link"
          :aria-label="t('attachmentsPanel.open', { name: filename(attachment.path) })"
        >
          <img
            v-if="attachment.mime.startsWith('image/')"
            :src="attachment.url"
            :alt="filename(attachment.path)"
            class="attachment-thumb"
          />
          <div v-else class="attachment-icon">
            <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="1.5">
              <path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"></path>
              <polyline points="14 2 14 8 20 8"></polyline>
            </svg>
          </div>
        </a>
        <div class="attachment-meta">
          <span class="attachment-name" :title="attachment.path">{{ filename(attachment.path) }}</span>
          <span class="attachment-sub">{{ formatSize(attachment.size) }} &middot; {{ formatDate(attachment.created_at) }}</span>
        </div>
        <button
          class="btn-delete-attachment"
          data-testid="attachment-delete-btn"
          :title="t('attachmentsPanel.deleteTooltip')"
          :aria-label="t('attachmentsPanel.delete', { name: filename(attachment.path) })"
          @click="$emit('delete-attachment', attachment)"
        >
          <svg viewBox="0 0 24 24" width="14" height="14" fill="none" stroke="currentColor" stroke-width="2">
            <polyline points="3 6 5 6 21 6"></polyline>
            <path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"></path>
          </svg>
        </button>
      </li>
    </ul>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { AttachmentItem } from '../services/types'

const { t, locale } = useI18n()

defineProps<{
  attachments: AttachmentItem[]
  loading?: boolean
}>()

defineEmits<{
  (e: 'delete-attachment', attachment: AttachmentItem): void
}>()

function filename(path: string): string {
  return path.split('/').pop() || path
}

function formatSize(bytes: number): string {
  const [value, unit] = bytes < 1024
    ? [bytes, 'B']
    : bytes < 1024 * 1024
      ? [bytes / 1024, 'KB']
      : [bytes / (1024 * 1024), 'MB']

  return `${new Intl.NumberFormat(locale.value, {
    minimumFractionDigits: value < 1024 && unit !== 'B' ? 1 : 0,
    maximumFractionDigits: 1,
  }).format(value)} ${unit}`
}

function formatDate(iso: string): string {
  try {
    return new Intl.DateTimeFormat(locale.value, { year: 'numeric', month: 'short', day: 'numeric' }).format(new Date(iso))
  } catch {
    return iso
  }
}
</script>

<style scoped>
.attachments-panel {
  flex: 1;
  overflow-y: auto;
  padding: var(--space-6);
}

.panel-header {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin-bottom: var(--space-5);
}

.panel-header h2 {
  font-family: var(--font-sans);
  font-size: 1.25rem;
  color: var(--color-text);
}

.attachment-count {
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

.panel-empty-hint {
  font-size: 0.875rem;
  margin-top: var(--space-2);
}

.attachment-grid {
  list-style: none;
  display: grid;
  grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
  gap: var(--space-4);
}

.attachment-card {
  position: relative;
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  overflow: hidden;
  display: flex;
  flex-direction: column;
  transition: border-color var(--duration-fast) var(--ease-standard);
}

.attachment-card:hover {
  border-color: var(--color-border-strong);
}

.attachment-preview-link {
  display: flex;
  align-items: center;
  justify-content: center;
  height: 110px;
  background: var(--color-canvas);
}

.attachment-thumb {
  width: 100%;
  height: 100%;
  object-fit: cover;
}

.attachment-icon {
  color: var(--color-text-muted);
}

.attachment-meta {
  display: flex;
  flex-direction: column;
  padding: var(--space-2) var(--space-3);
  gap: 0.1rem;
}

.attachment-name {
  font-size: 0.8125rem;
  color: var(--color-text);
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
}

.attachment-sub {
  font-size: 0.7rem;
  color: var(--color-text-muted);
}

.btn-delete-attachment {
  position: absolute;
  inset-block-start: var(--space-2);
  inset-inline-end: var(--space-2);
  background: color-mix(in srgb, var(--color-surface) 85%, transparent);
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

.btn-delete-attachment:hover {
  color: var(--color-status-danger);
  background: color-mix(in srgb, var(--color-status-danger) 15%, transparent);
}
</style>
