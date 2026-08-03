import { mount } from '@vue/test-utils'
import { describe, expect, it, beforeEach } from 'vitest'
import BacklinksPanel from './components/BacklinksPanel.vue'

describe('BacklinksPanel', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('shows the empty state when there are no backlinks', () => {
    const wrapper = mount(BacklinksPanel, { props: { backlinks: [] } })
    expect(wrapper.text()).toContain('No notes link to this document yet.')
  })

  it('shows the body by default', () => {
    const wrapper = mount(BacklinksPanel, { props: { backlinks: [] } })
    expect((wrapper.find('.backlinks-body').element as HTMLElement).style.display).not.toBe('none')
  })

  it('hides the body when collapsed via the header chevron', async () => {
    const wrapper = mount(BacklinksPanel, { props: { backlinks: [] } })
    await wrapper.find('[data-testid="panel-collapse-toggle"]').trigger('click')
    expect((wrapper.find('.backlinks-body').element as HTMLElement).style.display).toBe('none')
  })

  it('does not apply panel-collapsed to the root by default (expanded)', () => {
    const wrapper = mount(BacklinksPanel, { props: { backlinks: [] } })
    expect(wrapper.find('.backlinks-panel').classes()).not.toContain('panel-collapsed')
  })

  it('applies panel-collapsed to the root once collapsed', async () => {
    const wrapper = mount(BacklinksPanel, { props: { backlinks: [] } })
    await wrapper.find('[data-testid="panel-collapse-toggle"]').trigger('click')
    expect(wrapper.find('.backlinks-panel').classes()).toContain('panel-collapsed')
  })
})
