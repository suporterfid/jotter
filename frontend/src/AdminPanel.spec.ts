import { mount, flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import AdminPanel from './components/AdminPanel.vue'

const apiGet = vi.fn().mockResolvedValue({ data: { data: [] } })
const apiPost = vi.fn().mockResolvedValue({ data: { data: {} } })
const apiPut = vi.fn().mockResolvedValue({ data: { data: {} } })
const apiDelete = vi.fn().mockResolvedValue({ data: {} })
const adminResetPassword = vi.fn().mockResolvedValue(undefined)

vi.mock('./services/api', () => ({
  api: {
    get: (...args: unknown[]) => apiGet(...args),
    post: (...args: unknown[]) => apiPost(...args),
    put: (...args: unknown[]) => apiPut(...args),
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
    apiPut.mockResolvedValue({ data: { data: {} } })
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

  it('edits a workspace name and slug via the authenticated axios instance, without vault_path', async () => {
    apiGet.mockImplementation((url: string) => {
      if (url === '/workspaces') {
        return Promise.resolve({ data: { data: [{ id: 3, tenant_id: 1, slug: 'old-slug', name: 'Old Name' }] } })
      }
      return Promise.resolve({ data: { data: [] } })
    })

    const wrapper = mount(AdminPanel, { props: { isOpen: false } })
    await wrapper.setProps({ isOpen: true })
    await flushPromises()

    await wrapper.find('[data-testid="admin-edit-workspace-btn"]').trigger('click')
    expect((wrapper.find('[data-testid="admin-edit-workspace-name"]').element as HTMLInputElement).value).toBe('Old Name')
    expect((wrapper.find('[data-testid="admin-edit-workspace-slug"]').element as HTMLInputElement).value).toBe('old-slug')

    await wrapper.find('[data-testid="admin-edit-workspace-name"]').setValue('New Name')
    await wrapper.find('[data-testid="admin-edit-workspace-slug"]').setValue('new-slug')
    await wrapper.find('[data-testid="admin-edit-workspace-save"]').trigger('click')
    await flushPromises()

    expect(apiPut).toHaveBeenCalledWith('/admin/workspaces/3', { name: 'New Name', slug: 'new-slug', storage_quota_mb: null })
    expect(wrapper.find('[data-testid="admin-edit-workspace-name"]').exists()).toBe(false)
  })

  it('discards edits and closes the edit form on Cancel', async () => {
    apiGet.mockImplementation((url: string) => {
      if (url === '/workspaces') {
        return Promise.resolve({ data: { data: [{ id: 3, tenant_id: 1, slug: 'old-slug', name: 'Old Name' }] } })
      }
      return Promise.resolve({ data: { data: [] } })
    })

    const wrapper = mount(AdminPanel, { props: { isOpen: false } })
    await wrapper.setProps({ isOpen: true })
    await flushPromises()

    await wrapper.find('[data-testid="admin-edit-workspace-btn"]').trigger('click')
    await wrapper.find('[data-testid="admin-edit-workspace-name"]').setValue('Discarded Name')
    await wrapper.find('[data-testid="admin-edit-workspace-cancel"]').trigger('click')

    expect(apiPut).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="admin-edit-workspace-name"]').exists()).toBe(false)
    expect(wrapper.text()).toContain('Old Name')
  })

  it('shows the backend validation error and keeps the edit form open when saving fails', async () => {
    apiGet.mockImplementation((url: string) => {
      if (url === '/workspaces') {
        return Promise.resolve({ data: { data: [{ id: 3, tenant_id: 1, slug: 'old-slug', name: 'Old Name' }] } })
      }
      return Promise.resolve({ data: { data: [] } })
    })
    apiPut.mockRejectedValue({ response: { data: { message: 'That slug is already taken.' } } })

    const wrapper = mount(AdminPanel, { props: { isOpen: false } })
    await wrapper.setProps({ isOpen: true })
    await flushPromises()

    await wrapper.find('[data-testid="admin-edit-workspace-btn"]').trigger('click')
    await wrapper.find('[data-testid="admin-edit-workspace-slug"]').setValue('taken-slug')
    await wrapper.find('[data-testid="admin-edit-workspace-save"]').trigger('click')
    await flushPromises()

    expect(wrapper.text()).toContain('That slug is already taken.')
    expect(wrapper.find('[data-testid="admin-edit-workspace-name"]').exists()).toBe(true)
  })

  it('issues a scoped MCP token with workspaces, write paths and expiry', async () => {
    apiGet.mockImplementation((url: string) => {
      if (url === '/workspaces') {
        return Promise.resolve({ data: { data: [
          { id: 10, tenant_id: 1, slug: 'brain', name: 'Brain' },
          { id: 11, tenant_id: 1, slug: 'brain-restrito', name: 'Restrito' },
          { id: 20, tenant_id: 2, slug: 'elsewhere', name: 'Elsewhere' },
        ] } })
      }
      if (url === '/admin/machine-tokens') {
        return Promise.resolve({ data: { data: [
          { id: 7, name: 'Hermes VPS', tenant_slug: 'acme', user_email: 'joe@acme.test', scopes: ['mcp:read', 'mcp:write'], workspace_ids: [10], client_slug: 'hermes', created_at: null, expires_at: null, last_used_at: null, revoked_at: null },
        ] } })
      }
      if (url === '/admin/users') {
        return Promise.resolve({ data: { data: [{ id: 3, name: 'Joe', email: 'joe@acme.test' }] } })
      }
      return Promise.resolve({ data: { data: [] } })
    })
    apiPost.mockResolvedValue({ data: { data: { token: 'jt_mkt_x', mcp_url: 'https://acme.test/api/mcp', tenant_slug: 'acme' } } })

    const wrapper = mount(AdminPanel, { props: { isOpen: false } })
    await wrapper.setProps({ isOpen: true })
    await flushPromises()
    await wrapper.find('[data-testid="admin-tab-mcp"]').trigger('click')

    expect(wrapper.find('[data-testid="admin-mcp-token-scope-7"]').text().replace(/\s+/g, ' ')).toContain('mcp:read mcp:write · brain · hermes')

    await wrapper.find('[data-testid="admin-mcp-tenant"]').setValue(1)
    await wrapper.find('[data-testid="admin-mcp-user"]').setValue(3)
    await wrapper.find('[data-testid="admin-mcp-name"]').setValue('Hermes VPS')

    // Only the selected tenant's workspaces are offered.
    expect(wrapper.find('[data-testid="admin-mcp-ws-20"]').exists()).toBe(false)
    await wrapper.find('[data-testid="admin-mcp-ws-10"]').setValue(true)

    await wrapper.find('[data-testid="admin-mcp-scope-mcp:write"]').setValue(true)
    await flushPromises()
    expect((wrapper.find('[data-testid="admin-mcp-glob-0"]').element as HTMLInputElement).value).toBe('inbox/{client}/**')
    await wrapper.find('[data-testid="admin-mcp-client"]').setValue('hermes')
    await wrapper.find('[data-testid="admin-mcp-expires"]').setValue('180d')

    await wrapper.find('[data-testid="admin-mcp-tab"] form').trigger('submit.prevent')
    await flushPromises()

    expect(apiPost).toHaveBeenCalledWith('/admin/machine-tokens', {
      tenant_id: 1,
      user_id: 3,
      name: 'Hermes VPS',
      scopes: ['mcp:read', 'mcp:write'],
      workspace_ids: [10],
      write_paths: [
        { glob: 'inbox/{client}/**', ops: ['create', 'append', 'update'] },
        { glob: 'diario/**', ops: ['append'] },
      ],
      client_slug: 'hermes',
      expires: '180d',
    })
    expect(wrapper.find('[data-testid="admin-mcp-token-value"]').text()).toBe('jt_mkt_x')
  })
  it('lists every OAuth connection under Connected apps and revokes one', async () => {
    const grants = [
      { id: 4, client_name: 'Claude', redirect_hosts: ['claude.ai'], user_email: 'joe@acme.test', workspaces: ['brain'], scopes: ['mcp:read', 'mcp:write'], client_slug: 'claude', created_at: null, expires_at: null, last_used_at: null },
      { id: 5, client_name: null, redirect_hosts: ['chatgpt.com'], user_email: null, workspaces: ['brain'], scopes: ['mcp:read'], client_slug: null, created_at: null, expires_at: null, last_used_at: null },
    ]
    apiGet.mockImplementation((url: string) =>
      Promise.resolve({ data: { data: url === '/admin/oauth-grants' ? grants : [] } }),
    )
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true)

    const wrapper = mount(AdminPanel, { props: { isOpen: false } })
    await wrapper.setProps({ isOpen: true })
    await flushPromises()
    expect(apiGet).not.toHaveBeenCalledWith('/admin/oauth-grants')

    await wrapper.find('[data-testid="admin-tab-apps"]').trigger('click')
    await flushPromises()

    expect(apiGet).toHaveBeenCalledWith('/admin/oauth-grants')
    expect(wrapper.find('[data-testid="admin-app-4"]').text()).toContain('Claude')
    expect(wrapper.find('[data-testid="admin-app-4"]').text()).toContain('joe@acme.test')
    expect(wrapper.find('[data-testid="admin-app-detail-4"]').text().replace(/\s+/g, ' ')).toContain('claude.ai · brain · mcp:read mcp:write · claude')
    expect(wrapper.find('[data-testid="admin-app-5"]').text()).toContain('Unnamed app')
    expect(wrapper.find('[data-testid="admin-app-5"]').text()).toContain('unknown user')

    grants.splice(0, 1)
    await wrapper.find('[data-testid="admin-app-revoke-4"]').trigger('click')
    await flushPromises()

    expect(confirmSpy).toHaveBeenCalledWith(expect.stringContaining('joe@acme.test'))
    expect(apiDelete).toHaveBeenCalledWith('/admin/oauth-grants/4')
    expect(wrapper.find('[data-testid="admin-app-4"]').exists()).toBe(false)
    confirmSpy.mockRestore()
  })

  it('shows an empty state when nobody connected an app', async () => {
    const wrapper = mount(AdminPanel, { props: { isOpen: true } })
    await wrapper.find('[data-testid="admin-tab-apps"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="admin-apps-empty"]').text()).toBe('No connected apps.')
  })
  it('shows workspace storage and edits the quota (empty = default, 0 = unlimited)', async () => {
    apiGet.mockImplementation((url: string) => {
      if (url === '/workspaces') {
        return Promise.resolve({ data: { data: [
          { id: 3, tenant_id: 1, slug: 'brain', name: 'Brain', storage: { used_bytes: 900 * 1024 * 1024, limit_bytes: 1024 * 1024 * 1024, percent: 87.9, warning: true, exceeded: false, quota_mb: null } },
        ] } })
      }
      return Promise.resolve({ data: { data: [] } })
    })
    const wrapper = mount(AdminPanel, { props: { isOpen: false } })
    await wrapper.setProps({ isOpen: true })
    await flushPromises()

    expect(wrapper.find('[data-testid="admin-ws-storage-3"]').text().replace(/\s+/g, ' ')).toContain('900.0 MB / 1.0 GB (88%)')

    await wrapper.find('[data-testid="admin-edit-workspace-btn"]').trigger('click')
    await wrapper.find('[data-testid="admin-edit-workspace-quota"]').setValue('0')
    await wrapper.find('[data-testid="admin-edit-workspace-save"]').trigger('click')
    await flushPromises()

    expect(apiPut).toHaveBeenCalledWith('/admin/workspaces/3', { name: 'Brain', slug: 'brain', storage_quota_mb: 0 })
  })
  it('lists pending sign-up requests apart and approves or rejects them (#390)', async () => {
    type Row = { id: number; name: string; email: string; is_admin: boolean; is_active: boolean; signup_status: string | null; signup_requested_at?: string }
    let users: Row[] = [
      { id: 1, name: 'Admin', email: 'admin@acme.test', is_admin: true, is_active: true, signup_status: null },
      { id: 9, name: 'Maria', email: 'maria@example.com', is_admin: false, is_active: false, signup_status: 'pending', signup_requested_at: '2026-11-08T10:00:00Z' },
    ]
    apiGet.mockImplementation((url: string) => Promise.resolve({ data: { data: url === '/admin/users' ? users : [] } }))
    apiPost.mockImplementation((url: string) => {
      if (url === '/admin/users/9/approve') users = users.map((u) => (u.id === 9 ? { ...u, is_active: true, signup_status: 'approved' } : u))
      return Promise.resolve({ data: { data: {} } })
    })

    const wrapper = mount(AdminPanel, { props: { isOpen: false } })
    await wrapper.setProps({ isOpen: true })
    await flushPromises()
    expect(wrapper.find('[data-testid="admin-pending-badge"]').text()).toBe('1')

    const usersTab = wrapper.findAll('.tab-btn').find((b) => b.text().startsWith('Users'))!
    await usersTab.trigger('click')
    expect(wrapper.find('[data-testid="admin-pending-9"]').text()).toContain('maria@example.com')

    await wrapper.find('[data-testid="admin-approve-9"]').trigger('click')
    await flushPromises()
    expect(apiPost).toHaveBeenCalledWith('/admin/users/9/approve')
    expect(wrapper.find('[data-testid="admin-pending-signups"]').exists()).toBe(false)
  })
})
