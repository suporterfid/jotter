import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import UnlinkedMentionsPanel from './components/UnlinkedMentionsPanel.vue'

const mention = {
  id: 2,
  path: 'meeting-notes.md',
  title: 'Meeting Notes',
  matched_phrase: 'Project Alpha',
  snippet: 'We discussed Project Alpha today.'
}

describe('UnlinkedMentionsPanel', () => {
  it('shows the empty state when there are no mentions', () => {
    const wrapper = mount(UnlinkedMentionsPanel, { props: { mentions: [] } })
    expect(wrapper.text()).toContain('No unlinked mentions found.')
  })

  it('renders one entry per mention with title and snippet', () => {
    const wrapper = mount(UnlinkedMentionsPanel, { props: { mentions: [mention] } })
    expect(wrapper.text()).toContain('Meeting Notes')
    expect(wrapper.text()).toContain('We discussed Project Alpha today.')
  })

  it('emits select-note when the mention body is clicked', async () => {
    const wrapper = mount(UnlinkedMentionsPanel, { props: { mentions: [mention] } })
    await wrapper.get('.mention-main').trigger('click')
    expect(wrapper.emitted('select-note')![0]).toEqual([2])
  })

  it('emits convert-to-link with the full mention when the Link button is clicked', async () => {
    const wrapper = mount(UnlinkedMentionsPanel, { props: { mentions: [mention] } })
    await wrapper.get('[data-testid="convert-to-link-btn"]').trigger('click')
    expect(wrapper.emitted('convert-to-link')![0]).toEqual([mention])
  })
})
