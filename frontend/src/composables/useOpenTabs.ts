import { ref, type Ref } from 'vue'

interface StoredTabs {
  openNoteIds: number[]
  activeNoteId: number | null
}

const STORAGE_PREFIX = 'jotter-open-tabs:'

export function useOpenTabs(): {
  openNoteIds: Ref<number[]>
  loadTabs: (workspaceId: number) => number | null
  saveTabs: (workspaceId: number, activeNoteId: number | null) => void
  openTab: (noteId: number) => void
  closeTab: (noteId: number, activeNoteId: number | null) => number | null
} {
  const openNoteIds = ref<number[]>([])

  function loadTabs(workspaceId: number): number | null {
    const raw = localStorage.getItem(`${STORAGE_PREFIX}${workspaceId}`)
    if (!raw) {
      openNoteIds.value = []
      return null
    }
    try {
      const parsed = JSON.parse(raw) as StoredTabs
      openNoteIds.value = Array.isArray(parsed.openNoteIds) ? parsed.openNoteIds : []
      return typeof parsed.activeNoteId === 'number' ? parsed.activeNoteId : null
    } catch {
      openNoteIds.value = []
      return null
    }
  }

  function saveTabs(workspaceId: number, activeNoteId: number | null) {
    localStorage.setItem(
      `${STORAGE_PREFIX}${workspaceId}`,
      JSON.stringify({ openNoteIds: openNoteIds.value, activeNoteId })
    )
  }

  function openTab(noteId: number) {
    if (!openNoteIds.value.includes(noteId)) {
      openNoteIds.value.push(noteId)
    }
  }

  function closeTab(noteId: number, activeNoteId: number | null): number | null {
    const index = openNoteIds.value.indexOf(noteId)
    if (index === -1) return activeNoteId

    openNoteIds.value.splice(index, 1)

    if (activeNoteId !== noteId) return activeNoteId
    if (openNoteIds.value.length === 0) return null

    const nextIndex = Math.min(index, openNoteIds.value.length - 1)
    return openNoteIds.value[nextIndex]
  }

  return { openNoteIds, loadTabs, saveTabs, openTab, closeTab }
}
