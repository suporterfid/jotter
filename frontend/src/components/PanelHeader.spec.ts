import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import PanelHeader from './PanelHeader.vue'

describe('PanelHeader', () => {
  it('renders the title', () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks', collapsed: false } })
    expect(wrapper.text()).toContain('Backlinks')
  })

  it('renders the count badge when count is provided', () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks', count: 3, collapsed: false } })
    expect(wrapper.find('.panel-header-count').text()).toBe('3')
  })

  it('omits the count badge when count is undefined', () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks', collapsed: false } })
    expect(wrapper.find('.panel-header-count').exists()).toBe(false)
  })

  it('renders slotted icon content', () => {
    const wrapper = mount(PanelHeader, {
      props: { title: 'Backlinks', collapsed: false },
      slots: { icon: '<svg class="my-icon"></svg>' },
    })
    expect(wrapper.find('.my-icon').exists()).toBe(true)
  })

  it('emits toggle when the chevron button is clicked', async () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks', collapsed: false } })
    await wrapper.find('[data-testid="panel-collapse-toggle"]').trigger('click')
    expect(wrapper.emitted('toggle')).toBeTruthy()
  })

  it('applies the collapsed class to the chevron when collapsed is true', () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks', collapsed: true } })
    expect(wrapper.find('[data-testid="panel-collapse-toggle"] .chevron').classes()).toContain('collapsed')
  })

  it('does not apply the collapsed class when collapsed is false', () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks', collapsed: false } })
    expect(wrapper.find('[data-testid="panel-collapse-toggle"] .chevron').classes()).not.toContain('collapsed')
  })

  it('applies panel-header-collapsed to the root when collapsed is true', () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks', collapsed: true } })
    expect(wrapper.find('.panel-header').classes()).toContain('panel-header-collapsed')
  })

  it('does not apply panel-header-collapsed to the root when collapsed is false', () => {
    const wrapper = mount(PanelHeader, { props: { title: 'Backlinks', collapsed: false } })
    expect(wrapper.find('.panel-header').classes()).not.toContain('panel-header-collapsed')
  })
})
