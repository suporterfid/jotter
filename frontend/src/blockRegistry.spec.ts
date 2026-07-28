import { describe, expect, it } from 'vitest'
import { blockDefinitions, getClientAllowedAttributes, getClientAllowedTags } from './services/blockRegistry'

describe('BlockRegistry Service', () => {
  it('defines all required block types in client registry', () => {
    expect(blockDefinitions).toHaveProperty('task_list')
    expect(blockDefinitions).toHaveProperty('code_block')
    expect(blockDefinitions).toHaveProperty('wikilink')
    expect(blockDefinitions).toHaveProperty('callout')
    expect(blockDefinitions).toHaveProperty('toggle')
    expect(blockDefinitions).toHaveProperty('table')
    expect(blockDefinitions).toHaveProperty('divider')
  })

  it('derives client allowed tags and attributes matching server definitions', () => {
    const tags = getClientAllowedTags()
    const attrs = getClientAllowedAttributes()

    expect(tags).toContain('details')
    expect(tags).toContain('summary')
    expect(tags).toContain('table')
    expect(attrs).toContain('data-target')
    expect(attrs).toContain('data-language')
  })
})
