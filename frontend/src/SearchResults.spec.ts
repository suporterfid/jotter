import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SearchResults from './components/SearchResults.vue'

describe('SearchResults filters', () => {
  it('emits update:filters with a trimmed title on change', async () => {
    const wrapper = mount(SearchResults, {
      props: { query: 'demo', results: [], filters: {}, availableTags: [] }
    })

    const titleInput = wrapper.get('[data-testid="search-filter-title"]')
    await titleInput.setValue('  Meeting Notes  ')
    await titleInput.trigger('change')

    const events = wrapper.emitted('update:filters')
    expect(events).toBeTruthy()
    expect(events![events!.length - 1][0]).toEqual({
      title: 'Meeting Notes',
      modifiedAfter: undefined,
      modifiedBefore: undefined,
      tags: undefined
    })
  })

  it('toggles a tag on and off and emits the resulting tags array', async () => {
    const wrapper = mount(SearchResults, {
      props: { query: '', results: [], filters: {}, availableTags: ['work', 'personal'] }
    })

    const tagButtons = wrapper.findAll('[data-testid="search-filter-tag"]')
    expect(tagButtons).toHaveLength(2)

    await tagButtons[0].trigger('click')
    let events = wrapper.emitted('update:filters')!
    expect(events[events.length - 1][0]).toMatchObject({ tags: ['work'] })

    await tagButtons[0].trigger('click')
    events = wrapper.emitted('update:filters')!
    expect(events[events.length - 1][0]).toMatchObject({ tags: undefined })
  })

  it('clear filters resets title, dates, and tags', async () => {
    const wrapper = mount(SearchResults, {
      props: {
        query: '',
        results: [],
        filters: { title: 'foo', tags: ['work'], modifiedAfter: '2026-01-01' },
        availableTags: ['work']
      }
    })

    const clearBtn = wrapper.get('[data-testid="search-filter-clear"]')
    await clearBtn.trigger('click')

    const events = wrapper.emitted('update:filters')!
    expect(events[events.length - 1][0]).toEqual({
      title: undefined,
      modifiedAfter: undefined,
      modifiedBefore: undefined,
      tags: undefined
    })
  })

  it('does not show the clear-filters button when no filters are active', () => {
    const wrapper = mount(SearchResults, {
      props: { query: '', results: [], filters: {}, availableTags: [] }
    })

    expect(wrapper.find('[data-testid="search-filter-clear"]').exists()).toBe(false)
  })
})
