export interface FrontMatterSplit {
  /** Raw `---\n...\n---\n` block including delimiters, or '' if absent. */
  frontMatter: string
  /** Everything after the front matter block, untouched. */
  body: string
}

/**
 * Splits off a leading YAML front matter block by text only (no YAML
 * parsing) so it can be kept out of Milkdown entirely — CommonMark has no
 * front matter concept and destroys it (see
 * wysiwygRoundTrip.spec.ts's "front matter" known gap). Mirrors
 * MarkdownDocument::splitFrontMatter()'s delimiter rule server-side:
 * requires the block to start at position 0 with `---\n`, with a closing
 * `\n---` on its own line.
 */
export function splitFrontMatter(raw: string): FrontMatterSplit {
  if (!raw.startsWith('---\n')) {
    return { frontMatter: '', body: raw }
  }

  const closing = raw.indexOf('\n---', 4)
  if (closing === -1) {
    return { frontMatter: '', body: raw }
  }

  const afterClosing = closing + 4
  const restOfLine = raw.slice(afterClosing, raw.indexOf('\n', afterClosing) === -1 ? raw.length : raw.indexOf('\n', afterClosing))
  if (restOfLine.trim() !== '') {
    return { frontMatter: '', body: raw }
  }

  const end = raw.indexOf('\n', afterClosing)
  const frontMatterEnd = end === -1 ? raw.length : end + 1

  return {
    frontMatter: raw.slice(0, frontMatterEnd),
    body: raw.slice(frontMatterEnd),
  }
}

export function joinFrontMatter(frontMatter: string, body: string): string {
  return frontMatter + body
}
