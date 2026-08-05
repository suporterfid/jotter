import { $nodeSchema, $remark } from '@milkdown/utils'
import { visit } from 'unist-util-visit'
import type { Blockquote, Paragraph, Root, Text } from 'mdast'

/**
 * `> [!NOTE] Content` — the same marker markdown.ts/MarkdownServerRenderer.php
 * already recognize for Preview mode. CommonMark parses this as a plain
 * blockquote containing a paragraph whose text happens to start with
 * `[!NOTE] `; this transform retags that blockquote as a `callout` mdast
 * node (stripping the marker prefix) so it gets a dedicated, editable
 * ProseMirror node instead of surviving as a blockquote full of literal,
 * defensively-escaped `[!NOTE]` text.
 */
const CALLOUT_MARKER = /^\[!([A-Za-z]+)\]\s?/

function tagCallouts() {
  return (tree: Root) => {
    visit(tree, 'blockquote', (node: Blockquote) => {
      const firstChild = node.children[0]
      if (!firstChild || firstChild.type !== 'paragraph') return

      const paragraph = firstChild as Paragraph
      const firstText = paragraph.children[0]
      if (!firstText || firstText.type !== 'text') return

      const match = CALLOUT_MARKER.exec((firstText as Text).value)
      if (!match) return

      ;(firstText as Text).value = (firstText as Text).value.slice(match[0].length)
      if ((firstText as Text).value === '') paragraph.children.shift()

      const callout = node as unknown as { type: string; calloutType: string }
      callout.type = 'callout'
      callout.calloutType = match[1].toUpperCase()
    })
  }
}

export const remarkCallout = $remark('remarkCallout', () => tagCallouts)

function calloutToMarkdown() {
  return {
    handlers: {
      callout(
        node: { calloutType: string },
        _parent: unknown,
        state: {
          enter: (name: string) => () => void
          createTracker: (info: unknown) => { move: (s: string) => string; shift: (n: number) => void; current: () => unknown }
          containerFlow: (node: unknown, tracker: unknown) => string
          indentLines: (value: string, map: (line: string, index: number, blank: boolean) => string) => string
        },
        info: unknown
      ) {
        const exit = state.enter('blockquote')
        const tracker = state.createTracker(info)
        tracker.move('> ')
        tracker.shift(2)
        const value = state.indentLines(
          state.containerFlow(node, tracker.current()),
          (line, index, blank) => `>${blank ? '' : ' '}${line}`
        )
        exit()
        return value.replace(/^> /, `> [!${node.calloutType}] `)
      },
    },
  }
}

function attachToMarkdownExtension() {
  // eslint-disable-next-line @typescript-eslint/no-this-alias
  const processor = this as { data: (key: string, value?: unknown) => unknown }
  const existing = (processor.data('toMarkdownExtensions') as unknown[] | undefined) ?? []
  processor.data('toMarkdownExtensions', [...existing, calloutToMarkdown()])
  return (tree: Root) => tree
}

export const remarkCalloutToMarkdown = $remark('remarkCalloutToMarkdown', () => attachToMarkdownExtension)

const calloutNodeSchema = $nodeSchema('callout', () => ({
  content: 'block+',
  group: 'block',
  defining: true,
  attrs: {
    calloutType: { default: 'NOTE' },
  },
  parseDOM: [
    {
      tag: 'div.callout',
      getAttrs: (dom) => {
        if (!(dom instanceof HTMLElement)) return false
        return { calloutType: dom.dataset.calloutType ?? 'NOTE' }
      },
    },
  ],
  toDOM: (node) => [
    'div',
    { class: 'callout', 'data-callout-type': node.attrs.calloutType },
    0,
  ],
  parseMarkdown: {
    match: ({ type }) => type === 'callout',
    runner: (state, node, type) => {
      const callout = node as unknown as { calloutType: string }
      state.openNode(type, { calloutType: callout.calloutType })
      state.next(node.children)
      state.closeNode()
    },
  },
  toMarkdown: {
    match: (node) => node.type.name === 'callout',
    runner: (state, node) => {
      state.openNode('callout', undefined, { calloutType: node.attrs.calloutType }).next(node.content).closeNode()
    },
  },
}))

export const calloutNode = [remarkCallout, remarkCalloutToMarkdown, calloutNodeSchema].flat()
