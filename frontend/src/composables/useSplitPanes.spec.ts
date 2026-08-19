import { beforeEach, describe, expect, it } from 'vitest'
import { useSplitPanes } from './useSplitPanes'

describe('useSplitPanes', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('migrates the existing primary tab state on first load', () => {
    localStorage.setItem(
      'jotter-open-tabs:7',
      JSON.stringify({ openNoteIds: [11, 12], activeNoteId: 12 })
    )

    const { layout, loadLayout } = useSplitPanes()
    loadLayout(7)

    expect(layout.value).toEqual({
      enabled: false,
      primary: { id: 'primary', openNoteIds: [11, 12], activeNoteId: 12 },
      secondary: { id: 'secondary', openNoteIds: [], activeNoteId: null },
      primaryRatio: 0.5,
    })
  })

  it('resets malformed persisted layout safely', () => {
    localStorage.setItem('jotter-split-layout:7', '{not-json')

    const { layout, loadLayout } = useSplitPanes()
    loadLayout(7)

    expect(layout.value.enabled).toBe(false)
    expect(layout.value.primary.openNoteIds).toEqual([])
    expect(layout.value.secondary.openNoteIds).toEqual([])
  })

  it('opens a note idempotently and selects it in the requested pane', () => {
    const { layout, openNote } = useSplitPanes()

    openNote('primary', 11)
    openNote('primary', 11)

    expect(layout.value.primary.openNoteIds).toEqual([11])
    expect(layout.value.primary.activeNoteId).toBe(11)
  })

  it('selects the neighbor when closing the active note', () => {
    const { layout, openNote, closeNote } = useSplitPanes()
    ;[11, 12, 13].forEach((noteId) => openNote('primary', noteId))
    openNote('primary', 12)

    closeNote('primary', 12)

    expect(layout.value.primary.openNoteIds).toEqual([11, 13])
    expect(layout.value.primary.activeNoteId).toBe(13)
  })

  it('splits the current note and selects the next open note in secondary', () => {
    const { layout, openNote, splitWithNote } = useSplitPanes()
    ;[11, 12, 13].forEach((noteId) => openNote('primary', noteId))

    splitWithNote(12)

    expect(layout.value.enabled).toBe(true)
    expect(layout.value.primary.activeNoteId).toBe(12)
    expect(layout.value.secondary.openNoteIds).toEqual([13])
    expect(layout.value.secondary.activeNoteId).toBe(13)
  })

  it('merges secondary without changing the primary active note', () => {
    const { layout, openNote, splitWithNote, mergeSecondary } = useSplitPanes()
    openNote('primary', 11)
    openNote('primary', 12)
    splitWithNote(11)
    openNote('primary', 12)

    mergeSecondary()

    expect(layout.value.enabled).toBe(false)
    expect(layout.value.primary.activeNoteId).toBe(12)
    expect(layout.value.secondary.openNoteIds).toEqual([])
  })

  it('clamps the primary ratio to the supported bounds', () => {
    const { layout, setPrimaryRatio } = useSplitPanes()

    setPrimaryRatio(0.1)
    expect(layout.value.primaryRatio).toBe(0.25)

    setPrimaryRatio(0.9)
    expect(layout.value.primaryRatio).toBe(0.75)
  })
})
