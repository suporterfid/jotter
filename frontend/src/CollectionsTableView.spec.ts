import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import CollectionsTableView from './components/CollectionsTableView.vue'
import type { CollectionPage } from './services/types'

const emptyPage: CollectionPage = { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0 }

const page: CollectionPage = {
  data: [
    {
      id: 1, path: 'a.md', title: 'A',
      properties: [
        { id: 1, note_id: 1, name: 'status', type: 'string', value_string: 'draft', value_numeric: null, value_boolean: null, value_datetime: null, value_json: null },
        { id: 2, note_id: 1, name: 'priority', type: 'numeric', value_string: null, value_numeric: 3, value_boolean: null, value_datetime: null, value_json: null }
      ]
    },
    {
      id: 2, path: 'b.md', title: 'B',
      properties: [
        { id: 3, note_id: 2, name: 'status', type: 'string', value_string: 'done', value_numeric: null, value_boolean: null, value_datetime: null, value_json: null }
      ]
    }
  ],
  current_page: 1,
  last_page: 1,
  per_page: 50,
  total: 2
}

describe('CollectionsTableView', () => {
  it('uses logical alignment for table headers', () => {
    const source = readFileSync('src/components/CollectionsTableView.vue', 'utf8')
    expect(source).toMatch(/\.collections-table th\s*\{[\s\S]*?text-align: start/)
  })

  it('shows the empty state when there are no notes', () => {
    const wrapper = mount(CollectionsTableView, { props: { page: emptyPage } })
    expect(wrapper.text()).toContain('No notes match this view.')
  })

  it('derives property columns as the union of all notes\' property names', () => {
    const wrapper = mount(CollectionsTableView, { props: { page } })
    const headerText = wrapper.get('thead').text()
    expect(headerText).toContain('priority')
    expect(headerText).toContain('status')
  })

  it('renders a dash for a note missing a given property column', () => {
    const wrapper = mount(CollectionsTableView, { props: { page } })
    const rows = wrapper.findAll('[data-testid="collection-row"]')
    expect(rows[1].text()).toContain('—')
  })

  it('emits select-note when a row is clicked', async () => {
    const wrapper = mount(CollectionsTableView, { props: { page } })
    await wrapper.findAll('[data-testid="collection-row"]')[0].trigger('click')
    expect(wrapper.emitted('select-note')![0]).toEqual([1])
  })

  it('emits sort with the clicked column key', async () => {
    const wrapper = mount(CollectionsTableView, { props: { page } })
    const priorityHeaderBtn = wrapper.findAll('.sort-btn').find(b => b.text().includes('priority'))
    await priorityHeaderBtn!.trigger('click')
    expect(wrapper.emitted('sort')![0]).toEqual(['priority'])
  })

  it('emits filter-change with trimmed property/value on submit', async () => {
    const wrapper = mount(CollectionsTableView, { props: { page } })
    await wrapper.get('[data-testid="collection-filter-property"]').setValue('  status  ')
    await wrapper.get('[data-testid="collection-filter-value"]').setValue('  draft  ')
    await wrapper.get('.filter-bar').trigger('submit')
    expect(wrapper.emitted('filter-change')![0]).toEqual(['status', 'draft'])
  })

  it('shows pagination controls only when there is more than one page', () => {
    const wrapper = mount(CollectionsTableView, { props: { page } })
    expect(wrapper.find('[data-testid="collection-next-page"]').exists()).toBe(false)

    const multiPage = { ...page, last_page: 3 }
    const wrapper2 = mount(CollectionsTableView, { props: { page: multiPage } })
    expect(wrapper2.find('[data-testid="collection-next-page"]').exists()).toBe(true)
  })

  it('emits page-change when next is clicked', async () => {
    const multiPage = { ...page, last_page: 3, current_page: 1 }
    const wrapper = mount(CollectionsTableView, { props: { page: multiPage } })
    await wrapper.get('[data-testid="collection-next-page"]').trigger('click')
    expect(wrapper.emitted('page-change')![0]).toEqual([2])
  })
})
