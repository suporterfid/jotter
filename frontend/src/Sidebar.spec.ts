import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
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
    const wrapper = mount(Sidebar, { props: { notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [] } })
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
      props: { notes, selectedNoteId: null, workspaceId: 1, folderPositions, workspaces: [] },
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
      props: { notes, selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [] },
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
      props: { notes, selectedNoteId: null, workspaceId: 1, folderPositions: [], workspaces: [] },
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
      },
    })
    await wrapper.find('[data-testid="workspace-switcher-select"]').setValue('2')
    expect(wrapper.emitted('switch-workspace')![0]).toEqual([2])
  })
})
