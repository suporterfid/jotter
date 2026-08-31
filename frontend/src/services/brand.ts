import { reactive, readonly } from 'vue'

/**
 * Operator branding delivered by GET /api/auth/config. Defaults reproduce the
 * stock Jotter identity so a self-hosted install renders exactly as before the
 * config request resolves (or if it fails).
 */
export interface BrandConfig {
  name: string
  logo_url: string | null
  support_url: string | null
  terms_url: string | null
  privacy_url: string | null
  powered_by: boolean
  powered_by_url: string
}

export const DEFAULT_BRAND: BrandConfig = {
  name: 'Jotter',
  logo_url: null,
  support_url: null,
  terms_url: null,
  privacy_url: null,
  powered_by: true,
  powered_by_url: 'https://github.com/suporterfid/jotter',
}

const state = reactive<BrandConfig>({ ...DEFAULT_BRAND })

export const brand = readonly(state)

export function setBrand(config: Partial<BrandConfig> | null | undefined): void {
  Object.assign(state, DEFAULT_BRAND, config ?? {})
  if (!state.name || !state.name.trim()) state.name = DEFAULT_BRAND.name
}

export function resetBrand(): void {
  setBrand(null)
}

/** Links shown in footers: only the configured ones, in a stable order. */
export function brandLinks(config: BrandConfig = state): Array<{ key: 'terms' | 'privacy' | 'support'; href: string }> {
  const entries: Array<{ key: 'terms' | 'privacy' | 'support'; href: string | null }> = [
    { key: 'terms', href: config.terms_url },
    { key: 'privacy', href: config.privacy_url },
    { key: 'support', href: config.support_url },
  ]
  return entries.filter((entry): entry is { key: 'terms' | 'privacy' | 'support'; href: string } => !!entry.href)
}
