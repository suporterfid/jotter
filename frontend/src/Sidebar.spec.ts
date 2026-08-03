import { mount } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import Sidebar from './components/Sidebar.vue'
import type { NoteMeta, FolderPosition, Workspace } from './services/types'

vi.mock('./services/api', () => ({
  moveNote: vi.fn(),
  reorderNoteTree: vi.fn(),
}))

function makeNote(overrides: Partial<NoteMeta>): NoteMeta {
  return {
    id: 1,
    path: 'a.md',
    title: 'A',
    frontmatter: null,
    sort_position: null,
    updated_at: '2026-07-31T00:00:00Z',
    ...overrides,
  }
}

describe('Sidebar manual sort mode', () => {
  it('offers a Manual option in the sort dropdown', () => {
    const wrapper = mount(Sidebar, { props: { notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev' } })
    const options = wrapper.findAll('#sidebar-sort-select option').map(o => o.attributes('value'))
    expect(options).toContain('manual')
  })

  it('orders notes and folders by sort_position when manual mode is active', async () => {
    const notes: NoteMeta[] = [
      makeNote({ id: 1, path: 'docs/z-note.md', title: 'Z', sort_position: 20 }),
      makeNote({ id: 2, path: 'docs/a-note.md', title: 'A', sort_position: 0 }),
      makeNote({ id: 3, path: 'docs/archived/inner.md', title: 'Inner', sort_position: null }),
    ]
    const folderPositions: FolderPosition[] = [{ folder_path: 'docs/archived', sort_position: 10 }]

    const wrapper = mount(Sidebar, {
      props: { notes, selectedNoteId: null, workspaceId: 1, folderPositions, workspaces: [], frontendVersion: 'dev' },
    })
    await wrapper.find('#sidebar-sort-select').setValue('manual')

    const titles = wrapper.findAll('.note-title, .folder-name').map(el => el.text())
    expect(titles).toEqual(['docs', 'A', 'archived', 'Inner', 'Z'])
  })
})

describe('Sidebar folder quick-create', () => {
  it('bubbles create-note-in-folder from a root-level folder up to its own emit', async () => {
    const notes: NoteMeta[] = [
      makeNote({ id: 1, path: 'docs/a.md', title: 'A' }),
    ]
    const wrapper = mount(Sidebar, {
      props: { notes, selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev' },
    })
    await wrapper.find('[data-testid="folder-create-note-btn"]').trigger('click')
    expect(wrapper.emitted('create-note-in-folder')).toEqual([['docs']])
  })
})

describe('Sidebar reveal folder', () => {
  it('expands a nested folder and its ancestor when revealFolderRequest is set', async () => {
    const notes: NoteMeta[] = [
      makeNote({ id: 1, path: 'docs/archived/inner.md' }),
    ]
    const wrapper = mount(Sidebar, {
      props: { notes, selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev' },
    })

    // Both start expanded by default (NoteTreeNode's `expanded` ref defaults
    // to true) — collapse them first so the test proves reveal re-expands.
    const folderRows = wrapper.findAll('.folder-row')
    await folderRows[0].trigger('click')
    await folderRows[1].trigger('click')

    await wrapper.setProps({ revealFolderRequest: { path: 'docs/archived', nonce: 1 } })
    await wrapper.vm.$nextTick()

    const children = wrapper.findAll('.folder-children')
    for (const child of children) {
      expect((child.element as HTMLElement).style.display).not.toBe('none')
    }
  })
})

describe('Sidebar workspace switcher', () => {
  it('renders the workspace switcher with the provided workspaces', () => {
    const workspaces: Workspace[] = [
      { id: 1, tenant_id: 1, slug: 'main', name: 'Main Workspace' },
      { id: 2, tenant_id: 1, slug: 'side', name: 'Side Project' },
    ]
    const wrapper = mount(Sidebar, {
      props: {
        notes: [],
        selectedNoteId: null,
        workspaceId: 1,
        folderPositions: [],
        workspaces,
        frontendVersion: 'dev',
      },
    })
    expect(wrapper.findAll('[data-testid="workspace-switcher-select"] option')).toHaveLength(2)
  })

  it('emits switch-workspace when the switcher changes selection', async () => {
    const workspaces: Workspace[] = [
      { id: 1, tenant_id: 1, slug: 'main', name: 'Main Workspace' },
      { id: 2, tenant_id: 1, slug: 'side', name: 'Side Project' },
    ]
    const wrapper = mount(Sidebar, {
      props: {
        notes: [],
        selectedNoteId: null,
        workspaceId: 1,
        folderPositions: [],
        workspaces,
        frontendVersion: 'dev',
      },
    })
    await wrapper.find('[data-testid="workspace-switcher-select"]').setValue('2')
    expect(wrapper.emitted('switch-workspace')![0]).toEqual([2])
  })
})

describe('Sidebar tenant switcher', () => {
  it('does not render the tenant switcher when given 0 tenants', () => {
    const wrapper = mount(Sidebar, {
      props: { notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev' },
    })
    expect(wrapper.find('[data-testid="tenant-switcher-select"]').exists()).toBe(false)
  })

  it('does not render the tenant switcher when given exactly 1 tenant', () => {
    const wrapper = mount(Sidebar, {
      props: {
        notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev',
        tenants: [{ id: 1, slug: 'acme', name: 'Acme Corp' }],
      },
    })
    expect(wrapper.find('[data-testid="tenant-switcher-select"]').exists()).toBe(false)
  })

  it('renders the tenant switcher when given 2+ tenants', () => {
    const wrapper = mount(Sidebar, {
      props: {
        notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev',
        tenants: [
          { id: 1, slug: 'acme', name: 'Acme Corp' },
          { id: 2, slug: 'globex', name: 'Globex Inc' },
        ],
      },
    })
    expect(wrapper.findAll('[data-testid="tenant-switcher-select"] option')).toHaveLength(2)
  })

  it('emits switch-tenant when the switcher changes selection', async () => {
    const wrapper = mount(Sidebar, {
      props: {
        notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev',
        tenants: [
          { id: 1, slug: 'acme', name: 'Acme Corp' },
          { id: 2, slug: 'globex', name: 'Globex Inc' },
        ],
        activeTenantId: 1,
      },
    })
    await wrapper.find('[data-testid="tenant-switcher-select"]').setValue('2')
    expect(wrapper.emitted('switch-tenant')![0]).toEqual([2])
  })
})

describe('Sidebar version footer', () => {
  it('renders the frontend version', () => {
    const wrapper = mount(Sidebar, {
      props: { notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'abc1234 · 2026-08-01 16:30' },
    })
    expect(wrapper.find('[data-testid="app-version"]').text()).toContain('abc1234 · 2026-08-01 16:30')
  })

  it('shows the backend version alongside when it differs from the frontend version', () => {
    const wrapper = mount(Sidebar, {
      props: {
        notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [],
        frontendVersion: 'abc1234 · 2026-08-01 16:30',
        backendVersion: 'def5678 · 2026-08-01 16:31',
      },
    })
    expect(wrapper.find('[data-testid="app-version-mismatch"]').text()).toContain('def5678 · 2026-08-01 16:31')
  })

  it('does not show a mismatch segment when backend version matches frontend version', () => {
    const wrapper = mount(Sidebar, {
      props: {
        notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [],
        frontendVersion: 'abc1234 · 2026-08-01 16:30',
        backendVersion: 'abc1234 · 2026-08-01 16:30',
      },
    })
    expect(wrapper.find('[data-testid="app-version-mismatch"]').exists()).toBe(false)
  })

  it('does not show a mismatch segment when backend version is absent', () => {
    const wrapper = mount(Sidebar, {
      props: { notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'abc1234 · 2026-08-01 16:30' },
    })
    expect(wrapper.find('[data-testid="app-version-mismatch"]').exists()).toBe(false)
  })
})

describe('Sidebar desktop collapse', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('is expanded by default (not collapsed) on first mount', () => {
    const wrapper = mount(Sidebar, {
      props: { notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev' },
    })
    expect(wrapper.find('.sidebar').classes()).not.toContain('sidebar-collapsed')
    expect(wrapper.find('[data-testid="sidebar-expand-btn"]').exists()).toBe(false)
  })

  it('collapses via the More actions menu, and shows the expand button instead', async () => {
    const wrapper = mount(Sidebar, {
      props: { notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev' },
    })
    await wrapper.find('[data-testid="more-actions-btn"]').trigger('click')
    await wrapper.find('[data-testid="sidebar-collapse-btn"]').trigger('click')

    expect(wrapper.find('.sidebar').classes()).toContain('sidebar-collapsed')
    expect(wrapper.find('[data-testid="sidebar-expand-btn"]').exists()).toBe(true)
  })

  it('re-expands when the expand button is clicked', async () => {
    const wrapper = mount(Sidebar, {
      props: { notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev' },
    })
    await wrapper.find('[data-testid="more-actions-btn"]').trigger('click')
    await wrapper.find('[data-testid="sidebar-collapse-btn"]').trigger('click')
    await wrapper.find('[data-testid="sidebar-expand-btn"]').trigger('click')

    expect(wrapper.find('.sidebar').classes()).not.toContain('sidebar-collapsed')
    expect(wrapper.find('[data-testid="sidebar-expand-btn"]').exists()).toBe(false)
  })

  it('persists the collapsed state across remounts via localStorage', async () => {
    const wrapperA = mount(Sidebar, {
      props: { notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev' },
    })
    await wrapperA.find('[data-testid="more-actions-btn"]').trigger('click')
    await wrapperA.find('[data-testid="sidebar-collapse-btn"]').trigger('click')
    wrapperA.unmount()

    const wrapperB = mount(Sidebar, {
      props: { notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev' },
    })
    expect(wrapperB.find('.sidebar').classes()).toContain('sidebar-collapsed')
  })
})

describe('Sidebar admin panel entry', () => {
  it('does not show the Admin Panel menu item for a non-admin user', async () => {
    const wrapper = mount(Sidebar, {
      props: {
        notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev',
        currentUser: { subject_id: '1', email: 'a@b.com', name: 'A', is_admin: false },
      },
    })
    await wrapper.find('[data-testid="more-actions-btn"]').trigger('click')
    expect(wrapper.find('[data-testid="admin-panel-btn"]').exists()).toBe(false)
  })

  it('shows the Admin Panel menu item and emits toggle-admin-panel for an admin user', async () => {
    const wrapper = mount(Sidebar, {
      props: {
        notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev',
        currentUser: { subject_id: '1', email: 'a@b.com', name: 'A', is_admin: true },
      },
    })
    await wrapper.find('[data-testid="more-actions-btn"]').trigger('click')
    await wrapper.find('[data-testid="admin-panel-btn"]').trigger('click')
    expect(wrapper.emitted('toggle-admin-panel')).toBeTruthy()
  })
})

describe('Sidebar change password entry', () => {
  it('hides the Change Password button for a non-local auth provider', () => {
    const wrapper = mount(Sidebar, {
      props: {
        notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev',
        currentUser: { subject_id: '1', email: 'a@b.com', name: 'A', is_admin: false },
        authProvider: 'grandpasson',
      },
    })
    expect(wrapper.find('[data-testid="change-password-btn"]').exists()).toBe(false)
  })

  it('shows the Change Password button and emits open-change-password for the local provider', async () => {
    const wrapper = mount(Sidebar, {
      props: {
        notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [], frontendVersion: 'dev',
        currentUser: { subject_id: '1', email: 'a@b.com', name: 'A', is_admin: false },
        authProvider: 'local',
      },
    })
    await wrapper.find('[data-testid="change-password-btn"]').trigger('click')
    expect(wrapper.emitted('open-change-password')).toBeTruthy()
  })
})
