/**
 * The OAuth consent screen (/oauth/authorize) sends people without a session to
 * `/?login=1&return_to=<path>`. After signing in, the SPA goes back there.
 * Only same-origin consent paths are accepted, so the parameter cannot be used
 * as an open redirect.
 */
export function oauthReturnTo(search: string = window.location.search): string | null {
  const value = new URLSearchParams(search).get('return_to')
  if (!value || !value.startsWith('/oauth/authorize?') || value.startsWith('//') || value.includes('\\')) return null
  return value
}

export function resumeOAuthConsent(search: string = window.location.search): boolean {
  const target = oauthReturnTo(search)
  if (!target) return false
  window.location.assign(target)
  return true
}
