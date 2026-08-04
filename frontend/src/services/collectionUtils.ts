import type { BoardColumnConfig, CollectionNote, CollectionPage, RawNoteProperty } from './types'

export function rawValue(prop: RawNoteProperty): string | number | boolean | unknown | null {
  switch (prop.type) {
    case 'numeric': return prop.value_numeric
    case 'boolean': return prop.value_boolean
    case 'datetime': return prop.value_datetime
    case 'list':
    case 'json': return prop.value_json
    default: return prop.value_string
  }
}

export function findProperty(note: CollectionNote, name: string): RawNoteProperty | undefined {
  return note.properties.find(p => p.name === name)
}

export function formatPropertyValue(note: CollectionNote, column: string): string {
  const prop = findProperty(note, column)
  if (!prop) return '—'
  const value = rawValue(prop)
  if (value === null || value === undefined) return '—'
  if (Array.isArray(value)) return value.join(', ')
  if (typeof value === 'object') return JSON.stringify(value)
  return String(value)
}

export function propertyColumns(page: CollectionPage): string[] {
  const names = new Set<string>()
  for (const note of page.data) {
    for (const prop of note.properties) names.add(prop.name)
  }
  return Array.from(names).sort()
}

export function noteTagNames(note: CollectionNote): string[] {
  return (note.tags ?? []).map(t => t.name)
}

export function allTagNames(page: CollectionPage): string[] {
  const names = new Set<string>()
  for (const note of page.data) {
    for (const name of noteTagNames(note)) names.add(name)
  }
  return Array.from(names).sort()
}

export function filterNotesByTag(notes: CollectionNote[], tag: string): CollectionNote[] {
  if (!tag) return notes
  return notes.filter(note => noteTagNames(note).includes(tag))
}

export function coverImageUrl(note: CollectionNote): string | null {
  const prop = findProperty(note, 'cover')
  if (!prop || prop.type !== 'string') return null
  const value = prop.value_string
  return value && value.trim() !== '' ? value : null
}

export function firstDateProperty(note: CollectionNote): { name: string; value: string } | null {
  const prop = note.properties.find(p => p.type === 'datetime' && p.value_datetime)
  return prop ? { name: prop.name, value: prop.value_datetime as string } : null
}

export interface BoardColumn {
  key: string
  label: string
  notes: CollectionNote[]
}

function sortGroupKeys(keys: string[]): string[] {
  return keys.sort((a, b) => {
    if (a === UNGROUPED_LABEL) return 1
    if (b === UNGROUPED_LABEL) return -1
    return a.localeCompare(b)
  })
}

export function groupNotesIntoColumns(notes: CollectionNote[], groupProperty: string): BoardColumn[] {
  const groups = new Map<string, CollectionNote[]>()
  for (const note of notes) {
    const label = formatPropertyValue(note, groupProperty)
    const key = label === '—' ? UNGROUPED_LABEL : label
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key)!.push(note)
  }
  return sortGroupKeys(Array.from(groups.keys())).map(key => ({ key, label: key, notes: groups.get(key)! }))
}

export interface SwimlaneRow {
  key: string
  label: string
  notes: CollectionNote[]
}

export function groupNotesIntoSwimlaneRows(notes: CollectionNote[], swimlaneProperty: string): SwimlaneRow[] {
  const groups = new Map<string, CollectionNote[]>()
  for (const note of notes) {
    const label = formatPropertyValue(note, swimlaneProperty)
    const key = label === '—' ? UNGROUPED_LABEL : label
    if (!groups.has(key)) groups.set(key, [])
    groups.get(key)!.push(note)
  }
  return sortGroupKeys(Array.from(groups.keys())).map(key => ({ key, label: key, notes: groups.get(key)! }))
}

export interface ConfiguredBoardColumn extends BoardColumn {
  color: string | null
  wipLimit: number | null
  collapsed: boolean
}

/**
 * Merges the derived columns (from distinct property values present in the
 * page) with the board's persisted per-column config — order, label
 * override, color, WIP limit, collapsed. Columns with no config keep their
 * derived order, appended after configured ones. Config entries for values
 * no longer present in the data are dropped rather than shown empty.
 */
export function applyColumnConfig(
  columns: BoardColumn[],
  config: BoardColumnConfig[] | null | undefined
): ConfiguredBoardColumn[] {
  const byKey = new Map(columns.map(c => [c.key, c]))
  const configured: ConfiguredBoardColumn[] = []
  const seen = new Set<string>()

  for (const entry of config ?? []) {
    const column = byKey.get(entry.key)
    if (!column) continue
    configured.push({
      ...column,
      label: entry.label || column.label,
      color: entry.color ?? null,
      wipLimit: entry.wip_limit ?? null,
      collapsed: entry.collapsed ?? false,
    })
    seen.add(entry.key)
  }

  for (const column of columns) {
    if (seen.has(column.key)) continue
    configured.push({ ...column, color: null, wipLimit: null, collapsed: false })
  }

  return configured
}

export const UNGROUPED_LABEL = 'No value'

/**
 * Decides what a board drag-and-drop move means, from the Sortable `onEnd`
 * event's `from`/`to`/`item` elements — pure so it's testable without
 * spinning up a real Sortable instance. Returns null for a no-op (dropped
 * back in the same column, or the dragged element has no note id).
 */
export function resolveCardMove(
  from: HTMLElement,
  to: HTMLElement,
  item: HTMLElement
): { noteId: number; newValue: string } | null {
  if (from === to) return null

  const noteId = Number(item.dataset.noteId)
  if (!noteId) return null

  const columnKey = to.dataset.columnKey
  if (columnKey === undefined) return null

  return { noteId, newValue: columnKey === UNGROUPED_LABEL ? '' : columnKey }
}
