import { computed, watch } from 'vue'
import { useI18n } from 'vue-i18n'
import { updateLocale } from '../services/api'

export function localeDirection(locale: string): 'ltr' | 'rtl' {
  return /^(ar|he)(-|$)/i.test(locale) ? 'rtl' : 'ltr'
}

export function useLocale() {
  const { locale } = useI18n({ useScope: 'global' })

  watch(locale, (value) => {
    if (typeof document === 'undefined') return

    document.documentElement.lang = value
    document.documentElement.dir = localeDirection(value)
  }, { immediate: true })

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
