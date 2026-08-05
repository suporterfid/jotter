import { Editor, defaultValueCtx, rootCtx } from '@milkdown/kit/core'
import { commonmark } from '@milkdown/kit/preset/commonmark'
import { gfm } from '@milkdown/kit/preset/gfm'
import { listener, listenerCtx } from '@milkdown/kit/plugin/listener'
import { replaceAll, insert } from '@milkdown/kit/utils'
import { wikilinkNode } from './wysiwygNodes/wikilink'
import { embedNode } from './wysiwygNodes/embed'
import { calloutNode } from './wysiwygNodes/callout'
import { toggleNode } from './wysiwygNodes/toggle'

export interface WysiwygEditorHandle {
  /** Pushes external markdown into the editor (e.g. switching notes). */
  setMarkdown(markdown: string): void
  /** Inserts a raw markdown snippet at the current selection (slash menu). */
  insertMarkdown(markdown: string): void
  destroy(): Promise<void>
}

/**
 * Creates a Milkdown editor mounted on `root`, wired so every content change
 * (typing, paste, or an external setMarkdown() call) invokes onMarkdownChanged
 * with the freshly serialized markdown. CommonMark+GFM plus WY.3's (#323)
 * native nodes for [[wikilink]], ![[embed]], > [!NOTE] callouts, and
 * <details>/<summary> toggles — each proven against the round-trip harness
 * in its own wysiwygNodes/__tests__ spec before being wired in here.
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
    .use(wikilinkNode)
    .use(embedNode)
    .use(calloutNode)
    .use(toggleNode)

  await editor.create()

  return {
    setMarkdown(markdown: string) {
      editor.action(replaceAll(markdown))
    },
    insertMarkdown(markdown: string) {
      editor.action(insert(markdown))
    },
    async destroy() {
      await editor.destroy()
    },
  }
}
