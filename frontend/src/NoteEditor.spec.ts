import { config, mount, flushPromises } from '@vue/test-utils'
import { defineComponent } from 'vue'
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import { readFileSync } from 'node:fs'
import NoteEditor from './components/NoteEditor.vue'
import type { NoteDetail } from './services/types'

const noteEditorSource = readFileSync('src/components/NoteEditor.vue', 'utf8')

describe('NoteEditor identity layout', () => {
  it('anchors CSS panels with logical properties', () => {
    expect(noteEditorSource).toContain('inset-inline-end: 0')
    expect(noteEditorSource).toContain('inset-inline-start: max(2rem, calc(50% - 348px))')
    expect(noteEditorSource).toContain('border-inline-start: 1px solid var(--color-border)')
    expect(noteEditorSource).toContain('border-inline-end: 1px solid var(--color-border)')
  })
})

// WY.5 (#325) made 'live' the default viewMode, so every mount() below now
// mounts a real Milkdown instance unless told otherwise — an async,
// real-timer-dependent editor most of these tests have nothing to do with
// (and several use vi.useFakeTimers(), which Milkdown's own internal
// scheduling can't tolerate). Stub it out globally; the handful of tests
// that actually exercise the Live surface un-stub it locally via
// `global: { stubs: { NoteEditorWysiwyg: false } }`.
config.global.stubs = {
  // A bare `true` auto-stub would drop the data-testid the real
  // component's template carries, breaking existence checks — this
  // preserves it while still never mounting real Milkdown.
  NoteEditorWysiwyg: { name: 'NoteEditorWysiwyg', template: '<div data-testid="wysiwyg-editor"></div>' },
}

vi.mock('./services/api', () => ({
  getNoteComments: vi.fn().mockResolvedValue([]),
  getUnlinkedMentions: vi.fn().mockResolvedValue([]),
  getOutgoingLinks: vi.fn().mockResolvedValue([]),
  setNoteProperty: vi.fn().mockResolvedValue({}),
  deleteNoteProperty: vi.fn().mockResolvedValue({}),
  addNoteComment: vi.fn().mockResolvedValue({
    id: 1, actor_name: 'Admin', content: 'placeholder', anchor_line: null, created_at: '2026-08-03T00:00:00Z',
  }),
  getNote: vi.fn(),
  getNoteRevisions: vi.fn().mockResolvedValue([]),
  getNoteActivity: vi.fn().mockResolvedValue([]),
  getChecklistItems: vi.fn().mockResolvedValue([]),
  getWorkspaceProperties: vi.fn().mockResolvedValue([]),
  restoreNoteRevision: vi.fn().mockResolvedValue(undefined),
  setNoteWatching: vi.fn().mockResolvedValue(true),
  getNoteShare: vi.fn().mockResolvedValue({ active: false, url: null, expires_at: null, revoked_at: null }),
  createNoteShare: vi.fn().mockResolvedValue({ active: true, url: 'https://example.test/share/token', token: 'token', expires_at: null, revoked_at: null }),
  revokeNoteShare: vi.fn().mockResolvedValue(undefined),
}))

import { getNoteComments, setNoteProperty, deleteNoteProperty, addNoteComment, getNote, getOutgoingLinks, restoreNoteRevision, setNoteWatching, getNoteShare, createNoteShare } from './services/api'

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

describe('NoteEditor pane identity', () => {
  it('gives simultaneously mounted editors unique rendered heading ids', async () => {
    const host = defineComponent({
      components: { NoteEditor },
      setup: () => ({
        notes: [
          makeNote({ id: 1, content: '# Shared heading' }),
          makeNote({ id: 2, content: '# Shared heading' }),
        ],
      }),
      template: '<div><NoteEditor v-for="note in notes" :key="note.id" :note="note" :all-notes="[]" /></div>',
    })

    const wrapper = mount(host)
    await flushPromises()

    const headingIds = wrapper.findAll('.markdown-preview h1').map((heading) => heading.attributes('id'))
    expect(new Set(headingIds).size).toBe(2)
  })
})

describe('NoteEditor public sharing', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('loads share state for the selected note and refreshes it when the note changes', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ id: 1 }), allNotes: [], workspaceId: 1 },
    })
    await flushPromises()
    expect(getNoteShare).toHaveBeenCalledWith(1, 1)

    await wrapper.setProps({ note: makeNote({ id: 2 }) })
    await flushPromises()
    expect(getNoteShare).toHaveBeenLastCalledWith(1, 2)
  })

  it('shows the one-time public URL returned by share creation', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    await flushPromises()
    await wrapper.get('[data-testid="create-share-link"]').trigger('click')
    await flushPromises()

    expect(createNoteShare).toHaveBeenCalledWith(1, 1, null)
    expect(wrapper.get('[data-testid="share-link"]').text()).toContain('https://example.test/share/token')
  })
})

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

describe('NoteEditor page watching', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('toggles the watch state through the API and refreshes the note', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ watching: false }), allNotes: [], workspaceId: 1 },
    })

    await wrapper.get('[data-testid="note-watch-btn"]').trigger('click')
    await flushPromises()

    expect(setNoteWatching).toHaveBeenCalledWith(1, 1, true)
    expect(wrapper.emitted('select-note')![0]).toEqual([1])
  })
})

