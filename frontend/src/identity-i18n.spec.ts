import { describe, expect, it } from 'vitest'
import en from './i18n/locales/en'
import ptBR from './i18n/locales/pt-BR'
import { localeDirection } from './composables/useLocale'
import { pseudoLocalize } from './testing/pseudoLocale'

describe('identity i18n contract', () => {
  it.each([en, ptBR])('provides all theme preference labels', (messages) => {
    expect(messages.theme).toMatchObject({
      label: expect.any(String),
      system: expect.any(String),
      light: expect.any(String),
      dark: expect.any(String),
    })
  })

  it.each([
    ['ar', 'rtl'],
    ['ar-XB', 'rtl'],
    ['he-IL', 'rtl'],
    ['en-XA', 'ltr'],
    ['pt-BR', 'ltr'],
    ['zh-Hans', 'ltr'],
  ] as const)('maps %s to a document direction of %s', (locale, direction) => {
    expect(localeDirection(locale)).toBe(direction)
  })

  it('expands en-XA and wraps ar-XB without changing interpolation tokens', () => {
    expect(pseudoLocalize('Save {count} notes', 'en-XA')).toContain('{count}')
    expect(pseudoLocalize('Save', 'en-XA').length).toBeGreaterThan('Save'.length)
    expect(pseudoLocalize('Save {count} notes', 'ar-XB')).toMatch(/^\u202e.*\u202c$/)
    expect(pseudoLocalize('Save {count} notes', 'ar-XB')).toContain('{count}')
  })
})
