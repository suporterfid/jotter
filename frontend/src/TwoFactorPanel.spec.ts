import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import TwoFactorPanel from './components/TwoFactorPanel.vue'
import TwoFactorBanner from './components/TwoFactorBanner.vue'
import i18n from './i18n'
import { confirmTwoFactor, disableTwoFactor, regenerateRecoveryCodes, startTwoFactor } from './services/api'
import type { TwoFactorStatus } from './services/types'

vi.mock('qrcode', () => ({ default: { toString: vi.fn().mockResolvedValue('<svg xmlns="http://www.w3.org/2000/svg"></svg>') } }))
vi.mock('./services/api', () => ({
  startTwoFactor: vi.fn(),
  confirmTwoFactor: vi.fn(),
  disableTwoFactor: vi.fn(),
  regenerateRecoveryCodes: vi.fn(),
}))

const off: TwoFactorStatus = { available: true, enabled: false, required: false, recovery_codes_left: 0 }
const on: TwoFactorStatus = { available: true, enabled: true, required: false, recovery_codes_left: 10 }
const codes = Array.from({ length: 10 }, (_, i) => `abcde-fgh${i}k`)

describe('TwoFactorPanel (block 4b)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    i18n.global.locale.value = 'pt-BR'
  })

  it('is hidden where an identity provider handles sign-in', () => {
    expect(mount(TwoFactorPanel, { props: { status: { ...off, available: false } } }).find('[data-testid="two-factor"]').exists()).toBe(false)
  })

  it('turns on after the password, the QR code and a first code, then shows the recovery codes once', async () => {
    vi.mocked(startTwoFactor).mockResolvedValue({ secret: 'JBSWY3DPEHPK3PXPJBSWY3DPEHPK3PXP', otpauth_uri: 'otpauth://totp/x' })
    vi.mocked(confirmTwoFactor).mockResolvedValue({ recovery_codes: codes, two_factor: on })
    const wrapper = mount(TwoFactorPanel, { props: { status: off } })

    await wrapper.find('[data-testid="two-factor-enable"]').trigger('click')
    await wrapper.find('[data-testid="two-factor-password"]').setValue('minha-senha')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(startTwoFactor).toHaveBeenCalledWith('minha-senha')
    expect(wrapper.find('[data-testid="two-factor-qr"]').attributes('src')).toMatch(/^data:image\/svg\+xml/)
    expect(wrapper.find('[data-testid="two-factor-secret"]').text()).toBe('JBSW Y3DP EHPK 3PXP JBSW Y3DP EHPK 3PXP')

    await wrapper.find('[data-testid="two-factor-confirm-code"]').setValue('123456')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(confirmTwoFactor).toHaveBeenCalledWith('123456')
    expect(wrapper.findAll('[data-testid="two-factor-codes"] li')).toHaveLength(10)
    expect(wrapper.emitted('changed')?.[0]).toEqual([on])

    await wrapper.find('[data-testid="two-factor-codes-done"]').trigger('click')
    expect(wrapper.find('[data-testid="two-factor-codes"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="two-factor-on"]').text()).toContain('10')
  })

  it('shows the server message for a wrong password', async () => {
    vi.mocked(startTwoFactor).mockRejectedValue({ response: { status: 422, data: { message: 'A senha não está correta.', errors: { password: ['A senha não está correta.'] } } } })
    const wrapper = mount(TwoFactorPanel, { props: { status: off } })

    await wrapper.find('[data-testid="two-factor-enable"]').trigger('click')
    await wrapper.find('[data-testid="two-factor-password"]').setValue('errada')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('[data-testid="two-factor-panel-error"]').text()).toBe('A senha não está correta.')
    expect(wrapper.find('[data-testid="two-factor-qr"]').exists()).toBe(false)
  })

  it('turns off and renews recovery codes after the password', async () => {
    vi.mocked(disableTwoFactor).mockResolvedValue(off)
    vi.mocked(regenerateRecoveryCodes).mockResolvedValue({ recovery_codes: codes, two_factor: on })
    const wrapper = mount(TwoFactorPanel, { props: { status: on } })

    await wrapper.find('[data-testid="two-factor-regenerate"]').trigger('click')
    await wrapper.find('[data-testid="two-factor-password"]').setValue('minha-senha')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(regenerateRecoveryCodes).toHaveBeenCalledWith('minha-senha')
    expect(wrapper.findAll('[data-testid="two-factor-codes"] li')).toHaveLength(10)
    await wrapper.find('[data-testid="two-factor-codes-done"]').trigger('click')

    await wrapper.find('[data-testid="two-factor-disable"]').trigger('click')
    await wrapper.find('[data-testid="two-factor-password"]').setValue('minha-senha')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()
    expect(disableTwoFactor).toHaveBeenCalledWith('minha-senha')
    expect(wrapper.find('[data-testid="two-factor-enable"]').exists()).toBe(true)
  })
})

describe('TwoFactorBanner', () => {
  it('asks a required administrator without two-step verification to set it up', async () => {
    expect(mount(TwoFactorBanner, { props: { status: off } }).find('[data-testid="two-factor-banner"]').exists()).toBe(false)
    expect(mount(TwoFactorBanner, { props: { status: { ...on, required: true } } }).find('[data-testid="two-factor-banner"]').exists()).toBe(false)

    const wrapper = mount(TwoFactorBanner, { props: { status: { ...off, required: true } } })
    await wrapper.find('[data-testid="two-factor-banner-open"]').trigger('click')
    expect(wrapper.emitted('open-profile')).toHaveLength(1)
  })
})
