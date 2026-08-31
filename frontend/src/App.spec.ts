import { config, mount, flushPromises } from '@vue/test-utils'
import { beforeEach, describe, expect, it, vi } from 'vitest'
import App from './App.vue'
import { createNote, getWorkspaces, getAuthConfig, getTenants, getNotes, getNote, deleteNote, getNotifications, getTrash, restoreTrashNote, permanentlyDeleteTrashNote, queueWorkspacePdfExport, getWorkspacePdfExport, downloadWorkspacePdfExport } from './services/api'

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
  getTrash: vi.fn().mockResolvedValue([
    { id: 20, title: 'Deleted note', original_path: 'deleted.md', frontmatter: null, deleted_at: '2026-08-17T15:30:00Z' }
  ]),
  restoreTrashNote: vi.fn().mockResolvedValue({ id: 20, path: 'deleted.md', title: 'Deleted note', frontmatter: null, sort_position: null, updated_at: '2026-08-17T15:30:00Z' }),
  permanentlyDeleteTrashNote: vi.fn().mockResolvedValue(undefined),
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
    is_admin: true,
    locale: 'pt-BR'
  }),
  login: vi.fn(),
  logout: vi.fn(),
  getCsrfCookie: vi.fn().mockResolvedValue(undefined),
  setUnauthenticatedHandler: vi.fn(),
  getAuthConfig: vi.fn().mockResolvedValue({ provider: 'local', sso_login_url: null, version: null }),
  getNoteComments: vi.fn().mockResolvedValue([]),
  getChecklistItems: vi.fn().mockResolvedValue([]),
  getNoteActivity: vi.fn().mockResolvedValue([]),
  getNoteShare: vi.fn().mockResolvedValue({ active: false, url: null, expires_at: null, revoked_at: null }),
  createNoteShare: vi.fn().mockResolvedValue({ active: true, url: 'https://example.test/share/token', token: 'token', expires_at: null, revoked_at: null }),
  revokeNoteShare: vi.fn().mockResolvedValue(undefined),
  getUnlinkedMentions: vi.fn().mockResolvedValue([]),
  getOutgoingLinks: vi.fn().mockResolvedValue([]),
  getWorkspaceProperties: vi.fn().mockResolvedValue([]),
  getNotifications: vi.fn().mockResolvedValue([]),
  queueWorkspacePdfExport: vi.fn().mockResolvedValue({ id: 'export-1', status: 'queued', scope: 'workspace' }),
  getWorkspacePdfExport: vi.fn().mockResolvedValue({ id: 'export-1', status: 'ready', scope: 'workspace' }),
  downloadWorkspacePdfExport: vi.fn(),
}))

// The real Milkdown editor arms 3s readiness timers that can fire after the
// jsdom environment is torn down ("removeEventListener is not defined" as an
// unhandled error under load). Nothing here interacts with the WYSIWYG surface,
// so stub it; NoteEditor (the textarea surface) stays real.
config.global.stubs = { ...config.global.stubs, NoteEditorWysiwyg: true }

beforeEach(() => {
  localStorage.clear()
})

