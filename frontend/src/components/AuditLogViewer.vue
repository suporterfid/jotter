<template>
  <div class="audit-log-viewer">
    <div class="panel-header">
      <h2>{{ t('auditLogViewer.title') }}</h2>
      <span class="entry-count">{{ entries.length }}</span>
    </div>
    <p class="panel-hint">{{ t('auditLogViewer.hint') }}</p>

    <div v-if="loading" class="panel-empty">
      <p>{{ t('auditLogViewer.loading') }}</p>
    </div>

    <div v-else-if="entries.length === 0" class="panel-empty">
      <p>{{ t('auditLogViewer.empty') }}</p>
    </div>

    <table v-else class="audit-table">
      <thead>
        <tr>
          <th scope="col">{{ t('auditLogViewer.time') }}</th>
          <th scope="col">{{ t('auditLogViewer.event') }}</th>
          <th scope="col">{{ t('auditLogViewer.actor') }}</th>
          <th scope="col">{{ t('auditLogViewer.ip') }}</th>
          <th scope="col"><span class="sr-only">{{ t('auditLogViewer.details') }}</span></th>
        </tr>
      </thead>
      <tbody>
        <template v-for="entry in entries" :key="entry.id">
          <tr class="audit-row" data-testid="audit-log-row">
            <td class="audit-time">{{ formatDate(entry.created_at) }}</td>
            <td><span class="event-badge">{{ entry.event }}</span></td>
            <td class="audit-actor">{{ entry.actor_subject_id || '—' }}</td>
            <td class="audit-ip">{{ entry.ip_address || '—' }}</td>
            <td>
              <button
                v-if="entry.metadata && Object.keys(entry.metadata).length > 0"
                type="button"
                class="btn-toggle-details"
                data-testid="audit-log-details-toggle"
                :aria-expanded="expandedId === entry.id"
                :aria-label="t('auditLogViewer.toggleDetails', { event: entry.event })"
                @click="expandedId = expandedId === entry.id ? null : entry.id"
              >
                {{ expandedId === entry.id ? t('auditLogViewer.hide') : t('auditLogViewer.details') }}
              </button>
            </td>
          </tr>
          <tr v-if="expandedId === entry.id" class="audit-details-row">
            <td colspan="5">
              <pre class="audit-metadata" data-testid="audit-log-details">{{ JSON.stringify(entry.metadata, null, 2) }}</pre>
            </td>
          </tr>
        </template>
      </tbody>
    </table>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue'
import { useI18n } from 'vue-i18n'
import type { AuditLogEntry } from '../services/types'

const { t } = useI18n()

defineProps<{
  entries: AuditLogEntry[]
  loading?: boolean
}>()

const expandedId = ref<number | null>(null)

function formatDate(iso: string | null): string {
  if (!iso) return '—'
  try {
    return new Date(iso).toLocaleString(undefined, {
      year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit', second: '2-digit'
    })
  } catch {
    return iso
  }
}
</script>

<style scoped>
.audit-log-viewer {
  flex: 1;
  overflow-y: auto;
  padding: var(--space-6);
}

.panel-header {
  display: flex;
  align-items: center;
  gap: var(--space-3);
  margin-bottom: var(--space-2);
}

.panel-header h2 {
  font-family: var(--font-sans);
  font-size: 1.25rem;
  color: var(--color-text);
}

.entry-count {
  background: var(--color-surface-emphasis);
  color: var(--color-text-muted);
  padding: 0.1rem 0.5rem;
  border-radius: var(--radius-pill);
  font-size: 0.8rem;
  font-weight: 600;
}

.panel-hint {
  font-size: 0.8125rem;
  color: var(--color-text-muted);
  margin-bottom: var(--space-5);
}

.panel-empty {
  color: var(--color-text-muted);
  padding: var(--space-8) 0;
}

.sr-only {
  position: absolute;
  width: 1px;
  height: 1px;
  overflow: hidden;
  clip: rect(0, 0, 0, 0);
  white-space: nowrap;
}

.audit-table {
  width: 100%;
  border-collapse: collapse;
  font-size: 0.8125rem;
}

.audit-table th {
  text-align: left;
  padding: var(--space-2) var(--space-3);
  color: var(--color-text-muted);
  font-weight: 600;
  text-transform: uppercase;
  font-size: 0.7rem;
  letter-spacing: 0.05em;
  border-bottom: 1px solid var(--color-border);
}

.audit-row td {
  padding: var(--space-2) var(--space-3);
  border-bottom: 1px solid var(--color-border);
  color: var(--color-text);
}

.audit-time {
  white-space: nowrap;
  color: var(--color-text-muted);
}

.event-badge {
  background: var(--color-surface-emphasis);
  border-radius: var(--radius-sm);
  padding: 0.1rem 0.4rem;
  font-family: var(--font-mono, monospace);
  font-size: 0.75rem;
}

.audit-actor,
.audit-ip {
  color: var(--color-text-muted);
  font-family: var(--font-mono, monospace);
  font-size: 0.75rem;
}

.btn-toggle-details {
  background: transparent;
  border: 1px solid var(--color-border);
  color: var(--color-text-muted);
  border-radius: var(--radius-sm);
  padding: 0.15rem 0.5rem;
  font-size: 0.7rem;
  cursor: pointer;
  min-height: 28px;
  transition: border-color var(--duration-fast) var(--ease-standard),
              color var(--duration-fast) var(--ease-standard);
}

.btn-toggle-details:hover {
  border-color: var(--color-action);
  color: var(--color-action);
}

.audit-details-row td {
  border-bottom: 1px solid var(--color-border);
  padding: 0 var(--space-3) var(--space-3);
}

.audit-metadata {
  background: var(--color-canvas);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-sm);
  padding: var(--space-2);
  font-size: 0.75rem;
  color: var(--color-text);
  overflow-x: auto;
  white-space: pre;
}
</style>
