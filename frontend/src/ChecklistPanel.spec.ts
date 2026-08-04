import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import ChecklistPanel from './components/ChecklistPanel.vue'
import type { NoteChecklistItem } from './services/types'

const items: NoteChecklistItem[] = [
  { id: 1, note_id: 1, text: 'Write tests', done: true, sort_position: 0 },
  { id: 2, note_id: 1, text: 'Ship it', done: false, sort_position: 1 },
]

describe('ChecklistPanel', () => {
  it('shows an empty state when there are no items', () => {
    const wrapper = mount(ChecklistPanel, { props: { items: [] } })
    expect(wrapper.text()).toContain('No checklist items yet.')
  })

  it('renders each item with its checked state', () => {
    const wrapper = mount(ChecklistPanel, { props: { items } })
    const rows = wrapper.findAll('[data-testid="checklist-item"]')
    expect(rows).toHaveLength(2)
    expect(rows[0].text()).toContain('Write tests')
    expect((rows[0].get('[data-testid="checklist-item-checkbox"]').element as HTMLInputElement).checked).toBe(true)
    expect((rows[1].get('[data-testid="checklist-item-checkbox"]').element as HTMLInputElement).checked).toBe(false)
  })

  it('shows progress as done/total', () => {
    const wrapper = mount(ChecklistPanel, { props: { items } })
    expect(wrapper.text()).toContain('1/2')
  })

  it('emits toggle-item with the item id when a checkbox is clicked', async () => {
    const wrapper = mount(ChecklistPanel, { props: { items } })
    await wrapper.findAll('[data-testid="checklist-item-checkbox"]')[1].trigger('click')
    expect(wrapper.emitted('toggle-item')![0]).toEqual([2])
  })

  it('emits delete-item with the item id', async () => {
    const wrapper = mount(ChecklistPanel, { props: { items } })
    await wrapper.findAll('[data-testid="checklist-item-delete-btn"]')[0].trigger('click')
    expect(wrapper.emitted('delete-item')![0]).toEqual([1])
  })

  it('emits add-item with the trimmed text and clears the input', async () => {
    const wrapper = mount(ChecklistPanel, { props: { items } })
    await wrapper.get('[data-testid="checklist-item-input"]').setValue('  New item  ')
    await wrapper.get('[data-testid="checklist-item-form"]').trigger('submit')
    expect(wrapper.emitted('add-item')![0]).toEqual(['New item'])
    expect((wrapper.get('[data-testid="checklist-item-input"]').element as HTMLInputElement).value).toBe('')
  })

  it('does not emit add-item for a blank entry', async () => {
    const wrapper = mount(ChecklistPanel, { props: { items } })
    await wrapper.get('[data-testid="checklist-item-form"]').trigger('submit')
    expect(wrapper.emitted('add-item')).toBeFalsy()
  })
})
