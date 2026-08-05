import { Editor, defaultValueCtx, editorViewCtx, rootCtx, serializerCtx } from '@milkdown/kit/core'
import { commonmark } from '@milkdown/kit/preset/commonmark'
import { gfm } from '@milkdown/kit/preset/gfm'

/**
 * Parses markdown through Milkdown's commonmark+gfm presets into a
 * ProseMirror doc, then serializes that doc straight back to markdown — no
 * interactive editing involved, just the parse/serialize round trip used to
 * prove fidelity (or document gaps) before any editor UI exists (WY.1, see
 * docs/20260805-jotter-wysiwyg-editor-epic-spec.md).
 */
export async function roundTripMarkdown(markdown: string): Promise<string> {
  const editor = Editor.make()
    .config((ctx) => {
      ctx.set(rootCtx, document.createElement('div'))
      ctx.set(defaultValueCtx, markdown)
    })
    .use(commonmark)
    .use(gfm)

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
