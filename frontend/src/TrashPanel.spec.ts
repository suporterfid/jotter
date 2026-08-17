import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import TrashPanel from './components/TrashPanel.vue'
import type { TrashNoteMeta } from './services/types'

const deletedNote: TrashNoteMeta = {
  id: 7,
  title: 'Deleted note',
  original_path: 'docs/deleted.md',
  frontmatter: null,
  deleted_at: '2026-08-17T15:30:00Z',
}

describe('TrashPanel', () => {
  it('shows the original path and does not expose the internal trash path', () => {
    const wrapper = mount(TrashPanel, {
      props: { notes: [deletedNote] },
    })

    expect(wrapper.find('[data-testid="trash-note-row"]').text()).toContain('docs/deleted.md')
    expect(wrapper.text()).not.toContain('.trash')
  })

  it('emits restore-note for the selected note', async () => {
    const wrapper = mount(TrashPanel, { props: { notes: [deletedNote] } })

    await wrapper.find('[data-testid="trash-restore-btn"]').trigger('click')

    expect(wrapper.emitted('restore-note')).toEqual([[7]])
  })

  it('emits permanently-delete-note only after confirmation', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(TrashPanel, { props: { notes: [deletedNote] } })

    await wrapper.find('[data-testid="trash-permanent-delete-btn"]').trigger('click')

    expect(confirm).toHaveBeenCalled()
    expect(wrapper.emitted('permanently-delete-note')).toEqual([[7]])
    confirm.mockRestore()
  })
})
