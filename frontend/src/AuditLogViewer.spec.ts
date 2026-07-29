import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AuditLogViewer from './components/AuditLogViewer.vue'

const entries = [
  { id: 1, actor_subject_id: 'user:1', event: 'note.created', metadata: { path: 'a.md' }, ip_address: '127.0.0.1', created_at: '2026-07-01T00:00:00Z' },
  { id: 2, actor_subject_id: null, event: 'auth.login_success', metadata: null, ip_address: '127.0.0.1', created_at: '2026-07-01T00:01:00Z' }
]

describe('AuditLogViewer', () => {
  it('shows the empty state when there are no entries', () => {
    const wrapper = mount(AuditLogViewer, { props: { entries: [] } })
    expect(wrapper.text()).toContain('No audit events recorded yet.')
  })

  it('renders one row per entry', () => {
    const wrapper = mount(AuditLogViewer, { props: { entries } })
    expect(wrapper.findAll('[data-testid="audit-log-row"]')).toHaveLength(2)
  })

  it('shows a loading state', () => {
    const wrapper = mount(AuditLogViewer, { props: { entries: [], loading: true } })
    expect(wrapper.text()).toContain('Loading audit log')
  })

  it('only shows a details toggle for entries with metadata', () => {
    const wrapper = mount(AuditLogViewer, { props: { entries } })
    expect(wrapper.findAll('[data-testid="audit-log-details-toggle"]')).toHaveLength(1)
  })

  it('expands metadata as pretty-printed JSON on click', async () => {
    const wrapper = mount(AuditLogViewer, { props: { entries } })
    expect(wrapper.find('[data-testid="audit-log-details"]').exists()).toBe(false)

    await wrapper.get('[data-testid="audit-log-details-toggle"]').trigger('click')
    expect(wrapper.get('[data-testid="audit-log-details"]').text()).toContain('"path": "a.md"')

    await wrapper.get('[data-testid="audit-log-details-toggle"]').trigger('click')
    expect(wrapper.find('[data-testid="audit-log-details"]').exists()).toBe(false)
  })
})
