import { useColorMode } from '@vueuse/core'

/**
 * Wraps @vueuse/core's useColorMode with Jotter's storage key and attribute
 * strategy. Resolution order: localStorage['jotter-theme'] (explicit user
 * choice) -> prefers-color-scheme -> 'light'.
 */
export function useTheme() {
  const mode = useColorMode({
    selector: 'html',
    attribute: 'data-theme',
    storageKey: 'jotter-theme',
    modes: {
      light: 'light',
      dark: 'dark',
    },
    initialValue: 'auto',
  })

  return { mode }
}
