import { mount } from '@vue/test-utils'
import { defineComponent, h } from 'vue'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import { useLocale } from './useLocale'
import i18n from '../i18n'

vi.mock('../services/api', () => ({
  updateLocale: vi.fn().mockResolvedValue(undefined),
}))

const TestComponent = defineComponent({
  setup() {
    return { ...useLocale() }
  },
  render() {
    return h('div')
  },
})

describe('useLocale', () => {
  beforeEach(() => {
    i18n.global.locale.value = 'pt-BR'
    vi.clearAllMocks()
  })

  it('exposes the current vue-i18n locale', () => {
    const wrapper = mount(TestComponent)
    expect((wrapper.vm as unknown as { locale: string }).locale).toBe('pt-BR')
  })

  it('setLocale updates the reactive locale immediately', async () => {
    const wrapper = mount(TestComponent)
    const vm = wrapper.vm as unknown as { setLocale: (l: string) => Promise<void> }
    await vm.setLocale('en')
    expect(i18n.global.locale.value).toBe('en')
  })

  it('setLocale persists the change via the API', async () => {
    const { updateLocale } = await import('../services/api')
    const wrapper = mount(TestComponent)
    const vm = wrapper.vm as unknown as { setLocale: (l: string) => Promise<void> }
    await vm.setLocale('en')
    expect(updateLocale).toHaveBeenCalledWith('en')
  })
})