describe('NoteEditor PDF export', () => {
  it('emits export-pdf when the note export control is clicked', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })

    await wrapper.get('[data-testid="note-export-pdf-btn"]').trigger('click')

    expect(wrapper.emitted('export-pdf')).toEqual([[]])
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

  it('still renders the Properties panel when empty, since it has its own creation form', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    await flushPromises()

    expect(wrapper.find('[aria-label="Properties"]').exists()).toBe(true)
  })

  it('does not mount the Comments panel until the comments drawer is opened (#262)', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    await flushPromises()

    expect(wrapper.find('[aria-label="Comments"]').exists()).toBe(false)
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

describe('NoteEditor autosave', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows no save-status pill when content is unchanged', () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    expect(wrapper.find('[data-testid="save-status-indicator"]').exists()).toBe(false)
  })

  it('marks dirty and autosaves after the debounce timer elapses', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })

    await wrapper.find('[data-testid="markdown-textarea"]').setValue('# Test Note\nchanged')
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="save-status-indicator"]').text()).toBe('Unsaved changes')
    expect(wrapper.emitted('update-note')).toBeFalsy()

    vi.advanceTimersByTime(1000)
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('update-note')).toBeTruthy()
    expect(wrapper.emitted('update-note')![0]).toEqual([1, '# Test Note\nchanged'])
  })

  it('does not re-fire the debounce timer on every keystroke', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })

    await wrapper.find('[data-testid="markdown-textarea"]').setValue('# Test Note\na')
    vi.advanceTimersByTime(600)
    await wrapper.find('[data-testid="markdown-textarea"]').setValue('# Test Note\nab')
    vi.advanceTimersByTime(600)

    expect(wrapper.emitted('update-note')).toBeFalsy()

    vi.advanceTimersByTime(400)
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('update-note')).toBeTruthy()
    expect(wrapper.emitted('update-note')!.length).toBe(1)
  })

  it('manual Ctrl+S save flushes immediately without a stale duplicate autosave', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })

    await wrapper.find('[data-testid="markdown-textarea"]').setValue('# Test Note\nchanged')
    await wrapper.find('[data-testid="markdown-textarea"]').trigger('keydown', { key: 's', ctrlKey: true })
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('update-note')).toBeTruthy()
    expect(wrapper.emitted('update-note')!.length).toBe(1)

    vi.advanceTimersByTime(1000)
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('update-note')!.length).toBe(1)
  })

  it('shows "Saved" after a successful save, then auto-dismisses the pill', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })

    await wrapper.find('[data-testid="markdown-textarea"]').setValue('# Test Note\nchanged')
    vi.advanceTimersByTime(1000)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="save-status-indicator"]').text()).toBe('Saved')

    vi.advanceTimersByTime(2000)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="save-status-indicator"]').exists()).toBe(false)
  })

  it('clears a pending autosave timer when switching to a different note', async () => {
    const note = makeNote()
    const wrapper = mount(NoteEditor, {
      props: { note, allNotes: [], workspaceId: 1 },
    })

    await wrapper.find('[data-testid="markdown-textarea"]').setValue('# Test Note\nchanged')

    await wrapper.setProps({ note: makeNote({ id: 2, path: 'other.md', content: '# Other' }) })
    await wrapper.vm.$nextTick()

    vi.advanceTimersByTime(1000)
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('update-note')).toBeFalsy()
  })

  it('clears a pending "Saved" auto-dismiss timer when switching to a different note', async () => {
    const note = makeNote()
    const wrapper = mount(NoteEditor, {
      props: { note, allNotes: [], workspaceId: 1 },
    })

    await wrapper.find('[data-testid="markdown-textarea"]').setValue('# Test Note\nchanged')
    vi.advanceTimersByTime(1000)
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="save-status-indicator"]').text()).toBe('Saved')

    await wrapper.setProps({ note: makeNote({ id: 2, path: 'other.md', content: '# Other' }) })
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="save-status-indicator"]').exists()).toBe(false)

    vi.advanceTimersByTime(2000)
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="save-status-indicator"]').exists()).toBe(false)
  })
})

describe('NoteEditor stats popover', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('hides the stats popover by default and toggles it on button click', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })

    expect(wrapper.find('[data-testid="stats-popover"]').exists()).toBe(false)

    await wrapper.find('[data-testid="stats-toggle-btn"]').trigger('click')
    expect(wrapper.find('[data-testid="stats-popover"]').exists()).toBe(true)

    await wrapper.find('[data-testid="stats-toggle-btn"]').trigger('click')
    expect(wrapper.find('[data-testid="stats-popover"]').exists()).toBe(false)
  })

  it('no longer renders a permanent stats footer or explicit Save button', () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })

    expect(wrapper.find('.editor-status-bar').exists()).toBe(false)
    expect(wrapper.find('[data-testid="save-note-btn"]').exists()).toBe(false)
  })
})

