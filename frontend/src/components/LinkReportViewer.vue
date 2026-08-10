<template>
  <div class="link-report-viewer">
    <div class="panel-header">
      <h2>{{ t('linkReportViewer.title') }}</h2>
    </div>

    <div v-if="loading" class="panel-empty">
      <p>{{ t('linkReportViewer.loading') }}</p>
    </div>

    <template v-else>
      <section class="report-section">
        <div class="section-header">
          <h3>{{ t('linkReportViewer.brokenLinks') }}</h3>
          <span class="count-badge">{{ report.broken_links.length }}</span>
        </div>
        <p v-if="report.broken_links.length === 0" class="panel-empty-inline" v-html="t('linkReportViewer.noBrokenLinks')"></p>
        <ul v-else class="broken-link-list">
          <li v-for="group in report.broken_links" :key="group.target_ref" class="broken-link-group" data-testid="broken-link-group">
            <div class="broken-link-target">
              <span class="target-ref">[[{{ group.target_ref }}]]</span>
              <span class="count-badge">{{ group.count }}</span>
            </div>
            <ul class="broken-link-sources">
              <li v-for="source in group.sources" :key="source.id">
                <button
                  type="button"
                  class="source-link"
                  data-testid="broken-link-source"
                  @click="$emit('select-note', source.id)"
                >
                  {{ source.title || source.path }}
                </button>
              </li>
            </ul>
          </li>
        </ul>
      </section>

      <section class="report-section">
        <div class="section-header">
          <h3>{{ t('linkReportViewer.orphanNotes') }}</h3>
          <span class="count-badge">{{ report.orphans.length }}</span>
        </div>
        <p v-if="report.orphans.length === 0" class="panel-empty-inline">
          {{ t('linkReportViewer.noOrphans') }}
        </p>
        <ul v-else class="orphan-list">
          <li v-for="orphan in report.orphans" :key="orphan.id" data-testid="orphan-note">
            <button type="button" class="source-link" @click="$emit('select-note', orphan.id)">
              {{ orphan.title || orphan.path }}
            </button>
            <span class="orphan-path">{{ orphan.path }}</span>
          </li>
        </ul>
      </section>
    </template>
  </div>
</template>

<script setup lang="ts">
import { useI18n } from 'vue-i18n'
import type { LinkReport } from '../services/types'

const { t } = useI18n()

withDefaults(defineProps<{
  report: LinkReport
  loading?: boolean
}>(), {
  loading: false
})

defineEmits<{
  (e: 'select-note', noteId: number): void
}>()
</script>

<style scoped>
.link-report-viewer {
  flex: 1;
  overflow-y: auto;
  padding: var(--space-6);
}

.panel-header {
  margin-bottom: var(--space-5);
}

.panel-header h2 {
  font-family: var(--font-sans);
  font-size: 1.25rem;
  color: var(--color-text);
}

.panel-empty {
  color: var(--color-text-muted);
  padding: var(--space-8) 0;
}

.panel-empty-inline {
  color: var(--color-text-muted);
  font-size: 0.875rem;
  font-style: italic;
}

.report-section {
  margin-bottom: var(--space-8);
}

.section-header {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin-bottom: var(--space-3);
}

.section-header h3 {
  font-size: 1rem;
  font-weight: 600;
  color: var(--color-text);
}

.count-badge {
  background: var(--color-surface-emphasis);
  color: var(--color-text-muted);
  padding: 0.1rem 0.5rem;
  border-radius: var(--radius-pill);
  font-size: 0.75rem;
  font-weight: 600;
}

.broken-link-list,
.orphan-list {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: var(--space-3);
}

.broken-link-group {
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--space-3);
}

.broken-link-target {
  display: flex;
  align-items: center;
  gap: var(--space-2);
  margin-bottom: var(--space-2);
}

.target-ref {
  font-family: var(--font-mono, monospace);
  color: var(--color-status-danger);
  font-size: 0.875rem;
}

.broken-link-sources {
  list-style: none;
  display: flex;
  flex-direction: column;
  gap: var(--space-1);
  padding-inline-start: var(--space-4);
}

.source-link {
  background: transparent;
  border: none;
  color: var(--color-action);
  cursor: pointer;
  font-size: 0.8125rem;
  padding: 0;
  text-decoration: underline;
}

.source-link:hover {
  color: var(--color-action-hover);
}

.orphan-list li {
  display: flex;
  align-items: baseline;
  gap: var(--space-2);
  background: var(--color-surface);
  border: 1px solid var(--color-border);
  border-radius: var(--radius-md);
  padding: var(--space-2) var(--space-3);
}

.orphan-path {
  color: var(--color-text-muted);
  font-size: 0.75rem;
}
</style>
