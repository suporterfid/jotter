import { describe, it, expect, beforeEach } from 'vitest'
import { useOpenTabs } from './useOpenTabs'

describe('useOpenTabs', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('openTab appends a new note id', () => {
    const { openNoteIds, openTab } = useOpenTabs()
    openTab(1)
    openTab(2)
    expect(openNoteIds.value).toEqual([1, 2])
  })

  it('openTab does not duplicate an already-open note id', () => {
    const { openNoteIds, openTab } = useOpenTabs()
    openTab(1)
    openTab(2)
    openTab(1)
    expect(openNoteIds.value).toEqual([1, 2])
  })

  it('closeTab on a non-active tab leaves the active id unchanged', () => {
    const { openNoteIds, openTab, closeTab } = useOpenTabs()
    ;[1, 2, 3].forEach(openTab)
    const next = closeTab(2, 1)
    expect(next).toBe(1)
    expect(openNoteIds.value).toEqual([1, 3])
  })

  it('closeTab on the active middle tab activates its right neighbor', () => {
    const { openTab, closeTab } = useOpenTabs()
    ;[1, 2, 3].forEach(openTab)
    expect(closeTab(2, 2)).toBe(3)
  })

  it('closeTab on the active first tab activates its right neighbor', () => {
    const { openTab, closeTab } = useOpenTabs()
    ;[1, 2, 3].forEach(openTab)
    expect(closeTab(1, 1)).toBe(2)
  })

  it('closeTab on the active last tab activates its left neighbor', () => {
    const { openTab, closeTab } = useOpenTabs()
    ;[1, 2, 3].forEach(openTab)
    expect(closeTab(3, 3)).toBe(2)
  })

  it('closeTab on the last remaining tab returns null', () => {
    const { openTab, closeTab } = useOpenTabs()
    openTab(1)
    expect(closeTab(1, 1)).toBeNull()
  })

  it('saveTabs then loadTabs round-trips through localStorage', () => {
    const writer = useOpenTabs()
    ;[1, 2].forEach(writer.openTab)
    writer.saveTabs(5, 2)

    const reader = useOpenTabs()
    const restoredActiveId = reader.loadTabs(5)
    expect(reader.openNoteIds.value).toEqual([1, 2])
    expect(restoredActiveId).toBe(2)
  })

  it('loadTabs returns an empty list and null when nothing is stored', () => {
    const { openNoteIds, loadTabs } = useOpenTabs()
    expect(loadTabs(999)).toBeNull()
    expect(openNoteIds.value).toEqual([])
  })

  it('loadTabs degrades gracefully on corrupt stored JSON', () => {
    localStorage.setItem('jotter-open-tabs:7', 'not valid json{{{')
    const { openNoteIds, loadTabs } = useOpenTabs()
    expect(loadTabs(7)).toBeNull()
    expect(openNoteIds.value).toEqual([])
  })
})