describe('NoteEditor slash-command menu', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function typeAndPosition(el: HTMLTextAreaElement, value: string, cursorPos: number) {
    el.value = value
    el.selectionStart = cursorPos
    el.selectionEnd = cursorPos
  }

  it('opens the slash menu when typing "/" at the start of a line', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: '' }), allNotes: [], workspaceId: 1 },
    })
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    typeAndPosition(textarea.element as HTMLTextAreaElement, '/', 1)
    await textarea.trigger('input')

    expect(wrapper.text()).toContain('Insert Block')
    expect(wrapper.text()).toContain('To-do List')
  })

  it('does not open the slash menu for a "/" in the middle of a word', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: '' }), allNotes: [], workspaceId: 1 },
    })
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    typeAndPosition(textarea.element as HTMLTextAreaElement, 'a/b', 3)
    await textarea.trigger('input')

    expect(wrapper.text()).not.toContain('Insert Block')
  })

  it('inserts the selected block\'s syntax at the trigger position and closes the menu', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: '' }), allNotes: [], workspaceId: 1 },
    })
    // Slash-menu-via-textarea is Edit/Split-mode's domain; WY.5 made Live
    // the default, and selectSlashBlock() routes to the WYSIWYG surface
    // when viewMode is 'live' (#323), so this must switch away first to
    // exercise the textarea-splicing path this test is actually about.
    await wrapper.find('[data-testid="view-mode-split"]').trigger('click')
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    const el = textarea.element as HTMLTextAreaElement
    typeAndPosition(el, '/', 1)
    await textarea.trigger('input')

    await wrapper.find('.slash-menu-item').trigger('click')

    expect(el.value).toBe('- [ ] Task item')
    expect(wrapper.text()).not.toContain('Insert Block')
  })

  it('closes the slash menu on Escape', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: '' }), allNotes: [], workspaceId: 1 },
    })
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    typeAndPosition(textarea.element as HTMLTextAreaElement, '/', 1)
    await textarea.trigger('input')
    expect(wrapper.text()).toContain('Insert Block')

    await textarea.trigger('keydown', { key: 'Escape' })
    expect(wrapper.text()).not.toContain('Insert Block')
  })

  it('filters blocks by query typed after the "/"', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: '' }), allNotes: [], workspaceId: 1 },
    })
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    typeAndPosition(textarea.element as HTMLTextAreaElement, '/code', 5)
    await textarea.trigger('input')

    expect(wrapper.text()).toContain('Code Block')
    expect(wrapper.text()).not.toContain('To-do List')
  })

  it('shows and inserts the external embed block syntax from the textarea slash menu', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: '' }), allNotes: [], workspaceId: 1 },
    })
    await wrapper.find('[data-testid="view-mode-split"]').trigger('click')
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    const el = textarea.element as HTMLTextAreaElement
    typeAndPosition(el, '/external', 9)
    await textarea.trigger('input')

    expect(wrapper.text()).toContain('External Embed')
    await wrapper.find('.slash-menu-item').trigger('click')
    expect(el.value).toBe('https://example.com/embed')
  })

  it('opens the slash menu for "/" right after a space or a newline', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: '' }), allNotes: [], workspaceId: 1 },
    })
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    const el = textarea.element as HTMLTextAreaElement

    typeAndPosition(el, 'hello /code', 11)
    await textarea.trigger('input')
    expect(wrapper.text()).toContain('Code Block')

    typeAndPosition(el, 'hello\n/code', 11)
    await textarea.trigger('input')
    expect(wrapper.text()).toContain('Code Block')
  })

  it('closes the slash menu once a wikilink trigger takes over', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: '' }), allNotes: [], workspaceId: 1 },
    })
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    const el = textarea.element as HTMLTextAreaElement

    typeAndPosition(el, '/', 1)
    await textarea.trigger('input')
    expect(wrapper.text()).toContain('Insert Block')

    typeAndPosition(el, '[[', 2)
    await textarea.trigger('input')
    expect(wrapper.text()).not.toContain('Insert Block')
  })

  it('inserts a multi-line block syntax verbatim and places the cursor at its end', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: '' }), allNotes: [], workspaceId: 1 },
    })
    await wrapper.find('[data-testid="view-mode-split"]').trigger('click')
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    const el = textarea.element as HTMLTextAreaElement
    typeAndPosition(el, '/code', 5)
    await textarea.trigger('input')

    await wrapper.find('.slash-menu-item').trigger('click')
    await flushPromises()

    const expectedSyntax = '```js\ncode\n```'
    expect(el.value).toBe(expectedSyntax)
    expect(el.selectionStart).toBe(expectedSyntax.length)
    expect(el.selectionEnd).toBe(expectedSyntax.length)
  })
})

