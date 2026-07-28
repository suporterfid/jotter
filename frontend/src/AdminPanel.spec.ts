import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import AdminPanel from './components/AdminPanel.vue'

describe('AdminPanel Component', () => {
  it('renders tabs and closes on overlay click', async () => {
    const wrapper = mount(AdminPanel, {
      props: { isOpen: true }
    })

    expect(wrapper.text()).toContain('Administration')
    expect(wrapper.text()).toContain('Workspaces')
    expect(wrapper.text()).toContain('Members')
    expect(wrapper.text()).toContain('Users')

    await wrapper.find('.admin-modal-overlay').trigger('click.self')
    expect(wrapper.emitted('close')).toBeTruthy()
  })
})
