import { describe, it, expect, beforeEach } from 'vitest'
import { nextTick } from 'vue'
import { useTheme } from './useTheme'

describe('useTheme', () => {
  beforeEach(() => {
    localStorage.clear()
    document.documentElement.removeAttribute('data-theme')
  })

  it('resolves to a concrete light/dark value by default (never the literal "auto")', () => {
    const { mode } = useTheme()
    expect(['light', 'dark']).toContain(mode.value)
  })

  it('setting mode to dark sets data-theme="dark" on <html>', async () => {
    const { mode } = useTheme()
    mode.value = 'dark'
    await nextTick()
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark')
  })

  it('setting mode to light sets data-theme="light" on <html>', async () => {
    const { mode } = useTheme()
    mode.value = 'light'
    await nextTick()
    expect(document.documentElement.getAttribute('data-theme')).toBe('light')
  })

  it('persists an explicit choice to localStorage', async () => {
    const { mode } = useTheme()
    mode.value = 'dark'
    await nextTick()
    expect(localStorage.getItem('jotter-theme')).toBe('dark')
  })
})
