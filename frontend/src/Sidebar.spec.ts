import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import Sidebar from './components/Sidebar.vue'
import type { NoteMeta, FolderPosition } from './services/types'

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
    const wrapper = mount(Sidebar, { props: { notes: [], selectedNoteId: null, workspaceId: 1, folderPositions: [] } })
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
      props: { notes, selectedNoteId: null, workspaceId: 1, folderPositions },
    })
    await wrapper.find('#sidebar-sort-select').setValue('manual')

    const titles = wrapper.findAll('.note-title, .folder-name').map(el => el.text())
    expect(titles).toEqual(['docs', 'A', 'archived', 'Inner', 'Z'])
  })
})
