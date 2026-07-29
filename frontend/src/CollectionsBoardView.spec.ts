import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CollectionsBoardView from './components/CollectionsBoardView.vue'
import type { CollectionPage } from './services/types'

const emptyPage: CollectionPage = { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0 }

const page: CollectionPage = {
  data: [
    {
      id: 1, path: 'a.md', title: 'A',
      properties: [
        { id: 1, note_id: 1, name: 'status', type: 'string', value_string: 'draft', value_numeric: null, value_boolean: null, value_datetime: null, value_json: null }
      ]
    },
    {
      id: 2, path: 'b.md', title: 'B',
      properties: [
        { id: 2, note_id: 2, name: 'status', type: 'string', value_string: 'done', value_numeric: null, value_boolean: null, value_datetime: null, value_json: null }
      ]
    },
    {
      id: 3, path: 'c.md', title: 'C',
      properties: []
    }
  ],
  current_page: 1,
  last_page: 1,
  per_page: 50,
  total: 3
}

describe('CollectionsBoardView', () => {
  it('shows the empty state when there are no notes', () => {
    const wrapper = mount(CollectionsBoardView, { props: { page: emptyPage } })
    expect(wrapper.text()).toContain('No notes match this view.')
  })

  it('prompts for a group property when none is chosen', () => {
    const wrapper = mount(CollectionsBoardView, { props: { page } })
    expect(wrapper.text()).toContain('Choose a property above')
  })

  it('groups notes into columns by the chosen property, with a column for missing values', () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status' } })
    const columns = wrapper.findAll('[data-testid="board-column"]')
    expect(columns).toHaveLength(3)
    expect(wrapper.text()).toContain('draft')
    expect(wrapper.text()).toContain('done')
    expect(wrapper.text()).toContain('No value')
  })

  it('emits select-note when a card is clicked', async () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status' } })
    await wrapper.findAll('[data-testid="board-card"]')[0].trigger('click')
    expect(wrapper.emitted('select-note')).toBeTruthy()
  })

  it('emits group-change with the trimmed property name on submit', async () => {
    const wrapper = mount(CollectionsBoardView, { props: { page } })
    await wrapper.get('[data-testid="board-group-property"]').setValue('  status  ')
    await wrapper.get('.group-bar').trigger('submit')
    expect(wrapper.emitted('group-change')![0]).toEqual(['status'])
  })

  it('shows pagination controls only when there is more than one page', () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status' } })
    expect(wrapper.find('[data-testid="collection-next-page"]').exists()).toBe(false)

    const multiPage = { ...page, last_page: 3 }
    const wrapper2 = mount(CollectionsBoardView, { props: { page: multiPage, groupProperty: 'status' } })
    expect(wrapper2.find('[data-testid="collection-next-page"]').exists()).toBe(true)
  })

  it('emits page-change when next is clicked', async () => {
    const multiPage = { ...page, last_page: 3, current_page: 1 }
    const wrapper = mount(CollectionsBoardView, { props: { page: multiPage, groupProperty: 'status' } })
    await wrapper.get('[data-testid="collection-next-page"]').trigger('click')
    expect(wrapper.emitted('page-change')![0]).toEqual([2])
  })
})
