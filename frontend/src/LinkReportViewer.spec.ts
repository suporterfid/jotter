import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import LinkReportViewer from './components/LinkReportViewer.vue'

const source = readFileSync('src/components/LinkReportViewer.vue', 'utf8')

describe('LinkReportViewer identity layout', () => {
  it('uses logical list indentation for linked source notes', () => {
    expect(source).toContain('padding-inline-start: var(--space-4)')
  })
})

const emptyReport = { broken_links: [], orphans: [] }

const report = {
  broken_links: [
    { target_ref: 'missing-note', count: 2, sources: [{ id: 1, path: 'a.md', title: 'A' }] }
  ],
  orphans: [{ id: 2, path: 'b.md', title: 'B' }]
}

describe('LinkReportViewer', () => {
  it('shows empty-state text for both sections when the report is empty', () => {
    const wrapper = mount(LinkReportViewer, { props: { report: emptyReport } })
    expect(wrapper.text()).toContain('No broken')
    expect(wrapper.text()).toContain('No orphan notes')
  })

  it('renders a broken link group with its target ref and count', () => {
    const wrapper = mount(LinkReportViewer, { props: { report } })
    expect(wrapper.findAll('[data-testid="broken-link-group"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('[[missing-note]]')
  })

  it('renders an orphan note entry', () => {
    const wrapper = mount(LinkReportViewer, { props: { report } })
    expect(wrapper.findAll('[data-testid="orphan-note"]')).toHaveLength(1)
    expect(wrapper.text()).toContain('B')
  })

  it('emits select-note when a broken-link source is clicked', async () => {
    const wrapper = mount(LinkReportViewer, { props: { report } })
    await wrapper.get('[data-testid="broken-link-source"]').trigger('click')
    expect(wrapper.emitted('select-note')![0]).toEqual([1])
  })

  it('emits select-note when an orphan note is clicked', async () => {
    const wrapper = mount(LinkReportViewer, { props: { report } })
    await wrapper.get('[data-testid="orphan-note"] button').trigger('click')
    expect(wrapper.emitted('select-note')![0]).toEqual([2])
  })

  it('shows a loading state', () => {
    const wrapper = mount(LinkReportViewer, { props: { report: emptyReport, loading: true } })
    expect(wrapper.text()).toContain('Loading report')
  })
})
