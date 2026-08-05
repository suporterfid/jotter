import { describe, expect, it } from 'vitest'
import { Editor, defaultValueCtx, editorViewCtx, rootCtx, serializerCtx } from '@milkdown/kit/core'
import { commonmark } from '@milkdown/kit/preset/commonmark'
import { gfm } from '@milkdown/kit/preset/gfm'
import { toggleNode } from '../../wysiwygNodes/toggle'

async function roundTrip(markdown: string): Promise<string> {
  const editor = Editor.make()
    .config((ctx) => {
      ctx.set(rootCtx, document.createElement('div'))
      ctx.set(defaultValueCtx, markdown)
    })
    .use(commonmark)
    .use(gfm)
    .use(toggleNode)

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
    .use(toggleNode)

  await editor.create()
  return { root, destroy: () => editor.destroy() }
}

describe('toggleNode', () => {
  it('round-trips a single-line toggle byte-for-byte', async () => {
    const md = '<details><summary>Toggle</summary>Content</details>\n'
    expect((await roundTrip(md)).trim()).toBe(md.trim())
  })

  it('round-trips a toggle with a different title', async () => {
    const md = '<details><summary>More Info</summary>Hidden text here.</details>\n'
    expect((await roundTrip(md)).trim()).toBe(md.trim())
  })

  it('renders a details/summary element, matching the blockRegistry.ts syntax', async () => {
    const { root, destroy } = await domFor('<details><summary>Toggle</summary>Content</details>\n')
    const details = root.querySelector('details')
    expect(details).not.toBeNull()
    expect(details?.querySelector('summary')?.textContent).toBe('Toggle')
    expect(details?.textContent).toContain('Content')
    await destroy()
  })
})
