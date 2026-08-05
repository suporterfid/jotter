import { Editor, defaultValueCtx, editorViewCtx, rootCtx } from '@milkdown/kit/core'
import { commonmark } from '@milkdown/kit/preset/commonmark'
import { gfm } from '@milkdown/kit/preset/gfm'
import { listener, listenerCtx } from '@milkdown/kit/plugin/listener'
import { replaceAll, insert } from '@milkdown/kit/utils'
import { wikilinkNode } from './wysiwygNodes/wikilink'
import { embedNode } from './wysiwygNodes/embed'
import { calloutNode } from './wysiwygNodes/callout'
import { toggleNode } from './wysiwygNodes/toggle'

export interface SlashQuery {
  /** Text typed after the triggering `/`, for filtering blockRegistry.ts entries. */
  query: string
  /** Viewport coordinates to position SlashMenu.vue at, mirroring NoteEditor.vue's textarea trigger. */
  top: number
  left: number
}

export interface WysiwygEditorHandle {
  /** Pushes external markdown into the editor (e.g. switching notes). */
  setMarkdown(markdown: string): void
  /** Inserts a raw markdown snippet at the current selection (slash menu). */
  insertMarkdown(markdown: string): void
  /** Replaces the `/query` text that triggered the slash menu with a block. */
  insertBlockReplacingSlashQuery(markdown: string): void
  destroy(): Promise<void>
}

/**
 * Creates a Milkdown editor mounted on `root`, wired so every content change
 * (typing, paste, or an external setMarkdown() call) invokes onMarkdownChanged
 * with the freshly serialized markdown. CommonMark+GFM plus WY.3's (#323)
 * native nodes for [[wikilink]], ![[embed]], > [!NOTE] callouts, and
 * <details>/<summary> toggles — each proven against the round-trip harness
 * in its own wysiwygNodes/__tests__ spec before being wired in here.
 *
 * `onSlashQuery` re-points NoteEditor.vue's existing SlashMenu.vue (#256) at
 * this editor: fired with the text typed after a triggering `/` (same
 * "start of line, or after a space" rule the textarea's own trigger uses),
 * or `null` once that context is no longer active. blockRegistry.ts stays
 * the single source of truth for filtering/insertion either way.
 */
export async function createWysiwygEditor(
  root: HTMLElement,
  initialMarkdown: string,
  onMarkdownChanged: (markdown: string) => void,
  onSlashQuery?: (state: SlashQuery | null) => void
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

  const view = editor.ctx.get(editorViewCtx)
  let slashStartPos = -1

  function checkSlashTrigger() {
    if (!onSlashQuery) return
    const { $from } = view.state.selection
    const textBefore = $from.parent.textBetween(0, $from.parentOffset, undefined, '￼')
    const lastSlash = textBefore.lastIndexOf('/')

    if (lastSlash === -1) {
      slashStartPos = -1
      onSlashQuery(null)
      return
    }

    const charBefore = textBefore[lastSlash - 1]
    const isTriggerPosition = lastSlash === 0 || charBefore === ' ' || charBefore === '\n'
    const query = textBefore.slice(lastSlash + 1)

    if (!isTriggerPosition || query.includes(' ')) {
      slashStartPos = -1
      onSlashQuery(null)
      return
    }

    slashStartPos = $from.pos - (textBefore.length - lastSlash)
    // coordsAtPos needs real layout (getClientRects), unavailable under
    // jsdom in tests — fall back to the origin there rather than throw.
    let top = 0
    let left = 0
    try {
      const coords = view.coordsAtPos($from.pos)
      top = coords.bottom
      left = coords.left
    } catch {
      /* jsdom has no layout engine; production browsers always support this. */
    }
    onSlashQuery({ query, top, left })
  }

  root.addEventListener('keyup', checkSlashTrigger)

  return {
    setMarkdown(markdown: string) {
      editor.action(replaceAll(markdown))
    },
    insertMarkdown(markdown: string) {
      editor.action(insert(markdown))
    },
    insertBlockReplacingSlashQuery(markdown: string) {
      if (slashStartPos === -1) return
      const { from } = view.state.selection
      view.dispatch(view.state.tr.delete(slashStartPos, from))
      editor.action(insert(markdown))
      slashStartPos = -1
      onSlashQuery?.(null)
    },
    async destroy() {
      root.removeEventListener('keyup', checkSlashTrigger)
      await editor.destroy()
    },
  }
}