describe('App Component', () => {
  it('keeps primary and secondary active notes independent in split mode', async () => {
    localStorage.setItem(
      'jotter-open-tabs:1',
      JSON.stringify({ openNoteIds: [10, 11], activeNoteId: 10 })
    )
    vi.mocked(getNotes).mockResolvedValueOnce([
      { id: 10, path: 'welcome.md', title: 'Welcome Note', frontmatter: null, sort_position: null, updated_at: '2026-07-27T00:00:00Z' },
      { id: 11, path: 'second.md', title: 'Second Note', frontmatter: null, sort_position: null, updated_at: '2026-07-28T00:00:00Z' },
    ])
    const previousGetNote = vi.mocked(getNote).getMockImplementation()
    vi.mocked(getNote).mockImplementation(async (_workspaceId, noteId) => ({
      id: noteId,
      path: noteId === 11 ? 'second.md' : 'welcome.md',
      title: noteId === 11 ? 'Second Note' : 'Welcome Note',
      frontmatter: null,
      sort_position: null,
      updated_at: '2026-07-27T00:00:00Z',
      content: `# ${noteId === 11 ? 'Second' : 'Welcome'}`,
      backlinks: [],
    }))

    const wrapper = mount(App)
    await flushPromises()

    await wrapper.find('[data-testid="split-tab-btn"]').trigger('click')
    await flushPromises()

    expect(wrapper.findAll('[data-pane-id]')).toHaveLength(2)
    expect(wrapper.find('[data-pane-id="primary"]').attributes('data-active-note-id')).not.toBe(
      wrapper.find('[data-pane-id="secondary"]').attributes('data-active-note-id')
    )

    await wrapper.find('[data-pane-id="secondary"] [data-testid="tab-strip-item"]').trigger('click')
    expect(wrapper.find('[data-pane-id="primary"]').attributes('data-active-note-id')).toBe('10')
    expect(wrapper.find('[data-pane-id="secondary"]').attributes('data-active-note-id')).toBe('11')

    if (previousGetNote) vi.mocked(getNote).mockImplementation(previousGetNote)
  })

  it('splits a dragged tab through the accessible drop zone', async () => {
    localStorage.setItem(
      'jotter-open-tabs:1',
      JSON.stringify({ openNoteIds: [10, 11], activeNoteId: 10 })
    )
    vi.mocked(getNotes).mockResolvedValueOnce([
      { id: 10, path: 'welcome.md', title: 'Welcome Note', frontmatter: null, sort_position: null, updated_at: '2026-07-27T00:00:00Z' },
      { id: 11, path: 'second.md', title: 'Second Note', frontmatter: null, sort_position: null, updated_at: '2026-07-28T00:00:00Z' },
    ])

    const wrapper = mount(App)
    await flushPromises()
    await wrapper.find('[data-pane-id="primary"] [data-testid="tab-strip-item"]').trigger('dragstart')
    await wrapper.find('[data-testid="split-drop-zone"]').trigger('drop')
    await flushPromises()

    expect(wrapper.find('[data-pane-id="secondary"]').exists()).toBe(true)
  })

  it('queues a workspace PDF export and downloads it when ready', async () => {
    const wrapper = mount(App)
    await flushPromises()

    await wrapper.findComponent({ name: 'Sidebar' }).vm.$emit('export-workspace-pdf')
    await flushPromises()

    expect(queueWorkspacePdfExport).toHaveBeenCalledWith(1)
    expect(getWorkspacePdfExport).toHaveBeenCalledWith(1, 'export-1')
    expect(downloadWorkspacePdfExport).toHaveBeenCalledWith(1, 'export-1')
  })

  it('renders the Jotter sidebar brand title', () => {
    const wrapper = mount(App)
    expect(wrapper.find('.brand-title').text()).toBe('Jotter')
  })

  it('loads an empty notification list without reporting a mock error', async () => {
    const errorSpy = vi.spyOn(console, 'error').mockImplementation(() => {})
    const wrapper = mount(App)

    await flushPromises()

    expect(getNotifications).toHaveBeenCalledWith(1)
    expect(errorSpy).not.toHaveBeenCalled()

    wrapper.unmount()
    errorSpy.mockRestore()
  })

  it('opens the trash panel and loads deleted notes when Sidebar requests it', async () => {
    const wrapper = mount(App)
    await flushPromises()

    await wrapper.findComponent({ name: 'Sidebar' }).vm.$emit('toggle-trash')
    await flushPromises()

    expect(getTrash).toHaveBeenCalledWith(1)
    expect(wrapper.findComponent({ name: 'TrashPanel' }).exists()).toBe(true)
    expect(wrapper.find('[data-testid="trash-note-row"]').text()).toContain('deleted.md')
  })

  it('restores a trash note and refreshes the notes list', async () => {
    const wrapper = mount(App)
    await flushPromises()
    await wrapper.findComponent({ name: 'Sidebar' }).vm.$emit('toggle-trash')
    await flushPromises()

    await wrapper.find('[data-testid="trash-restore-btn"]').trigger('click')
    await flushPromises()

    expect(restoreTrashNote).toHaveBeenCalledWith(1, 20)
    expect(getNotes).toHaveBeenCalled()
  })

  it('permanently deletes a trash note after panel confirmation', async () => {
    const confirm = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(App)
    await flushPromises()
    await wrapper.findComponent({ name: 'Sidebar' }).vm.$emit('toggle-trash')
    await flushPromises()

    await wrapper.find('[data-testid="trash-permanent-delete-btn"]').trigger('click')
    await flushPromises()

    expect(permanentlyDeleteTrashNote).toHaveBeenCalledWith(1, 20)
    confirm.mockRestore()
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

describe('App tab strip', () => {
  it('opening the same note twice does not duplicate its tab', async () => {
    const wrapper = mount(App)
    await flushPromises()

    await wrapper.findComponent({ name: 'Sidebar' }).vm.$emit('select-note', 10)
    await flushPromises()

    expect(wrapper.findAllComponents({ name: 'TabStrip' })[0].props('tabs')).toEqual([
      { id: 10, title: 'Welcome Note' },
    ])
  })

  it('opening a second note keeps the first tab present', async () => {
    vi.mocked(getNotes).mockResolvedValueOnce([
      { id: 10, path: 'welcome.md', title: 'Welcome Note', frontmatter: null, sort_position: null, updated_at: '2026-07-27T00:00:00Z' },
      { id: 11, path: 'second.md', title: 'Second Note', frontmatter: null, sort_position: null, updated_at: '2026-07-27T00:00:00Z' },
    ])
    vi.mocked(getNote).mockResolvedValueOnce({
      id: 11, path: 'second.md', title: 'Second Note', frontmatter: null, sort_position: null,
      updated_at: '2026-07-27T00:00:00Z', content: '# Second', backlinks: [],
    })
    const wrapper = mount(App)
    await flushPromises()

    await wrapper.findComponent({ name: 'Sidebar' }).vm.$emit('select-note', 11)
    await flushPromises()

    const tabs = wrapper.findComponent({ name: 'TabStrip' }).props('tabs')
    expect(tabs.map((t: { id: number }) => t.id)).toEqual([10, 11])
  })

  it('closing the active tab switches to and loads a neighbor', async () => {
    vi.mocked(getNotes).mockResolvedValueOnce([
      { id: 10, path: 'welcome.md', title: 'Welcome Note', frontmatter: null, sort_position: null, updated_at: '2026-07-27T00:00:00Z' },
      { id: 11, path: 'second.md', title: 'Second Note', frontmatter: null, sort_position: null, updated_at: '2026-07-27T00:00:00Z' },
    ])
    vi.mocked(getNote).mockResolvedValueOnce({
      id: 10, path: 'welcome.md', title: 'Welcome Note', frontmatter: null, sort_position: null,
      updated_at: '2026-07-27T00:00:00Z', content: '# Welcome', backlinks: [],
    }).mockResolvedValueOnce({
      id: 11, path: 'second.md', title: 'Second Note', frontmatter: null, sort_position: null,
      updated_at: '2026-07-27T00:00:00Z', content: '# Second', backlinks: [],
    })
    const wrapper = mount(App)
    await flushPromises()
    await wrapper.findComponent({ name: 'Sidebar' }).vm.$emit('select-note', 11)
    await flushPromises()

    await wrapper.findComponent({ name: 'TabStrip' }).vm.$emit('close-tab', 11)
    await flushPromises()

    expect(wrapper.findComponent({ name: 'TabStrip' }).props('activeId')).toBe(10)
    expect(wrapper.findComponent({ name: 'NoteEditor' }).props('note').id).toBe(10)
  })

  it('closing the last tab clears to the empty state', async () => {
    const wrapper = mount(App)
    await flushPromises()

    await wrapper.findComponent({ name: 'TabStrip' }).vm.$emit('close-tab', 10)
    await flushPromises()

    expect(wrapper.findComponent({ name: 'NoteEditor' }).exists()).toBe(false)
    expect(wrapper.text()).toContain('Nenhuma nota selecionada')
  })

  it('deleting a note removes its tab', async () => {
    vi.mocked(getNotes).mockResolvedValueOnce([
      { id: 10, path: 'welcome.md', title: 'Welcome Note', frontmatter: null, sort_position: null, updated_at: '2026-07-27T00:00:00Z' },
    ]).mockResolvedValueOnce([])
    vi.mocked(deleteNote).mockResolvedValueOnce(undefined)
    vi.spyOn(window, 'confirm').mockReturnValue(true)

    const wrapper = mount(App)
    await flushPromises()

    await wrapper.findComponent({ name: 'Sidebar' }).vm.$emit('delete-note', 10)
    await flushPromises()

    expect(wrapper.findComponent({ name: 'TabStrip' }).exists()).toBe(false)
  })

  it('restores the open tabs and active note from localStorage on remount', async () => {
    vi.mocked(getNotes).mockResolvedValue([
      { id: 10, path: 'welcome.md', title: 'Welcome Note', frontmatter: null, sort_position: null, updated_at: '2026-07-27T00:00:00Z' },
      { id: 11, path: 'second.md', title: 'Second Note', frontmatter: null, sort_position: null, updated_at: '2026-07-27T00:00:00Z' },
    ])
    localStorage.setItem('jotter-open-tabs:1', JSON.stringify({ openNoteIds: [10, 11], activeNoteId: 11 }))
    vi.mocked(getNote).mockResolvedValueOnce({
      id: 11, path: 'second.md', title: 'Second Note', frontmatter: null, sort_position: null,
      updated_at: '2026-07-27T00:00:00Z', content: '# Second', backlinks: [],
    })

    const wrapper = mount(App)
    await flushPromises()

    const tabs = wrapper.findComponent({ name: 'TabStrip' }).props('tabs')
    expect(tabs.map((t: { id: number }) => t.id)).toEqual([10, 11])
    expect(wrapper.findComponent({ name: 'TabStrip' }).props('activeId')).toBe(11)
  })
})
