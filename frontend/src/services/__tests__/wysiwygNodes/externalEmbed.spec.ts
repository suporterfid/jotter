import { describe, expect, it } from 'vitest'
import { Editor, defaultValueCtx, editorViewCtx, rootCtx, serializerCtx } from '@milkdown/kit/core'
import { commonmark } from '@milkdown/kit/preset/commonmark'
import { gfm } from '@milkdown/kit/preset/gfm'
import { externalEmbedNode } from '../../wysiwygNodes/externalEmbed'

async function roundTrip(markdown: string): Promise<string> {
  const editor = Editor.make()
    .config((ctx) => {
      ctx.set(rootCtx, document.createElement('div'))
      ctx.set(defaultValueCtx, markdown)
    })
    .use(commonmark)
    .use(gfm)
    .use(externalEmbedNode)

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
    .use(externalEmbedNode)

  await editor.create()
  return { root, destroy: async () => { await editor.destroy() } }
}

describe('externalEmbedNode', () => {
  it('round-trips a standalone HTTPS URL byte-for-byte', async () => {
    const markdown = 'https://www.youtube.com/embed/abc\n'

    expect((await roundTrip(markdown)).trim()).toBe(markdown.trim())
  })

  it('renders a safe link in the WYSIWYG DOM and never an iframe', async () => {
    const { root, destroy } = await domFor('https://www.youtube.com/embed/abc\n')
    const link = root.querySelector('a.external-embed-link')

    expect(link).not.toBeNull()
    expect(link?.getAttribute('href')).toBe('https://www.youtube.com/embed/abc')
    expect(link?.getAttribute('target')).toBe('_blank')
    expect(link?.getAttribute('rel')).toBe('noopener noreferrer')
    expect(root.querySelector('iframe')).toBeNull()
    await destroy()
  })
})
