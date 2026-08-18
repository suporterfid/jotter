import { $nodeSchema, $remark } from '@milkdown/utils'
import { visit } from 'unist-util-visit'
import type { Link, Paragraph, Root } from 'mdast'

interface ExternalEmbedMdastNode {
  type: 'externalEmbed'
  url: string
}

function tagExternalEmbeds() {
  return (tree: Root) => {
    visit(tree, 'paragraph', (node: Paragraph, index, parent) => {
      if (!parent || index === undefined || node.children.length !== 1) return

      const child = node.children[0] as Link
      if (child.type !== 'link' || child.children.length !== 1) return
      if (child.children[0].type !== 'text' || child.children[0].value !== child.url) return
      if (!child.url.startsWith('https://')) return

      const externalEmbed: ExternalEmbedMdastNode = {
        type: 'externalEmbed',
        url: child.url,
      }
      parent.children.splice(index, 1, externalEmbed as never)
    })
  }
}

export const remarkExternalEmbed = $remark('remarkExternalEmbed', () => tagExternalEmbeds)

function externalEmbedToMarkdown() {
  return {
    handlers: {
      externalEmbed: (node: ExternalEmbedMdastNode) => node.url,
    },
  }
}

function attachToMarkdownExtension(this: { data: (key: string, value?: unknown) => unknown }) {
  const processor = this
  const existing = (processor.data('toMarkdownExtensions') as unknown[] | undefined) ?? []
  processor.data('toMarkdownExtensions', [...existing, externalEmbedToMarkdown()])
  return (tree: Root) => tree
}

export const remarkExternalEmbedToMarkdown = $remark(
  'remarkExternalEmbedToMarkdown',
  () => attachToMarkdownExtension,
)

const externalEmbedNodeSchema = $nodeSchema('external_embed', () => ({
  group: 'block',
  atom: true,
  defining: true,
  attrs: {
    url: { default: '' },
  },
  parseDOM: [
    {
      tag: 'a.external-embed-link',
      getAttrs: (dom) => {
        if (!(dom instanceof HTMLElement)) return false
        return { url: dom.getAttribute('href') ?? '' }
      },
    },
  ],
  toDOM: (node) => [
    'a',
    {
      class: 'external-embed-link',
      href: node.attrs.url,
      target: '_blank',
      rel: 'noopener noreferrer',
    },
    node.attrs.url,
  ],
  parseMarkdown: {
    match: ({ type }) => type === 'externalEmbed',
    runner: (state, node, type) => {
      const externalEmbed = node as unknown as ExternalEmbedMdastNode
      state.addNode(type, { url: externalEmbed.url })
    },
  },
  toMarkdown: {
    match: (node) => node.type.name === 'external_embed',
    runner: (state, node) => {
      state.addNode('externalEmbed', undefined, undefined, { url: node.attrs.url })
    },
  },
}))

export const externalEmbedNode = [
  remarkExternalEmbed,
  remarkExternalEmbedToMarkdown,
  externalEmbedNodeSchema,
].flat()
