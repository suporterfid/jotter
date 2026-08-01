import { mount } from '@vue/test-utils'
import { describe, expect, it, beforeEach } from 'vitest'
import UnlinkedMentionsPanel from './components/UnlinkedMentionsPanel.vue'

const mention = {
  id: 2,
  path: 'meeting-notes.md',
  title: 'Meeting Notes',
  matched_phrase: 'Project Alpha',
  snippet: 'We discussed Project Alpha today.'
}

describe('UnlinkedMentionsPanel', () => {
  beforeEach(() => {
    localStorage.clear()
  })

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

  it('shows the body by default', () => {
    const wrapper = mount(UnlinkedMentionsPanel, { props: { mentions: [] } })
    expect((wrapper.find('.unlinked-mentions-body').element as HTMLElement).style.display).not.toBe('none')
  })

  it('hides the body when collapsed via the header chevron', async () => {
    const wrapper = mount(UnlinkedMentionsPanel, { props: { mentions: [] } })
    await wrapper.find('[data-testid="panel-collapse-toggle"]').trigger('click')
    expect((wrapper.find('.unlinked-mentions-body').element as HTMLElement).style.display).toBe('none')
  })
})
