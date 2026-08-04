import { describe, expect, it } from 'vitest'
import { allTagNames, applyColumnConfig, coverImageUrl, filterNotesByTag, firstDateProperty, groupNotesIntoColumns, groupNotesIntoSwimlaneRows, noteTagNames, resolveCardMove, UNGROUPED_LABEL } from './services/collectionUtils'
import type { BoardColumn } from './services/collectionUtils'
import type { CollectionNote, CollectionPage, RawNoteProperty } from './services/types'

function makeNote(id: number, tags: string[], properties: RawNoteProperty[] = []): CollectionNote {
  return { id, path: `${id}.md`, title: `Note ${id}`, properties, tags: tags.map((name, i) => ({ id: i, name })) }
}

function stringProp(name: string, value: string): RawNoteProperty {
  return { id: 1, note_id: 1, name, type: 'string', value_string: value, value_numeric: null, value_boolean: null, value_datetime: null, value_json: null }
}

function dateProp(name: string, value: string): RawNoteProperty {
  return { id: 2, note_id: 1, name, type: 'datetime', value_string: null, value_numeric: null, value_boolean: null, value_datetime: value, value_json: null }
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

describe('coverImageUrl', () => {
  it('returns the cover property value when set', () => {
    const note = makeNote(1, [], [stringProp('cover', 'https://example.com/img.png')])
    expect(coverImageUrl(note)).toBe('https://example.com/img.png')
  })

  it('returns null when there is no cover property or it is blank', () => {
    expect(coverImageUrl(makeNote(1, []))).toBeNull()
    expect(coverImageUrl(makeNote(1, [], [stringProp('cover', '  ')]))).toBeNull()
  })
})

describe('firstDateProperty', () => {
  it('returns the first datetime property name and value', () => {
    const note = makeNote(1, [], [stringProp('status', 'open'), dateProp('due', '2026-08-10')])
    expect(firstDateProperty(note)).toEqual({ name: 'due', value: '2026-08-10' })
  })

  it('returns null when there is no datetime property', () => {
    expect(firstDateProperty(makeNote(1, [], [stringProp('status', 'open')]))).toBeNull()
  })
})

describe('groupNotesIntoColumns', () => {
  it('groups notes by the property value, with a column for missing values sorted last', () => {
    const notes = [
      makeNote(1, [], [stringProp('status', 'draft')]),
      makeNote(2, [], [stringProp('status', 'done')]),
      makeNote(3, [], []),
    ]
    const result = groupNotesIntoColumns(notes, 'status')
    expect(result.map(c => c.key)).toEqual(['done', 'draft', UNGROUPED_LABEL])
    expect(result.find(c => c.key === 'draft')!.notes).toEqual([notes[0]])
  })
})

describe('groupNotesIntoSwimlaneRows', () => {
  it('groups notes by the swimlane property value, sorted, missing values last', () => {
    const notes = [
      makeNote(1, [], [stringProp('assignee', 'bob')]),
      makeNote(2, [], [stringProp('assignee', 'alice')]),
      makeNote(3, [], []),
    ]
    const result = groupNotesIntoSwimlaneRows(notes, 'assignee')
    expect(result.map(r => r.key)).toEqual(['alice', 'bob', UNGROUPED_LABEL])
    expect(result.find(r => r.key === 'bob')!.notes).toEqual([notes[0]])
  })
})

describe('applyColumnConfig', () => {
  const columns: BoardColumn[] = [
    { key: 'draft', label: 'draft', notes: [] },
    { key: 'done', label: 'done', notes: [] },
    { key: 'review', label: 'review', notes: [] },
  ]

  it('returns derived columns unchanged, defaulted, when there is no config', () => {
    expect(applyColumnConfig(columns, null)).toEqual([
      { key: 'draft', label: 'draft', notes: [], color: null, wipLimit: null, collapsed: false },
      { key: 'done', label: 'done', notes: [], color: null, wipLimit: null, collapsed: false },
      { key: 'review', label: 'review', notes: [], color: null, wipLimit: null, collapsed: false },
    ])
  })

  it('reorders columns to match the config order and applies overrides', () => {
    const result = applyColumnConfig(columns, [
      { key: 'done', label: 'Done!', color: '#22c55e', wip_limit: 3, collapsed: false },
      { key: 'draft', collapsed: true },
    ])
    expect(result.map(c => c.key)).toEqual(['done', 'draft', 'review'])
    expect(result[0]).toMatchObject({ label: 'Done!', color: '#22c55e', wipLimit: 3 })
    expect(result[1]).toMatchObject({ label: 'draft', collapsed: true })
  })

  it('drops config entries for columns no longer present in the data', () => {
    const result = applyColumnConfig(columns, [{ key: 'archived', label: 'Archived' }])
    expect(result.map(c => c.key)).toEqual(['draft', 'done', 'review'])
  })
})
