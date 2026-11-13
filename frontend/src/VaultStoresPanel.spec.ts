import { mount, flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import AdminPanel from './components/AdminPanel.vue'
import VaultStoresPanel from './components/VaultStoresPanel.vue'

const apiGet = vi.fn()
const apiPost = vi.fn().mockResolvedValue({ data: { data: {} } })

vi.mock('./services/api', () => ({
  api: {
    get: (...args: unknown[]) => apiGet(...args),
    post: (...args: unknown[]) => apiPost(...args),
    put: vi.fn().mockResolvedValue({ data: { data: {} } }),
    delete: vi.fn().mockResolvedValue({ data: {} }),
  },
  getTenants: vi.fn().mockResolvedValue([{ id: 1, slug: 'acme', name: 'Acme' }]),
  adminResetPassword: vi.fn(),
}))

function routeGets(storage: { enabled: boolean; drivers: string[] }) {
  apiGet.mockImplementation((url: string) => {
    if (url === '/admin/vault-stores/config') return Promise.resolve({ data: { data: storage } })
    if (url === '/admin/tenants/1/vault-stores') {
      return Promise.resolve({ data: { data: [{ id: 7, name: 'R2', driver: 's3', status: 'ok', last_error: null, workspaces: 0 }] } })
    }
    return Promise.resolve({ data: { data: [] } })
  })
}

describe('Vault stores (Storage tab)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    apiPost.mockResolvedValue({ data: { data: {} } })
  })

  it('hides the Storage tab while only the local driver is enabled', async () => {
    routeGets({ enabled: false, drivers: [] })
    const wrapper = mount(AdminPanel, { props: { isOpen: false } })
    await wrapper.setProps({ isOpen: true })
    await flushPromises()

    expect(wrapper.find('[data-testid="admin-tab-storage"]').exists()).toBe(false)
  })

  it('shows the Storage tab when a remote driver is enabled', async () => {
    routeGets({ enabled: true, drivers: ['local', 's3'] })
    const wrapper = mount(AdminPanel, { props: { isOpen: false } })
    await wrapper.setProps({ isOpen: true })
    await flushPromises()

    await wrapper.find('[data-testid="admin-tab-storage"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="admin-storage-tab"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="vault-store-access-warning"]').text()).toContain('whole workspace')
    expect(wrapper.find('[data-testid="vault-store-7"]').text()).toContain('R2')
  })

  it('creates an S3 store without echoing credentials back into the form', async () => {
    routeGets({ enabled: true, drivers: ['local', 's3'] })
    const wrapper = mount(VaultStoresPanel, {
      props: { tenants: [{ id: 1, slug: 'acme', name: 'Acme' }], workspaces: [], drivers: ['local', 's3'] },
    })
    await flushPromises()

    const inputs = wrapper.findAll('[data-testid="vault-store-form"] input')
    await inputs[0].setValue('Bucket')
    await inputs[1].setValue('https://s3.example.com')
    await inputs[2].setValue('acme-vaults')
    await inputs[5].setValue('AKIA')
    await inputs[6].setValue('secret')
    await wrapper.find('[data-testid="vault-store-form"]').trigger('submit')
    await flushPromises()

    expect(apiPost).toHaveBeenCalledWith('/admin/tenants/1/vault-stores', {
      name: 'Bucket',
      driver: 's3',
      settings: { endpoint: 'https://s3.example.com', bucket: 'acme-vaults' },
      credentials: { access_key_id: 'AKIA', secret_access_key: 'secret' },
    })
    expect((wrapper.findAll('[data-testid="vault-store-form"] input')[6].element as HTMLInputElement).value).toBe('')
  })

  it('connects a GitHub repository through the installed App', async () => {
    apiGet.mockImplementation((url: string) => {
      if (url === '/admin/tenants/1/github') {
        return Promise.resolve({ data: { data: { app_configured: true, app_slug: 'jotter', webhook_url: 'https://j.test/webhooks/github', installations: [{ installation_id: 55, account_login: 'acme' }] } } })
      }
      if (url === '/admin/tenants/1/github/installations/55/repositories') {
        return Promise.resolve({ data: { data: { account: 'acme', repositories: [
          { owner: 'acme', repo: 'notes', full_name: 'acme/notes', private: true, default_branch: 'trunk' },
          { owner: 'acme', repo: 'site', full_name: 'acme/site', private: false, default_branch: 'main' },
        ] } } })
      }
      return Promise.resolve({ data: { data: [] } })
    })
    apiPost.mockResolvedValue({ data: { data: { id: 9 } } })
    const wrapper = mount(VaultStoresPanel, {
      props: { tenants: [{ id: 1, slug: 'acme', name: 'Acme' }], workspaces: [], drivers: ['local', 'github'] },
    })
    await flushPromises()

    expect(wrapper.find('[data-testid="github-access-warning"]').text()).toContain('whole workspace')
    await wrapper.find('[data-testid="github-installation"]').setValue('55')
    await flushPromises()
    const repository = wrapper.find('[data-testid="github-repository"]')
    expect(repository.find('option[value="acme/site"]').attributes('disabled')).toBeDefined()
    await repository.setValue('acme/notes')
    await wrapper.find('[data-testid="github-controller"]').setValue(true)
    await wrapper.find('[data-testid="vault-store-form"]').trigger('submit')
    await flushPromises()

    expect(apiPost).toHaveBeenCalledWith('/admin/tenants/1/vault-stores', {
      name: 'acme/notes',
      driver: 'github',
      settings: { installation_id: '55', owner: 'acme', repo: 'notes', branch: 'trunk', controller_confirmed: true },
    })
  })

  it('shows the webhook URL and secret after creating a PAT store', async () => {
    apiGet.mockImplementation((url: string) => {
      if (url === '/admin/tenants/1/github') {
        return Promise.resolve({ data: { data: { app_configured: false, app_slug: null, webhook_url: 'https://j.test/webhooks/github', installations: [] } } })
      }
      if (url === '/admin/vault-stores/9/github/webhook') {
        return Promise.resolve({ data: { data: { mode: 'pat', url: 'https://j.test/webhooks/github', secret: 's3cr3t-value' } } })
      }
      return Promise.resolve({ data: { data: [] } })
    })
    apiPost.mockResolvedValue({ data: { data: { id: 9 } } })
    const wrapper = mount(VaultStoresPanel, {
      props: { tenants: [{ id: 1, slug: 'acme', name: 'Acme' }], workspaces: [], drivers: ['local', 'github'] },
    })
    await flushPromises()

    expect(wrapper.find('[data-testid="github-install"]').exists()).toBe(false)
    const form = wrapper.find('[data-testid="vault-store-form"]')
    await form.find('input').setValue('Notes')
    await form.find('input[placeholder="Owner (user or organization)"]').setValue('acme')
    await form.find('input[placeholder="Repository"]').setValue('notes')
    await form.find('input[type="password"]').setValue('github_pat_x')
    await wrapper.find('[data-testid="github-controller"]').setValue(true)
    await form.trigger('submit')
    await flushPromises()

    expect(apiPost).toHaveBeenCalledWith('/admin/tenants/1/vault-stores', {
      name: 'Notes',
      driver: 'github',
      settings: { owner: 'acme', repo: 'notes', controller_confirmed: true },
      credentials: { token: 'github_pat_x' },
    })
    expect(wrapper.find('[data-testid="vault-store-webhook-secret"]').text()).toBe('s3cr3t-value')
  })
})
