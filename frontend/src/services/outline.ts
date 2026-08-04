export interface HeadingEntry {
  level: number
  text: string
  line: number
  id: string
}

const FENCE_RE = /^(```|~~~)/
const ATX_HEADING_RE = /^(#{1,6})\s+(.+?)\s*#*\s*$/

function slugify(text: string): string {
  return text
    .toLowerCase()
    .replace(/[^a-z0-9]+/g, '-')
    .replace(/^-+|-+$/g, '')
}

/**
 * Parses ATX headings (# .. ######) out of raw Markdown, skipping any
 * line inside a fenced code block. This is the single source of truth
 * for heading text/level/line/id — services/markdown.ts's renderer
 * stamps the same ids onto rendered <h1>-<h6> tags so outline clicks
 * can scroll the preview to a matching element.
 */
export function parseHeadings(markdown: string): HeadingEntry[] {
  const lines = markdown.split('\n')
  const headings: HeadingEntry[] = []
  const slugCounts = new Map<string, number>()
  let inFence = false

  lines.forEach((line, index) => {
    if (FENCE_RE.test(line.trim())) {
      inFence = !inFence
      return
    }
    if (inFence) return

    const match = ATX_HEADING_RE.exec(line)
    if (!match) return

    const level = match[1].length
    const text = match[2].trim()
    if (!text) return

    let slug = slugify(text) || 'section'
    const seen = slugCounts.get(slug) ?? 0
    slugCounts.set(slug, seen + 1)
    if (seen > 0) slug = `${slug}-${seen + 1}`

    headings.push({ level, text, line: index, id: slug })
  })

  return headings
}
