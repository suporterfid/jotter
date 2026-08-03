import { mount, flushPromises } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest'
import NoteEditor from './components/NoteEditor.vue'
import type { NoteDetail } from './services/types'

vi.mock('./services/api', () => ({
  getNoteComments: vi.fn().mockResolvedValue([]),
  getUnlinkedMentions: vi.fn().mockResolvedValue([]),
  getOutgoingLinks: vi.fn().mockResolvedValue([]),
  setNoteProperty: vi.fn().mockResolvedValue({}),
  deleteNoteProperty: vi.fn().mockResolvedValue({}),
  addNoteComment: vi.fn().mockResolvedValue({
    id: 1, actor_name: 'Admin', content: 'placeholder', anchor_line: null, created_at: '2026-08-03T00:00:00Z',
  }),
}))

import { setNoteProperty, deleteNoteProperty, addNoteComment } from './services/api'

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
})
