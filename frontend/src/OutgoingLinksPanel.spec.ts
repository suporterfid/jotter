import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import OutgoingLinksPanel from './components/OutgoingLinksPanel.vue'

const resolvedLink = {
  id: 5,
  path: 'note-b.md',
  title: 'Note B',
  target_ref: 'note-b',
  target_block: null,
  resolved: true
}

const blockLink = {
  id: 5,
  path: 'note-b.md',
  title: 'Note B',
  target_ref: 'note-b#^myblock',
  target_block: 'myblock',
  resolved: true
}

const unresolvedLink = {
  id: null,
  path: null,
  title: null,
  target_ref: 'missing-note',
  target_block: null,
  resolved: false
}

describe('OutgoingLinksPanel', () => {
  it('shows the empty state when there are no outgoing links', () => {
    const wrapper = mount(OutgoingLinksPanel, { props: { links: [] } })
    expect(wrapper.text()).toContain("doesn't link to any other notes yet")
  })

  it('renders a resolved link with its title and path', () => {
    const wrapper = mount(OutgoingLinksPanel, { props: { links: [resolvedLink] } })
    expect(wrapper.text()).toContain('Note B')
    expect(wrapper.text()).toContain('note-b.md')
  })

  it('renders a block-reference badge for block links', () => {
    const wrapper = mount(OutgoingLinksPanel, { props: { links: [blockLink] } })
    expect(wrapper.text()).toContain('^myblock')
  })

  it('renders an unresolved badge and falls back to the target_ref for unresolved links', () => {
    const wrapper = mount(OutgoingLinksPanel, { props: { links: [unresolvedLink] } })
    expect(wrapper.text()).toContain('missing-note')
    expect(wrapper.text()).toContain('unresolved')
  })

  it('emits select-note when a resolved link is clicked', async () => {
    const wrapper = mount(OutgoingLinksPanel, { props: { links: [resolvedLink] } })
    await wrapper.get('.outgoing-link-item').trigger('click')
    expect(wrapper.emitted('select-note')![0]).toEqual([5])
  })

  it('does not emit select-note when an unresolved link is clicked', async () => {
    const wrapper = mount(OutgoingLinksPanel, { props: { links: [unresolvedLink] } })
    await wrapper.get('.outgoing-link-item').trigger('click')
    expect(wrapper.emitted('select-note')).toBeFalsy()
  })
})
