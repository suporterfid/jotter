import { $nodeSchema } from '@milkdown/utils'
import type { WikiLinkNode } from './wikilink'

/**
 * `![[Target]]` — parsed into a `wikiEmbed` mdast node by
 * wysiwygNodes/wikilink.ts's shared tokenizer (both syntaxes are driven by
 * one regex so they can never disagree on where a wikilink ends and an
 * embed begins). This module only bridges that mdast node to a ProseMirror
 * node; toMarkdown serialization is registered by wikilink.ts's
 * remarkWikilinkToMarkdown extension.
 *
 * Content resolution (the actual transclusion — reusing #288/#294's
 * resolve-embed logic) is a NodeView concern wired in wysiwygEditor.ts,
 * not this schema: the schema only renders the placeholder chrome
 * MarkdownPreview.vue already uses (`div.embed-block[data-embed-target]`).
 */
export const embedNode = $nodeSchema('wiki_embed', () => ({
  group: 'inline',
  inline: true,
  atom: true,
  attrs: {
    target: { default: '' },
    heading: { default: null },
  },
  parseDOM: [
    {
      tag: 'div.embed-block',
      getAttrs: (dom) => {
        if (!(dom instanceof HTMLElement)) return false
        return { target: dom.dataset.embedTarget ?? '' }
      },
    },
  ],
  toDOM: (node) => {
    const { target } = node.attrs
    return [
      'div',
      {
        class: 'embed-block',
        'data-embed-status': 'unresolved',
        'data-embed-target': target,
      },
      target,
    ]
  },
  parseMarkdown: {
    match: ({ type }) => type === 'wikiEmbed',
    runner: (state, node, type) => {
      const wiki = node as unknown as WikiLinkNode
      state.addNode(type, {
        target: wiki.target,
        heading: wiki.heading ?? null,
      })
    },
  },
  toMarkdown: {
    match: (node) => node.type.name === 'wiki_embed',
    runner: (state, node) => {
      state.addNode('wikiEmbed', undefined, undefined, {
        target: node.attrs.target,
        heading: node.attrs.heading ?? undefined,
      })
    },
  },
}))