describe('NoteEditor editable page title', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    vi.useFakeTimers()
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('renders the title as an editable field pre-filled with note.title', () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    const titleField = wrapper.find('[data-testid="editor-title"]')
    expect(titleField.element.tagName).toBe('TEXTAREA')
    expect((titleField.element as HTMLTextAreaElement).value).toBe('Test Note')
  })

  it('persists a typed title to frontmatter.title after the debounce, without touching body content', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    const titleField = wrapper.find('[data-testid="editor-title"]')
    await titleField.setValue('Renamed Title')

    expect(setNoteProperty).not.toHaveBeenCalled()
    vi.advanceTimersByTime(1000)
    await flushPromises()

    expect(setNoteProperty).toHaveBeenCalledWith(1, 1, 'title', 'Renamed Title')
    expect((wrapper.find('[data-testid="markdown-textarea"]').element as HTMLTextAreaElement).value).toBe('# Test Note')
  })

  it('deletes the title property when cleared to empty', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    const titleField = wrapper.find('[data-testid="editor-title"]')
    await titleField.setValue('')

    vi.advanceTimersByTime(1000)
    await flushPromises()

    expect(deleteNoteProperty).toHaveBeenCalledWith(1, 1, 'title')
  })

  it('moves focus to the content editor on Enter instead of inserting a newline', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
    })
    const titleField = wrapper.find('[data-testid="editor-title"]')
    await titleField.trigger('keydown', { key: 'Enter' })

    expect(document.activeElement).toBe(wrapper.find('[data-testid="markdown-textarea"]').element)
    wrapper.unmount()
  })

  it('resets the title field when switching to a different note', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    await wrapper.find('[data-testid="editor-title"]').setValue('Mid-edit draft')

    await wrapper.setProps({
      note: makeNote({ id: 2, path: 'other.md', title: 'Other Note', content: '# Other' }),
    })

    expect((wrapper.find('[data-testid="editor-title"]').element as HTMLTextAreaElement).value).toBe('Other Note')
  })
})

describe('NoteEditor selection-triggered comments', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  function selectRange(el: HTMLTextAreaElement, start: number, end: number) {
    el.selectionStart = start
    el.selectionEnd = end
  }

  it('shows the comment trigger button after selecting text', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: 'line one\nline two\nline three' }), allNotes: [], workspaceId: 1 },
    })
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    selectRange(textarea.element as HTMLTextAreaElement, 0, 4)
    await textarea.trigger('mouseup', { clientX: 50, clientY: 30 })

    expect(wrapper.find('[data-testid="comment-trigger-btn"]').exists()).toBe(true)
  })

  it('does not show the trigger button when the mouseup leaves no selection', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: 'line one\nline two' }), allNotes: [], workspaceId: 1 },
    })
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    selectRange(textarea.element as HTMLTextAreaElement, 3, 3)
    await textarea.trigger('mouseup', { clientX: 50, clientY: 30 })

    expect(wrapper.find('[data-testid="comment-trigger-btn"]').exists()).toBe(false)
  })

  it('opens the composer when the trigger is clicked, and hides the trigger', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: 'line one\nline two' }), allNotes: [], workspaceId: 1 },
    })
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    selectRange(textarea.element as HTMLTextAreaElement, 0, 4)
    await textarea.trigger('mouseup', { clientX: 50, clientY: 30 })
    await wrapper.find('[data-testid="comment-trigger-btn"]').trigger('mousedown')

    expect(wrapper.find('[data-testid="comment-composer"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="comment-trigger-btn"]').exists()).toBe(false)
  })

  it('closes the composer on Escape without submitting', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: 'line one\nline two' }), allNotes: [], workspaceId: 1 },
    })
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    selectRange(textarea.element as HTMLTextAreaElement, 0, 4)
    await textarea.trigger('mouseup', { clientX: 50, clientY: 30 })
    await wrapper.find('[data-testid="comment-trigger-btn"]').trigger('mousedown')
    await wrapper.find('[data-testid="comment-composer-textarea"]').setValue('a draft')
    await wrapper.find('[data-testid="comment-composer-textarea"]').trigger('keydown.escape')

    expect(wrapper.find('[data-testid="comment-composer"]').exists()).toBe(false)
    expect(addNoteComment).not.toHaveBeenCalled()
  })

  it('submits the selection comment anchored to the line the selection starts on', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: 'line one\nline two\nline three' }), allNotes: [], workspaceId: 1 },
    })
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    // Selection starts at index 9, which is the first character of "line two"
    // (line one\n = 9 chars) -> anchor line 2.
    selectRange(textarea.element as HTMLTextAreaElement, 9, 13)
    await textarea.trigger('mouseup', { clientX: 50, clientY: 30 })
    await wrapper.find('[data-testid="comment-trigger-btn"]').trigger('mousedown')
    await wrapper.find('[data-testid="comment-composer-textarea"]').setValue('Nice line')
    await wrapper.find('[data-testid="comment-composer-submit"]').trigger('click')
    await flushPromises()

    expect(addNoteComment).toHaveBeenCalledWith(1, 1, 'Nice line', 2)
    expect(wrapper.find('[data-testid="comment-composer"]').exists()).toBe(false)
  })

  it('hides the trigger button once the user resumes typing', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: 'line one\nline two' }), allNotes: [], workspaceId: 1 },
    })
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    const el = textarea.element as HTMLTextAreaElement
    selectRange(el, 0, 4)
    await textarea.trigger('mouseup', { clientX: 50, clientY: 30 })
    expect(wrapper.find('[data-testid="comment-trigger-btn"]').exists()).toBe(true)

    el.value = 'changed\nline two'
    await textarea.trigger('input')

    expect(wrapper.find('[data-testid="comment-trigger-btn"]').exists()).toBe(false)
  })

  it('closes an open composer once the user resumes typing', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: 'line one\nline two' }), allNotes: [], workspaceId: 1 },
    })
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    const el = textarea.element as HTMLTextAreaElement
    selectRange(el, 0, 4)
    await textarea.trigger('mouseup', { clientX: 50, clientY: 30 })
    await wrapper.find('[data-testid="comment-trigger-btn"]').trigger('mousedown')
    expect(wrapper.find('[data-testid="comment-composer"]').exists()).toBe(true)

    el.value = 'changed\nline two'
    await textarea.trigger('input')

    expect(wrapper.find('[data-testid="comment-composer"]').exists()).toBe(false)
  })

  it('clears the trigger and composer when switching to a different note', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: 'line one\nline two' }), allNotes: [], workspaceId: 1 },
    })
    const textarea = wrapper.find('[data-testid="markdown-textarea"]')
    selectRange(textarea.element as HTMLTextAreaElement, 0, 4)
    await textarea.trigger('mouseup', { clientX: 50, clientY: 30 })
    await wrapper.find('[data-testid="comment-trigger-btn"]').trigger('mousedown')
    expect(wrapper.find('[data-testid="comment-composer"]').exists()).toBe(true)

    await wrapper.setProps({ note: makeNote({ id: 2, path: 'other.md', content: '# Other' }) })
    await wrapper.vm.$nextTick()

    expect(wrapper.find('[data-testid="comment-composer"]').exists()).toBe(false)
    expect(wrapper.find('[data-testid="comment-trigger-btn"]').exists()).toBe(false)
  })
})

