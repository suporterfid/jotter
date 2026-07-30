import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PanelHeader from './PanelHeader.vue'

describe('PanelHeader', () => {
  it('renders the title', () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks' } })
    expect(wrapper.text()).toContain('Backlinks')
  })

  it('renders the count badge when count is provided', () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks', count: 3 } })
    expect(wrapper.find('.panel-header-count').text()).toBe('3')
  })

  it('omits the count badge when count is undefined', () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks' } })
    expect(wrapper.find('.panel-header-count').exists()).toBe(false)
  })

  it('renders slotted icon content', () => {
    const wrapper = mount(PanelHeader, {
      props: { title: 'Backlinks' },
      slots: { icon: '<svg class="my-icon"></svg>' },
    })
    expect(wrapper.find('.my-icon').exists()).toBe(true)
  })
})
