import { mount, flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App.vue'
import { createNote, getWorkspaces, getAuthConfig, getTenants } from './services/api'

vi.mock('./services/api', () => ({
  getWorkspaces: vi.fn().mockResolvedValue([
    { id: 1, tenant_id: 1, slug: 'default', name: 'Default Workspace' }
  ]),
  getTenants: vi.fn().mockResolvedValue([
    { id: 1, slug: 'default', name: 'Default Tenant' }
  ]),
  getNotes: vi.fn().mockResolvedValue([
    { id: 10, path: 'welcome.md', title: 'Welcome Note', frontmatter: null, sort_position: null, updated_at: '2026-07-27T00:00:00Z' }
  ]),
  getFolderPositions: vi.fn().mockResolvedValue([]),
  getNote: vi.fn().mockResolvedValue({
    id: 10,
    path: 'welcome.md',
    title: 'Welcome Note',
    frontmatter: null,
    sort_position: null,
    updated_at: '2026-07-27T00:00:00Z',
    content: '# Welcome to Jotter\n\n[[Other Note]]',
    backlinks: []
  }),
  createNote: vi.fn().mockResolvedValue({ id: 99, path: 'docs/untitled-x.md', title: '', frontmatter: null, sort_position: null, updated_at: '2026-07-31T00:00:00Z' }),
  updateNote: vi.fn(),
  deleteNote: vi.fn(),
  searchNotes: vi.fn().mockResolvedValue([]),
  getMe: vi.fn().mockResolvedValue({
    subject_id: '1',
    email: 'admin@example.com',
    name: 'Admin',
    is_admin: true
  }),
  login: vi.fn(),
  logout: vi.fn(),
  getCsrfCookie: vi.fn().mockResolvedValue(undefined),
  setUnauthenticatedHandler: vi.fn(),
  getAuthConfig: vi.fn().mockResolvedValue({ provider: 'local', sso_login_url: null, version: null })
}))

beforeEach(() => {
  localStorage.clear()
})

describe('App Component', () => {
  it('renders the Jotter sidebar brand title', () => {
    const wrapper = mount(App)
    expect(wrapper.find('.brand-title').text()).toBe('Jotter')
  })
})

describe('App folder quick-create', () => {
  it('prefixes the auto-generated filename with the folder path', async () => {
    const wrapper = mount(App)
    await wrapper.vm.$nextTick()
    const vm = wrapper.vm as any
    await vm.handleCreateNoteInFolder('docs')
    expect(createNote).toHaveBeenCalledWith(
      1,
      expect.stringMatching(/^docs\/untitled-[a-z0-9]+\.md$/),
      expect.any(String),
    )
  })

  it('creates at the vault root when folderPath is empty', async () => {
    const wrapper = mount(App)
    await wrapper.vm.$nextTick()
    const vm = wrapper.vm as any
    await vm.handleCreateNoteInFolder('')
    expect(createNote).toHaveBeenCalledWith(
      1,
      expect.stringMatching(/^untitled-[a-z0-9]+\.md$/),
      expect.any(String),
    )
  })
})

describe('App reveal folder', () => {
  it('opens the mobile sidebar when a folder is revealed', async () => {
    const wrapper = mount(App)
    await wrapper.vm.$nextTick()
    const vm = wrapper.vm as any
    vm.handleRevealFolder('docs')
    await wrapper.vm.$nextTick()
    expect(vm.isMobileSidebarOpen).toBe(true)
  })
})

describe('App workspace switching and persistence', () => {
  it('uses the workspace id from localStorage when it is in the fetched list', async () => {
    vi.mocked(getWorkspaces).mockResolvedValueOnce([
      { id: 1, tenant_id: 1, slug: 'main', name: 'Main' },
      { id: 2, tenant_id: 1, slug: 'side', name: 'Side' },
    ])
    localStorage.setItem('jotter-active-workspace-id', '2')

    const wrapper = mount(App)
    await flushPromises()

    expect(wrapper.findComponent({ name: 'Sidebar' }).props('workspaceId')).toBe(2)
  })

  it('falls back to the first workspace when the stored id is not in the list', async () => {
    vi.mocked(getWorkspaces).mockResolvedValueOnce([
      { id: 1, tenant_id: 1, slug: 'main', name: 'Main' },
    ])
    localStorage.setItem('jotter-active-workspace-id', '999')

    const wrapper = mount(App)
    await flushPromises()

    expect(wrapper.findComponent({ name: 'Sidebar' }).props('workspaceId')).toBe(1)
    expect(localStorage.getItem('jotter-active-workspace-id')).toBe('1')
  })

  it('persists the new workspace id when Sidebar emits switch-workspace', async () => {
    vi.mocked(getWorkspaces).mockResolvedValueOnce([
      { id: 1, tenant_id: 1, slug: 'main', name: 'Main' },
      { id: 2, tenant_id: 1, slug: 'side', name: 'Side' },
    ])

    const wrapper = mount(App)
    await flushPromises()

    await wrapper.findComponent({ name: 'Sidebar' }).vm.$emit('switch-workspace', 2)

    expect(localStorage.getItem('jotter-active-workspace-id')).toBe('2')
    expect(wrapper.findComponent({ name: 'Sidebar' }).props('workspaceId')).toBe(2)
  })

  it('passes the frontend version and fetched backend version down to Sidebar', async () => {
    vi.mocked(getAuthConfig).mockResolvedValueOnce({
      provider: 'local',
      sso_login_url: null,
      version: 'abc1234 · 2026-08-01 16:30',
    })

    const wrapper = mount(App)
    await flushPromises()

    expect(wrapper.findComponent({ name: 'Sidebar' }).props('backendVersion')).toBe('abc1234 · 2026-08-01 16:30')
    expect(wrapper.findComponent({ name: 'Sidebar' }).props('frontendVersion')).toBe('dev')
  })

  it('passes the auth provider down to Sidebar and opens the Change Password modal on request', async () => {
    const wrapper = mount(App)
    await flushPromises()

    expect(wrapper.findComponent({ name: 'Sidebar' }).props('authProvider')).toBe('local')
    expect(wrapper.findComponent({ name: 'ChangePasswordModal' }).exists()).toBe(false)

    await wrapper.findComponent({ name: 'Sidebar' }).vm.$emit('open-change-password')

    expect(wrapper.findComponent({ name: 'ChangePasswordModal' }).exists()).toBe(true)
  })
})

describe('App tenant switching and persistence', () => {
  it('does not fetch a tenant-scoped workspace list when there is only one tenant', async () => {
    vi.mocked(getTenants).mockResolvedValueOnce([
      { id: 1, slug: 'default', name: 'Default Tenant' },
    ])
    vi.mocked(getWorkspaces).mockResolvedValueOnce([
      { id: 1, tenant_id: 1, slug: 'main', name: 'Main' },
    ])

    const wrapper = mount(App)
    await flushPromises()

    expect(getWorkspaces).toHaveBeenCalledWith(undefined)
    expect(wrapper.findComponent({ name: 'Sidebar' }).props('tenants')).toEqual([
      { id: 1, slug: 'default', name: 'Default Tenant' },
    ])
  })

  it('resolves the active tenant from localStorage when there are 2+ tenants', async () => {
    vi.mocked(getTenants).mockResolvedValueOnce([
      { id: 1, slug: 'acme', name: 'Acme Corp' },
      { id: 2, slug: 'globex', name: 'Globex Inc' },
    ])
    vi.mocked(getWorkspaces).mockResolvedValueOnce([
      { id: 5, tenant_id: 2, slug: 'side', name: 'Side' },
    ])
    localStorage.setItem('jotter-active-tenant-id', '2')

    const wrapper = mount(App)
    await flushPromises()

    expect(getWorkspaces).toHaveBeenCalledWith(2)
    expect(wrapper.findComponent({ name: 'Sidebar' }).props('activeTenantId')).toBe(2)
  })

  it('falls back to the first tenant when the stored id is not in the list', async () => {
    vi.mocked(getTenants).mockResolvedValueOnce([
      { id: 1, slug: 'acme', name: 'Acme Corp' },
      { id: 2, slug: 'globex', name: 'Globex Inc' },
    ])
    vi.mocked(getWorkspaces).mockResolvedValueOnce([])
    localStorage.setItem('jotter-active-tenant-id', '999')

    const wrapper = mount(App)
    await flushPromises()

    expect(wrapper.findComponent({ name: 'Sidebar' }).props('activeTenantId')).toBe(1)
    expect(localStorage.getItem('jotter-active-tenant-id')).toBe('1')
  })

  it('persists the new tenant id and refetches workspaces when Sidebar emits switch-tenant', async () => {
    vi.mocked(getTenants).mockResolvedValueOnce([
      { id: 1, slug: 'acme', name: 'Acme Corp' },
      { id: 2, slug: 'globex', name: 'Globex Inc' },
    ])
    vi.mocked(getWorkspaces)
      .mockResolvedValueOnce([{ id: 5, tenant_id: 1, slug: 'main', name: 'Main' }])
      .mockResolvedValueOnce([{ id: 9, tenant_id: 2, slug: 'side', name: 'Side' }])

    const wrapper = mount(App)
    await flushPromises()

    await wrapper.findComponent({ name: 'Sidebar' }).vm.$emit('switch-tenant', 2)
    await flushPromises()

    expect(localStorage.getItem('jotter-active-tenant-id')).toBe('2')
    expect(getWorkspaces).toHaveBeenLastCalledWith(2)
    expect(wrapper.findComponent({ name: 'Sidebar' }).props('workspaceId')).toBe(9)
  })
})
