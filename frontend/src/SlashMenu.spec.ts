import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import SlashMenu from './components/SlashMenu.vue'

describe('SlashMenu Component', () => {
  it('renders block items from registry when open', () => {
    const wrapper = mount(SlashMenu, {
      props: { isOpen: true, filterQuery: '' }
    })

    expect(wrapper.text()).toContain('Insert Block')
    expect(wrapper.text()).toContain('To-do List')
    expect(wrapper.text()).toContain('Code Block')
    expect(wrapper.text()).toContain('Callout Box')
  })

  it('filters block items based on query', () => {
    const wrapper = mount(SlashMenu, {
      props: { isOpen: true, filterQuery: 'code' }
    })

    expect(wrapper.text()).toContain('Code Block')
    expect(wrapper.text()).not.toContain('To-do List')
  })

  it('emits select event when item is clicked', async () => {
    const wrapper = mount(SlashMenu, {
      props: { isOpen: true, filterQuery: '' }
    })

    await wrapper.find('.slash-menu-item').trigger('click')
    expect(wrapper.emitted('select')).toBeTruthy()
  })
})
