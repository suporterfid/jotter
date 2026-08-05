import { Editor, defaultValueCtx, rootCtx } from '@milkdown/kit/core'
import { commonmark } from '@milkdown/kit/preset/commonmark'
import { gfm } from '@milkdown/kit/preset/gfm'
import { listener, listenerCtx } from '@milkdown/kit/plugin/listener'
import { replaceAll } from '@milkdown/kit/utils'

export interface WysiwygEditorHandle {
  /** Pushes external markdown into the editor (e.g. switching notes). */
  setMarkdown(markdown: string): void
  destroy(): Promise<void>
}

/**
 * Creates a Milkdown editor mounted on `root`, wired so every content change
 * (typing, paste, or an external setMarkdown() call) invokes onMarkdownChanged
 * with the freshly serialized markdown. Standard CommonMark+GFM nodes only —
 * the same configuration WY.1's round-trip harness
 * (services/__tests__/wysiwygRoundTrip.spec.ts) already proves is safe for
 * Jotter's syntax: [[wikilink]], ![[embed]], and > [!NOTE] callouts pass
 * through as plain text until WY.3 adds native nodes for them.
 */
export async function createWysiwygEditor(
  root: HTMLElement,
  initialMarkdown: string,
  onMarkdownChanged: (markdown: string) => void
): Promise<WysiwygEditorHandle> {
  const editor = Editor.make()
    .config((ctx) => {
      ctx.set(rootCtx, root)
      ctx.set(defaultValueCtx, initialMarkdown)
      ctx.get(listenerCtx).markdownUpdated((_ctx, markdown, prevMarkdown) => {
        if (markdown !== prevMarkdown) {
          onMarkdownChanged(markdown)
        }
      })
    })
    .use(commonmark)
    .use(gfm)
    .use(listener)

  await editor.create()

  return {
    setMarkdown(markdown: string) {
      editor.action(replaceAll(markdown))
    },
    async destroy() {
      await editor.destroy()
    },
  }
}
