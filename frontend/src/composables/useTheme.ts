import { computed, ref, watch } from 'vue'

export type ThemePreference = 'system' | 'light' | 'dark'
type ResolvedTheme = Exclude<ThemePreference, 'system'>

const storageKey = 'jotter-theme'

function storedPreference(): ThemePreference {
  const value = localStorage.getItem(storageKey)
  return value === 'light' || value === 'dark' || value === 'system' ? value : 'system'
}

function systemTheme(): ResolvedTheme {
  return window.matchMedia?.('(prefers-color-scheme: dark)').matches ? 'dark' : 'light'
}

/**
 * Keeps an explicit user preference separate from the resolved DOM theme.
 * `system` follows the OS, while light and dark always win over it.
 */
export function useTheme() {
  const preference = ref<ThemePreference>(storedPreference())
  const resolvedTheme = computed<ResolvedTheme>(() => preference.value === 'system' ? systemTheme() : preference.value)

  function applyTheme() {
    document.documentElement.setAttribute('data-theme', resolvedTheme.value)
  }

  watch(preference, () => {
    localStorage.setItem(storageKey, preference.value)
    applyTheme()
  }, { immediate: true })

  window.matchMedia?.('(prefers-color-scheme: dark)').addEventListener?.('change', () => {
    if (preference.value === 'system') applyTheme()
  })

  return { preference, resolvedTheme }
}
