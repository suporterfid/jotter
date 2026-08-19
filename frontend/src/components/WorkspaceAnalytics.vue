<template>
  <section class="workspace-analytics">
    <div class="panel-header">
      <div>
        <h2>{{ t('workspaceAnalytics.title') }}</h2>
        <p class="panel-hint">{{ t('workspaceAnalytics.hint') }}</p>
      </div>
    </div>

    <div v-if="loading" class="panel-empty" data-testid="analytics-loading">
      <p>{{ t('workspaceAnalytics.loading') }}</p>
    </div>

    <div v-else-if="error" class="panel-empty analytics-error" data-testid="analytics-error">
      <p>{{ error }}</p>
    </div>

    <div v-else-if="!analytics" class="panel-empty" data-testid="analytics-empty">
      <p>{{ t('workspaceAnalytics.empty') }}</p>
    </div>

    <template v-else>
      <p class="period-label">{{ t('workspaceAnalytics.period', { from: formatDate(analytics.period.from), to: formatDate(analytics.period.to) }) }}</p>

      <div class="summary-grid" :aria-label="t('workspaceAnalytics.summary')">
        <article class="summary-card">
          <span>{{ t('workspaceAnalytics.totalActivity') }}</span>
          <strong>{{ totalActivity }}</strong>
        </article>
        <article class="summary-card">
          <span>{{ t('workspaceAnalytics.activeNotes') }}</span>
          <strong>{{ analytics.most_active_notes.length }}</strong>
        </article>
        <article class="summary-card">
          <span>{{ t('workspaceAnalytics.staleNotes') }}</span>
          <strong>{{ analytics.stale_notes.length }}</strong>
        </article>
      </div>

      <div class="analytics-grid">
        <section class="analytics-card analytics-card-wide">
          <h3>{{ t('workspaceAnalytics.activityOverTime') }}</h3>
          <div v-if="analytics.activity_over_time.length === 0" class="section-empty">{{ t('workspaceAnalytics.sectionEmpty') }}</div>
          <table v-else class="analytics-table">
            <thead>
              <tr><th scope="col">{{ t('workspaceAnalytics.date') }}</th><th scope="col">{{ t('workspaceAnalytics.count') }}</th></tr>
            </thead>
            <tbody>
              <tr v-for="entry in analytics.activity_over_time" :key="entry.period_start" data-testid="analytics-daily-row">
                <td>{{ formatDate(entry.period_start) }}</td><td>{{ entry.count }}</td>
              </tr>
            </tbody>
          </table>
        </section>

        <section class="analytics-card">
          <h3>{{ t('workspaceAnalytics.mostActiveNotes') }}</h3>
          <div v-if="analytics.most_active_notes.length === 0" class="section-empty">{{ t('workspaceAnalytics.sectionEmpty') }}</div>
          <ol v-else class="ranked-list">
            <li v-for="note in analytics.most_active_notes" :key="note.note_id" data-testid="analytics-active-note">
              <span class="ranked-label">{{ note.title || note.path }}</span><strong>{{ note.count }}</strong>
            </li>
          </ol>
        </section>

        <section class="analytics-card">
          <h3>{{ t('workspaceAnalytics.contributors') }}</h3>
          <div v-if="analytics.activity_by_user.length === 0" class="section-empty">{{ t('workspaceAnalytics.sectionEmpty') }}</div>
          <ol v-else class="ranked-list">
            <li v-for="actor in analytics.activity_by_user" :key="actor.actor_subject_id" data-testid="analytics-contributor">
              <span class="ranked-label">{{ actor.actor_subject_id }}</span><strong>{{ actor.count }}</strong>
            </li>
          </ol>
        </section>

        <section class="analytics-card analytics-card-wide">
          <h3>{{ t('workspaceAnalytics.staleNotes') }}</h3>
          <div v-if="analytics.stale_notes.length === 0" class="section-empty">{{ t('workspaceAnalytics.sectionEmpty') }}</div>
          <ul v-else class="stale-list">
            <li v-for="note in analytics.stale_notes" :key="note.note_id" data-testid="analytics-stale-note">
              <span>{{ note.title || note.path }}</span><span>{{ t('workspaceAnalytics.daysStale', { count: note.days_stale }) }}</span>
            </li>
          </ul>
        </section>
      </div>
    </template>
  </section>
</template>

<script setup lang="ts">
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import type { WorkspaceAnalytics as WorkspaceAnalyticsData } from '../services/types'

const { t, locale } = useI18n()

const props = withDefaults(defineProps<{
  analytics: WorkspaceAnalyticsData | null
  loading?: boolean
  error?: string | null
}>(), {
  loading: false,
  error: null,
})

const totalActivity = computed(() => props.analytics?.activity_over_time.reduce((total, entry) => total + entry.count, 0) ?? 0)

function formatDate(value: string): string {
  try {
    return new Intl.DateTimeFormat(locale.value, { year: 'numeric', month: 'short', day: 'numeric', timeZone: 'UTC' }).format(new Date(`${value}T00:00:00Z`))
  } catch {
    return value
  }
}
</script>

<style scoped>
.workspace-analytics { flex: 1; overflow-y: auto; padding: var(--space-6); }
.panel-header { display: flex; align-items: flex-start; justify-content: space-between; margin-bottom: var(--space-2); }
.panel-header h2, .analytics-card h3 { font-family: var(--font-sans); color: var(--color-text); }
.panel-header h2 { font-size: 1.25rem; }
.panel-hint, .period-label, .section-empty { color: var(--color-text-muted); font-size: 0.8125rem; }
.panel-hint { margin-top: var(--space-2); }
.period-label { margin: var(--space-4) 0; }
.panel-empty { color: var(--color-text-muted); padding: var(--space-8) 0; }
.analytics-error { color: var(--color-danger, var(--color-text-muted)); }
.summary-grid, .analytics-grid { display: grid; gap: var(--space-4); }
.summary-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); margin-bottom: var(--space-5); }
.analytics-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
.summary-card, .analytics-card { border: 1px solid var(--color-border); border-radius: var(--radius-md); background: var(--color-surface); padding: var(--space-4); }
.summary-card { display: grid; gap: var(--space-2); color: var(--color-text-muted); font-size: 0.75rem; }
.summary-card strong { color: var(--color-text); font-size: 1.5rem; }
.analytics-card-wide { grid-column: span 2; }
.analytics-card h3 { font-size: 0.95rem; margin-bottom: var(--space-3); }
.analytics-table { width: 100%; border-collapse: collapse; font-size: 0.8125rem; }
.analytics-table th { color: var(--color-text-muted); font-size: 0.7rem; text-align: start; text-transform: uppercase; letter-spacing: 0.05em; }
.analytics-table th, .analytics-table td { padding: var(--space-2); border-bottom: 1px solid var(--color-border); }
.analytics-table td:last-child, .analytics-table th:last-child { text-align: end; }
.ranked-list, .stale-list { margin: 0; padding: 0; list-style: none; }
.ranked-list li, .stale-list li { display: flex; justify-content: space-between; gap: var(--space-3); padding: var(--space-2) 0; border-bottom: 1px solid var(--color-border); color: var(--color-text); font-size: 0.8125rem; }
.ranked-label { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.stale-list li span:last-child { color: var(--color-text-muted); white-space: nowrap; }
@media (max-width: 760px) { .summary-grid, .analytics-grid { grid-template-columns: 1fr; } .analytics-card-wide { grid-column: span 1; } }
</style>
