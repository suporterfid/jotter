import { mount, flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import PropertiesPanel from './components/PropertiesPanel.vue'

const getWorkspaceProperties = vi.fn().mockResolvedValue([])

vi.mock('./services/api', () => ({
  getWorkspaceProperties: (...args: unknown[]) => getWorkspaceProperties(...args),
}))

describe('PropertiesPanel', () => {
  beforeEach(() => {
    localStorage.clear()
    vi.clearAllMocks()
    getWorkspaceProperties.mockResolvedValue([])
  })

  it('shows the empty state when there are no properties', () => {
    const wrapper = mount(PropertiesPanel, { props: { properties: [] } })
    expect(wrapper.text()).toContain('No typed properties on this note yet.')
  })

  it('renders one row per property with name and value, but no type badge', () => {
    const wrapper = mount(PropertiesPanel, {
      props: { properties: [{ name: 'status', type: 'string', value: 'draft' }] }
    })
    const text = wrapper.text()
    expect(text).toContain('status')
    expect(text).toContain('draft')
    expect(wrapper.find('.property-type').exists()).toBe(false)
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

  it('clicking a string value opens a text input pre-filled with the current value', async () => {
    const wrapper = mount(PropertiesPanel, {
      props: { properties: [{ name: 'status', type: 'string', value: 'draft' }] }
    })
    await wrapper.get('[data-testid="property-value-btn"]').trigger('click')
    const input = wrapper.get('[data-testid="property-value-edit-input"]')
    expect((input.element as HTMLInputElement).value).toBe('draft')
  })

  it('commits an edited string value on Enter and closes the editor', async () => {
    const wrapper = mount(PropertiesPanel, {
      props: { properties: [{ name: 'status', type: 'string', value: 'draft' }] }
    })
    await wrapper.get('[data-testid="property-value-btn"]').trigger('click')
    await wrapper.get('[data-testid="property-value-edit-input"]').setValue('published')
    await wrapper.get('[data-testid="property-value-edit-input"]').trigger('keydown.enter')

    expect(wrapper.emitted('add-property')![0]).toEqual(['status', 'published'])
    expect(wrapper.find('[data-testid="property-value-edit-input"]').exists()).toBe(false)
  })

  it('discards the edit and emits nothing on Escape', async () => {
    const wrapper = mount(PropertiesPanel, {
      props: { properties: [{ name: 'status', type: 'string', value: 'draft' }] }
    })
    await wrapper.get('[data-testid="property-value-btn"]').trigger('click')
    await wrapper.get('[data-testid="property-value-edit-input"]').setValue('published')
    await wrapper.get('[data-testid="property-value-edit-input"]').trigger('keydown.escape')

    expect(wrapper.emitted('add-property')).toBeFalsy()
    expect(wrapper.find('[data-testid="property-value-edit-input"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('draft')
  })

  it('commits a numeric edit coerced to a number', async () => {
    const wrapper = mount(PropertiesPanel, {
      props: { properties: [{ name: 'priority', type: 'numeric', value: 3 }] }
    })
    await wrapper.get('[data-testid="property-value-btn"]').trigger('click')
    await wrapper.get('[data-testid="property-value-edit-input"]').setValue('7')
    await wrapper.get('[data-testid="property-value-edit-input"]').trigger('keydown.enter')

    expect(wrapper.emitted('add-property')![0]).toEqual(['priority', 7])
  })

  it('opening a second row\'s editor discards the first row\'s in-progress edit without emitting', async () => {
    const wrapper = mount(PropertiesPanel, {
      props: {
        properties: [
          { name: 'status', type: 'string', value: 'draft' },
          { name: 'priority', type: 'numeric', value: 1 },
        ],
      },
    })
    const buttons = wrapper.findAll('[data-testid="property-value-btn"]')
    await buttons[0].trigger('click')
    await wrapper.get('[data-testid="property-value-edit-input"]').setValue('unsaved draft edit')

    await buttons[1].trigger('click')

    expect(wrapper.emitted('add-property')).toBeFalsy()
    expect((wrapper.get('[data-testid="property-value-edit-input"]').element as HTMLInputElement).value).toBe('1')
    expect(wrapper.text()).toContain('draft')
    expect(wrapper.text()).not.toContain('unsaved draft edit')
  })

  it('commits a numeric edit cleared to empty as 0, mirroring the Add form\'s own Number() coercion', async () => {
    const wrapper = mount(PropertiesPanel, {
      props: { properties: [{ name: 'priority', type: 'numeric', value: 3 }] }
    })
    await wrapper.get('[data-testid="property-value-btn"]').trigger('click')
    await wrapper.get('[data-testid="property-value-edit-input"]').setValue('')
    await wrapper.get('[data-testid="property-value-edit-input"]').trigger('keydown.enter')

    expect(wrapper.emitted('add-property')![0]).toEqual(['priority', 0])
  })

  it('commits a list edit split back into an array of trimmed strings', async () => {
    const wrapper = mount(PropertiesPanel, {
      props: { properties: [{ name: 'tags', type: 'list', value: ['a', 'b'] }] }
    })
    await wrapper.get('[data-testid="property-value-btn"]').trigger('click')
    expect((wrapper.get('[data-testid="property-value-edit-input"]').element as HTMLInputElement).value).toBe('a, b')
    await wrapper.get('[data-testid="property-value-edit-input"]').setValue('x, y , z')
    await wrapper.get('[data-testid="property-value-edit-input"]').trigger('keydown.enter')

    expect(wrapper.emitted('add-property')![0]).toEqual(['tags', ['x', 'y', 'z']])
  })

  it('commits a boolean edit immediately on toggle, without a separate confirm step', async () => {
    const wrapper = mount(PropertiesPanel, {
      props: { properties: [{ name: 'archived', type: 'boolean', value: false }] }
    })
    await wrapper.get('[data-testid="property-value-btn"]').trigger('click')
    await wrapper.get('[data-testid="property-value-edit-input"]').setValue(true)

    expect(wrapper.emitted('add-property')![0]).toEqual(['archived', true])
  })

  it('does not render a click-to-edit surface for json-typed properties', () => {
    const wrapper = mount(PropertiesPanel, {
      props: { properties: [{ name: 'meta', type: 'json', value: { a: 1 } }] }
    })
    expect(wrapper.find('[data-testid="property-value-btn"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('{"a":1}')
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

describe('PropertiesPanel name autocomplete', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('does not fetch workspace properties when no workspaceId is given', () => {
    mount(PropertiesPanel, { props: { properties: [] } })
    expect(getWorkspaceProperties).not.toHaveBeenCalled()
  })

  it('loads known property names into a datalist when workspaceId is given', async () => {
    getWorkspaceProperties.mockResolvedValue([
      { name: 'status', type: 'string' },
      { name: 'priority', type: 'numeric' },
    ])
    const wrapper = mount(PropertiesPanel, { props: { properties: [], workspaceId: 1 } })
    await flushPromises()

    expect(getWorkspaceProperties).toHaveBeenCalledWith(1)
    const options = wrapper.find('#known-property-names').findAll('option')
    expect(options.map(o => o.attributes('value'))).toEqual(['status', 'priority'])
  })

  it("adopts a known property's existing type when its name is selected", async () => {
    getWorkspaceProperties.mockResolvedValue([{ name: 'priority', type: 'numeric' }])
    const wrapper = mount(PropertiesPanel, { props: { properties: [], workspaceId: 1 } })
    await flushPromises()

    await wrapper.find('[data-testid="property-name-input"]').setValue('priority')
    await wrapper.find('[data-testid="property-name-input"]').trigger('change')

    expect((wrapper.find('[data-testid="property-type-select"]').element as HTMLSelectElement).value).toBe('numeric')
  })

  it('re-fetches known properties on focus, since the panel instance is reused across note switches', async () => {
    getWorkspaceProperties.mockResolvedValue([])
    const wrapper = mount(PropertiesPanel, { props: { properties: [], workspaceId: 1 } })
    await flushPromises()
    expect(getWorkspaceProperties).toHaveBeenCalledTimes(1)

    getWorkspaceProperties.mockResolvedValue([{ name: 'newly-added', type: 'string' }])
    await wrapper.find('[data-testid="property-name-input"]').trigger('focus')
    await flushPromises()

    expect(getWorkspaceProperties).toHaveBeenCalledTimes(2)
    const options = wrapper.find('#known-property-names').findAll('option')
    expect(options.map(o => o.attributes('value'))).toContain('newly-added')
  })

  it('re-fetches known properties when the workspaceId prop changes', async () => {
    getWorkspaceProperties.mockResolvedValue([{ name: 'from-ws-1', type: 'string' }])
    const wrapper = mount(PropertiesPanel, { props: { properties: [], workspaceId: 1 } })
    await flushPromises()
    expect(getWorkspaceProperties).toHaveBeenCalledWith(1)

    getWorkspaceProperties.mockResolvedValue([{ name: 'from-ws-2', type: 'string' }])
    await wrapper.setProps({ workspaceId: 2 })
    await flushPromises()

    expect(getWorkspaceProperties).toHaveBeenCalledWith(2)
    const options = wrapper.find('#known-property-names').findAll('option')
    expect(options.map(o => o.attributes('value'))).toEqual(['from-ws-2'])
  })

  it('clears suggestions and does not throw when the properties fetch fails', async () => {
    getWorkspaceProperties.mockRejectedValue(new Error('network down'))
    const wrapper = mount(PropertiesPanel, { props: { properties: [], workspaceId: 1 } })
    await flushPromises()

    expect(wrapper.find('#known-property-names').findAll('option')).toHaveLength(0)
  })
})
