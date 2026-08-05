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
})
