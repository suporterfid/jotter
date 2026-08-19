import DOMPurify from 'dompurify'
import { marked } from 'marked'
import { parseHeadings, type HeadingEntry } from './outline'
import { EMBED_PATTERN } from './wikilinks'
import { isExternalEmbedAllowed } from './externalEmbeds'

// Configure marked with GFM (GitHub Flavored Markdown)
marked.use({
  gfm: true,
  breaks: true,
})

/**
 * Transforms wikilinks like [[note]], [[note|alias]], [[note#heading]] into HTML anchors.
 */
export function renderWikilinks(text: string): string {
  // Pattern: [[target]] or [[target|alias]] — the (?<!!) lookbehind skips
  // ![[...]] embeds (handled separately by renderEmbeds), so an embed's
  // inner [[...]] is never also turned into a plain link.
  return text.replace(/(?<!!)\[\[([^\]|#]+)(?:#[^\]|]+)?(?:\|([^\]]+))?\]\]/g, (_match, target, alias) => {
    const cleanTarget = target.trim()
    const label = (alias || target).trim()
    const safeTarget = DOMPurify.sanitize(cleanTarget)
    const safeLabel = DOMPurify.sanitize(label)
    return `<a class="wikilink" data-target="${safeTarget}" href="#/note/${encodeURIComponent(safeTarget)}">${safeLabel}</a>`
  })
}

export interface EmbedResolution {
  status: 'resolved' | 'loading' | 'unresolved' | 'circular'
  html?: string
}

/**
 * Splices ![[Target]] embeds into <div class="embed-block"> blocks, using
 * the caller-supplied resolveEmbed callback to decide each embed's content.
 * Runs before renderWikilinks so the negative lookbehind there never has to
 * see an embed's [[...]] portion. When resolveEmbed is omitted this is a
 * no-op — the source of v1's non-recursive nesting: an embedded note's own
 * ![[...]] syntax, rendered via a plain renderMarkdown() call with no
 * resolver, is left completely literal.
 */
function renderEmbeds(text: string, resolveEmbed?: (target: string) => EmbedResolution): string {
  if (!resolveEmbed) return text
  return text.replace(EMBED_PATTERN, (_match, target) => {
    const cleanTarget = target.trim()
    const safeTarget = DOMPurify.sanitize(cleanTarget)
    const resolution = resolveEmbed(cleanTarget)

    if (resolution.status === 'resolved' && resolution.html !== undefined) {
      return `<div class="embed-block" data-embed-status="resolved" data-embed-target="${safeTarget}">${resolution.html}</div>`
    }
    if (resolution.status === 'loading') {
      return `<div class="embed-block" data-embed-status="loading" data-embed-target="${safeTarget}">Loading embed…</div>`
    }
    if (resolution.status === 'circular') {
      return `<div class="embed-block" data-embed-status="circular" data-embed-target="${safeTarget}">Cannot embed a note within itself.</div>`
    }
    return `<div class="embed-block" data-embed-status="unresolved" data-embed-target="${safeTarget}">Note not found: '${safeTarget}'</div>`
  })
}

function escapeHtmlAttribute(value: string): string {
  return value.replace(/[&<>"']/g, character => ({
    '&': '&amp;',
    '<': '&lt;',
    '>': '&gt;',
    '"': '&quot;',
    "'": '&#39;',
  })[character]!)
}

/**
 * Replaces allowlisted standalone HTTPS URLs outside fenced code blocks with
 * placeholders until after marked has rendered the surrounding Markdown.
 */
function extractExternalEmbeds(text: string): { text: string; blocks: Record<string, string> } {
  const lines = text.split(/\r\n|\n|\r/)
  const blocks: Record<string, string> = {}
  let inFence = false
  let fenceChar = ''
  let fenceLength = 0
  let index = 0

  for (let lineIndex = 0; lineIndex < lines.length; lineIndex += 1) {
    const line = lines[lineIndex]
    const fenceMatch = line.match(/^ {0,3}(`{3,}|~{3,})/)
    if (fenceMatch) {
      const marker = fenceMatch[1]
      if (!inFence) {
        inFence = true
        fenceChar = marker[0]
        fenceLength = marker.length
      } else if (marker[0] === fenceChar && marker.length >= fenceLength) {
        inFence = false
      }
      continue
    }

    if (inFence) continue

    const urlMatch = line.match(/^ {0,3}(https:\/\/[^\s<>]+)\s*$/)
    if (!urlMatch || !isExternalEmbedAllowed(urlMatch[1])) continue

    const token = `JOTTEREXTERNALEMBED${index}TOKEN`
    blocks[token] = `<iframe class="external-embed" src="${escapeHtmlAttribute(urlMatch[1])}" title="External content" sandbox="allow-scripts" referrerpolicy="no-referrer" loading="lazy"></iframe>`
    lines[lineIndex] = token
    index += 1
  }

  return { text: lines.join('\n'), blocks }
}

/**
 * Wraps <pre><code> blocks with a container and Copy button.
 */
function wrapCodeBlocks(html: string, copyLabel: string): string {
  return html.replace(/<pre><code([^>]*)>([\s\S]*?)<\/code><\/pre>/gi, (_match, codeAttrs, codeContent) => {
    return `<div class="code-block-wrapper"><button class="copy-code-btn" type="button">${copyLabel}</button><pre><code${codeAttrs}>${codeContent}</code></pre></div>`
  })
}

import { getClientAllowedAttributes, getClientAllowedTags } from './blockRegistry'

/**
 * Stamps a pane-unique id and the source slug onto each rendered <h1>-<h6>
 * tag, in document order, using the same parseHeadings() ids the outline
 * panel lists. headings must come from parseHeadings() run against the
 * *same* raw markdown passed to renderMarkdown, so counts and order line up
 * with marked's own heading output.
 */
function injectHeadingIds(html: string, headings: HeadingEntry[], headingIdPrefix = ''): string {
  let index = 0
  return html.replace(/<h([1-6])>/g, (match, level) => {
    const heading = headings[index]
    index += 1
    if (!heading) return match
    const sourceSlug = escapeHtmlAttribute(heading.id)
    const uniqueId = escapeHtmlAttribute(`${headingIdPrefix}${heading.id}`)
    return `<h${level} id="${uniqueId}" data-heading-source="${sourceSlug}">`
  })
}

/**
 * Transforms callouts like > [!NOTE] content into styled div containers.
 */
export function renderCallouts(text: string): string {
  return text.replace(/>\s*\[!([A-Z]+)\]\s*(.*?)(?=\n\n|\n$|$)/gs, (_match, type, content) => {
    const cleanType = type.toLowerCase().trim()
    const cleanContent = DOMPurify.sanitize(content.trim())
    return `<div class="callout" data-callout-type="${cleanType}"><p>${cleanContent}</p></div>`
  })
}

/**
 * Parses markdown to HTML, processes wikilinks, callouts, code block wrappers, and sanitizes output.
 */
export function renderMarkdown(
  markdownText: string,
  resolveEmbed?: (target: string) => EmbedResolution,
  copyLabel = 'Copy',
  headingIdPrefix = '',
): string {
  if (!markdownText) return ''

  const headings = parseHeadings(markdownText)

  const externalEmbeds = extractExternalEmbeds(markdownText)
  const withEmbeds = renderEmbeds(externalEmbeds.text, resolveEmbed)
  const withWikilinks = renderWikilinks(withEmbeds)
  const withCallouts = renderCallouts(withWikilinks)

  // Convert markdown to HTML
  let rawHtml = marked.parse(withCallouts, { async: false }) as string

  // Wrap code blocks
  rawHtml = wrapCodeBlocks(rawHtml, copyLabel)

  // Stamp heading ids for outline navigation
  rawHtml = injectHeadingIds(rawHtml, headings, headingIdPrefix)

  // Raw user HTML must not create iframes. Generated external embeds are
  // inserted only after this guard, from placeholders we created above.
  rawHtml = rawHtml
    .replace(/<iframe\b[^>]*>[\s\S]*?<\/iframe>/gi, '')
    .replace(/<iframe\b[^>]*\/?>(?:<\/iframe>)?/gi, '')
  rawHtml = Object.entries(externalEmbeds.blocks).reduce(
    (html, [token, iframe]) => html.replaceAll(token, iframe),
    rawHtml,
  )

  // Sanitize with DOMPurify ensuring derived tags and attributes from block registry are allowed
  return DOMPurify.sanitize(rawHtml, {
    ADD_ATTR: getClientAllowedAttributes(),
    ALLOWED_TAGS: getClientAllowedTags(),
    ALLOWED_ATTR: getClientAllowedAttributes(),
  })
}
