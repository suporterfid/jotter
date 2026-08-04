import { describe, expect, it, vi, beforeEach } from 'vitest'

vi.mock('axios', () => {
  const put = vi.fn().mockResolvedValue({ data: { data: { id: 1, path: 'docs/a.md' } } })
  const post = vi.fn().mockResolvedValue({ data: { data: { id: 1, path: 'docs/a.md' } } })
  const get = vi.fn().mockResolvedValue({ data: { data: [{ folder_path: 'docs', sort_position: 0 }] } })
  const del = vi.fn()
  const instance = { put, post, get, delete: del, interceptors: { response: { use: vi.fn() } } }
  return {
    default: {
      create: vi.fn(() => instance),
      get,
      defaults: {},
    },
  }
})

import { moveNote, reorderNoteTree, getFolderPositions, createBoard, updateBoard, deleteBoard } from './services/api'
import axios from 'axios'

const instance = (axios.create as ReturnType<typeof vi.fn>).mock.results[0].value

describe('note-tree API functions', () => {
  beforeEach(() => {
    instance.put.mockClear()
    instance.post.mockClear()
    instance.get.mockClear()
  })

  it('moveNote posts new_path to the move endpoint', async () => {
    await moveNote(1, 7, 'docs/renamed.md')
    expect(instance.post).toHaveBeenCalledWith('/workspaces/1/notes/7/move', { new_path: 'docs/renamed.md' })
  })

  it('reorderNoteTree puts folder_path and items to the order endpoint', async () => {
    await reorderNoteTree(1, 'docs', [{ type: 'note', id: 7 }, { type: 'folder', path: 'docs/archived' }])
    expect(instance.put).toHaveBeenCalledWith('/workspaces/1/note-tree/order', {
      folder_path: 'docs',
      items: [{ type: 'note', id: 7 }, { type: 'folder', path: 'docs/archived' }],
    })
  })

  it('getFolderPositions gets the order endpoint and returns the data array', async () => {
    const result = await getFolderPositions(1)
    expect(result).toEqual([{ folder_path: 'docs', sort_position: 0 }])
  })

  it('createBoard posts the board attrs to the boards endpoint', async () => {
    await createBoard(1, { name: 'Sprint', group_property: 'status' })
    expect(instance.post).toHaveBeenCalledWith('/workspaces/1/boards', { name: 'Sprint', group_property: 'status' })
  })

  it('updateBoard puts the changed attrs to the board endpoint', async () => {
    await updateBoard(1, 5, { name: 'Renamed' })
    expect(instance.put).toHaveBeenCalledWith('/workspaces/1/boards/5', { name: 'Renamed' })
  })

  it('deleteBoard deletes the board endpoint', async () => {
    await deleteBoard(1, 5)
    expect(instance.delete).toHaveBeenCalledWith('/workspaces/1/boards/5')
  })
})
