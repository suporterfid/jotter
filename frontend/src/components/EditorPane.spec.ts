import { defineComponent } from 'vue'
import { describe, expect, it } from 'vitest'
import { mount } from '@vue/test-utils'
import EditorPane from './EditorPane.vue'
import type { NoteDetail, NoteMeta } from '../services/types'

const TabStripStub = defineComponent({
  props: ['tabs', 'activeId'],
  emits: ['select-tab', 'close-tab', 'split-tab'],
  template: `
    <div data-testid="tab-strip-stub">
      <button data-testid="select-tab" @click="$emit('select-tab', tabs[0].id)">Select</button>
      <button data-testid="close-tab" @click="$emit('close-tab', tabs[0].id)">Close</button>
      <button data-testid="split-tab" @click="$emit('split-tab', tabs[0].id)">Split</button>
    </div>
  `,
})

const NoteEditorStub = defineComponent({
  props: ['note', 'allNotes', 'workspaceId', 'paneId', 'drawerTarget'],
  template: '<div data-testid="note-editor-stub" :data-pane-id="paneId" :data-drawer-target="drawerTarget" />',
})

const note: NoteDetail = {
  id: 11,
  path: 'notes/one.md',
  title: 'One',
  content: '# One',
  frontmatter: null,
  sort_position: null,
  updated_at: '2026-08-19T00:00:00Z',
  backlinks: [],
}

const allNotes: NoteMeta[] = [note]

describe('EditorPane', () => {
  it('keeps pane identity, drawer target, and tab actions local to the pane', async () => {
    const wrapper = mount(EditorPane, {
      props: {
        paneId: 'secondary',
        tabs: [{ id: 11, title: 'One' }],
        activeId: 11,
        note,
        allNotes,
        workspaceId: 7,
      },
      global: {
        stubs: { TabStrip: TabStripStub, NoteEditor: NoteEditorStub },
      },
    })

    expect(wrapper.find('[data-pane-id="secondary"]').exists()).toBe(true)
    expect(wrapper.find('[data-testid="note-editor-stub"]').attributes('data-pane-id')).toBe('secondary')
    expect(wrapper.find('[data-testid="note-editor-stub"]').attributes('data-drawer-target')).toBe(
      '#app-right-drawer-secondary'
    )

    await wrapper.find('[data-testid="select-tab"]').trigger('click')
    await wrapper.find('[data-testid="close-tab"]').trigger('click')
    await wrapper.find('[data-testid="split-tab"]').trigger('click')

    expect(wrapper.emitted('select-note')).toEqual([[11]])
    expect(wrapper.emitted('close-tab')).toEqual([[11]])
    expect(wrapper.emitted('split-note')).toEqual([[11]])
  })
})
