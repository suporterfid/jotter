import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'

const source = readFileSync('src/components/NoteEditorWysiwyg.vue', 'utf8')

describe('NoteEditorWysiwyg identity layout', () => {
  it('uses logical properties for prose callouts and code overflow', () => {
    expect(source).toContain('border-inline-start: 3px solid var(--color-border)')
    expect(source).toContain('padding-inline-start: var(--space-3)')
    expect(source).toContain('overflow-inline: auto')
  })
})
