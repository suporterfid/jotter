import { mount } from '@vue/test-utils'
import { describe, expect, it } from 'vitest'
import LocalGraphPanel from './components/LocalGraphPanel.vue'
import type { LocalGraphNeighbor } from './services/types'

const neighbors: LocalGraphNeighbor[] = [
  { id: 2, title: 'Backlinked Note', path: 'backlinked.md', direction: 'backlink' },
  { id: 3, title: 'Outgoing Note', path: 'outgoing.md', direction: 'outgoing' },
]

describe('LocalGraphPanel', () => {
  it('shows the empty state when there are no neighbors', () => {
    const wrapper = mount(LocalGraphPanel, { props: { centerTitle: 'Current Note', neighbors: [] } })
    expect(wrapper.text()).toContain('No connections yet.')
  })

  it('renders the center node plus one node per neighbor', () => {
    const wrapper = mount(LocalGraphPanel, { props: { centerTitle: 'Current Note', neighbors } })
    expect(wrapper.find('[data-testid="local-graph-center"]').text()).toContain('Current Note')
    expect(wrapper.findAll('[data-testid="local-graph-neighbor"]')).toHaveLength(2)
  })

  it('styles backlink and outgoing edges differently', () => {
    const wrapper = mount(LocalGraphPanel, { props: { centerTitle: 'Current Note', neighbors } })
    expect(wrapper.find('.local-graph-edge-backlink').exists()).toBe(true)
    expect(wrapper.find('.local-graph-edge-outgoing').exists()).toBe(true)
  })

  it('emits select-neighbor with the clicked neighbor id', async () => {
    const wrapper = mount(LocalGraphPanel, { props: { centerTitle: 'Current Note', neighbors } })
    await wrapper.findAll('[data-testid="local-graph-neighbor"]')[1].trigger('click')
    expect(wrapper.emitted('select-neighbor')![0]).toEqual([3])
  })
})
