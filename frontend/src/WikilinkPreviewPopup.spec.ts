import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import WikilinkPreviewPopup from './components/WikilinkPreviewPopup.vue'
import type { NoteMeta } from './services/types'

const note: NoteMeta = {
  id: 1,
  path: 'ideas.md',
  title: 'Ideas',
  frontmatter: null,
  sort_position: null,
  updated_at: '2026-07-31T00:00:00Z',
}

const rect = { top: 100, bottom: 120, left: 50, right: 150, width: 100, height: 20, x: 50, y: 100, toJSON: () => ({}) } as DOMRect

describe('WikilinkPreviewPopup', () => {
  it('renders rendered markdown content when resolved and loaded', () => {
    const wrapper = mount(WikilinkPreviewPopup, {
      props: { rect, note, content: '# Ideas\n\nSome body.', unresolvedTarget: null },
    })
    expect(wrapper.html()).toContain('<h1')
    expect(wrapper.text()).toContain('Some body.')
  })

  it('shows a loading state when resolved but content is still null', () => {
    const wrapper = mount(WikilinkPreviewPopup, {
      props: { rect, note, content: null, unresolvedTarget: null },
    })
    expect(wrapper.text()).toContain('Loading...')
  })

  it('shows the new-note affordance for an unresolved target', () => {
    const wrapper = mount(WikilinkPreviewPopup, {
      props: { rect, note: null, content: null, unresolvedTarget: 'Missing Note' },
    })
    expect(wrapper.text()).toContain('No note yet')
    expect(wrapper.text()).toContain('Missing Note')
  })

  it('positions using the given rect', () => {
    const wrapper = mount(WikilinkPreviewPopup, {
      props: { rect, note, content: 'text', unresolvedTarget: null },
    })
    const style = wrapper.get('[data-testid="wikilink-preview-popup"]').attributes('style')
    expect(style).toContain('top: 124px')
    expect(style).toContain('left: 50px')
  })
})
