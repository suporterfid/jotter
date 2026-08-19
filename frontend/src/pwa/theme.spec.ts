import { beforeEach, describe, expect, it } from 'vitest'
import { applyThemeColor, resolveThemeColor } from './theme'

describe('PWA theme color', () => {
  beforeEach(() => {
    document.head.innerHTML = '<meta name="theme-color" content="#FFFFFF">'
  })

  it('maps the canonical light and dark canvas colors', () => {
    expect(resolveThemeColor('light')).toBe('#FFFFFF')
    expect(resolveThemeColor('dark')).toBe('#191919')
  })

  it('updates the existing theme-color meta element without duplicating it', () => {
    applyThemeColor('dark')
    applyThemeColor('light')

    expect(document.querySelectorAll('meta[name="theme-color"]')).toHaveLength(1)
    expect(document.querySelector('meta[name="theme-color"]')?.getAttribute('content')).toBe('#FFFFFF')
  })

  it('does nothing when the shell has no theme-color meta element', () => {
    document.head.innerHTML = ''

    expect(() => applyThemeColor('dark')).not.toThrow()
    expect(document.head.innerHTML).toBe('')
  })
})
