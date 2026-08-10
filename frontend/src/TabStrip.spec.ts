import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import TabStrip from './components/TabStrip.vue'

const source = readFileSync('src/components/TabStrip.vue', 'utf8')

describe('TabStrip identity layout', () => {
  it('uses a logical divider for its vertical navigation edge', () => {
    expect(source).toContain('border-inline-end: 1px solid var(--color-border)')
  })
})

const tabs = [
  { id: 1, title: 'First Note' },
  { id: 2, title: 'Second Note' },
]

describe('TabStrip', () => {
  it('renders nothing when there are no tabs', () => {
    const wrapper = mount(TabStrip, { props: { tabs: [], activeId: null } })
    expect(wrapper.find('.tab-strip').exists()).toBe(false)
  })

  it('renders one item per tab with the active one styled', () => {
    const wrapper = mount(TabStrip, { props: { tabs, activeId: 2 } })
    const items = wrapper.findAll('[data-testid="tab-strip-item"]')
    expect(items).toHaveLength(2)
    expect(items[0].classes()).not.toContain('active')
    expect(items[1].classes()).toContain('active')
  })

  it('renders as a vertical column, not a horizontal row', () => {
    const wrapper = mount(TabStrip, { props: { tabs, activeId: 1 } })
    expect(wrapper.find('.tab-strip').classes()).toContain('tab-strip-vertical')
  })

  it('emits select-tab when a tab is clicked', async () => {
    const wrapper = mount(TabStrip, { props: { tabs, activeId: 1 } })
    await wrapper.findAll('[data-testid="tab-strip-item"]')[1].trigger('click')
    expect(wrapper.emitted('select-tab')![0]).toEqual([2])
  })

  it('emits close-tab without also emitting select-tab when the close button is clicked', async () => {
    const wrapper = mount(TabStrip, { props: { tabs, activeId: 1 } })
    await wrapper.findAll('[data-testid="tab-strip-close-btn"]')[0].trigger('click')
    expect(wrapper.emitted('close-tab')![0]).toEqual([1])
    expect(wrapper.emitted('select-tab')).toBeUndefined()
  })
})
