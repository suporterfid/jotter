import { describe, it, expect, beforeEach } from 'vitest'
import { useCollapsiblePanel } from './useCollapsiblePanel'

describe('useCollapsiblePanel', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('uses the default when no stored value exists', () => {
    const { collapsed } = useCollapsiblePanel('properties', true)
    expect(collapsed.value).toBe(true)
  })

  it('uses the stored value instead of the default when one exists', () => {
    localStorage.setItem('jotter-panel-collapsed:comments', 'true')
    const { collapsed } = useCollapsiblePanel('comments', false)
    expect(collapsed.value).toBe(true)
  })

  it('toggle flips the value and persists it', () => {
    const { collapsed, toggle } = useCollapsiblePanel('backlinks', false)
    toggle()
    expect(collapsed.value).toBe(true)
    expect(localStorage.getItem('jotter-panel-collapsed:backlinks')).toBe('true')

    toggle()
    expect(collapsed.value).toBe(false)
    expect(localStorage.getItem('jotter-panel-collapsed:backlinks')).toBe('false')
  })
})
