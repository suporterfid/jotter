import { describe, expect, it } from 'vitest'
import { Editor, defaultValueCtx, editorViewCtx, rootCtx, serializerCtx } from '@milkdown/kit/core'
import { commonmark } from '@milkdown/kit/preset/commonmark'
import { gfm } from '@milkdown/kit/preset/gfm'
import { wikilinkNode } from '../../wysiwygNodes/wikilink'

async function roundTrip(markdown: string): Promise<string> {
  const editor = Editor.make()
    .config((ctx) => {
      ctx.set(rootCtx, document.createElement('div'))
      ctx.set(defaultValueCtx, markdown)
    })
    .use(commonmark)
    .use(gfm)
    .use(wikilinkNode)

  await editor.create()
  try {
    return editor.action((ctx) => {
      const view = ctx.get(editorViewCtx)
      const serializer = ctx.get(serializerCtx)
      return serializer(view.state.doc)
    })
  } finally {
    await editor.destroy()
  }
}

async function domFor(markdown: string): Promise<{ root: HTMLElement; destroy: () => Promise<void> }> {
  const root = document.createElement('div')
  const editor = Editor.make()
    .config((ctx) => {
      ctx.set(rootCtx, root)
      ctx.set(defaultValueCtx, markdown)
    })
    .use(commonmark)
    .use(gfm)
    .use(wikilinkNode)

  await editor.create()
  return { root, destroy: async () => { await editor.destroy() } }
}

describe('wikilinkNode', () => {
  it('round-trips a plain wikilink byte-for-byte', async () => {
    const md = 'See [[Other Note]] for more.\n'
    expect((await roundTrip(md)).trim()).toBe(md.trim())
  })

  it('round-trips a wikilink with an alias', async () => {
    const md = '[[Other Note|Click here]]\n'
    expect((await roundTrip(md)).trim()).toBe(md.trim())
  })

  it('round-trips a wikilink with a heading anchor', async () => {
    const md = '[[Other Note#Some Heading]]\n'
    expect((await roundTrip(md)).trim()).toBe(md.trim())
  })

  it('round-trips a wikilink with a block-id anchor', async () => {
    const md = '[[Other Note#^blockid]]\n'
    expect((await roundTrip(md)).trim()).toBe(md.trim())
  })

  it('round-trips a wikilink with both heading anchor and alias', async () => {
    const md = '[[Other Note#Heading|Alias]]\n'
    expect((await roundTrip(md)).trim()).toBe(md.trim())
  })

  it('renders as an anchor with the wikilink class and data-target, matching MarkdownPreview.vue', async () => {
    const { root, destroy } = await domFor('See [[Other Note]] here.\n')
    const anchor = root.querySelector('a.wikilink')
    expect(anchor).not.toBeNull()
    expect(anchor?.getAttribute('data-target')).toBe('Other Note')
    expect(anchor?.textContent).toBe('Other Note')
    await destroy()
  })

  it('renders the alias as the label when present', async () => {
    const { root, destroy } = await domFor('[[Other Note|Click here]]\n')
    const anchor = root.querySelector('a.wikilink')
    expect(anchor?.textContent).toBe('Click here')
    expect(anchor?.getAttribute('data-target')).toBe('Other Note')
    await destroy()
  })

  it('does not touch plain double-bracket-free text', async () => {
    const md = 'Just a normal paragraph, no brackets.\n'
    expect((await roundTrip(md)).trim()).toBe(md.trim())
  })
})
