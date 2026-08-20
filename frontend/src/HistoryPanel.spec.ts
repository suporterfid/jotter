import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import HistoryPanel from './components/HistoryPanel.vue'
import type { NoteRevisionComparison } from './services/types'

const source = readFileSync('src/components/HistoryPanel.vue', 'utf8')

describe('HistoryPanel identity layout', () => {
  it('uses a logical divider between revision list and preview', () => {
    expect(source).toContain('border-inline-end: 1px solid var(--color-border)')
    expect(source).toContain('new Intl.DateTimeFormat(locale.value')
  })
})

const revisions = [
  { id: 2, note_id: 1, content_hash: 'abc123def456', actor_id: null, created_at: '2026-07-02T00:00:00Z' },
  { id: 1, note_id: 1, content_hash: '789xyz000111', actor_id: null, created_at: '2026-07-01T00:00:00Z' }
]

const comparison: NoteRevisionComparison = {
  from: revisions[1],
  to: { ...revisions[0], id: null, created_at: null },
  changed: true,
  lines: [
    { type: 'context', from_line: 1, to_line: 1, text: 'same' },
    { type: 'removed', from_line: 2, to_line: null, text: 'old' },
    { type: 'added', from_line: null, to_line: 2, text: 'new' }
  ]
}

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

  it('emits a comparison request for the selected source and target', async () => {
    const wrapper = mount(HistoryPanel, {
      props: { revisions, selectedRevisionId: 2, previewContent: null }
    })
    await wrapper.get('[data-testid="revision-compare-btn"]').trigger('click')
    expect(wrapper.emitted('compare-revisions')![0]).toEqual([2, 'current'])
  })

  it('renders a line comparison with semantic change markers', () => {
    const wrapper = mount(HistoryPanel, {
      props: { revisions, selectedRevisionId: 2, previewContent: null, comparison }
    })
    expect(wrapper.get('[data-testid="revision-diff"]').text()).toContain('old')
    expect(wrapper.get('[data-testid="revision-diff"]').text()).toContain('new')
    expect(wrapper.findAll('[data-diff-type="removed"]')).toHaveLength(1)
    expect(wrapper.findAll('[data-diff-type="added"]')).toHaveLength(1)
  })
})
