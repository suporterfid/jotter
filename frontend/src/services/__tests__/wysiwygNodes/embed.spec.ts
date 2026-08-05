import { describe, expect, it } from 'vitest'
import { Editor, defaultValueCtx, editorViewCtx, rootCtx, serializerCtx } from '@milkdown/kit/core'
import { commonmark } from '@milkdown/kit/preset/commonmark'
import { gfm } from '@milkdown/kit/preset/gfm'
import { wikilinkNode } from '../../wysiwygNodes/wikilink'
import { embedNode } from '../../wysiwygNodes/embed'

async function roundTrip(markdown: string): Promise<string> {
  const editor = Editor.make()
    .config((ctx) => {
      ctx.set(rootCtx, document.createElement('div'))
      ctx.set(defaultValueCtx, markdown)
    })
    .use(commonmark)
    .use(gfm)
    .use(wikilinkNode)
    .use(embedNode)

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
    .use(embedNode)

  await editor.create()
  return { root, destroy: () => editor.destroy() }
}

describe('embedNode', () => {
  it('round-trips a plain embed byte-for-byte', async () => {
    const md = 'See ![[Other Note]] embedded.\n'
    expect((await roundTrip(md)).trim()).toBe(md.trim())
  })

  it('round-trips an embed alone on its own line', async () => {
    const md = '![[Other Note]]\n'
    expect((await roundTrip(md)).trim()).toBe(md.trim())
  })

  it('does not get confused with a sibling plain wikilink', async () => {
    const md = 'See [[Plain Link]] and ![[Embedded Note]] together.\n'
    expect((await roundTrip(md)).trim()).toBe(md.trim())
  })

  it('renders a div.embed-block with data-embed-target, matching MarkdownPreview.vue', async () => {
    const { root, destroy } = await domFor('![[Other Note]]\n')
    const embed = root.querySelector('div.embed-block')
    expect(embed).not.toBeNull()
    expect(embed?.getAttribute('data-embed-target')).toBe('Other Note')
    await destroy()
  })
})
