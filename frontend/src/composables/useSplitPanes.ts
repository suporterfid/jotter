import { ref, type Ref } from 'vue'

export type PaneId = 'primary' | 'secondary'

export interface PaneState {
  id: PaneId
  openNoteIds: number[]
  activeNoteId: number | null
}

export interface SplitLayout {
  enabled: boolean
  primary: PaneState
  secondary: PaneState
  primaryRatio: number
}

const STORAGE_PREFIX = 'jotter-split-layout:'
const LEGACY_STORAGE_PREFIX = 'jotter-open-tabs:'
const MIN_RATIO = 0.25
const MAX_RATIO = 0.75

function emptyPane(id: PaneId): PaneState {
  return { id, openNoteIds: [], activeNoteId: null }
}

function emptyLayout(): SplitLayout {
  return {
    enabled: false,
    primary: emptyPane('primary'),
    secondary: emptyPane('secondary'),
    primaryRatio: 0.5,
  }
}

function clampRatio(value: number): number {
  return Math.min(MAX_RATIO, Math.max(MIN_RATIO, value))
}

function normalizePane(value: unknown, id: PaneId): PaneState {
  if (!value || typeof value !== 'object') return emptyPane(id)

  const candidate = value as { openNoteIds?: unknown; activeNoteId?: unknown }
  const openNoteIds = Array.isArray(candidate.openNoteIds)
    ? [...new Set(candidate.openNoteIds.filter((noteId): noteId is number => Number.isInteger(noteId)))]
    : []

  return {
    id,
    openNoteIds,
    activeNoteId:
      typeof candidate.activeNoteId === 'number' && openNoteIds.includes(candidate.activeNoteId)
        ? candidate.activeNoteId
        : openNoteIds[0] ?? null,
  }
}

function normalizeLayout(value: unknown): SplitLayout | null {
  if (!value || typeof value !== 'object') return null

  const candidate = value as {
    enabled?: unknown
    primary?: unknown
    secondary?: unknown
    primaryRatio?: unknown
  }

  return {
    enabled: candidate.enabled === true,
    primary: normalizePane(candidate.primary, 'primary'),
    secondary: normalizePane(candidate.secondary, 'secondary'),
    primaryRatio:
      typeof candidate.primaryRatio === 'number' && Number.isFinite(candidate.primaryRatio)
        ? clampRatio(candidate.primaryRatio)
        : 0.5,
  }
}

function readLegacyLayout(workspaceId: number): SplitLayout {
  const fallback = emptyLayout()
  const raw = localStorage.getItem(`${LEGACY_STORAGE_PREFIX}${workspaceId}`)
  if (!raw) return fallback

  try {
    const parsed = JSON.parse(raw) as { openNoteIds?: unknown; activeNoteId?: unknown }
    const primary = normalizePane(parsed, 'primary')
    return { ...fallback, primary }
  } catch {
    return fallback
  }
}

export function useSplitPanes() {
  const layout: Ref<SplitLayout> = ref(emptyLayout())

  function loadLayout(workspaceId: number): void {
    const raw = localStorage.getItem(`${STORAGE_PREFIX}${workspaceId}`)
    if (!raw) {
      layout.value = readLegacyLayout(workspaceId)
      return
    }

    try {
      const parsed = normalizeLayout(JSON.parse(raw))
      layout.value = parsed ?? readLegacyLayout(workspaceId)
    } catch {
      layout.value = readLegacyLayout(workspaceId)
    }
  }

  function saveLayout(workspaceId: number): void {
    localStorage.setItem(`${STORAGE_PREFIX}${workspaceId}`, JSON.stringify(layout.value))
  }

  function openNote(paneId: PaneId, noteId: number): void {
    const pane = layout.value[paneId]
    if (!pane.openNoteIds.includes(noteId)) pane.openNoteIds.push(noteId)
    pane.activeNoteId = noteId
  }

  function closeNote(paneId: PaneId, noteId: number): void {
    const pane = layout.value[paneId]
    const index = pane.openNoteIds.indexOf(noteId)
    if (index === -1) return

    pane.openNoteIds.splice(index, 1)
    if (pane.activeNoteId !== noteId) return

    pane.activeNoteId = pane.openNoteIds[index] ?? pane.openNoteIds[index - 1] ?? null
  }

  function splitWithNote(noteId: number): void {
    const primary = layout.value.primary
    if (!primary.openNoteIds.includes(noteId)) primary.openNoteIds.push(noteId)
    primary.activeNoteId = noteId
    layout.value.enabled = true

    const currentIndex = primary.openNoteIds.indexOf(noteId)
    const secondaryNoteId =
      primary.openNoteIds[currentIndex + 1] ?? primary.openNoteIds[currentIndex - 1] ?? null

    layout.value.secondary.openNoteIds = secondaryNoteId === null ? [] : [secondaryNoteId]
    layout.value.secondary.activeNoteId = secondaryNoteId
  }

  function mergeSecondary(): void {
    layout.value.enabled = false
    layout.value.secondary = emptyPane('secondary')
  }

  function moveNote(paneId: PaneId, noteId: number): void {
    const source = layout.value[paneId]
    const targetPaneId: PaneId = paneId === 'primary' ? 'secondary' : 'primary'
    const target = layout.value[targetPaneId]
    const index = source.openNoteIds.indexOf(noteId)
    if (index === -1) return

    source.openNoteIds.splice(index, 1)
    if (!target.openNoteIds.includes(noteId)) target.openNoteIds.push(noteId)
    target.activeNoteId = noteId
    if (source.activeNoteId === noteId) {
      source.activeNoteId = source.openNoteIds[index] ?? source.openNoteIds[index - 1] ?? null
    }
  }

  function setPrimaryRatio(value: number): void {
    layout.value.primaryRatio = clampRatio(value)
  }

  return {
    layout,
    loadLayout,
    saveLayout,
    openNote,
    closeNote,
    splitWithNote,
    mergeSecondary,
    moveNote,
    setPrimaryRatio,
  }
}
