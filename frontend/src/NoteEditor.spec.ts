import { mount, flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import NoteEditor from './components/NoteEditor.vue'
import type { NoteDetail } from './services/types'

vi.mock('./services/api', () => ({
  getNoteComments: vi.fn().mockResolvedValue([]),
  getUnlinkedMentions: vi.fn().mockResolvedValue([]),
  getOutgoingLinks: vi.fn().mockResolvedValue([]),
  setNoteProperty: vi.fn().mockResolvedValue({}),
  deleteNoteProperty: vi.fn().mockResolvedValue({}),
}))

import { setNoteProperty, deleteNoteProperty } from './services/api'

function makeNote(overrides: Partial<NoteDetail> = {}): NoteDetail {
  return {
    id: 1,
    path: 'test-note.md',
    title: 'Test Note',
    frontmatter: null,
    sort_position: null,
    updated_at: '2026-07-31T00:00:00Z',
    content: '# Test Note',
    backlinks: [],
    properties: [],
    ...overrides,
  }
}

describe('NoteEditor page icon', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('renders the fallback icon when no icon is set', () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    expect(wrapper.find('[data-testid="editor-icon-fallback"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="editor-icon-emoji"]').exists()).toBe(false)
  })

  it('renders the emoji when frontmatter.icon is set', () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ frontmatter: { icon: '📄' } }),
        allNotes: [],
        workspaceId: 1,
      },
    })
    expect(wrapper.find('[data-testid="editor-icon-emoji"]').text()).toBe('📄')
    expect(wrapper.find('[data-testid="editor-icon-fallback"]').exists()).toBe(false)
  })

  it('clicking the icon opens the input, typing an emoji and pressing Enter calls setNoteProperty', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    await wrapper.find('[data-testid="editor-icon-btn"]').trigger('click')
    const input = wrapper.find('[data-testid="editor-icon-input"]')
    expect(input.exists()).toBe(true)
    await input.setValue('🚀')
    await input.trigger('keydown.enter')
    expect(setNoteProperty).toHaveBeenCalledWith(1, 1, 'icon', '🚀')
  })

  it('pressing Enter with an empty draft calls deleteNoteProperty instead', async () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ frontmatter: { icon: '📄' } }),
        allNotes: [],
        workspaceId: 1,
      },
    })
    await wrapper.find('[data-testid="editor-icon-btn"]').trigger('click')
    const input = wrapper.find('[data-testid="editor-icon-input"]')
    await input.setValue('')
    await input.trigger('keydown.enter')
    expect(deleteNoteProperty).toHaveBeenCalledWith(1, 1, 'icon')
    expect(setNoteProperty).not.toHaveBeenCalled()
  })

  it('clicking the hover clear button calls deleteNoteProperty without opening the input', async () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ frontmatter: { icon: '📄' } }),
        allNotes: [],
        workspaceId: 1,
      },
    })
    await wrapper.find('[data-testid="editor-icon-clear"]').trigger('click')
    expect(deleteNoteProperty).toHaveBeenCalledWith(1, 1, 'icon')
    expect(wrapper.find('[data-testid="editor-icon-input"]').exists()).toBe(false)
  })

  it('pressing Escape closes the input without calling either API function', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    await wrapper.find('[data-testid="editor-icon-btn"]').trigger('click')
    const input = wrapper.find('[data-testid="editor-icon-input"]')
    await input.setValue('🚀')
    await input.trigger('keydown.escape')
    expect(setNoteProperty).not.toHaveBeenCalled()
    expect(deleteNoteProperty).not.toHaveBeenCalled()
    expect(wrapper.find('[data-testid="editor-icon-input"]').exists()).toBe(false)
  })
})

