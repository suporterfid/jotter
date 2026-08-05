import { $nodeSchema, $remark } from '@milkdown/utils'
import { visit } from 'unist-util-visit'
import type { Root, Text, Parent } from 'mdast'

export interface WikiLinkNode {
  type: 'wikiLink' | 'wikiEmbed'
  target: string
  heading?: string
  alias?: string
}

/**
 * `[[Target]]`, `[[Target|Alias]]`, `[[Target#Heading]]`,
 * `[[Target#^blockid]]` — the same grammar wikilinks.ts/markdown.ts already
 * parse for Preview mode (see frontend/src/services/wikilinks.ts). The
 * leading `!` is captured too so the sibling embed node (wysiwygNodes/embed.ts)
 * can reuse this same tokenizer instead of duplicating the regex.
 */
export const WIKILINK_PATTERN = /(!)?\[\[([^\]|#]+)(?:#([^\]|]+))?(?:\|([^\]]+))?\]\]/g

/**
 * Splits `[[...]]`/`![[...]]` occurrences out of mdast text nodes into
 * dedicated `wikiLink`/`wikiEmbed` nodes, the same way remark's own syntax
 * extensions (e.g. remark-gfm's autolink literals) rewrite the tree after
 * the initial parse — no micromark tokenizer needed since this syntax
 * never spans block boundaries.
 */
function splitWikilinks() {
  return (tree: Root) => {
    visit(tree, 'text', (node: Text, index, parent: Parent | undefined) => {
      if (!parent || index === undefined) return
      WIKILINK_PATTERN.lastIndex = 0
      if (!WIKILINK_PATTERN.test(node.value)) return
      WIKILINK_PATTERN.lastIndex = 0

      const replacements: Array<Text | (WikiLinkNode & { type: 'wikiLink' | 'wikiEmbed' })> = []
      let lastIndex = 0
      let match: RegExpExecArray | null

      while ((match = WIKILINK_PATTERN.exec(node.value)) !== null) {
        if (match.index > lastIndex) {
          replacements.push({ type: 'text', value: node.value.slice(lastIndex, match.index) })
        }

        const [, bang, target, heading, alias] = match
        replacements.push({
          type: bang ? 'wikiEmbed' : 'wikiLink',
          target: target.trim(),
          ...(heading ? { heading: heading.trim() } : {}),
          ...(alias ? { alias: alias.trim() } : {}),
        })

        lastIndex = match.index + match[0].length
      }

      if (lastIndex < node.value.length) {
        replacements.push({ type: 'text', value: node.value.slice(lastIndex) })
      }

      parent.children.splice(index, 1, ...(replacements as never[]))
    })
  }
}

export const remarkWikilink = $remark('remarkWikilink', () => splitWikilinks)

function serializeWikilink(node: WikiLinkNode): string {
  const bang = node.type === 'wikiEmbed' ? '!' : ''
  const heading = node.heading ? `#${node.heading}` : ''
  const alias = node.alias ? `|${node.alias}` : ''
  return `${bang}[[${node.target}${heading}${alias}]]`
}

const wikilinkNodeSchema = $nodeSchema('wiki_link', () => ({
  group: 'inline',
  inline: true,
  atom: true,
  attrs: {
    target: { default: '' },
    heading: { default: null },
    alias: { default: null },
  },
  parseDOM: [
    {
      tag: 'a.wikilink',
      getAttrs: (dom) => {
        if (!(dom instanceof HTMLElement)) return false
        return {
          target: dom.dataset.target ?? '',
          heading: dom.dataset.heading ?? null,
          alias: dom.textContent !== dom.dataset.target ? dom.textContent : null,
        }
      },
    },
  ],
  toDOM: (node) => {
    const { target, alias } = node.attrs
    return [
      'a',
      {
        class: 'wikilink',
        'data-target': target,
        href: `#/note/${encodeURIComponent(target)}`,
      },
      alias || target,
    ]
  },
  parseMarkdown: {
    match: ({ type }) => type === 'wikiLink',
    runner: (state, node, type) => {
      const wiki = node as unknown as WikiLinkNode
      state.addNode(type, {
        target: wiki.target,
        heading: wiki.heading ?? null,
        alias: wiki.alias ?? null,
      })
    },
  },
  toMarkdown: {
    match: (node) => node.type.name === 'wiki_link',
    runner: (state, node) => {
      state.addNode('wikiLink', undefined, undefined, {
        target: node.attrs.target,
        heading: node.attrs.heading ?? undefined,
        alias: node.attrs.alias ?? undefined,
      })
    },
  },
}))

function toMarkdownExtension() {
  return {
    unsafe: [{ character: '[', inConstruct: ['phrasing'] }],
    handlers: {
      wikiLink: (node: unknown) => serializeWikilink(node as WikiLinkNode),
      wikiEmbed: (node: unknown) => serializeWikilink({ ...(node as WikiLinkNode), type: 'wikiEmbed' }),
    },
  }
}

function attachToMarkdownExtension() {
  // eslint-disable-next-line @typescript-eslint/no-this-alias
  const processor = this as { data: (key: string, value?: unknown) => unknown }
  const existing = (processor.data('toMarkdownExtensions') as unknown[] | undefined) ?? []
  processor.data('toMarkdownExtensions', [...existing, toMarkdownExtension()])
  return (tree: Root) => tree
}

export const remarkWikilinkToMarkdown = $remark('remarkWikilinkToMarkdown', () => attachToMarkdownExtension)

export const wikilinkNode = [remarkWikilink, remarkWikilinkToMarkdown, wikilinkNodeSchema].flat()
