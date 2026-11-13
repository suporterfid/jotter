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
})
