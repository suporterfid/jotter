import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import AttachmentsPanel from './components/AttachmentsPanel.vue'

const attachment = {
  id: 1,
  workspace_id: 1,
  path: 'inbox/photo.png',
  mime: 'image/png',
  size: 2048,
  created_at: '2026-07-01T00:00:00Z',
  url: '/api/workspaces/1/attachments/inbox/photo.png'
}

describe('AttachmentsPanel', () => {
  it('shows the empty state when there are no attachments', () => {
    const wrapper = mount(AttachmentsPanel, { props: { attachments: [] } })
    expect(wrapper.text()).toContain('No attachments yet.')
  })

  it('renders one card per attachment with filename and size', () => {
    const wrapper = mount(AttachmentsPanel, { props: { attachments: [attachment] } })
    expect(wrapper.text()).toContain('photo.png')
    expect(wrapper.text()).toContain('2.0 KB')
  })

  it('emits delete-attachment with the full attachment on delete click', async () => {
    const wrapper = mount(AttachmentsPanel, { props: { attachments: [attachment] } })
    await wrapper.get('[data-testid="attachment-delete-btn"]').trigger('click')

    const events = wrapper.emitted('delete-attachment')
    expect(events).toBeTruthy()
    expect(events![0][0]).toEqual(attachment)
  })

  it('shows a loading state', () => {
    const wrapper = mount(AttachmentsPanel, { props: { attachments: [], loading: true } })
    expect(wrapper.text()).toContain('Loading attachments')
  })
})
