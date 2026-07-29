import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CollectionsCalendarView from './components/CollectionsCalendarView.vue'
import type { CollectionPage } from './services/types'

const emptyPage: CollectionPage = { data: [], current_page: 1, last_page: 1, per_page: 50, total: 0 }

const page: CollectionPage = {
  data: [
    {
      id: 1, path: 'a.md', title: 'A',
      properties: [
        { id: 1, note_id: 1, name: 'due_date', type: 'datetime', value_string: null, value_numeric: null, value_boolean: null, value_datetime: '2026-08-01T00:00:00Z', value_json: null }
      ]
    },
    {
      id: 2, path: 'b.md', title: 'B',
      properties: [
        { id: 2, note_id: 2, name: 'due_date', type: 'datetime', value_string: null, value_numeric: null, value_boolean: null, value_datetime: '2026-08-01T09:00:00Z', value_json: null }
      ]
    },
    {
      id: 3, path: 'c.md', title: 'C',
      properties: [
        { id: 3, note_id: 3, name: 'due_date', type: 'datetime', value_string: null, value_numeric: null, value_boolean: null, value_datetime: '2026-08-03T00:00:00Z', value_json: null }
      ]
    },
    {
      id: 4, path: 'd.md', title: 'D',
      properties: []
    }
  ],
  current_page: 1,
  last_page: 1,
  per_page: 50,
  total: 4
}

describe('CollectionsCalendarView', () => {
  it('shows the empty state when there are no notes', () => {
    const wrapper = mount(CollectionsCalendarView, { props: { page: emptyPage } })
    expect(wrapper.text()).toContain('No notes match this view.')
  })

  it('prompts for a date property when none is chosen', () => {
    const wrapper = mount(CollectionsCalendarView, { props: { page } })
    expect(wrapper.text()).toContain('Choose a datetime property above')
  })

  it('groups notes by day for the chosen property, in date order, with a "No date" bucket', () => {
    const wrapper = mount(CollectionsCalendarView, { props: { page, dateProperty: 'due_date' } })
    const days = wrapper.findAll('[data-testid="calendar-day"]')
    expect(days).toHaveLength(3)
    expect(days[0].text()).toContain('2026-08-01')
    expect(days[1].text()).toContain('2026-08-03')
    expect(days[2].text()).toContain('No date')
  })

  it('emits select-note when a card is clicked', async () => {
    const wrapper = mount(CollectionsCalendarView, { props: { page, dateProperty: 'due_date' } })
    await wrapper.findAll('[data-testid="calendar-card"]')[0].trigger('click')
    expect(wrapper.emitted('select-note')).toBeTruthy()
  })

  it('emits date-property-change with the trimmed property name on submit', async () => {
    const wrapper = mount(CollectionsCalendarView, { props: { page } })
    await wrapper.get('[data-testid="calendar-date-property"]').setValue('  due_date  ')
    await wrapper.get('.group-bar').trigger('submit')
    expect(wrapper.emitted('date-property-change')![0]).toEqual(['due_date'])
  })

  it('shows pagination controls only when there is more than one page', () => {
    const wrapper = mount(CollectionsCalendarView, { props: { page, dateProperty: 'due_date' } })
    expect(wrapper.find('[data-testid="collection-next-page"]').exists()).toBe(false)

    const multiPage = { ...page, last_page: 3 }
    const wrapper2 = mount(CollectionsCalendarView, { props: { page: multiPage, dateProperty: 'due_date' } })
    expect(wrapper2.find('[data-testid="collection-next-page"]').exists()).toBe(true)
  })
})
