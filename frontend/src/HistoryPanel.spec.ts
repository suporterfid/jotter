import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import HistoryPanel from './components/HistoryPanel.vue'

const revisions = [
  { id: 2, note_id: 1, content_hash: 'abc123def456', actor_id: null, created_at: '2026-07-02T00:00:00Z' },
  { id: 1, note_id: 1, content_hash: '789xyz000111', actor_id: null, created_at: '2026-07-01T00:00:00Z' }
]

describe('HistoryPanel', () => {
  it('shows the empty state when there are no revisions', () => {
    const wrapper = mount(HistoryPanel, {
      props: { revisions: [], selectedRevisionId: null, previewContent: null }
    })
    expect(wrapper.text()).toContain('No saved revisions yet.')
  })

  it('lists one entry per revision', () => {
    const wrapper = mount(HistoryPanel, {
      props: { revisions, selectedRevisionId: null, previewContent: null }
    })
    expect(wrapper.findAll('[data-testid="revision-item"]')).toHaveLength(2)
  })

  it('emits select-revision when a revision row is clicked', async () => {
    const wrapper = mount(HistoryPanel, {
      props: { revisions, selectedRevisionId: null, previewContent: null }
    })
    await wrapper.findAll('[data-testid="revision-item"]')[0].trigger('click')
    expect(wrapper.emitted('select-revision')![0]).toEqual([2])
  })

  it('shows the preview content and restore button once a revision is selected', () => {
    const wrapper = mount(HistoryPanel, {
      props: { revisions, selectedRevisionId: 2, previewContent: '# Old content' }
    })
    expect(wrapper.get('[data-testid="revision-preview"]').text()).toContain('# Old content')
    expect(wrapper.find('[data-testid="revision-restore-btn"]').exists()).toBe(true)
  })

  it('emits restore-revision with the selected id on restore click', async () => {
    const wrapper = mount(HistoryPanel, {
      props: { revisions, selectedRevisionId: 1, previewContent: 'old body' }
    })
    await wrapper.get('[data-testid="revision-restore-btn"]').trigger('click')
    expect(wrapper.emitted('restore-revision')![0]).toEqual([1])
  })

  it('emits close when the backdrop is clicked', async () => {
    const wrapper = mount(HistoryPanel, {
      attachTo: document.body,
      props: { revisions, selectedRevisionId: null, previewContent: null }
    })
    await wrapper.get('.modal-overlay').trigger('click')
    expect(wrapper.emitted('close')).toBeTruthy()
    wrapper.unmount()
  })
})
