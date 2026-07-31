import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import CommandPalette from './components/CommandPalette.vue'

describe('CommandPalette Component', () => {
  it('renders command palette items when opened', async () => {
    const notes = [
      { id: 1, path: 'project.md', title: 'Project Plan', frontmatter: null, sort_position: null, updated_at: '2026-07-27' }
    ]
    const wrapper = mount(CommandPalette, {
      props: { notes },
      attachTo: document.body
    })

    const vm = wrapper.vm as any
    vm.open()
    await wrapper.vm.$nextTick()

    const modal = document.querySelector('.command-palette-modal')
    expect(modal).not.toBeNull()
    expect(document.body.textContent).toContain('Create New Note')
    expect(document.body.textContent).toContain('Project Plan')
  })
})
