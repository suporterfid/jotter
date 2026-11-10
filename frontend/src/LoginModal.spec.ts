import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import LoginModal from './components/LoginModal.vue'
import { completeTwoFactor, getAuthConfig, login, register } from './services/api'
import { setBrand } from './services/brand'

vi.mock('./services/api', () => ({
  login: vi.fn(),
  getAuthConfig: vi.fn(),
  register: vi.fn(),
  completeTwoFactor: vi.fn(),
  needsTwoFactor: (result: { two_factor_required?: boolean }) => result.two_factor_required === true,
}))

describe('LoginModal', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('does not show the SSO link when the provider is local', async () => {
    vi.mocked(getAuthConfig).mockResolvedValue({ provider: 'local', sso_login_url: null, version: null })

    const wrapper = mount(LoginModal, { props: { show: true } })
    await flushPromises()

    expect(wrapper.find('[data-testid="sso-login-link"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="login-email"]').exists()).toBe(true)
  })

  it('shows a GrandpaSSOn link above the local form when configured', async () => {
    vi.mocked(getAuthConfig).mockResolvedValue({
      provider: 'grandpasson',
      sso_login_url: 'https://hub.taskconnect.com.br/sso/login/email?client_id=jotter&redirect_uri=x&state=y',
      version: null,
    })

    const wrapper = mount(LoginModal, { props: { show: true } })
    await flushPromises()

    const ssoLink = wrapper.find('[data-testid="sso-login-link"]')
    expect(ssoLink.exists()).toBe(true)
    expect(ssoLink.attributes('href')).toBe(
      'https://hub.taskconnect.com.br/sso/login/email?client_id=jotter&redirect_uri=x&state=y',
    )
    // Local form stays available as a fallback, not replaced.
    expect(wrapper.find('[data-testid="login-email"]').exists()).toBe(true)
  })

  it('shows a generic SSO link for the OIDC provider', async () => {
    vi.mocked(getAuthConfig).mockResolvedValue({
      provider: 'oidc',
      sso_login_url: '/api/auth/oidc/redirect',
      version: null,
    })

    const wrapper = mount(LoginModal, { props: { show: true } })
    await flushPromises()

    const ssoLink = wrapper.find('[data-testid="sso-login-link"]')
    expect(ssoLink.exists()).toBe(true)
    expect(ssoLink.attributes('href')).toBe('/api/auth/oidc/redirect')
    expect(ssoLink.text()).toContain('Sign in with SSO')
    expect(wrapper.find('[data-testid="login-email"]').exists()).toBe(true)
  })

  it('does not fetch auth config when the modal is not shown', () => {
    mount(LoginModal, { props: { show: false } })
    expect(getAuthConfig).not.toHaveBeenCalled()
  })

  it('renders the sign-in heading and subtitle via i18n', () => {
    const wrapper = mount(LoginModal, { props: { show: true } })
    expect(wrapper.find('h2').text()).toBe('Jotter Sign In')
    expect(wrapper.find('.login-subtitle').text()).toBe(
      'Enter your administrator credentials to access your notes vault.',
    )
  })
  it('hides "Create an account" unless the server enables sign-up', async () => {
    vi.mocked(getAuthConfig).mockResolvedValue({ provider: 'local', sso_login_url: null, version: null })
    const wrapper = mount(LoginModal, { props: { show: true } })
    await flushPromises()
    expect(wrapper.find('[data-testid="signup-open"]').exists()).toBe(false)
  })

  it('sends a sign-up request and shows that it waits for approval (#390)', async () => {
    vi.mocked(getAuthConfig).mockResolvedValue({ provider: 'local', sso_login_url: null, version: null, signup_enabled: true })
    vi.mocked(register).mockResolvedValue('Request received.')
    setBrand({ terms_url: 'https://example.com/terms', privacy_url: 'https://example.com/privacy' } as never)

    const wrapper = mount(LoginModal, { props: { show: true } })
    await flushPromises()
    await wrapper.find('[data-testid="signup-open"]').trigger('click')

    await wrapper.find('[data-testid="signup-name"]').setValue('Maria')
    await wrapper.find('[data-testid="signup-email"]').setValue('maria@example.com')
    await wrapper.find('[data-testid="signup-password"]').setValue('uma-senha-longa-1')
    await wrapper.find('[data-testid="signup-password-confirm"]').setValue('outra-senha-longa')
    await wrapper.find('[data-testid="signup-form"]').trigger('submit.prevent')
    expect(wrapper.find('[data-testid="signup-error"]').text()).toBe('The passwords do not match.')
    expect(register).not.toHaveBeenCalled()

    await wrapper.find('[data-testid="signup-password-confirm"]').setValue('uma-senha-longa-1')
    await wrapper.find('[data-testid="signup-terms"]').setValue(true)
    await wrapper.find('[data-testid="signup-form"]').trigger('submit.prevent')
    await flushPromises()

    expect(register).toHaveBeenCalledWith(expect.objectContaining({ name: 'Maria', email: 'maria@example.com', password_confirmation: 'uma-senha-longa-1', accept_terms: true }))
    expect(wrapper.find('[data-testid="signup-done"]').text()).toBe('Request received.')
    expect(login).not.toHaveBeenCalled()

    await wrapper.find('[data-testid="signup-back"]').trigger('click')
    expect(wrapper.find('[data-testid="login-email"]').exists()).toBe(true)
  })

  it('shows the pending-approval message the server sends on sign-in', async () => {
    vi.mocked(getAuthConfig).mockResolvedValue({ provider: 'local', sso_login_url: null, version: null })
    vi.mocked(login).mockRejectedValue({ response: { status: 403, data: { message: 'Your account is waiting for an administrator to approve it.', signup_pending: true } } })
    const wrapper = mount(LoginModal, { props: { show: true } })
    await flushPromises()

    await wrapper.find('[data-testid="login-email"]').setValue('maria@example.com')
    await wrapper.find('[data-testid="login-password"]').setValue('uma-senha-longa-1')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.find('[data-testid="login-error"]').text()).toContain('waiting for an administrator')
  })

  it('asks for the two-step verification code after the password', async () => {
    vi.mocked(getAuthConfig).mockResolvedValue({ provider: 'local', sso_login_url: null, version: null })
    vi.mocked(login).mockResolvedValue({ two_factor_required: true })
    const user = { subject_id: '7', email: 'ana@example.com', name: 'Ana', is_admin: false, locale: 'en' }
    vi.mocked(completeTwoFactor).mockResolvedValueOnce(user)

    const wrapper = mount(LoginModal, { props: { show: true } })
    await flushPromises()
    await wrapper.find('[data-testid="login-email"]').setValue('ana@example.com')
    await wrapper.find('[data-testid="login-password"]').setValue('senha-longa-123')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    expect(wrapper.emitted('login-success')).toBeUndefined()
    expect(wrapper.find('[data-testid="two-factor-form"]').exists()).toBe(true)

    await wrapper.find('[data-testid="two-factor-code"]').setValue('123456')
    await wrapper.find('[data-testid="two-factor-form"]').trigger('submit.prevent')
    await flushPromises()

    expect(completeTwoFactor).toHaveBeenCalledWith({ code: '123456' })
    expect(wrapper.emitted('login-success')?.[0]).toEqual([user])
  })

  it('takes a recovery code and goes back to the password when the challenge expired', async () => {
    vi.mocked(getAuthConfig).mockResolvedValue({ provider: 'local', sso_login_url: null, version: null })
    vi.mocked(login).mockResolvedValue({ two_factor_required: true })
    vi.mocked(completeTwoFactor).mockRejectedValueOnce({ response: { status: 401, data: { message: 'Digite a senha de novo.', two_factor_expired: true } } })

    const wrapper = mount(LoginModal, { props: { show: true } })
    await flushPromises()
    await wrapper.find('[data-testid="login-email"]').setValue('ana@example.com')
    await wrapper.find('[data-testid="login-password"]').setValue('senha-longa-123')
    await wrapper.find('form').trigger('submit.prevent')
    await flushPromises()

    await wrapper.find('[data-testid="two-factor-toggle"]').trigger('click')
    await wrapper.find('[data-testid="two-factor-code"]').setValue('abcde-fghjk')
    await wrapper.find('[data-testid="two-factor-form"]').trigger('submit.prevent')
    await flushPromises()

    expect(completeTwoFactor).toHaveBeenCalledWith({ recovery_code: 'abcde-fghjk' })
    expect(wrapper.find('[data-testid="two-factor-form"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="login-error"]').text()).toContain('Digite a senha de novo.')
  })
})
