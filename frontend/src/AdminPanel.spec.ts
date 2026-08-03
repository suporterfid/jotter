import { mount, flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import AdminPanel from './components/AdminPanel.vue'

const apiGet = vi.fn().mockResolvedValue({ data: { data: [] } })
const apiPost = vi.fn().mockResolvedValue({ data: { data: {} } })
const apiDelete = vi.fn().mockResolvedValue({ data: {} })
const adminResetPassword = vi.fn().mockResolvedValue(undefined)

vi.mock('./services/api', () => ({
  api: {
    get: (...args: unknown[]) => apiGet(...args),
    post: (...args: unknown[]) => apiPost(...args),
    delete: (...args: unknown[]) => apiDelete(...args),
  },
  getTenants: vi.fn().mockResolvedValue([
    { id: 1, slug: 'acme', name: 'Acme' },
    { id: 2, slug: 'other', name: 'Other Co' },
  ]),
  adminResetPassword: (...args: unknown[]) => adminResetPassword(...args),
}))

describe('AdminPanel Component', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    apiGet.mockResolvedValue({ data: { data: [] } })
    apiPost.mockResolvedValue({ data: { data: {} } })
    adminResetPassword.mockResolvedValue(undefined)
  })

  it('renders tabs and closes on overlay click', async () => {
    const wrapper = mount(AdminPanel, {
      props: { isOpen: true }
    })

    expect(wrapper.text()).toContain('Administration')
    expect(wrapper.text()).toContain('Workspaces')
    expect(wrapper.text()).toContain('Members')
    expect(wrapper.text()).toContain('Users')

    await wrapper.find('.admin-modal-overlay').trigger('click.self')
    expect(wrapper.emitted('close')).toBeTruthy()
  })

  it('auto-selects the tenant when only one exists', async () => {
    const { getTenants } = await import('./services/api')
    ;(getTenants as unknown as ReturnType<typeof vi.fn>).mockResolvedValueOnce([
      { id: 5, slug: 'solo', name: 'Solo Tenant' },
    ])

    const wrapper = mount(AdminPanel, { props: { isOpen: false } })
    await wrapper.setProps({ isOpen: true })
    await flushPromises()

    await wrapper.find('[data-testid="admin-new-workspace-name"]').setValue('Test WS')
    await wrapper.find('[data-testid="admin-new-workspace-slug"]').setValue('test-ws')
    await wrapper.find('[data-testid="admin-new-workspace-vault-path"]').setValue('/vaults/test')
    await wrapper.find('.admin-form').trigger('submit')
    await flushPromises()

    expect(apiPost).toHaveBeenCalledWith('/admin/workspaces', {
      tenant_id: 5, name: 'Test WS', slug: 'test-ws', vault_path: '/vaults/test',
    })
  })

  it('loads tenants into the create-workspace tenant select when opened', async () => {
    const wrapper = mount(AdminPanel, { props: { isOpen: false } })
    await wrapper.setProps({ isOpen: true })
    await flushPromises()

    const options = wrapper.find('[data-testid="admin-new-workspace-tenant"]').findAll('option')
    expect(options.map(o => o.text())).toContain('Acme')
    expect(options.map(o => o.text())).toContain('Other Co')
  })

  it('blocks workspace creation without a selected tenant and does not call the API', async () => {
    const wrapper = mount(AdminPanel, { props: { isOpen: false } })
    await wrapper.setProps({ isOpen: true })
    await flushPromises()

    await wrapper.find('[data-testid="admin-new-workspace-name"]').setValue('Test WS')
    await wrapper.find('[data-testid="admin-new-workspace-slug"]').setValue('test-ws')
    await wrapper.find('[data-testid="admin-new-workspace-vault-path"]').setValue('/vaults/test')
    await wrapper.find('.admin-form').trigger('submit')
    await flushPromises()

    expect(apiPost).not.toHaveBeenCalled()
    expect(wrapper.text()).toContain('Select a tenant')
  })

  it('submits the new workspace via the authenticated axios instance, including tenant_id', async () => {
    const wrapper = mount(AdminPanel, { props: { isOpen: false } })
    await wrapper.setProps({ isOpen: true })
    await flushPromises()

    await wrapper.find('[data-testid="admin-new-workspace-tenant"]').setValue('1')
    await wrapper.find('[data-testid="admin-new-workspace-name"]').setValue('Test WS')
    await wrapper.find('[data-testid="admin-new-workspace-slug"]').setValue('test-ws')
    await wrapper.find('[data-testid="admin-new-workspace-vault-path"]').setValue('/vaults/test')
    await wrapper.find('.admin-form').trigger('submit')
    await flushPromises()

    expect(apiPost).toHaveBeenCalledWith('/admin/workspaces', {
      tenant_id: 1, name: 'Test WS', slug: 'test-ws', vault_path: '/vaults/test',
    })
  })

  it('resets a user password via the prompt-entered value', async () => {
    apiGet.mockImplementation((url: string) => {
      if (url === '/admin/users') {
        return Promise.resolve({ data: { data: [{ id: 7, name: 'Bob', email: 'bob@example.com', is_active: true }] } })
      }
      return Promise.resolve({ data: { data: [] } })
    })
    const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue('a-new-password')

    const wrapper = mount(AdminPanel, { props: { isOpen: false } })
    await wrapper.setProps({ isOpen: true })
    await flushPromises()
    await wrapper.find('.tab-btn:nth-child(3)').trigger('click')

    await wrapper.find('[data-testid="admin-reset-password-btn"]').trigger('click')
    await flushPromises()

    expect(promptSpy).toHaveBeenCalled()
    expect(adminResetPassword).toHaveBeenCalledWith(7, 'a-new-password')
    promptSpy.mockRestore()
  })

  it('does not reset a password when the prompt is cancelled', async () => {
    apiGet.mockImplementation((url: string) => {
      if (url === '/admin/users') {
        return Promise.resolve({ data: { data: [{ id: 7, name: 'Bob', email: 'bob@example.com', is_active: true }] } })
      }
      return Promise.resolve({ data: { data: [] } })
    })
    const promptSpy = vi.spyOn(window, 'prompt').mockReturnValue(null)

    const wrapper = mount(AdminPanel, { props: { isOpen: false } })
    await wrapper.setProps({ isOpen: true })
    await flushPromises()
    await wrapper.find('.tab-btn:nth-child(3)').trigger('click')

    await wrapper.find('[data-testid="admin-reset-password-btn"]').trigger('click')
    await flushPromises()

    expect(adminResetPassword).not.toHaveBeenCalled()
    promptSpy.mockRestore()
  })
})
