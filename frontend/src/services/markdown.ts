import DOMPurify from 'dompurify'
import { marked } from 'marked'
import { parseHeadings, type HeadingEntry } from './outline'

// Configure marked with GFM (GitHub Flavored Markdown)
marked.use({
  gfm: true,
  breaks: true,
})

/**
 * Transforms wikilinks like [[note]], [[note|alias]], [[note#heading]] into HTML anchors.
 */
export function renderWikilinks(text: string): string {
  // Pattern: [[target]] or [[target|alias]]
  return text.replace(/\[\[([^\]|#]+)(?:#[^\]|]+)?(?:\|([^\]]+))?\]\]/g, (_match, target, alias) => {
    const cleanTarget = target.trim()
    const label = (alias || target).trim()
    const safeTarget = DOMPurify.sanitize(cleanTarget)
    const safeLabel = DOMPurify.sanitize(label)
    return `<a class="wikilink" data-target="${safeTarget}" href="#/note/${encodeURIComponent(safeTarget)}">${safeLabel}</a>`
  })
}

/**
 * Wraps <pre><code> blocks with a container and Copy button.
 */
function wrapCodeBlocks(html: string): string {
  return html.replace(/<pre><code([^>]*)>([\s\S]*?)<\/code><\/pre>/gi, (_match, codeAttrs, codeContent) => {
    return `<div class="code-block-wrapper"><button class="copy-code-btn" type="button">Copy</button><pre><code${codeAttrs}>${codeContent}</code></pre></div>`
  })
}

import { getClientAllowedAttributes, getClientAllowedTags } from './blockRegistry'

/**
 * Stamps id="<slug>" onto each rendered <h1>-<h6> tag, in document order,
 * using the same parseHeadings() ids the outline panel lists — so a
 * drawer click can scroll the preview to a matching element via
 * document.getElementById. headings must come from parseHeadings() run
 * against the *same* raw markdown passed to renderMarkdown, so counts
 * and order line up with marked's own heading output.
 */
function injectHeadingIds(html: string, headings: HeadingEntry[]): string {
  let index = 0
  return html.replace(/<h([1-6])>/g, (match, level) => {
    const heading = headings[index]
    index += 1
    if (!heading) return match
    return `<h${level} id="${heading.id}">`
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
export function renderMarkdown(markdownText: string): string {
  if (!markdownText) return ''

  const headings = parseHeadings(markdownText)

  // Convert wikilinks and callouts
  const withWikilinks = renderWikilinks(markdownText)
  const withCallouts = renderCallouts(withWikilinks)

  // Convert markdown to HTML
  let rawHtml = marked.parse(withCallouts, { async: false }) as string

  // Wrap code blocks
  rawHtml = wrapCodeBlocks(rawHtml)

  // Stamp heading ids for outline navigation
  rawHtml = injectHeadingIds(rawHtml, headings)

  // Sanitize with DOMPurify ensuring derived tags and attributes from block registry are allowed
  return DOMPurify.sanitize(rawHtml, {
    ADD_ATTR: getClientAllowedAttributes(),
    ALLOWED_TAGS: getClientAllowedTags(),
    ALLOWED_ATTR: getClientAllowedAttributes(),
  })
}
