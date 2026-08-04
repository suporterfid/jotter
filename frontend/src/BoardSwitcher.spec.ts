import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import BoardSwitcher from './components/BoardSwitcher.vue'
import type { Board } from './services/types'

const boards: Board[] = [
  { id: 1, workspace_id: 1, name: 'Sprint', group_property: 'status', filter_property: null, filter_value: null, sort_position: 0 },
  { id: 2, workspace_id: 1, name: 'Roadmap', group_property: 'quarter', filter_property: null, filter_value: null, sort_position: 1 },
]

describe('BoardSwitcher', () => {
  it('lists an unsaved option plus every board', () => {
    const wrapper = mount(BoardSwitcher, { props: { boards, activeBoardId: null } })
    const options = wrapper.findAll('option').map(o => o.text())
    expect(options).toEqual(['Unsaved view', 'Sprint', 'Roadmap'])
  })

  it('emits select-board with the board id on change', async () => {
    const wrapper = mount(BoardSwitcher, { props: { boards, activeBoardId: null } })
    await wrapper.get('[data-testid="board-switcher-select"]').setValue('2')
    expect(wrapper.emitted('select-board')![0]).toEqual([2])
  })

  it('emits select-board with null when switching back to unsaved', async () => {
    const wrapper = mount(BoardSwitcher, { props: { boards, activeBoardId: 1 } })
    await wrapper.get('[data-testid="board-switcher-select"]').setValue('')
    expect(wrapper.emitted('select-board')![0]).toEqual([null])
  })

  it('emits create-board with the entered name', async () => {
    const wrapper = mount(BoardSwitcher, { props: { boards, activeBoardId: null } })
    await wrapper.get('[data-testid="board-new-button"]').trigger('click')
    await wrapper.get('[data-testid="board-new-input"]').setValue('Backlog')
    await wrapper.get('[data-testid="board-new-form"]').trigger('submit')
    expect(wrapper.emitted('create-board')![0]).toEqual(['Backlog'])
  })

  it('shows rename and delete controls only when a board is active', () => {
    const unsaved = mount(BoardSwitcher, { props: { boards, activeBoardId: null } })
    expect(unsaved.find('[data-testid="board-rename-button"]').exists()).toBe(false)
    expect(unsaved.find('[data-testid="board-delete-button"]').exists()).toBe(false)

    const active = mount(BoardSwitcher, { props: { boards, activeBoardId: 1 } })
    expect(active.find('[data-testid="board-rename-button"]').exists()).toBe(true)
    expect(active.find('[data-testid="board-delete-button"]').exists()).toBe(true)
  })

  it('emits rename-board with the new name', async () => {
    const wrapper = mount(BoardSwitcher, { props: { boards, activeBoardId: 1 } })
    await wrapper.get('[data-testid="board-rename-button"]').trigger('click')
    await wrapper.get('[data-testid="board-rename-input"]').setValue('Sprint 2')
    await wrapper.get('[data-testid="board-rename-form"]').trigger('submit')
    expect(wrapper.emitted('rename-board')![0]).toEqual([1, 'Sprint 2'])
  })

  it('emits delete-board when delete is confirmed', async () => {
    const wrapper = mount(BoardSwitcher, { props: { boards, activeBoardId: 1 } })
    await wrapper.get('[data-testid="board-delete-button"]').trigger('click')
    expect(wrapper.emitted('delete-board')![0]).toEqual([1])
  })
})
