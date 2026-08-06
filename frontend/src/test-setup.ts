import { config } from '@vue/test-utils'
import { createI18n } from 'vue-i18n'
import en from './i18n/locales/en'
import ptBR from './i18n/locales/pt-BR'

const testI18n = createI18n({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  messages: { en, 'pt-BR': ptBR },
})

config.global.plugins.push(testI18n)
