import { beforeEach, describe, expect, it } from 'vitest'
import { isExternalEmbedAllowed, setExternalEmbedAllowedHosts } from './services/externalEmbeds'

describe('external embed policy', () => {
  beforeEach(() => {
    setExternalEmbedAllowedHosts(['YouTube.com'])
  })

  it('allows HTTPS exact hosts and dot-boundary subdomains', () => {
    expect(isExternalEmbedAllowed('https://youtube.com/embed/abc')).toBe(true)
    expect(isExternalEmbedAllowed('https://www.youtube.com/embed/abc')).toBe(true)
  })

  it('rejects lookalike hosts, HTTP, credentials, and malformed URLs', () => {
    expect(isExternalEmbedAllowed('https://evil-youtube.com/embed/abc')).toBe(false)
    expect(isExternalEmbedAllowed('http://youtube.com/embed/abc')).toBe(false)
    expect(isExternalEmbedAllowed('https://youtube.com@evil.example/embed/abc')).toBe(false)
    expect(isExternalEmbedAllowed('javascript:alert(1)')).toBe(false)
  })
})