describe('NoteEditor comments drawer', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    ;(getNoteComments as unknown as ReturnType<typeof vi.fn>).mockResolvedValue([])
  })

  it('does not render the drawer until toggled open', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
    })
    await flushPromises()

    expect(document.querySelector('[data-testid="comments-drawer"]')).toBeNull()
    wrapper.unmount()
  })

  it('opens the drawer via the comments toggle button and closes it via the close button', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
    })
    await flushPromises()

    await wrapper.find('[data-testid="comments-drawer-btn"]').trigger('click')
    expect(document.querySelector('[data-testid="comments-drawer"]')).not.toBeNull()

    ;(document.querySelector('[data-testid="comments-drawer-close-btn"]') as HTMLElement).click()
    await wrapper.vm.$nextTick()
    expect(document.querySelector('[data-testid="comments-drawer"]')).toBeNull()

    wrapper.unmount()
  })

  it('shows a badge with the comment count once comments load', async () => {
    ;(getNoteComments as unknown as ReturnType<typeof vi.fn>).mockResolvedValue([
      { id: 1, actor_name: 'Admin', content: 'hi', anchor_line: null, created_at: '2026-08-03T00:00:00Z' },
      { id: 2, actor_name: 'Admin', content: 'again', anchor_line: null, created_at: '2026-08-03T00:00:00Z' },
    ])
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
    })
    await flushPromises()

    expect(wrapper.find('[data-testid="comments-drawer-btn"]').text()).toContain('2')
    wrapper.unmount()
  })

  it('keeps the drawer open when switching to a different note', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
    })
    await flushPromises()
    await wrapper.find('[data-testid="comments-drawer-btn"]').trigger('click')
    expect(document.querySelector('[data-testid="comments-drawer"]')).not.toBeNull()

    await wrapper.setProps({ note: makeNote({ id: 2, path: 'other.md', content: '# Other' }) })
    await flushPromises()

    expect(document.querySelector('[data-testid="comments-drawer"]')).not.toBeNull()
    wrapper.unmount()
  })
})

describe('NoteEditor outline drawer', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('does not render the drawer until toggled open', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
    })
    await flushPromises()

    expect(document.querySelector('[data-testid="outline-drawer"]')).toBeNull()
    wrapper.unmount()
  })

  it('opens the drawer via the outline toggle button and lists headings from the note content', async () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ content: '# Title\n\n## Section' }),
        allNotes: [],
        workspaceId: 1,
      },
      attachTo: document.body,
    })
    await flushPromises()

    await wrapper.find('[data-testid="outline-drawer-btn"]').trigger('click')
    const drawer = document.querySelector('[data-testid="outline-drawer"]')
    expect(drawer).not.toBeNull()
    expect(drawer!.textContent).toContain('Title')
    expect(drawer!.textContent).toContain('Section')

    ;(document.querySelector('[data-testid="outline-drawer-close-btn"]') as HTMLElement).click()
    await wrapper.vm.$nextTick()
    expect(document.querySelector('[data-testid="outline-drawer"]')).toBeNull()

    wrapper.unmount()
  })

  it('clicking a heading in edit/split mode moves the textarea caret to that heading and focuses it', async () => {
    const content = '# Title\n\nBody text.\n\n## Section'
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content }), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
    })
    await flushPromises()
    await wrapper.find('[data-testid="outline-drawer-btn"]').trigger('click')

    const items = document.querySelectorAll('[data-testid="outline-item-btn"]')
    expect(items).toHaveLength(2)
    ;(items[1] as HTMLElement).click()
    await wrapper.vm.$nextTick()

    const textarea = wrapper.find('[data-testid="markdown-textarea"]').element as HTMLTextAreaElement
    const expectedOffset = '# Title\n\nBody text.\n\n'.length
    expect(document.activeElement).toBe(textarea)
    expect(textarea.selectionStart).toBe(expectedOffset)

    wrapper.unmount()
  })
})

