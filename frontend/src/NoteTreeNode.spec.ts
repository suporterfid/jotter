import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import NoteTreeNode from './components/NoteTreeNode.vue'
import type { TreeNode } from './components/NoteTreeNode.vue'

describe('NoteTreeNode drag attributes', () => {
  it('marks a note row with its type and id', () => {
    const node: TreeNode = {
      type: 'file',
      note: { id: 7, path: 'docs/a.md', title: 'A', frontmatter: null, sort_position: null, updated_at: '2026-07-31T00:00:00Z' },
    }
    const wrapper = mount(NoteTreeNode, { props: { node, selectedNoteId: null, depth: 0 } })
    const row = wrapper.find('.note-item')
    expect(row.attributes('data-item-type')).toBe('note')
    expect(row.attributes('data-item-id')).toBe('7')
    expect(row.attributes('data-item-note-path')).toBe('docs/a.md')
  })

  it('marks a folder row with its type and path, and keeps children in the DOM when collapsed', async () => {
    const node: TreeNode = {
      type: 'folder',
      name: 'docs',
      fullPath: 'docs',
      children: [
        { type: 'file', note: { id: 1, path: 'docs/a.md', title: 'A', frontmatter: null, sort_position: null, updated_at: '2026-07-31T00:00:00Z' } },
      ],
    }
    const wrapper = mount(NoteTreeNode, { props: { node, selectedNoteId: null, depth: 0 } })
    const row = wrapper.find('.tree-folder')
    expect(row.attributes('data-item-type')).toBe('folder')
    expect(row.attributes('data-item-path')).toBe('docs')

    await wrapper.find('.folder-row').trigger('click')
    const children = wrapper.find('.folder-children')
    expect(children.exists()).toBe(true)
    expect((children.element as HTMLElement).style.display).toBe('none')
  })
})
