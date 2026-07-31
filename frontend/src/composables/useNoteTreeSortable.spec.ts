import { describe, expect, it } from 'vitest'
import {
  parseSortItemFromDataset,
  itemsFromContainer,
  basename,
  pathInFolder,
  shouldRejectMove,
} from './useNoteTreeSortable'

function makeEl(dataset: Record<string, string>): HTMLElement {
  const el = document.createElement('div')
  for (const [key, value] of Object.entries(dataset)) {
    el.dataset[key] = value
  }
  return el
}

describe('parseSortItemFromDataset', () => {
  it('parses a note item', () => {
    expect(parseSortItemFromDataset(makeEl({ itemType: 'note', itemId: '42' }).dataset))
      .toEqual({ type: 'note', id: 42 })
  })

  it('parses a folder item', () => {
    expect(parseSortItemFromDataset(makeEl({ itemType: 'folder', itemPath: 'docs/archived' }).dataset))
      .toEqual({ type: 'folder', path: 'docs/archived' })
  })
})

describe('itemsFromContainer', () => {
  it('reads items from a container in DOM order', () => {
    const container = document.createElement('div')
    container.appendChild(makeEl({ itemType: 'folder', itemPath: 'docs/archived' }))
    container.appendChild(makeEl({ itemType: 'note', itemId: '7' }))

    expect(itemsFromContainer(container)).toEqual([
      { type: 'folder', path: 'docs/archived' },
      { type: 'note', id: 7 },
    ])
  })
})

describe('basename', () => {
  it('returns the segment after the last slash', () => {
    expect(basename('docs/archived/note.md')).toBe('note.md')
  })

  it('returns the whole string when there is no slash', () => {
    expect(basename('note.md')).toBe('note.md')
  })
})

describe('pathInFolder', () => {
  it('joins a non-root folder path with a file name', () => {
    expect(pathInFolder('docs/archived', 'note.md')).toBe('docs/archived/note.md')
  })

  it('returns just the file name for the root folder', () => {
    expect(pathInFolder('', 'note.md')).toBe('note.md')
  })
})

describe('shouldRejectMove', () => {
  it('rejects a folder dragged into a different container', () => {
    expect(shouldRejectMove('folder', false)).toBe(true)
  })

  it('allows a folder reordered within the same container', () => {
    expect(shouldRejectMove('folder', true)).toBe(false)
  })

  it('allows a note dragged into a different container', () => {
    expect(shouldRejectMove('note', false)).toBe(false)
  })
})
