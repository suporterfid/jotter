import { Editor, defaultValueCtx, editorViewCtx, rootCtx, serializerCtx } from '@milkdown/kit/core'
import { TextSelection } from '@milkdown/kit/prose/state'
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
  /**
   * The 1-based markdown line the current selection's containing top-level
   * block starts on (the same "line N" convention NoteComment.anchor_line
   * already uses), or null if the selection is collapsed. Computed by
   * serializing only the doc's preceding sibling blocks and counting
   * newlines — an exact document-structure mapping, not a coordinate
   * heuristic (WY.4, #324).
   */
  getSelectionAnchorLine(): number | null
  /** Test-only: programmatically sets the selection (mouse selection can't be simulated under jsdom). */
  selectTextRange(from: number, to: number): void
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
    getSelectionAnchorLine() {
      const { selection } = view.state
      if (selection.empty) return null

      const $from = selection.$from
      const prefixFragment = view.state.doc.content.cut(0, $from.before(1))
      if (prefixFragment.size === 0) return 1

      const prefixDoc = view.state.schema.topNodeType.create(null, prefixFragment)
      const serializer = editor.ctx.get(serializerCtx)
      // Serializing the prefix as its own standalone doc always ends with
      // exactly one trailing newline, but the full document has a blank
      // line separating this prefix from the block the selection is in —
      // that separator line is what the selection's block actually starts
      // after, so it must be added back.
      return serializer(prefixDoc).split('\n').length + 1
    },
    selectTextRange(from: number, to: number) {
      const size = view.state.doc.content.size
      const clampedTo = Math.max(0, Math.min(to, size - 1))
      let clampedFrom = Math.max(0, Math.min(from, size - 1))
      // A caller-requested non-collapsed range (from !== to) that both
      // clamp to the same ceiling would otherwise silently collapse.
      if (clampedFrom === clampedTo && from !== to && clampedTo > 0) {
        clampedFrom = clampedTo - 1
      }
      const selection = TextSelection.create(view.state.doc, clampedFrom, clampedTo)
      view.dispatch(view.state.tr.setSelection(selection))
    },
    async destroy() {
      root.removeEventListener('keyup', checkSlashTrigger)
      await editor.destroy()
    },
  }
}
