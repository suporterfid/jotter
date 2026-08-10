import { describe, expect, it } from 'vitest'

describe('shared Vitest setup', () => {
  it('provides the right drawer Teleport target', () => {
    expect(document.getElementById('app-right-drawer')).not.toBeNull()
  })
})
