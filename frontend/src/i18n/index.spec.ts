import { describe, expect, it } from 'vitest'
import i18n from './index'

describe('i18n bootstrap', () => {
  it('defaults to pt-BR in the app runtime configuration', () => {
    expect(i18n.global.locale.value).toBe('pt-BR')
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
