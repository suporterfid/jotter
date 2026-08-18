let allowedHosts: string[] = []

export function setExternalEmbedAllowedHosts(hosts: string[]): void {
  allowedHosts = Array.from(new Set(hosts
    .map(host => host.trim().toLowerCase())
    .filter(Boolean)))
}

export function getExternalEmbedAllowedHosts(): string[] {
  return [...allowedHosts]
}

export function isExternalEmbedAllowed(url: string): boolean {
  try {
    const parsed = new URL(url)
    if (parsed.protocol !== 'https:' || parsed.username || parsed.password || !parsed.hostname) return false

    const host = parsed.hostname.toLowerCase()
    return allowedHosts.some(allowed => host === allowed || host.endsWith(`.${allowed}`))
  } catch {
    return false
  }
}
