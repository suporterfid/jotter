import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WorkspaceSwitcher from './components/WorkspaceSwitcher.vue'

const workspaces = [
  { id: 1, tenant_id: 1, slug: 'main', name: 'Main Workspace' },
  { id: 2, tenant_id: 1, slug: 'side', name: 'Side Project' },
]

describe('WorkspaceSwitcher', () => {
  it('renders one option per workspace', () => {
    const wrapper = mount(WorkspaceSwitcher, { props: { workspaces, activeWorkspaceId: 1 } })
    const options = wrapper.findAll('option')
    expect(options).toHaveLength(2)
    expect(options[0].text()).toBe('Main Workspace')
    expect(options[1].text()).toBe('Side Project')
  })

  it('selects the active workspace by default', () => {
    const wrapper = mount(WorkspaceSwitcher, { props: { workspaces, activeWorkspaceId: 2 } })
    expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('2')
  })

  it('emits switch with the chosen workspace id', async () => {
    const wrapper = mount(WorkspaceSwitcher, { props: { workspaces, activeWorkspaceId: 1 } })
    await wrapper.find('select').setValue('2')
    expect(wrapper.emitted('switch')![0]).toEqual([2])
  })

  it('renders correctly with a single workspace', () => {
    const wrapper = mount(WorkspaceSwitcher, {
      props: { workspaces: [workspaces[0]], activeWorkspaceId: 1 },
    })
    expect(wrapper.findAll('option')).toHaveLength(1)
    expect(wrapper.find('select').exists()).toBe(true)
  })
})
