import { mount, flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import Sidebar from './components/Sidebar.vue'
import LoginModal from './components/LoginModal.vue'
import BrandFooter from './components/BrandFooter.vue'
import { getAuthConfig } from './services/api'
import { resetBrand, setBrand } from './services/brand'

vi.mock('./services/api', () => ({
  getAuthConfig: vi.fn(),
  login: vi.fn(),
  moveNote: vi.fn(),
  reorderNoteTree: vi.fn(),
}))

const CADERNIA = {
  name: 'Cadernia',
  logo_url: 'https://cdn.example.com/cadernia.svg',
  support_url: 'https://cadernia.example.com/support',
  terms_url: 'https://cadernia.example.com/terms',
  privacy_url: 'https://cadernia.example.com/privacy',
  powered_by: true,
  powered_by_url: 'https://github.com/suporterfid/jotter',
}

function mountSidebar() {
  return mount(Sidebar, {
    props: { notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev' },
  })
}

describe('Branding', () => {
  beforeEach(() => {
    resetBrand()
    vi.mocked(getAuthConfig).mockReset()
  })

  it('renders the stock Jotter identity by default', () => {
    const wrapper = mountSidebar()

    expect(wrapper.find('[data-testid="brand-title"]').text()).toBe('Jotter')
    expect(wrapper.find('[data-testid="brand-mark"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="brand-logo"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="brand-powered-by"]').attributes('href')).toBe('https://github.com/suporterfid/jotter')
    expect(wrapper.find('[data-testid="brand-link-terms"]').exists()).toBe(false)
  })

  it('shows the operator name, logo, and footer links in the sidebar', () => {
    setBrand(CADERNIA)
    const wrapper = mountSidebar()

    expect(wrapper.find('[data-testid="brand-title"]').text()).toBe('Cadernia')
    expect(wrapper.find('[data-testid="brand-logo"]').attributes('src')).toBe(CADERNIA.logo_url)
    expect(wrapper.find('[data-testid="brand-logo"]').attributes('alt')).toBe('Cadernia')
    expect(wrapper.find('[data-testid="brand-mark"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="brand-link-terms"]').attributes('href')).toBe(CADERNIA.terms_url)
    expect(wrapper.find('[data-testid="brand-link-privacy"]').attributes('href')).toBe(CADERNIA.privacy_url)
    expect(wrapper.find('[data-testid="brand-link-support"]').attributes('href')).toBe(CADERNIA.support_url)
    expect(wrapper.find('[data-testid="brand-powered-by"]').text()).toBe('Powered by Jotter')
  })

  it('hides the powered-by link when disabled and the footer when nothing remains', () => {
    setBrand({ ...CADERNIA, powered_by: false })
    expect(mount(BrandFooter).find('[data-testid="brand-powered-by"]').exists()).toBe(false)

    setBrand({ powered_by: false })
    expect(mount(BrandFooter).find('[data-testid="brand-footer"]').exists()).toBe(false)
  })

  it('applies the brand from the auth config on the login screen', async () => {
    vi.mocked(getAuthConfig).mockResolvedValue({ provider: 'local', sso_login_url: null, version: null, brand: CADERNIA })

    const wrapper = mount(LoginModal, { props: { show: true } })
    await flushPromises()

    expect(wrapper.find('[data-testid="login-heading"]').text()).toBe('Cadernia Sign In')
    expect(wrapper.find('[data-testid="brand-logo"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="brand-link-terms"]').attributes('href')).toBe(CADERNIA.terms_url)
    expect(wrapper.find('[data-testid="brand-powered-by"]').exists()).toBe(true)
  })

  it('falls back to Jotter when the brand name is blank', () => {
    setBrand({ name: '   ' })
    expect(mountSidebar().find('[data-testid="brand-title"]').text()).toBe('Jotter')
  })
})
