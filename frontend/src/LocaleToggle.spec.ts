import { mount } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import LocaleToggle from './components/LocaleToggle.vue'
import i18n from './i18n'

vi.mock('./services/api', () => ({
  updateLocale: vi.fn().mockResolvedValue(undefined),
}))

describe('LocaleToggle', () => {
  beforeEach(() => {
    i18n.global.locale.value = 'pt-BR'
  })

  it('highlights the active locale', () => {
    const wrapper = mount(LocaleToggle)
    expect(wrapper.find('[data-testid="locale-toggle-pt"]').classes()).toContain('active')
    expect(wrapper.find('[data-testid="locale-toggle-en"]').classes()).not.toContain('active')
  })

  it('switches locale on click', async () => {
    const wrapper = mount(LocaleToggle)
    await wrapper.find('[data-testid="locale-toggle-en"]').trigger('click')
    expect(i18n.global.locale.value).toBe('en')
  })
})
