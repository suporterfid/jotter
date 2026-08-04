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
      ],
      tags: [{ id: 1, name: 'urgent' }]
    },
    {
      id: 2, path: 'b.md', title: 'B',
      properties: [
        { id: 2, note_id: 2, name: 'status', type: 'string', value_string: 'done', value_numeric: null, value_boolean: null, value_datetime: null, value_json: null }
      ],
      tags: [{ id: 2, name: 'work' }]
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

  it('shows an add-card form per column, closed by default', () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status' } })
    expect(wrapper.findAll('[data-testid="board-add-card-button"]')).toHaveLength(3)
    expect(wrapper.find('[data-testid="board-add-card-input"]').exists()).toBe(false)
  })

  it('emits create-card with the title and column key on submit, then closes the form', async () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status' } })
    const draftColumn = wrapper.findAll('[data-testid="board-column"]')
      .find(c => c.text().includes('draft'))!
    await draftColumn.get('[data-testid="board-add-card-button"]').trigger('click')
    await draftColumn.get('[data-testid="board-add-card-input"]').setValue('New task')
    await draftColumn.get('[data-testid="board-add-card-form"]').trigger('submit')
    expect(wrapper.emitted('create-card')![0]).toEqual(['New task', 'draft', null])
    expect(draftColumn.find('[data-testid="board-add-card-input"]').exists()).toBe(false)
  })

  it('shows a cover image, due date, checklist progress, and comment count on the card face', () => {
    const richPage: CollectionPage = {
      ...page,
      data: [
        {
          id: 4, path: 'd.md', title: 'D',
          properties: [
            { id: 3, note_id: 4, name: 'status', type: 'string', value_string: 'draft', value_numeric: null, value_boolean: null, value_datetime: null, value_json: null },
            { id: 4, note_id: 4, name: 'cover', type: 'string', value_string: 'https://example.com/cover.png', value_numeric: null, value_boolean: null, value_datetime: null, value_json: null },
            { id: 5, note_id: 4, name: 'due', type: 'datetime', value_string: null, value_numeric: null, value_boolean: null, value_datetime: '2026-08-10', value_json: null },
          ],
          checklist_total: 3,
          checklist_done: 1,
          comments_count: 2,
        },
      ],
    }
    const wrapper = mount(CollectionsBoardView, { props: { page: richPage, groupProperty: 'status' } })
    expect(wrapper.find('[data-testid="board-card-cover"]').attributes('src')).toBe('https://example.com/cover.png')
    expect(wrapper.find('[data-testid="board-card-due"]').text()).toContain('2026-08-10')
    expect(wrapper.find('[data-testid="board-card-checklist"]').text()).toContain('1/3')
    expect(wrapper.find('[data-testid="board-card-comments"]').text()).toContain('2')
  })

  it('shows an archive toggle button on every card and emits toggle-archive with the note id', async () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status' } })
    const buttons = wrapper.findAll('[data-testid="board-card-archive-toggle"]')
    expect(buttons.length).toBeGreaterThan(0)
    await buttons[0].trigger('click')
    expect(wrapper.emitted('toggle-archive')).toBeTruthy()
  })

  it('shows an archived badge for archived notes', () => {
    const archivedPage: CollectionPage = {
      ...page,
      data: [{
        id: 7, path: 'g.md', title: 'G',
        properties: [
          { id: 10, note_id: 7, name: 'archived', type: 'boolean', value_string: null, value_numeric: null, value_boolean: true, value_datetime: null, value_json: null },
        ],
      }],
    }
    const wrapper = mount(CollectionsBoardView, { props: { page: archivedPage, groupProperty: 'status' } })
    expect(wrapper.find('[data-testid="board-card-archived-badge"]').exists()).toBe(true)
  })

  it('shows a show-archived checkbox and emits archived-filter-change', async () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status' } })
    await wrapper.get('[data-testid="board-show-archived"]').setValue(true)
    expect(wrapper.emitted('archived-filter-change')![0]).toEqual([true])
  })

  it('shows tag pills on the card face', () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status' } })
    expect(wrapper.find('[data-testid="board-card-tag"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('urgent')
    expect(wrapper.text()).toContain('work')
  })

  it('offers a tag filter with every tag present on the page', () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status' } })
    const options = wrapper.findAll('[data-testid="board-tag-filter"] option').map(o => o.text())
    expect(options).toContain('urgent')
    expect(options).toContain('work')
  })

  it('filters cards to only those carrying the selected tag', async () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status' } })
    await wrapper.get('[data-testid="board-tag-filter"]').setValue('urgent')
    const cards = wrapper.findAll('[data-testid="board-card"]')
    expect(cards).toHaveLength(1)
    expect(cards[0].text()).toContain('A')
  })

  it('does not show column config controls by default', () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status' } })
    expect(wrapper.find('[data-testid="board-column-move-right"]').exists()).toBe(false)
  })

  it('shows reorder, collapse, and edit controls per column when configurable', () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status', configurable: true } })
    expect(wrapper.findAll('[data-testid="board-column-move-left"]').length).toBeGreaterThan(0)
    expect(wrapper.findAll('[data-testid="board-column-move-right"]').length).toBeGreaterThan(0)
    expect(wrapper.findAll('[data-testid="board-column-collapse-toggle"]').length).toBe(3)
    expect(wrapper.findAll('[data-testid="board-column-edit-button"]').length).toBe(3)
  })

  it('emits reorder-columns with the swapped key order', async () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status', configurable: true } })
    const firstColumn = wrapper.findAll('[data-testid="board-column"]')[0]
    await firstColumn.get('[data-testid="board-column-move-right"]').trigger('click')
    expect(wrapper.emitted('reorder-columns')![0][0]).toEqual(['draft', 'done', 'No value'])
  })

  it('emits toggle-column-collapse with the column key', async () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status', configurable: true } })
    const firstColumn = wrapper.findAll('[data-testid="board-column"]')[0]
    await firstColumn.get('[data-testid="board-column-collapse-toggle"]').trigger('click')
    expect(wrapper.emitted('toggle-column-collapse')).toBeTruthy()
  })

  it('hides the column body when the column is collapsed', () => {
    const wrapper = mount(CollectionsBoardView, {
      props: {
        page, groupProperty: 'status', configurable: true,
        columnConfig: [{ key: 'draft', collapsed: true }],
      },
    })
    const draftColumn = wrapper.findAll('[data-testid="board-column"]').find(c => c.text().includes('draft'))!
    expect(draftColumn.find('[data-testid="board-column-body"]').exists()).toBe(false)
  })

  it('emits update-column with the edited label, color, and wip limit', async () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status', configurable: true } })
    const draftColumn = wrapper.findAll('[data-testid="board-column"]').find(c => c.text().includes('draft'))!
    await draftColumn.get('[data-testid="board-column-edit-button"]').trigger('click')
    await draftColumn.get('[data-testid="board-column-label-input"]').setValue('In Progress')
    await draftColumn.get('[data-testid="board-column-wip-input"]').setValue('2')
    await draftColumn.get('[data-testid="board-column-edit-form"]').trigger('submit')
    expect(wrapper.emitted('update-column')![0]).toEqual(['draft', { label: 'In Progress', color: null, wip_limit: 2, auto_archive: false }])
  })

  it('emits update-column with auto_archive when the checkbox is set', async () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status', configurable: true } })
    const draftColumn = wrapper.findAll('[data-testid="board-column"]').find(c => c.text().includes('draft'))!
    await draftColumn.get('[data-testid="board-column-edit-button"]').trigger('click')
    await draftColumn.get('[data-testid="board-column-auto-archive-checkbox"]').setValue(true)
    await draftColumn.get('[data-testid="board-column-edit-form"]').trigger('submit')
    expect(wrapper.emitted('update-column')![0][1]).toMatchObject({ auto_archive: true })
  })

  it('flags a column over its WIP limit', () => {
    const wrapper = mount(CollectionsBoardView, {
      props: {
        page, groupProperty: 'status', configurable: true,
        columnConfig: [{ key: 'draft', wip_limit: 0 }],
      },
    })
    const draftColumn = wrapper.findAll('[data-testid="board-column"]').find(c => c.text().includes('draft'))!
    expect(draftColumn.find('[data-testid="board-column-wip-warning"]').exists()).toBe(true)
  })

  it('renders a single unlabeled row when no swimlane property is set', () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status' } })
    expect(wrapper.findAll('[data-testid="board-row"]')).toHaveLength(1)
    expect(wrapper.find('[data-testid="board-row-label"]').exists()).toBe(false)
  })

  it('emits swimlane-change with the trimmed property name on change', async () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status' } })
    await wrapper.get('[data-testid="board-swimlane-property"]').setValue('  assignee  ')
    await wrapper.get('[data-testid="board-swimlane-property"]').trigger('change')
    expect(wrapper.emitted('swimlane-change')![0]).toEqual(['assignee'])
  })

  it('splits notes into one row per swimlane value, each with the same columns', () => {
    const swimlanePage: CollectionPage = {
      ...page,
      data: [
        {
          id: 5, path: 'e.md', title: 'E',
          properties: [
            { id: 6, note_id: 5, name: 'status', type: 'string', value_string: 'draft', value_numeric: null, value_boolean: null, value_datetime: null, value_json: null },
            { id: 7, note_id: 5, name: 'assignee', type: 'string', value_string: 'alice', value_numeric: null, value_boolean: null, value_datetime: null, value_json: null },
          ],
        },
        {
          id: 6, path: 'f.md', title: 'F',
          properties: [
            { id: 8, note_id: 6, name: 'status', type: 'string', value_string: 'done', value_numeric: null, value_boolean: null, value_datetime: null, value_json: null },
            { id: 9, note_id: 6, name: 'assignee', type: 'string', value_string: 'bob', value_numeric: null, value_boolean: null, value_datetime: null, value_json: null },
          ],
        },
      ],
    }
    const wrapper = mount(CollectionsBoardView, {
      props: { page: swimlanePage, groupProperty: 'status', swimlaneProperty: 'assignee' },
    })
    const rows = wrapper.findAll('[data-testid="board-row"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('alice')
    expect(rows[0].findAll('[data-testid="board-column"]')).toHaveLength(2)
    const aliceCards = rows[0].findAll('[data-testid="board-card"]')
    expect(aliceCards).toHaveLength(1)
    expect(aliceCards[0].text()).toContain('E')
  })

  it('emits create-card with the row value alongside the column value', async () => {
    const swimlanePage: CollectionPage = {
      ...page,
      data: [
        {
          id: 5, path: 'e.md', title: 'E',
          properties: [
            { id: 6, note_id: 5, name: 'status', type: 'string', value_string: 'draft', value_numeric: null, value_boolean: null, value_datetime: null, value_json: null },
            { id: 7, note_id: 5, name: 'assignee', type: 'string', value_string: 'alice', value_numeric: null, value_boolean: null, value_datetime: null, value_json: null },
          ],
        },
      ],
    }
    const wrapper = mount(CollectionsBoardView, {
      props: { page: swimlanePage, groupProperty: 'status', swimlaneProperty: 'assignee' },
    })
    const aliceRow = wrapper.findAll('[data-testid="board-row"]')[0]
    const draftColumn = aliceRow.findAll('[data-testid="board-column"]').find(c => c.text().includes('draft'))!
    await draftColumn.get('[data-testid="board-add-card-button"]').trigger('click')
    await draftColumn.get('[data-testid="board-add-card-input"]').setValue('New task')
    await draftColumn.get('[data-testid="board-add-card-form"]').trigger('submit')
    expect(wrapper.emitted('create-card')![0]).toEqual(['New task', 'draft', 'alice'])
  })

  it('does not emit create-card for a blank title', async () => {
    const wrapper = mount(CollectionsBoardView, { props: { page, groupProperty: 'status' } })
    const draftColumn = wrapper.findAll('[data-testid="board-column"]')
      .find(c => c.text().includes('draft'))!
    await draftColumn.get('[data-testid="board-add-card-button"]').trigger('click')
    await draftColumn.get('[data-testid="board-add-card-form"]').trigger('submit')
    expect(wrapper.emitted('create-card')).toBeFalsy()
  })
})
