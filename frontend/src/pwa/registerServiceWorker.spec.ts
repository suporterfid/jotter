import { afterEach, describe, expect, it, vi } from 'vitest'
import { registerServiceWorker } from './registerServiceWorker'

describe('service worker registration', () => {
  afterEach(() => {
    vi.restoreAllMocks()
    Object.defineProperty(navigator, 'serviceWorker', {
      configurable: true,
      value: undefined,
    })
  })

  it('registers only when explicitly enabled and the browser supports workers', () => {
    const register = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'serviceWorker', {
      configurable: true,
      value: { register },
    })

    registerServiceWorker(true)

    expect(register).toHaveBeenCalledWith('/service-worker.js', { scope: '/' })
  })

  it('does not register in development mode', () => {
    const register = vi.fn().mockResolvedValue(undefined)
    Object.defineProperty(navigator, 'serviceWorker', {
      configurable: true,
      value: { register },
    })

    registerServiceWorker(false)

    expect(register).not.toHaveBeenCalled()
  })

  it('swallows registration failures so app startup is unaffected', async () => {
    const register = vi.fn().mockRejectedValue(new Error('worker unavailable'))
    Object.defineProperty(navigator, 'serviceWorker', {
      configurable: true,
      value: { register },
    })

    expect(() => registerServiceWorker(true)).not.toThrow()
    await vi.waitFor(() => expect(register).toHaveBeenCalled())
  })
})
