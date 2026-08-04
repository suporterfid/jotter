import { describe, expect, it } from 'vitest'
import { parseHeadings } from './services/outline'

describe('parseHeadings', () => {
  it('returns an empty array for content with no headings', () => {
    expect(parseHeadings('just text\nmore text')).toEqual([])
  })

  it('parses ATX headings at all 6 levels with 0-based line numbers', () => {
    const md = '# One\n## Two\n### Three\n#### Four\n##### Five\n###### Six'
    const headings = parseHeadings(md)
    expect(headings).toHaveLength(6)
    expect(headings[0]).toEqual({ level: 1, text: 'One', line: 0, id: 'one' })
    expect(headings[5]).toEqual({ level: 6, text: 'Six', line: 5, id: 'six' })
  })

  it('skips headings inside fenced code blocks (both ``` and ~~~ fences)', () => {
    const md = [
      '# Real Heading',
      '',
      '```',
      '# Not a heading',
      '```',
      '',
      '~~~',
      '## Also not a heading',
      '~~~',
      '',
      '## Also Real',
    ].join('\n')
    const headings = parseHeadings(md)
    expect(headings.map(h => h.text)).toEqual(['Real Heading', 'Also Real'])
  })

  it('dedupes colliding slugs with -2, -3 suffixes', () => {
    const md = '# Notes\n## Notes\n### Notes'
    const headings = parseHeadings(md)
    expect(headings.map(h => h.id)).toEqual(['notes', 'notes-2', 'notes-3'])
  })

  it('ignores lines that are only # characters with no text', () => {
    expect(parseHeadings('#\n##   \n# Real')).toEqual([
      { level: 1, text: 'Real', line: 2, id: 'real' },
    ])
  })
})
