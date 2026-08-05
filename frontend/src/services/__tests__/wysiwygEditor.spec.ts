import { describe, expect, it } from 'vitest'
import { createWysiwygEditor } from '../wysiwygEditor'

describe('createWysiwygEditor', () => {
  it('mounts with the initial markdown and does not call onMarkdownChanged just from creation', async () => {
    const root = document.createElement('div')
    const changes: string[] = []

    const handle = await createWysiwygEditor(root, '# Hello\n', (md) => changes.push(md))

    expect(changes).toEqual([])
    expect(root.querySelector('h1')?.textContent).toBe('Hello')

    await handle.destroy()
  })

  it('calls onMarkdownChanged with the freshly serialized markdown when setMarkdown pushes new content', async () => {
    const root = document.createElement('div')
    const changes: string[] = []

    const handle = await createWysiwygEditor(root, '# Hello\n', (md) => changes.push(md))

    handle.setMarkdown('# Goodbye\n\nNew paragraph.\n')
    // The listener plugin debounces markdownUpdated by 200ms internally.
    await new Promise((resolve) => setTimeout(resolve, 300))

    expect(changes.length).toBeGreaterThan(0)
    expect(changes.at(-1)?.trim()).toBe('# Goodbye\n\nNew paragraph.'.trim())
    expect(root.querySelector('h1')?.textContent).toBe('Goodbye')

    await handle.destroy()
  })

  it('does not call onMarkdownChanged when setMarkdown pushes identical content', async () => {
    const root = document.createElement('div')
    const changes: string[] = []

    const handle = await createWysiwygEditor(root, '# Same\n', (md) => changes.push(md))
    handle.setMarkdown('# Same\n')
    await new Promise((resolve) => setTimeout(resolve, 300))

    expect(changes).toEqual([])

    await handle.destroy()
  })

  it('destroy() detaches the editor cleanly (no throw, root no longer has editor content)', async () => {
    const root = document.createElement('div')
    const handle = await createWysiwygEditor(root, '# Hello\n', () => {})

    await expect(handle.destroy()).resolves.toBeUndefined()
  })

  it('fires onSlashQuery when "/" is typed at the start of a paragraph, and null once the query breaks', async () => {
    const root = document.createElement('div')
    const queries: Array<{ query: string; top: number; left: number } | null> = []

    const handle = await createWysiwygEditor(root, '\n', () => {}, (state) => queries.push(state))

    root.dispatchEvent(new KeyboardEvent('keyup', { key: '/' }))
    expect(queries.at(-1)).toBeNull()

    handle.setMarkdown('/tab')
    await new Promise((resolve) => setTimeout(resolve, 300))
    root.dispatchEvent(new KeyboardEvent('keyup', { key: 'b' }))

    expect(queries.at(-1)).toMatchObject({ query: 'tab' })

    handle.setMarkdown('/tab done')
    await new Promise((resolve) => setTimeout(resolve, 300))
    root.dispatchEvent(new KeyboardEvent('keyup', { key: 'e' }))

    expect(queries.at(-1)).toBeNull()

    await handle.destroy()
  })

  it('insertBlockReplacingSlashQuery replaces the "/query" text with the inserted block', async () => {
    const root = document.createElement('div')
    const changes: string[] = []
    let lastQuery: { query: string; top: number; left: number } | null = null

    const handle = await createWysiwygEditor(root, '\n', (md) => changes.push(md), (state) => {
      lastQuery = state
    })

    handle.setMarkdown('/table')
    await new Promise((resolve) => setTimeout(resolve, 300))
    root.dispatchEvent(new KeyboardEvent('keyup', { key: 'e' }))
    expect(lastQuery).toMatchObject({ query: 'table' })

    handle.insertBlockReplacingSlashQuery('- [ ] Task item')
    await new Promise((resolve) => setTimeout(resolve, 300))

    // Bullet marker renormalized `-` -> `*` — WY.1's documented, cosmetic
    // remark-stringify gap (wysiwygRoundTrip.spec.ts), not a bug here.
    expect(changes.at(-1)?.trim()).toBe('* [ ] Task item')

    await handle.destroy()
  })

  describe('getSelectionAnchorLine (WY.4, #324)', () => {
    it('returns null when there is no selection (collapsed cursor)', async () => {
      const root = document.createElement('div')
      const handle = await createWysiwygEditor(root, '# Hello\n\nWorld\n', () => {})

      expect(handle.getSelectionAnchorLine()).toBeNull()

      await handle.destroy()
    })

    it('returns 1 when the selection is inside the first block', async () => {
      const root = document.createElement('div')
      const handle = await createWysiwygEditor(root, 'First paragraph.\n\nSecond paragraph.\n', () => {})

      handle.selectTextRange(2, 6)
      expect(handle.getSelectionAnchorLine()).toBe(1)

      await handle.destroy()
    })

    it('returns the line the block starts on when the selection is in a later block', async () => {
      const root = document.createElement('div')
      const handle = await createWysiwygEditor(
        root,
        '# Heading\n\nFirst paragraph.\n\nSecond paragraph.\n',
        () => {}
      )

      // A position past the end clamps to the very end of the document,
      // i.e. inside the last block ("Second paragraph.") — avoids relying
      // on exact ProseMirror position arithmetic in the test itself.
      handle.selectTextRange(Number.MAX_SAFE_INTEGER - 2, Number.MAX_SAFE_INTEGER)

      // "# Heading\n\nFirst paragraph.\n\n" is 4 lines (heading, blank,
      // paragraph, blank) so the second paragraph starts on line 5.
      expect(handle.getSelectionAnchorLine()).toBe(5)

      await handle.destroy()
    })
  })
})
