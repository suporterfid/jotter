import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import Sidebar from './components/Sidebar.vue'

const baseProps = { notes: [], selectedNoteId: null, currentUser: null, workspaces: [] }

const notifications = [
  { id: 1, workspace_id: 1, user_id: 1, type: 'mention', title: 'You were mentioned', data: { comment_snippet: 'hey check this' }, read_at: null, created_at: '2026-07-01T00:00:00Z' },
  { id: 2, workspace_id: 1, user_id: 1, type: 'mention', title: 'Another mention', data: null, read_at: '2026-07-02T00:00:00Z', created_at: '2026-07-01T00:00:00Z' }
]

describe('Sidebar notifications', () => {
  it('does not show a badge when there are no unread notifications', () => {
    const wrapper = mount(Sidebar, { props: { ...baseProps, notifications: [notifications[1]] } })
    expect(wrapper.find('[data-testid="notification-badge"]').exists()).toBe(false)
  })

  it('shows the unread count badge', () => {
    const wrapper = mount(Sidebar, { props: { ...baseProps, notifications } })
    expect(wrapper.get('[data-testid="notification-badge"]').text()).toBe('1')
  })

  it('lists notifications when the bell is clicked', async () => {
    const wrapper = mount(Sidebar, { props: { ...baseProps, notifications } })
    await wrapper.get('[data-testid="notifications-btn"]').trigger('click')
    expect(wrapper.findAll('[data-testid="notification-item"]')).toHaveLength(2)
  })

  it('emits mark-notification-read when an unread notification body is clicked', async () => {
    const wrapper = mount(Sidebar, { props: { ...baseProps, notifications } })
    await wrapper.get('[data-testid="notifications-btn"]').trigger('click')
    await wrapper.get('.notification-body').trigger('click')
    expect(wrapper.emitted('mark-notification-read')![0]).toEqual([1])
  })

  it('emits delete-notification when dismiss is clicked', async () => {
    const wrapper = mount(Sidebar, { props: { ...baseProps, notifications } })
    await wrapper.get('[data-testid="notifications-btn"]').trigger('click')
    await wrapper.findAll('[data-testid="notification-delete-btn"]')[0].trigger('click')
    expect(wrapper.emitted('delete-notification')![0]).toEqual([1])
  })
})
