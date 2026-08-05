import { $nodeSchema, $remark } from '@milkdown/utils'
import { visit } from 'unist-util-visit'
import type { Html, Paragraph, Root } from 'mdast'

/**
 * `<details><summary>Title</summary>Content</details>` — blockRegistry.ts's
 * toggle syntax. There is no real Markdown grammar for this (it's raw HTML
 * that CommonMark parses as an opaque `html` node — see
 * MarkdownServerRenderer.php, which only unescapes the tags after
 * conversion rather than truly parsing them). This transform recognizes
 * the single-line `<details><summary>...</summary>...</details>` shape and
 * retags it as a dedicated `toggle` mdast node so it becomes a real,
 * editable ProseMirror node instead of inert raw-HTML passthrough.
 *
 * Only the single-line form is handled (matching blockRegistry.ts's own
 * snippet) — a toggle body spanning a blank line remains an opaque `html`
 * node, same as today. That's a known, documented limit, not a silent gap:
 * multi-paragraph toggle content is out of scope until a future WY phase
 * needs it.
 */
const TOGGLE_PATTERN = /^<details><summary>([\s\S]*?)<\/summary>([\s\S]*?)<\/details>$/

interface ToggleNode {
  type: 'toggle'
  title: string
  body: string
}

/**
 * Milkdown's own commonmark preset (remark-html-transformer.ts) wraps any
 * root/blockquote/listItem-level `html` mdast node in a synthetic
 * paragraph before this transform runs (so a lone HTML block doesn't
 * violate a block container's `inline*` content expression by default).
 * A toggle needs to be a block node in its own right, so this replaces
 * that synthetic `paragraph > html` pair with a bare `toggle` node at the
 * paragraph's own position — retagging the nested `html` child in place
 * would leave it trapped inside a paragraph that only accepts inline
 * content. The replacement happens in the *grandparent's* children array
 * (via index assignment) rather than mutating the paragraph node being
 * visited, since mutating a node mid-traversal confuses unist-util-visit.
 */
function tagToggles() {
  return (tree: Root) => {
    visit(tree, 'paragraph', (node: Paragraph, index, parent) => {
      if (!parent || index === undefined || node.children.length !== 1) return

      const child = node.children[0] as Html
      if (child.type !== 'html') return

      const match = TOGGLE_PATTERN.exec(child.value)
      if (!match) return

      const toggle: ToggleNode = { type: 'toggle', title: match[1], body: match[2] }
      parent.children.splice(index, 1, toggle as never)
    })
  }
}

export const remarkToggle = $remark('remarkToggle', () => tagToggles)

function toggleToMarkdown() {
  return {
    handlers: {
      toggle: (node: { title: string; body: string }) =>
        `<details><summary>${node.title}</summary>${node.body}</details>`,
    },
  }
}

function attachToMarkdownExtension() {
  // eslint-disable-next-line @typescript-eslint/no-this-alias
  const processor = this as { data: (key: string, value?: unknown) => unknown }
  const existing = (processor.data('toMarkdownExtensions') as unknown[] | undefined) ?? []
  processor.data('toMarkdownExtensions', [...existing, toggleToMarkdown()])
  return (tree: Root) => tree
}

export const remarkToggleToMarkdown = $remark('remarkToggleToMarkdown', () => attachToMarkdownExtension)

const toggleNodeSchema = $nodeSchema('toggle', () => ({
  content: 'text*',
  group: 'block',
  defining: true,
  attrs: {
    title: { default: 'Toggle' },
  },
  parseDOM: [
    {
      tag: 'details',
      getAttrs: (dom) => {
        if (!(dom instanceof HTMLElement)) return false
        return { title: dom.querySelector('summary')?.textContent ?? 'Toggle' }
      },
      contentElement: (dom) => {
        const el = dom as HTMLElement
        const summary = el.querySelector('summary')
        summary?.remove()
        return el
      },
    },
  ],
  toDOM: (node) => [
    'details',
    {},
    ['summary', {}, node.attrs.title],
    ['div', { class: 'toggle-content' }, 0],
  ],
  parseMarkdown: {
    match: ({ type }) => type === 'toggle',
    runner: (state, node, type) => {
      const toggle = node as unknown as { title: string; body: string }
      state.openNode(type, { title: toggle.title })
      if (toggle.body) state.addText(toggle.body)
      state.closeNode()
    },
  },
  toMarkdown: {
    match: (node) => node.type.name === 'toggle',
    runner: (state, node) => {
      const body = node.textContent
      state.addNode('toggle', undefined, undefined, { title: node.attrs.title, body })
    },
  },
}))

export const toggleNode = [remarkToggle, remarkToggleToMarkdown, toggleNodeSchema].flat()
