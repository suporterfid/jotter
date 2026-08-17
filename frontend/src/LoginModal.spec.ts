import { flushPromises, mount } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import LoginModal from './components/LoginModal.vue'
import { getAuthConfig } from './services/api'

vi.mock('./services/api', () => ({
  login: vi.fn(),
  getAuthConfig: vi.fn(),
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
})
