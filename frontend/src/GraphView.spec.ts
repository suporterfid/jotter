import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import GraphView from './components/GraphView.vue'

describe('GraphView Component', () => {
  it('renders notes as graph nodes and handles node selection', async () => {
    const notes = [
      { id: 1, path: 'note1.md', title: 'Note 1', frontmatter: null, updated_at: '2026-07-27' },
      { id: 2, path: 'note2.md', title: 'Note 2', frontmatter: null, updated_at: '2026-07-27' }
    ]

    const wrapper = mount(GraphView, {
      props: {
        notes,
        activeNoteId: 1
      }
    })

    expect(wrapper.find('.graph-container').exists()).toBe(true)
    expect(wrapper.text()).toContain('Vault Relationship Graph')
    expect(wrapper.findAll('.graph-node-group').length).toBe(2)

    await wrapper.find('.graph-node-group').trigger('click')
    expect(wrapper.emitted('select-note')?.[0]).toEqual([1])
  })
})
