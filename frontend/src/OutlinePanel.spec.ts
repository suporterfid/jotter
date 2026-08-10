import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import OutlinePanel from './components/OutlinePanel.vue'
import type { HeadingEntry } from './services/outline'

const headings: HeadingEntry[] = [
  { level: 1, text: 'Intro', line: 0, id: 'intro' },
  { level: 2, text: 'Details', line: 4, id: 'details' },
]

describe('OutlinePanel', () => {
  it('uses logical indentation and alignment for headings', () => {
    const source = readFileSync('src/components/OutlinePanel.vue', 'utf8')
    expect(source).toContain('paddingInlineStart')
    expect(source).toMatch(/\.outline-item-btn\s*\{[\s\S]*?text-align: start/)
  })

  it('shows the empty state when there are no headings', () => {
    const wrapper = mount(OutlinePanel, { props: { headings: [] } })
    expect(wrapper.text()).toContain('No headings in this note yet.')
  })

  it('renders one row per heading, indented by level', () => {
    const wrapper = mount(OutlinePanel, { props: { headings } })
    const items = wrapper.findAll('[data-testid="outline-item"]')
    expect(items).toHaveLength(2)
    expect(items[0].text()).toBe('Intro')
    expect(items[1].text()).toBe('Details')
    expect(items[1].attributes('style')).toContain('padding-inline-start: 12px')
  })

  it('emits jump-to-heading with the clicked entry', async () => {
    const wrapper = mount(OutlinePanel, { props: { headings } })
    await wrapper.findAll('[data-testid="outline-item-btn"]')[1].trigger('click')
    expect(wrapper.emitted('jump-to-heading')![0]).toEqual([headings[1]])
  })
})
