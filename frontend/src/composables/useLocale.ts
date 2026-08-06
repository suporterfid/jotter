import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { updateLocale } from '../services/api'

export function useLocale() {
  const { locale } = useI18n({ useScope: 'global' })

  async function setLocale(newLocale: string) {
    locale.value = newLocale
    try {
      await updateLocale(newLocale)
    } catch (err) {
      console.warn('Failed to persist locale change:', err)
    }
  }

  return { locale: computed(() => locale.value), setLocale }
}
