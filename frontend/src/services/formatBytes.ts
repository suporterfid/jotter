/** Human-readable size in binary units (1 KB = 1024 B), localized decimals. */
export function formatBytes(bytes: number, locale?: string): string {
  const units = ['B', 'KB', 'MB', 'GB', 'TB']
  let value = bytes
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }
  if (unit === 0) return `${bytes} B`
  return `${value.toLocaleString(locale, { maximumFractionDigits: 1, minimumFractionDigits: 1 })} ${units[unit]}`
}
