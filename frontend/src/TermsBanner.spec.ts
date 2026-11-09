import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TermsBanner from './components/TermsBanner.vue'
import i18n from './i18n'
import { resetBrand, setBrand } from './services/brand'
import type { TermsStatus } from './services/types'

const acceptTerms = vi.fn()
vi.mock('./services/api', () => ({ acceptTerms: () => acceptTerms() }))

function terms(overrides: Partial<TermsStatus>): TermsStatus {
  return { version: '2026-11-09', accepted_version: null, accepted_at: null, acceptance_required: false, ...overrides }
}

describe('TermsBanner', () => {
  beforeEach(() => {
    resetBrand()
    acceptTerms.mockReset()
    i18n.global.locale.value = 'pt-BR'
  })

  it('renders nothing when no acceptance is required', () => {
    expect(mount(TermsBanner, { props: { terms: terms({}) } }).find('[data-testid="terms-banner"]').exists()).toBe(false)
    expect(mount(TermsBanner, { props: { terms: null } }).find('[data-testid="terms-banner"]').exists()).toBe(false)
  })

  it('links the published texts and records the acceptance', async () => {
    setBrand({ terms_url: 'https://example.com/termos', privacy_url: 'https://example.com/privacidade' })
    const accepted = terms({ accepted_version: '2026-11-09', acceptance_required: false })
    acceptTerms.mockResolvedValue(accepted)

    const wrapper = mount(TermsBanner, { props: { terms: terms({ acceptance_required: true }) } })
    expect(wrapper.text().replace(/\s+/g, ' ')).toContain('Nossos textos mudaram. Leia os termos de uso e a política de privacidade')
    expect(wrapper.findAll('a').map((link) => link.attributes('href'))).toEqual(['https://example.com/termos', 'https://example.com/privacidade'])

    await wrapper.find('[data-testid="terms-accept"]').trigger('click')
    await flushPromises()

    expect(acceptTerms).toHaveBeenCalledOnce()
    expect(wrapper.emitted('accepted')?.[0]).toEqual([accepted])
  })

  it('shows an error when the acceptance cannot be recorded', async () => {
    acceptTerms.mockRejectedValue(new Error('offline'))
    const wrapper = mount(TermsBanner, { props: { terms: terms({ acceptance_required: true }) } })

    await wrapper.find('[data-testid="terms-accept"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[role="alert"]').text()).toBe('Não foi possível registrar o seu aceite. Tente de novo.')
    expect(wrapper.emitted('accepted')).toBeUndefined()
  })
})
