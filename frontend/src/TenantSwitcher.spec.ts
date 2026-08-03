import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import TenantSwitcher from './components/TenantSwitcher.vue'

const tenants = [
  { id: 1, slug: 'acme', name: 'Acme Corp' },
  { id: 2, slug: 'globex', name: 'Globex Inc' },
]

describe('TenantSwitcher', () => {
  it('renders one option per tenant', () => {
    const wrapper = mount(TenantSwitcher, { props: { tenants, activeTenantId: 1 } })
    const options = wrapper.findAll('option')
    expect(options).toHaveLength(2)
    expect(options[0].text()).toBe('Acme Corp')
    expect(options[1].text()).toBe('Globex Inc')
  })

  it('selects the active tenant by default', () => {
    const wrapper = mount(TenantSwitcher, { props: { tenants, activeTenantId: 2 } })
    expect((wrapper.find('select').element as HTMLSelectElement).value).toBe('2')
  })

  it('emits switch with the chosen tenant id', async () => {
    const wrapper = mount(TenantSwitcher, { props: { tenants, activeTenantId: 1 } })
    await wrapper.find('select').setValue('2')
    expect(wrapper.emitted('switch')![0]).toEqual([2])
  })

  it('renders correctly with a single tenant', () => {
    const wrapper = mount(TenantSwitcher, { props: { tenants: [tenants[0]], activeTenantId: 1 } })
    expect(wrapper.findAll('option')).toHaveLength(1)
    expect(wrapper.find('select').exists()).toBe(true)
  })
})
