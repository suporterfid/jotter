import { describe, expect, it } from 'vitest'
import { resolveWikilinkTarget, parseEmbedTargets } from './services/wikilinks'
import type { NoteMeta } from './services/types'

function makeNote(overrides: Partial<NoteMeta> = {}): NoteMeta {
  return {
    id: 1,
    path: 'Projects/Jotter.md',
    title: 'Jotter',
    frontmatter: null,
    sort_position: null,
    updated_at: '2026-07-31T00:00:00Z',
    ...overrides,
  }
}

describe('resolveWikilinkTarget', () => {
  it('matches by title, case-insensitively', () => {
    const notes = [makeNote({ id: 1, title: 'Jotter' })]
    expect(resolveWikilinkTarget('jotter', notes)).toBe(notes[0])
  })

  it('matches by full path, case-insensitively', () => {
    const notes = [makeNote({ id: 1, path: 'Projects/Jotter.md', title: 'Something Else' })]
    expect(resolveWikilinkTarget('projects/jotter.md', notes)).toBe(notes[0])
  })

  it('matches by path with a .md suffix appended to the target, when title differs', () => {
    const notes = [makeNote({ id: 1, path: 'ideas.md', title: 'My Note' })]
    expect(resolveWikilinkTarget('ideas', notes)).toBe(notes[0])
  })

  it('returns undefined when nothing matches', () => {
    const notes = [makeNote()]
    expect(resolveWikilinkTarget('nonexistent', notes)).toBeUndefined()
  })
})

describe('parseEmbedTargets', () => {
  it('returns an empty array when there are no embeds', () => {
    expect(parseEmbedTargets('no embeds here')).toEqual([])
  })

  it('extracts a single embed target', () => {
    expect(parseEmbedTargets('See ![[Ideas]] below.')).toEqual(['Ideas'])
  })

  it('extracts multiple distinct embed targets in order', () => {
    expect(parseEmbedTargets('![[Ideas]] and ![[Projects/Jotter]]')).toEqual(['Ideas', 'Projects/Jotter'])
  })

  it('dedupes repeated embed targets', () => {
    expect(parseEmbedTargets('![[Ideas]] again: ![[Ideas]]')).toEqual(['Ideas'])
  })

  it('strips a #heading fragment from the target', () => {
    expect(parseEmbedTargets('![[Ideas#Section]]')).toEqual(['Ideas'])
  })

  it('does not match a plain [[wikilink]] (no ! prefix)', () => {
    expect(parseEmbedTargets('[[Ideas]]')).toEqual([])
  })
})