describe('NoteEditor breadcrumb', () => {
  it('renders a clickable segment per folder and a plain-text file name', () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ path: 'docs/archived/note.md' }), allNotes: [], workspaceId: 1 },
    })
    const segments = wrapper.findAll('[data-testid="editor-path-segment"]')
    expect(segments).toHaveLength(2)
    expect(segments[0].text()).toBe('docs')
    expect(segments[1].text()).toBe('archived')
    expect(wrapper.find('[data-testid="editor-path-filename"]').text()).toBe('note.md')
  })

  it('renders no folder segments for a root-level note', () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ path: 'note.md' }), allNotes: [], workspaceId: 1 },
    })
    expect(wrapper.findAll('[data-testid="editor-path-segment"]')).toHaveLength(0)
    expect(wrapper.find('[data-testid="editor-path-filename"]').text()).toBe('note.md')
  })

  it('emits reveal-folder with the cumulative path when a segment is clicked', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ path: 'docs/archived/note.md' }), allNotes: [], workspaceId: 1 },
    })
    const segments = wrapper.findAll('[data-testid="editor-path-segment"]')
    await segments[1].trigger('click')
    expect(wrapper.emitted('reveal-folder')).toEqual([['docs/archived']])
  })
})

describe('NoteEditor cover image', () => {
  it('renders the Add cover button when no cover is set', () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    expect(wrapper.find('[data-testid="add-cover-btn"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="editor-cover-image"]').exists()).toBe(false)
  })

  it('renders the cover image when frontmatter.cover is set', () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ frontmatter: { cover: 'https://example.com/banner.jpg' } }),
        allNotes: [],
        workspaceId: 1,
      },
    })
    const img = wrapper.find('[data-testid="editor-cover-image"]')
    expect(img.exists()).toBe(true)
    expect(img.attributes('src')).toBe('https://example.com/banner.jpg')
    expect(wrapper.find('[data-testid="add-cover-btn"]').exists()).toBe(false)
  })

  it('opens the cover modal when Add cover is clicked', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    await wrapper.find('[data-testid="add-cover-btn"]').trigger('click')
    expect(wrapper.findComponent({ name: 'CoverImageModal' }).exists()).toBe(true)
  })

  it('sets the cover property when the modal emits set-cover', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    await wrapper.find('[data-testid="add-cover-btn"]').trigger('click')
    await wrapper.findComponent({ name: 'CoverImageModal' }).vm.$emit('set-cover', 'https://example.com/banner.jpg')
    expect(setNoteProperty).toHaveBeenCalledWith(1, 1, 'cover', 'https://example.com/banner.jpg')
  })

  it('clears the cover property when Remove is clicked', async () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ frontmatter: { cover: 'https://example.com/banner.jpg' } }),
        allNotes: [],
        workspaceId: 1,
      },
    })
    await wrapper.find('[data-testid="remove-cover-btn"]').trigger('click')
    expect(deleteNoteProperty).toHaveBeenCalledWith(1, 1, 'cover')
  })
})

describe('NoteEditor empty metadata panels', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('does not render Backlinks, Outgoing Links, or Unlinked Mentions panels when all are empty', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    await flushPromises()

    expect(wrapper.find('[aria-label="Backlinks"]').exists()).toBe(false)
    expect(wrapper.find('[aria-label="Outgoing links"]').exists()).toBe(false)
    expect(wrapper.find('[aria-label="Unlinked mentions"]').exists()).toBe(false)
  })

  it('still renders Properties and Comments panels when empty, since they have their own creation forms', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    await flushPromises()

    expect(wrapper.find('[aria-label="Properties"]').exists()).toBe(true)
    expect(wrapper.find('[aria-label="Comments"]').exists()).toBe(true)
  })

  it('renders the Backlinks panel when the note has at least one backlink', async () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ backlinks: [{ id: 2, path: 'other.md', title: 'Other' }] }),
        allNotes: [],
        workspaceId: 1,
      },
    })
    await flushPromises()

    expect(wrapper.find('[aria-label="Backlinks"]').exists()).toBe(true)
  })
})
