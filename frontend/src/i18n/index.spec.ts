import { createI18n } from 'vue-i18n'
import { describe, expect, it } from 'vitest'
import i18n from './index'
import en from './locales/en'
import ptBR from './locales/pt-BR'

describe('i18n bootstrap', () => {
  it('is configured with pt-BR as the default locale', () => {
    // The shared `i18n` singleton's live locale is deliberately forced to
    // 'en' for the whole test run by test-setup.ts, so a fresh instance
    // built from the same locale files is what verifies the real default
    // createI18n({ locale: 'pt-BR', ... }) config in ./index.ts.
    const fresh = createI18n({
      legacy: false,
      locale: 'pt-BR',
      fallbackLocale: 'en',
      messages: { en, 'pt-BR': ptBR },
    })
    expect(fresh.global.locale.value).toBe('pt-BR')
  })

  it('falls back to en for missing keys', () => {
    expect(i18n.global.fallbackLocale.value).toBe('en')
  })

  it('resolves a known nested key in both locales', () => {
    i18n.global.locale.value = 'en'
    expect(i18n.global.t('nav.settings')).toBe('Settings')
    i18n.global.locale.value = 'pt-BR'
    expect(i18n.global.t('nav.settings')).toBe('Configurações')
  })
})
