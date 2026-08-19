import { describe, it, expect, beforeEach } from 'vitest'
import { nextTick } from 'vue'
import { useTheme } from './useTheme'

describe('useTheme', () => {
  beforeEach(() => {
    localStorage.clear()
    document.documentElement.removeAttribute('data-theme')
    document.head.innerHTML = '<meta name="theme-color" content="#FFFFFF">'
  })

  it('defaults to the persisted system preference and resolves it to a concrete theme', () => {
    const { preference, resolvedTheme } = useTheme()
    expect(preference.value).toBe('system')
    expect(['light', 'dark']).toContain(resolvedTheme.value)
  })

  it('setting mode to dark sets data-theme="dark" on <html>', async () => {
    const { preference } = useTheme()
    preference.value = 'dark'
    await nextTick()
    expect(document.documentElement.getAttribute('data-theme')).toBe('dark')
  })

  it('setting mode to light sets data-theme="light" on <html>', async () => {
    const { preference } = useTheme()
    preference.value = 'light'
    await nextTick()
    expect(document.documentElement.getAttribute('data-theme')).toBe('light')
  })

  it('updates the browser theme color when the resolved theme changes', async () => {
    const { preference } = useTheme()
    preference.value = 'dark'
    await nextTick()

    expect(document.querySelector('meta[name="theme-color"]')?.getAttribute('content')).toBe('#191919')
  })

  it('persists an explicit choice to localStorage', async () => {
    const { preference } = useTheme()
    preference.value = 'dark'
    await nextTick()
    expect(localStorage.getItem('jotter-theme')).toBe('dark')
  })

  it('persists system as an explicit preference', async () => {
    const { preference } = useTheme()
    preference.value = 'system'
    await nextTick()
    expect(localStorage.getItem('jotter-theme')).toBe('system')
  })
})
