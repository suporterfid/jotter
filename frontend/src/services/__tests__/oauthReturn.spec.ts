import { describe, expect, it } from 'vitest'
import { oauthReturnTo } from '../oauthReturn'

describe('oauthReturnTo', () => {
  it('accepts only same-origin consent paths', () => {
    expect(oauthReturnTo('?login=1&return_to=%2Foauth%2Fauthorize%3Fclient_id%3Djtc_x')).toBe('/oauth/authorize?client_id=jtc_x')
    expect(oauthReturnTo('?return_to=https%3A%2F%2Fevil.example%2Foauth%2Fauthorize%3Fx')).toBeNull()
    expect(oauthReturnTo('?return_to=%2F%2Fevil.example%2Foauth%2Fauthorize%3Fx')).toBeNull()
    expect(oauthReturnTo('?return_to=%2Fapi%2Fadmin%2Fusers')).toBeNull()
    expect(oauthReturnTo('?login=1')).toBeNull()
  })
})
