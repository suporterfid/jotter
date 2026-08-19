import { describe, expect, it } from 'vitest'
import { renderMarkdown } from './services/markdown'
import { setExternalEmbedAllowedHosts } from './services/externalEmbeds'

describe('Markdown rendering & XSS security', () => {
  it('renders an allowlisted standalone HTTPS URL as a sandboxed iframe', () => {
    setExternalEmbedAllowedHosts(['youtube.com'])

    const html = renderMarkdown('https://www.youtube.com/embed/abc\n')

    expect(html).toContain('<iframe class="external-embed"')
    expect(html).toContain('src="https://www.youtube.com/embed/abc"')
    expect(html).toContain('sandbox="allow-scripts"')
    expect(html).toContain('referrerpolicy="no-referrer"')
    expect(html).toContain('loading="lazy"')
    expect(html).not.toContain('allow-same-origin')
  })

  it('leaves rejected and fenced URLs as links or code, never iframes', () => {
    setExternalEmbedAllowedHosts(['youtube.com'])

    const html = renderMarkdown([
      'https://evil-youtube.com/embed/abc',
      '',
      '```',
      'https://www.youtube.com/embed/fenced',
      '```',
      '',
      '~~~',
      'https://www.youtube.com/embed/tilde-fenced',
      '~~~',
    ].join('\n'))

    expect(html).not.toContain('<iframe')
    expect(html).toContain('https://evil-youtube.com/embed/abc')
    expect(html).toContain('https://www.youtube.com/embed/fenced')
    expect(html).toContain('https://www.youtube.com/embed/tilde-fenced')
  })

  it('does not turn raw user iframe HTML into an external embed', () => {
    setExternalEmbedAllowedHosts(['youtube.com'])

    const html = renderMarkdown('<iframe src="https://www.youtube.com/embed/raw"></iframe>')

    expect(html).not.toContain('<iframe')
    expect(html).not.toContain('https://www.youtube.com/embed/raw')
  })

  it('renders standard markdown elements', () => {
    const md = '# Heading\n\nThis is **bold** text and *italic* text.'
    const html = renderMarkdown(md)
    expect(html).toContain('<h1')
    expect(html).toContain('Heading')
    expect(html).toContain('<strong>bold</strong>')
  })

  it('stamps matching ids on rendered headings for outline navigation', () => {
    const md = '# Notes\n\nSome text.\n\n## Notes'
    const html = renderMarkdown(md)
    expect(html).toContain('<h1 id="notes" data-heading-source="notes">')
    expect(html).toContain('<h2 id="notes-2" data-heading-source="notes-2">')
  })

  it('prefixes heading ids while retaining the outline source slug', () => {
    const html = renderMarkdown('# Notes\n\n## Notes', undefined, 'Copy', 'pane-secondary--')

    expect(html).toContain('<h1 id="pane-secondary--notes" data-heading-source="notes">')
    expect(html).toContain('<h2 id="pane-secondary--notes-2" data-heading-source="notes-2">')
  })

  it('parses wikilinks into data-target anchor elements', () => {
    const md = 'Check out [[Projects/Jotter|Jotter Docs]] and [[Ideas]].'
    const html = renderMarkdown(md)
    expect(html).toContain('class="wikilink"')
    expect(html).toContain('data-target="Projects/Jotter"')
    expect(html).toContain('Jotter Docs')
    expect(html).toContain('data-target="Ideas"')
  })

  it('renders GFM task list checkboxes and code block wrappers', () => {
    const md = '- [ ] Task 1\n- [x] Task 2\n\n```js\nconsole.log("hello");\n```'
    const html = renderMarkdown(md)
    expect(html).toContain('type="checkbox"')
    expect(html).toContain('class="code-block-wrapper"')
    expect(html).toContain('class="copy-code-btn"')
  })

  it('sanitizes malicious script tags and inline XSS handlers', () => {
    const maliciousMd = '# Evil\n\n<script>alert("xss")</script>\n<img src="x" onerror="alert(1)">\n<a href="javascript:alert(1)">Click Me</a>'
    const html = renderMarkdown(maliciousMd)
    
    // Assert executable scripts and attributes are stripped by DOMPurify
    expect(html).not.toContain('<script>')
    expect(html).not.toContain('onerror')
    expect(html).not.toContain('href="javascript:')
  })

  it('renders a dataview code block as readable code without crashing', () => {
    const md = '# Notes\n\n```dataview\nLIST FROM #project WHERE status = "active"\n```\n\nAfter.'
    const html = renderMarkdown(md)

    expect(html).toContain('class="code-block-wrapper"')
    expect(html).toContain('LIST FROM')
    expect(html).toContain('#project')
    expect(html).toContain('After.')
  })

  it('renders Tasks-plugin emoji syntax as readable checkbox text without crashing', () => {
    const md = '- [ ] Do thing \u{1F4C5} 2026-08-01 \u{1F501} every week\n- [x] Done thing \u{2705} 2026-07-01'
    const html = renderMarkdown(md)

    expect(html).toContain('type="checkbox"')
    expect(html).toContain('Do thing')
    expect(html).toContain('\u{1F4C5}')
    expect(html).toContain('2026-08-01')
    expect(html).toContain('every week')
    expect(html).toContain('\u{2705}')
  })

  it('renders callouts, toggles, tables, and dividers', () => {
    const md = '> [!NOTE] Callout body\n\n<details><summary>Summary</summary>Body</details>\n\n| H1 | H2 |\n| --- | --- |\n| C1 | C2 |\n\n---'
    const html = renderMarkdown(md)

    expect(html).toContain('class="callout"')
    expect(html).toContain('data-callout-type="note"')
    expect(html).toContain('<details>')
    expect(html).toContain('<summary>')
    expect(html).toContain('<table>')
    expect(html).toContain('<hr')
  })

  it('renders a resolved embed inline via the resolveEmbed callback', () => {
    const md = 'Before.\n\n![[Ideas]]\n\nAfter.'
    const html = renderMarkdown(md, (target) => {
      expect(target).toBe('Ideas')
      return { status: 'resolved', html: '<p>Idea body.</p>' }
    })
    expect(html).toContain('data-embed-status="resolved"')
    expect(html).toContain('data-embed-target="Ideas"')
    expect(html).toContain('Idea body.')
  })

  it('renders a loading placeholder for a loading embed', () => {
    const html = renderMarkdown('![[Ideas]]', () => ({ status: 'loading' }))
    expect(html).toContain('data-embed-status="loading"')
    expect(html).toContain('Loading embed')
  })

  it('renders an unresolved-note message for an unresolved embed', () => {
    const html = renderMarkdown('![[Missing]]', () => ({ status: 'unresolved' }))
    expect(html).toContain('data-embed-status="unresolved"')
    expect(html).toContain('Missing')
  })

  it('renders a circular-embed guard message', () => {
    const html = renderMarkdown('![[Self]]', () => ({ status: 'circular' }))
    expect(html).toContain('data-embed-status="circular"')
    expect(html).toContain('Cannot embed a note within itself')
  })

  it('leaves ![[...]] completely literal when no resolveEmbed is given', () => {
    const html = renderMarkdown('![[Ideas]]')
    expect(html).toContain('![[Ideas]]')
    expect(html).not.toContain('class="wikilink"')
  })

  it('still renders a plain [[Note]] link unaffected by the embed lookbehind', () => {
    const html = renderMarkdown('[[Ideas]]')
    expect(html).toContain('class="wikilink"')
    expect(html).toContain('data-target="Ideas"')
  })
})
