import { readFileSync } from 'node:fs'
import { join } from 'node:path'
import { describe, expect, it } from 'vitest'

const css = readFileSync(join(process.cwd(), 'src/styles/tokens.css'), 'utf8')
const fontsCss = readFileSync(join(process.cwd(), 'src/styles/fonts.css'), 'utf8')

const canonicalTokens = [
  '--color-bg-canvas',
  '--color-bg-surface',
  '--color-bg-elevated',
  '--color-text-primary',
  '--color-text-link',
  '--color-border-strong',
  '--color-action-primary',
  '--color-focus-ring',
  '--color-danger-border',
] as const

function themeBlock(theme: 'light' | 'dark') {
  const matcher = new RegExp(`:root\\[data-theme="${theme}"\\]\\s*\\{([\\s\\S]*?)\\n\\}`, 'm')
  return css.match(matcher)?.[1] ?? ''
}

describe('canonical visual identity tokens', () => {
  it.each(canonicalTokens)('%s is defined in the light and dark theme blocks', (token) => {
    expect(themeBlock('light')).toContain(token)
    expect(themeBlock('dark')).toContain(token)
  })

  it('uses the canonical primary action and canvas values', () => {
    expect(themeBlock('light')).toContain('--color-bg-canvas: #FFFFFF')
    expect(themeBlock('light')).toContain('--color-action-primary: #1A6DC1')
    expect(themeBlock('dark')).toContain('--color-bg-canvas: #191919')
    expect(themeBlock('dark')).toContain('--color-action-primary: #529CCA')
  })

  it('self-hosts the required editorial and code faces', () => {
    expect(fontsCss).toContain('@fontsource-variable/source-serif-4')
    expect(fontsCss).toContain('@fontsource/ibm-plex-mono')
    expect(fontsCss).toContain('font-display: swap')
  })
})
