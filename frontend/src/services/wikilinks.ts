import type { NoteMeta } from './types'

/**
 * Resolves a raw wikilink target string (from [[target]] / data-target)
 * against the workspace's note list. Shared by App.vue's click-to-navigate
 * handler and NoteEditor.vue's hover-preview handler so both agree on what
 * a wikilink points to.
 */
export function resolveWikilinkTarget(target: string, notes: NoteMeta[]): NoteMeta | undefined {
  const targetLower = target.toLowerCase().trim()
  return notes.find(n =>
    n.title.toLowerCase() === targetLower ||
    n.path.toLowerCase() === targetLower ||
    n.path.toLowerCase() === `${targetLower}.md`
  )
}

export const EMBED_PATTERN = /!\[\[([^\]|#]+)(?:#[^\]|]*)?\]\]/g

/**
 * Extracts the unique embed targets (![[Target]]) referenced by a note's
 * raw markdown, for NoteEditor.vue to resolve+fetch ahead of render.
 */
export function parseEmbedTargets(markdown: string): string[] {
  const targets = new Set<string>()
  for (const match of markdown.matchAll(EMBED_PATTERN)) {
    targets.add(match[1].trim())
  }
  return Array.from(targets)
}
