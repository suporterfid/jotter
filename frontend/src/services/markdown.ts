import DOMPurify from 'dompurify'
import { marked } from 'marked'

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

/**
 * Parses markdown to HTML, processes wikilinks, code block wrappers, and sanitizes output.
 */
export function renderMarkdown(markdownText: string): string {
  if (!markdownText) return ''
  
  // First convert wikilinks to custom anchor tokens
  const withWikilinks = renderWikilinks(markdownText)
  
  // Convert markdown to HTML
  let rawHtml = marked.parse(withWikilinks, { async: false }) as string

  // Wrap code blocks
  rawHtml = wrapCodeBlocks(rawHtml)

  // Sanitize with DOMPurify ensuring required tags and attributes are allowed
  return DOMPurify.sanitize(rawHtml, {
    ADD_ATTR: ['data-target', 'type', 'checked', 'class'],
    ALLOWED_TAGS: [
      'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'p', 'a', 'ul', 'ol', 'li', 'code', 'pre',
      'blockquote', 'strong', 'em', 'del', 'hr', 'br', 'table', 'thead', 'tbody',
      'tr', 'th', 'td', 'img', 'span', 'div', 'input', 'button'
    ],
    ALLOWED_ATTR: ['href', 'src', 'alt', 'title', 'class', 'data-target', 'type', 'checked']
  })
}
