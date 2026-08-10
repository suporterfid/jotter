import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import NoteTreeNode from './components/NoteTreeNode.vue'
import type { TreeNode } from './components/NoteTreeNode.vue'

const source = readFileSync('src/components/NoteTreeNode.vue', 'utf8')

describe('NoteTreeNode identity layout', () => {
  it('uses logical indentation and mirrors its collapsed directional cue in RTL', () => {
    expect(source).toContain('paddingInlineStart')
    expect(source).toContain('margin-inline-end: var(--space-1)')
    expect(source).toContain('padding-inline-end: var(--space-3)')
    expect(source).toContain(':global([dir="rtl"]) .chevron.collapsed')
  })
})

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

describe('NoteTreeNode folder quick-create', () => {
  function makeFolderNode(overrides: Partial<TreeNode> = {}): TreeNode {
    return {
      type: 'folder',
      name: 'docs',
      fullPath: 'docs',
      children: [],
      ...overrides,
    } as TreeNode
  }

  it('emits create-note-in-folder with the folder\'s fullPath when + is clicked', async () => {
    const wrapper = mount(NoteTreeNode, {
      props: { node: makeFolderNode(), selectedNoteId: null, depth: 0 },
    })
    await wrapper.find('[data-testid="folder-create-note-btn"]').trigger('click')
    expect(wrapper.emitted('create-note-in-folder')).toEqual([['docs']])
  })

  it('does not toggle collapse when + is clicked on an expanded folder', async () => {
    const wrapper = mount(NoteTreeNode, {
      props: {
        node: makeFolderNode({
          children: [
            { type: 'file', note: { id: 1, path: 'docs/a.md', title: 'A', frontmatter: null, sort_position: null, updated_at: '2026-07-31T00:00:00Z' } },
          ],
        }),
        selectedNoteId: null,
        depth: 0,
      },
    })
    await wrapper.find('[data-testid="folder-create-note-btn"]').trigger('click')
    const children = wrapper.find('.folder-children')
    expect((children.element as HTMLElement).style.display).not.toBe('none')
  })

  it('expands a collapsed folder when + is clicked', async () => {
    const wrapper = mount(NoteTreeNode, {
      props: {
        node: makeFolderNode({
          children: [
            { type: 'file', note: { id: 1, path: 'docs/a.md', title: 'A', frontmatter: null, sort_position: null, updated_at: '2026-07-31T00:00:00Z' } },
          ],
        }),
        selectedNoteId: null,
        depth: 0,
      },
    })
    // Collapse it first (starts expanded by default)
    await wrapper.find('.folder-row').trigger('click')
    expect((wrapper.find('.folder-children').element as HTMLElement).style.display).toBe('none')

    await wrapper.find('[data-testid="folder-create-note-btn"]').trigger('click')
    expect((wrapper.find('.folder-children').element as HTMLElement).style.display).not.toBe('none')
    expect(wrapper.emitted('create-note-in-folder')).toEqual([['docs']])
  })

  it('bubbles a nested folder\'s + click up through the parent NoteTreeNode unchanged', async () => {
    const nested: TreeNode = makeFolderNode({
      name: 'archived',
      fullPath: 'docs/archived',
      children: [],
    })
    const wrapper = mount(NoteTreeNode, {
      props: { node: makeFolderNode({ children: [nested] }), selectedNoteId: null, depth: 0 },
    })
    const nestedBtn = wrapper.findAll('[data-testid="folder-create-note-btn"]')[1]
    await nestedBtn.trigger('click')
    expect(wrapper.emitted('create-note-in-folder')).toEqual([['docs/archived']])
  })
})

describe('NoteTreeNode revealPath', () => {
  function makeFolderNode(overrides: Partial<TreeNode> = {}): TreeNode {
    return {
      type: 'folder',
      name: 'docs',
      fullPath: 'docs',
      children: [],
      ...overrides,
    } as TreeNode
  }

  it('expands when revealPath equals its own fullPath', async () => {
    const wrapper = mount(NoteTreeNode, {
      props: { node: makeFolderNode(), selectedNoteId: null, depth: 0 },
    })
    await wrapper.find('.folder-row').trigger('click')
    expect((wrapper.find('.folder-children').element as HTMLElement).style.display).toBe('none')

    await wrapper.setProps({ revealPath: 'docs' })
    expect((wrapper.find('.folder-children').element as HTMLElement).style.display).not.toBe('none')
  })

  it('expands when revealPath is a nested descendant of its fullPath', async () => {
    const wrapper = mount(NoteTreeNode, {
      props: { node: makeFolderNode({ fullPath: 'docs' }), selectedNoteId: null, depth: 0 },
    })
    await wrapper.find('.folder-row').trigger('click')

    await wrapper.setProps({ revealPath: 'docs/archived' })
    expect((wrapper.find('.folder-children').element as HTMLElement).style.display).not.toBe('none')
  })

  it('does not expand for an unrelated folder path', async () => {
    const wrapper = mount(NoteTreeNode, {
      props: { node: makeFolderNode({ fullPath: 'other' }), selectedNoteId: null, depth: 0 },
    })
    await wrapper.find('.folder-row').trigger('click')

    await wrapper.setProps({ revealPath: 'docs' })
    expect((wrapper.find('.folder-children').element as HTMLElement).style.display).toBe('none')
  })

  it('does not falsely match a folder whose name is a string-prefix of another (docs vs docsx)', async () => {
    const wrapper = mount(NoteTreeNode, {
      props: { node: makeFolderNode({ fullPath: 'docsx' }), selectedNoteId: null, depth: 0 },
    })
    await wrapper.find('.folder-row').trigger('click')

    await wrapper.setProps({ revealPath: 'docs' })
    expect((wrapper.find('.folder-children').element as HTMLElement).style.display).toBe('none')
  })
})