describe('NoteEditor wikilink hover preview', () => {
  const allNotes = [
    { id: 2, path: 'ideas.md', title: 'Ideas', frontmatter: null, sort_position: null, updated_at: '2026-07-31T00:00:00Z' },
  ]

  beforeEach(() => {
    vi.clearAllMocks()
    ;(getNote as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({
      id: 2, path: 'ideas.md', title: 'Ideas', frontmatter: null, sort_position: null,
      updated_at: '2026-07-31T00:00:00Z', content: '# Ideas\n\nSome body.', backlinks: [],
    })
  })

  afterEach(() => {
    vi.useRealTimers()
  })

  it('shows the popup with fetched content after hovering a resolved wikilink', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: 'See [[Ideas]].' }), allNotes, workspaceId: 1 },
    })
    await flushPromises()
    // Wikilink hover preview is a Preview-mode (MarkdownPreview.vue)
    // feature, unrelated to WY.5's Live default; switch away from Live
    // first so Milkdown's own async lifecycle (which needs real timers)
    // isn't still settling once fake timers are engaged below.
    await wrapper.find('[data-testid="view-mode-split"]').trigger('click')
    await flushPromises()
    vi.useFakeTimers()

    const link = wrapper.get('a.wikilink')
    await link.trigger('mouseover')
    await vi.advanceTimersByTimeAsync(300)

    expect(wrapper.find('[data-testid="wikilink-preview-popup"]').exists()).toBe(true)
    expect(wrapper.text()).toContain('Some body.')
    expect(getNote).toHaveBeenCalledTimes(1)

    wrapper.unmount()
  })

  it('caches fetched content so hovering the same link twice only fetches once', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: 'See [[Ideas]].' }), allNotes, workspaceId: 1 },
    })
    await flushPromises()
    // Wikilink hover preview is a Preview-mode (MarkdownPreview.vue)
    // feature, unrelated to WY.5's Live default; switch away from Live
    // first so Milkdown's own async lifecycle (which needs real timers)
    // isn't still settling once fake timers are engaged below.
    await wrapper.find('[data-testid="view-mode-split"]').trigger('click')
    await flushPromises()
    vi.useFakeTimers()

    const link = wrapper.get('a.wikilink')
    await link.trigger('mouseover')
    await vi.advanceTimersByTimeAsync(300)
    await link.trigger('mouseout')

    await link.trigger('mouseover')
    await vi.advanceTimersByTimeAsync(300)

    expect(getNote).toHaveBeenCalledTimes(1)
    wrapper.unmount()
  })

  it('shows the new-note affordance for an unresolved target without calling getNote', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: 'See [[Missing]].' }), allNotes: [], workspaceId: 1 },
    })
    await flushPromises()
    await wrapper.find('[data-testid="view-mode-split"]').trigger('click')
    await flushPromises()
    vi.useFakeTimers()

    const link = wrapper.get('a.wikilink')
    await link.trigger('mouseover')
    await vi.advanceTimersByTimeAsync(300)

    expect(wrapper.text()).toContain('No note yet')
    expect(getNote).not.toHaveBeenCalled()

    wrapper.unmount()
  })
})

describe('NoteEditor wikilink embeds', () => {
  const allNotes = [
    { id: 2, path: 'ideas.md', title: 'Ideas', frontmatter: null, sort_position: null, updated_at: '2026-07-31T00:00:00Z' },
  ]

  beforeEach(() => {
    vi.clearAllMocks()
    ;(getNote as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({
      id: 2, path: 'ideas.md', title: 'Ideas', frontmatter: null, sort_position: null,
      updated_at: '2026-07-31T00:00:00Z', content: 'Idea body.', backlinks: [],
    })
  })

  it('fetches and renders a resolved embed', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: 'Before.\n\n![[Ideas]]' }), allNotes, workspaceId: 1 },
    })
    await flushPromises()

    expect(getNote).toHaveBeenCalledTimes(1)
    expect(wrapper.html()).toContain('data-embed-status="resolved"')
    expect(wrapper.text()).toContain('Idea body.')

    wrapper.unmount()
  })

  it('renders the circular guard for a note embedding its own id, without calling getNote', async () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ id: 1, content: 'See ![[Test Note]].' }),
        allNotes: [
          { id: 1, path: 'test-note.md', title: 'Test Note', frontmatter: null, sort_position: null, updated_at: '2026-07-31T00:00:00Z' },
        ],
        workspaceId: 1,
      },
    })
    await flushPromises()

    expect(getNote).not.toHaveBeenCalled()
    expect(wrapper.html()).toContain('data-embed-status="circular"')

    wrapper.unmount()
  })

  it('renders the unresolved message for an embed with no matching note, without calling getNote', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: '![[Missing]]' }), allNotes: [], workspaceId: 1 },
    })
    await flushPromises()

    expect(getNote).not.toHaveBeenCalled()
    expect(wrapper.html()).toContain('data-embed-status="unresolved"')

    wrapper.unmount()
  })
})

