export type AppTheme = 'light' | 'dark'

const THEME_COLORS: Record<AppTheme, '#FFFFFF' | '#191919'> = {
  light: '#FFFFFF',
  dark: '#191919',
}

export function resolveThemeColor(theme: AppTheme): '#FFFFFF' | '#191919' {
  return THEME_COLORS[theme]
}

export function applyThemeColor(theme: AppTheme, documentLike: Document = document): void {
  documentLike.querySelector('meta[name="theme-color"]')?.setAttribute('content', resolveThemeColor(theme))
}
