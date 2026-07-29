import type { CollectionNote, CollectionPage, RawNoteProperty } from './types'

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
