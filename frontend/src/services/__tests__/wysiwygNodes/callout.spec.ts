import { describe, expect, it } from 'vitest'
import { Editor, defaultValueCtx, editorViewCtx, rootCtx, serializerCtx } from '@milkdown/kit/core'
import { commonmark } from '@milkdown/kit/preset/commonmark'
import { gfm } from '@milkdown/kit/preset/gfm'
import { calloutNode } from '../../wysiwygNodes/callout'

async function roundTrip(markdown: string): Promise<string> {
  const editor = Editor.make()
    .config((ctx) => {
      ctx.set(rootCtx, document.createElement('div'))
      ctx.set(defaultValueCtx, markdown)
    })
    .use(commonmark)
    .use(gfm)
    .use(calloutNode)

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
    .use(calloutNode)

  await editor.create()
  return { root, destroy: async () => { await editor.destroy() } }
}

describe('calloutNode', () => {
  it('round-trips a NOTE callout byte-for-byte', async () => {
    const md = '> [!NOTE] This is a note.\n'
    expect((await roundTrip(md)).trim()).toBe(md.trim())
  })

  it('round-trips a WARNING callout', async () => {
    const md = '> [!WARNING] Careful here.\n'
    expect((await roundTrip(md)).trim()).toBe(md.trim())
  })

  it('does not touch a plain blockquote with no callout marker', async () => {
    const md = '> A plain quoted line.\n'
    expect((await roundTrip(md)).trim()).toBe(md.trim())
  })

  it('renders div.callout with data-callout-type, matching MarkdownPreview.vue', async () => {
    const { root, destroy } = await domFor('> [!NOTE] This is a note.\n')
    const callout = root.querySelector('div.callout')
    expect(callout).not.toBeNull()
    expect(callout?.getAttribute('data-callout-type')).toBe('NOTE')
    expect(callout?.textContent?.trim()).toBe('This is a note.')
    await destroy()
  })

  it('a plain blockquote still renders as <blockquote>, not a callout', async () => {
    const { root, destroy } = await domFor('> A plain quoted line.\n')
    expect(root.querySelector('div.callout')).toBeNull()
    expect(root.querySelector('blockquote')).not.toBeNull()
    await destroy()
  })
})