describe('NoteEditor local graph', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('does not render the drawer until toggled open', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
    })
    await flushPromises()

    expect(document.querySelector('[data-testid="local-graph-drawer"]')).toBeNull()
    wrapper.unmount()
  })

  it('opens the drawer and shows backlinks + resolved outgoing links as neighbors, excluding unresolved and deduping mutual links', async () => {
    ;(getOutgoingLinks as unknown as ReturnType<typeof vi.fn>).mockResolvedValue([
      { id: 2, path: 'ideas.md', title: 'Ideas', target_ref: 'Ideas', target_block: null, resolved: true },
      { id: null, path: null, title: null, target_ref: 'Missing', target_block: null, resolved: false },
    ])
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({
          backlinks: [
            { id: 2, path: 'ideas.md', title: 'Ideas' },
            { id: 3, path: 'projects.md', title: 'Projects' },
          ],
        }),
        allNotes: [],
        workspaceId: 1,
      },
      attachTo: document.body,
    })
    await flushPromises()

    await wrapper.find('[data-testid="local-graph-drawer-btn"]').trigger('click')
    const drawer = document.querySelector('[data-testid="local-graph-drawer"]')
    expect(drawer).not.toBeNull()

    const neighborNodes = drawer!.querySelectorAll('[data-testid="local-graph-neighbor"]')
    expect(neighborNodes).toHaveLength(2)

    wrapper.unmount()
  })

  it('clicking a neighbor node emits select-note with its id', async () => {
    const wrapper = mount(NoteEditor, {
      props: {
        note: makeNote({ backlinks: [{ id: 2, path: 'ideas.md', title: 'Ideas' }] }),
        allNotes: [],
        workspaceId: 1,
      },
      attachTo: document.body,
    })
    await flushPromises()
    await wrapper.find('[data-testid="local-graph-drawer-btn"]').trigger('click')

    document.querySelector('[data-testid="local-graph-neighbor"]')!.dispatchEvent(new MouseEvent('click', { bubbles: true }))
    await wrapper.vm.$nextTick()

    expect(wrapper.emitted('select-note')![0]).toEqual([2])

    wrapper.unmount()
  })
})

describe('NoteEditor "Live" WYSIWYG view mode (WY.2, #322)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('edit/split/preview remain reachable via the view-mode toggle', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
    })
    await wrapper.find('[data-testid="view-mode-split"]').trigger('click')
    expect(wrapper.find('.editor-body').classes()).toContain('view-split')
    expect(wrapper.find('[data-testid="markdown-textarea"]').exists()).toBe(true)
    wrapper.unmount()
  })

  it('clicking "Live" mounts the WYSIWYG editor and hides the textarea/preview', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: '# Hello\n' }), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
      global: { stubs: { NoteEditorWysiwyg: false } },
    })
    await flushPromises()

    await wrapper.find('[data-testid="view-mode-live"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('.editor-body').classes()).toContain('view-live')
    expect(wrapper.find('[data-testid="wysiwyg-editor"]').exists()).toBe(true)
    expect(wrapper.find('h1').text()).toBe('Hello')

    wrapper.unmount()
  })

  it('switching away from "Live" unmounts the WYSIWYG editor', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
    })
    await flushPromises()

    await wrapper.find('[data-testid="view-mode-live"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="wysiwyg-editor"]').exists()).toBe(true)

    await wrapper.find('[data-testid="view-mode-edit"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('[data-testid="wysiwyg-editor"]').exists()).toBe(false)

    wrapper.unmount()
  })
})

describe('NoteEditor "Live" is the default view mode (WY.5, #325)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('opening any existing note lands in the Live WYSIWYG surface with no Markdown syntax visible', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: '# Hello\n\nSome **bold** text.\n' }), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
      global: { stubs: { NoteEditorWysiwyg: false } },
    })
    await flushPromises()

    expect(wrapper.find('.editor-body').classes()).toContain('view-live')
    expect(wrapper.find('[data-testid="wysiwyg-editor"]').exists()).toBe(true)
    expect(wrapper.find('h1').text()).toBe('Hello')
    // No raw '#'/'**' syntax markers leak into the rendered surface.
    expect(wrapper.find('[data-testid="wysiwyg-editor"]').text()).not.toContain('#')
    expect(wrapper.find('[data-testid="wysiwyg-editor"]').text()).not.toContain('**')

    wrapper.unmount()
  })

  it('switching to Edit shows the exact underlying Markdown, unchanged', async () => {
    const content = '# Hello\n\nSome **bold** text.\n'
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content }), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
      global: { stubs: { NoteEditorWysiwyg: false } },
    })
    await flushPromises()
    expect(wrapper.find('.editor-body').classes()).toContain('view-live')

    await wrapper.find('[data-testid="view-mode-edit"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('.editor-body').classes()).toContain('view-edit')
    const textarea = wrapper.find<HTMLTextAreaElement>('[data-testid="markdown-textarea"]')
    expect(textarea.element.value).toBe(content)

    wrapper.unmount()
  })
})

