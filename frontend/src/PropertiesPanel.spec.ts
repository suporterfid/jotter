import { mount } from '@vue/test-utils'
import { describe, expect, it, beforeEach } from 'vitest'
import PropertiesPanel from './components/PropertiesPanel.vue'

describe('PropertiesPanel', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('shows the empty state when there are no properties', () => {
    const wrapper = mount(PropertiesPanel, { props: { properties: [] } })
    expect(wrapper.text()).toContain('No typed properties on this note yet.')
  })

  it('renders one row per property with name, type, and value', () => {
    const wrapper = mount(PropertiesPanel, {
      props: { properties: [{ name: 'status', type: 'string', value: 'draft' }] }
    })
    const text = wrapper.text()
    expect(text).toContain('status')
    expect(text).toContain('string')
    expect(text).toContain('draft')
  })

  it('joins list values with commas', () => {
    const wrapper = mount(PropertiesPanel, {
      props: { properties: [{ name: 'tags', type: 'list', value: ['a', 'b', 'c'] }] }
    })
    expect(wrapper.text()).toContain('a, b, c')
  })

  it('emits delete-property with the property name', async () => {
    const wrapper = mount(PropertiesPanel, {
      props: { properties: [{ name: 'status', type: 'string', value: 'draft' }] }
    })
    await wrapper.get('[data-testid="property-delete-btn"]').trigger('click')
    expect(wrapper.emitted('delete-property')![0]).toEqual(['status'])
  })

  it('emits add-property with a numeric value coerced to a number', async () => {
    const wrapper = mount(PropertiesPanel, { props: { properties: [] } })

    await wrapper.get('[data-testid="property-name-input"]').setValue('priority')
    await wrapper.get('[data-testid="property-type-select"]').setValue('numeric')
    await wrapper.get('[data-testid="property-value-input"]').setValue('5')
    await wrapper.get('.property-form').trigger('submit')

    expect(wrapper.emitted('add-property')![0]).toEqual(['priority', 5])
  })

  it('emits add-property with a list value split on commas', async () => {
    const wrapper = mount(PropertiesPanel, { props: { properties: [] } })

    await wrapper.get('[data-testid="property-name-input"]').setValue('tags')
    await wrapper.get('[data-testid="property-type-select"]').setValue('list')
    await wrapper.get('[data-testid="property-value-input"]').setValue('a, b , c')
    await wrapper.get('.property-form').trigger('submit')

    expect(wrapper.emitted('add-property')![0]).toEqual(['tags', ['a', 'b', 'c']])
  })

  it('hides the body when collapsed', () => {
    const wrapper = mount(PropertiesPanel, { props: { properties: [] } })
    expect((wrapper.find('.properties-body').element as HTMLElement).style.display).toBe('none')
  })

  it('shows the body and toggles collapse when the header chevron is clicked', async () => {
    const wrapper = mount(PropertiesPanel, { props: { properties: [] } })
    await wrapper.find('[data-testid="panel-collapse-toggle"]').trigger('click')
    expect((wrapper.find('.properties-body').element as HTMLElement).style.display).not.toBe('none')
  })

  it('applies panel-collapsed to the root when collapsed (default)', () => {
    const wrapper = mount(PropertiesPanel, { props: { properties: [] } })
    expect(wrapper.find('.properties-panel').classes()).toContain('panel-collapsed')
  })

  it('does not apply panel-collapsed to the root once expanded', async () => {
    const wrapper = mount(PropertiesPanel, { props: { properties: [] } })
    await wrapper.find('[data-testid="panel-collapse-toggle"]').trigger('click')
    expect(wrapper.find('.properties-panel').classes()).not.toContain('panel-collapsed')
  })
})
