import { describe, expect, it } from 'vitest'
import { allTagNames, filterNotesByTag, noteTagNames, resolveCardMove, UNGROUPED_LABEL } from './services/collectionUtils'
import type { CollectionNote, CollectionPage } from './services/types'

function makeNote(id: number, tags: string[]): CollectionNote {
  return { id, path: `${id}.md`, title: `Note ${id}`, properties: [], tags: tags.map((name, i) => ({ id: i, name })) }
}

function makeEl(dataset: Record<string, string>): HTMLElement {
  const el = document.createElement('div')
  Object.assign(el.dataset, dataset)
  return el
}

describe('resolveCardMove', () => {
  it('returns null when the card is dropped back in its own column', () => {
    const column = makeEl({ columnKey: 'draft' })
    const item = makeEl({ noteId: '1' })
    expect(resolveCardMove(column, column, item)).toBeNull()
  })

  it('returns the note id and target column value when moved to a different column', () => {
    const from = makeEl({ columnKey: 'draft' })
    const to = makeEl({ columnKey: 'done' })
    const item = makeEl({ noteId: '1' })
    expect(resolveCardMove(from, to, item)).toEqual({ noteId: 1, newValue: 'done' })
  })

  it('resolves the ungrouped column to an empty string value (clears the property)', () => {
    const from = makeEl({ columnKey: 'draft' })
    const to = makeEl({ columnKey: UNGROUPED_LABEL })
    const item = makeEl({ noteId: '1' })
    expect(resolveCardMove(from, to, item)).toEqual({ noteId: 1, newValue: '' })
  })

  it('returns null when the dragged item has no note id', () => {
    const from = makeEl({ columnKey: 'draft' })
    const to = makeEl({ columnKey: 'done' })
    const item = makeEl({})
    expect(resolveCardMove(from, to, item)).toBeNull()
  })
})

describe('noteTagNames', () => {
  it('returns the note tag names, empty array when untagged', () => {
    expect(noteTagNames(makeNote(1, ['urgent', 'work']))).toEqual(['urgent', 'work'])
    expect(noteTagNames(makeNote(2, []))).toEqual([])
  })
})

describe('allTagNames', () => {
  it('returns the sorted, deduplicated set of tags across a page', () => {
    const page: CollectionPage = {
      data: [makeNote(1, ['work', 'urgent']), makeNote(2, ['work']), makeNote(3, [])],
      current_page: 1, last_page: 1, per_page: 50, total: 3,
    }
    expect(allTagNames(page)).toEqual(['urgent', 'work'])
  })
})

describe('filterNotesByTag', () => {
  const notes = [makeNote(1, ['work']), makeNote(2, ['personal']), makeNote(3, ['work', 'personal'])]

  it('returns all notes when no tag is selected', () => {
    expect(filterNotesByTag(notes, '')).toEqual(notes)
  })

  it('returns only notes carrying the selected tag', () => {
    expect(filterNotesByTag(notes, 'personal')).toEqual([notes[1], notes[2]])
  })
})