describe('NoteEditor Live surface formatting toolbar', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('does not render the toolbar until a non-collapsed selection is reported', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: '# Hello\n\nSome text.\n' }), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
      global: { stubs: { NoteEditorWysiwyg: false } },
    })
    await flushPromises()

    // No chrome unless a selection actually exists — matches the Live
    // surface's "no permanent chrome" ethos (WY.5). The underlying
    // bold/italic/strike/code toggle logic itself is covered exhaustively
    // in wysiwygEditor.spec.ts (real text selection can't be simulated
    // under jsdom at this component level).
    expect(wrapper.find('[data-testid="format-toolbar"]').exists()).toBe(false)

    wrapper.unmount()
  })
})

describe('NoteEditor selection-driven features on the Live surface (WY.4, #324)', () => {
  beforeEach(() => {
    vi.clearAllMocks()
  })

  it('shows the comment-trigger button at the coordinates the WYSIWYG component reports', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
    })
    await flushPromises()
    await wrapper.find('[data-testid="view-mode-live"]').trigger('click')
    await flushPromises()

    expect(wrapper.find('[data-testid="comment-trigger-btn"]').exists()).toBe(false)

    const wysiwyg = wrapper.findComponent({ name: 'NoteEditorWysiwyg' })
    wysiwyg.vm.$emit('comment-trigger', { line: 3, top: 50, left: 20 })
    await wrapper.vm.$nextTick()

    const btn = wrapper.find('[data-testid="comment-trigger-btn"]')
    expect(btn.exists()).toBe(true)
    expect(btn.attributes('style')).toContain('top: 50px')
    expect(btn.attributes('style')).toContain('left: 20px')

    wrapper.unmount()
  })

  it('a null comment-trigger hides the button (collapsed selection)', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
    })
    await flushPromises()
    await wrapper.find('[data-testid="view-mode-live"]').trigger('click')
    await flushPromises()

    const wysiwyg = wrapper.findComponent({ name: 'NoteEditorWysiwyg' })
    wysiwyg.vm.$emit('comment-trigger', { line: 3, top: 50, left: 20 })
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="comment-trigger-btn"]').exists()).toBe(true)

    wysiwyg.vm.$emit('comment-trigger', null)
    await wrapper.vm.$nextTick()
    expect(wrapper.find('[data-testid="comment-trigger-btn"]').exists()).toBe(false)

    wrapper.unmount()
  })

  it('submitting a comment from the Live surface uses the reported anchor line', async () => {
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote(), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
    })
    await flushPromises()
    await wrapper.find('[data-testid="view-mode-live"]').trigger('click')
    await flushPromises()

    const wysiwyg = wrapper.findComponent({ name: 'NoteEditorWysiwyg' })
    wysiwyg.vm.$emit('comment-trigger', { line: 7, top: 50, left: 20 })
    await wrapper.vm.$nextTick()

    await wrapper.find('[data-testid="comment-trigger-btn"]').trigger('mousedown')
    await wrapper.vm.$nextTick()
    await wrapper.find('[data-testid="comment-composer-textarea"]').setValue('Nice section.')
    await wrapper.find('[data-testid="comment-composer-submit"]').trigger('click')
    await flushPromises()

    expect(addNoteComment).toHaveBeenCalledWith(1, 1, 'Nice section.', 7)

    wrapper.unmount()
  })

  it('restoring a revision (same note id, new content) refreshes the Live surface', async () => {
    const confirmSpy = vi.spyOn(window, 'confirm').mockReturnValue(true)
    const wrapper = mount(NoteEditor, {
      props: { note: makeNote({ content: '# Original\n' }), allNotes: [], workspaceId: 1 },
      attachTo: document.body,
      global: { stubs: { NoteEditorWysiwyg: false } },
    })
    await flushPromises()
    await wrapper.find('[data-testid="view-mode-live"]').trigger('click')
    await flushPromises()
    expect(wrapper.find('h1').text()).toBe('Original')

    await wrapper.find('[data-testid="history-btn"]').trigger('click')
    await flushPromises()
    wrapper.findComponent({ name: 'HistoryPanel' }).vm.$emit('restore-revision', 1)
    await flushPromises()

    expect(restoreNoteRevision).toHaveBeenCalledWith(1, 1, 1)
    // Real app: the parent handles `select-note` by re-fetching the same
    // note id with the restored content and passing a new `note` prop —
    // this is the read-path half of that round trip (WY.1's harness
    // already guarantees the write/re-parse half is lossless).
    expect(wrapper.emitted('select-note')![0]).toEqual([1])
    await wrapper.setProps({ note: makeNote({ content: '# Restored\n' }) })
    await flushPromises()

    expect(wrapper.find('h1').text()).toBe('Restored')

    confirmSpy.mockRestore()
    wrapper.unmount()
  })
})
