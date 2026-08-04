import { describe, expect, it } from 'vitest'
import { resolveCardMove, UNGROUPED_LABEL } from './services/collectionUtils'

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
