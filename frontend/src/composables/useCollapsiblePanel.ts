import { ref } from 'vue'

export function useCollapsiblePanel(key: string, defaultCollapsed: boolean) {
  const storageKey = `jotter-panel-collapsed:${key}`
  const collapsed = ref(defaultCollapsed)

  const stored = localStorage.getItem(storageKey)
  if (stored !== null) {
    collapsed.value = stored === 'true'
  }

  function toggle() {
    collapsed.value = !collapsed.value
    localStorage.setItem(storageKey, String(collapsed.value))
  }

  return { collapsed, toggle }
}
