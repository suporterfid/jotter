import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WorkspaceAnalytics from './components/WorkspaceAnalytics.vue'

const analytics = {
  workspace_id: 7,
  period: { days: 30, from: '2026-07-21', to: '2026-08-19' },
  most_active_notes: [
    { note_id: 11, path: 'docs/plan.md', title: 'Plan', count: 8, last_seen_at: '2026-08-19T12:00:00Z' }
  ],
  activity_over_time: [
    { period_start: '2026-08-18', count: 2 },
    { period_start: '2026-08-19', count: 6 }
  ],
  activity_by_user: [{ actor_subject_id: 'external:alex', count: 4 }],
  stale_notes: [{ note_id: 12, path: 'old.md', title: 'Old', updated_at: '2026-06-01T00:00:00Z', days_stale: 79 }]
}

describe('WorkspaceAnalytics', () => {
  it('renders localized analytics sections without raw audit metadata', () => {
    const wrapper = mount(WorkspaceAnalytics, { props: { analytics, loading: false } })

    expect(wrapper.text()).toContain('Workspace analytics')
    expect(wrapper.text()).toContain('Plan')
    expect(wrapper.text()).toContain('external:alex')
    expect(wrapper.text()).toContain('Old')
    expect(wrapper.text()).not.toContain('ip_address')
    expect(wrapper.findAll('[data-testid="analytics-daily-row"]')).toHaveLength(2)
  })

  it('shows loading, empty, and error states', () => {
    expect(mount(WorkspaceAnalytics, { props: { analytics: null, loading: true } }).text()).toContain('Loading analytics')
    expect(mount(WorkspaceAnalytics, { props: { analytics: null, loading: false } }).text()).toContain('No analytics data')
    expect(mount(WorkspaceAnalytics, { props: { analytics: null, loading: false, error: 'Network down' } }).text()).toContain('Network down')
  })
})
