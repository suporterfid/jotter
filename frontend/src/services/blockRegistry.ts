export interface BlockDefinition {
  name: string
  syntax: string
  allowed_tags: string[]
  allowed_attributes: string[]
  slash_menu: {
    label: string
    icon: string
  }
}

export const blockDefinitions: Record<string, BlockDefinition> = {
  task_list: {
    name: 'Task List',
    syntax: '- [ ] Task item',
    allowed_tags: ['ul', 'li', 'input', 'label'],
    allowed_attributes: ['type', 'checked', 'disabled', 'class'],
    slash_menu: { label: 'To-do List', icon: 'check-square' },
  },
  code_block: {
    name: 'Code Block',
    syntax: "```js\ncode\n```",
    allowed_tags: ['pre', 'code', 'button', 'span'],
    allowed_attributes: ['class', 'data-language', 'data-copy'],
    slash_menu: { label: 'Code Block', icon: 'code' },
  },
  wikilink: {
    name: 'Wikilink',
    syntax: '[[Note Title]]',
    allowed_tags: ['a'],
    allowed_attributes: ['href', 'class', 'data-target', 'title'],
    slash_menu: { label: 'Link Note', icon: 'link' },
  },
  callout: {
    name: 'Callout',
    syntax: '> [!NOTE] Callout content',
    allowed_tags: ['div', 'p', 'span'],
    allowed_attributes: ['class', 'data-callout-type'],
    slash_menu: { label: 'Callout Box', icon: 'info' },
  },
  toggle: {
    name: 'Toggle List',
    syntax: '<details><summary>Toggle</summary>Content</details>',
    allowed_tags: ['details', 'summary', 'p', 'div'],
    allowed_attributes: ['open', 'class'],
    slash_menu: { label: 'Toggle', icon: 'chevron-right' },
  },
  table: {
    name: 'Table',
    syntax: "| Header 1 | Header 2 |\n| --- | --- |\n| Cell 1 | Cell 2 |",
    allowed_tags: ['table', 'thead', 'tbody', 'tr', 'th', 'td'],
    allowed_attributes: ['class', 'align'],
    slash_menu: { label: 'Table', icon: 'grid' },
  },
  divider: {
    name: 'Horizontal Divider',
    syntax: '---',
    allowed_tags: ['hr'],
    allowed_attributes: ['class'],
    slash_menu: { label: 'Divider', icon: 'minus' },
  },
  embed: {
    name: 'Embed',
    syntax: '![[Note Title]]',
    allowed_tags: ['div'],
    allowed_attributes: ['class', 'data-embed-status', 'data-embed-target'],
    slash_menu: { label: 'Embed Note', icon: 'layout' },
  },
  external_embed: {
    name: 'External Embed',
    syntax: 'https://example.com/embed',
    allowed_tags: ['iframe', 'a'],
    allowed_attributes: ['class', 'src', 'title', 'sandbox', 'referrerpolicy', 'loading', 'href', 'target', 'rel'],
    slash_menu: { label: 'External Embed', icon: 'globe' },
  },
}

export function getClientAllowedTags(): string[] {
  const base = ['p', 'br', 'em', 'strong', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'blockquote', 'ol', 'ul', 'li']
  const tags = new Set(base)
  Object.values(blockDefinitions).forEach(def => {
    def.allowed_tags.forEach(t => tags.add(t))
  })
  return Array.from(tags)
}

export function getClientAllowedAttributes(): string[] {
  const base = ['class', 'id']
  const attrs = new Set(base)
  Object.values(blockDefinitions).forEach(def => {
    def.allowed_attributes.forEach(a => attrs.add(a))
  })
  return Array.from(attrs)
}
