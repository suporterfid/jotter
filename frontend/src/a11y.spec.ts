import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import axe from 'axe-core'
import Sidebar from './components/Sidebar.vue'
import LoginModal from './components/LoginModal.vue'
import CommandPalette from './components/CommandPalette.vue'
import SearchResults from './components/SearchResults.vue'
import BacklinksPanel from './components/BacklinksPanel.vue'
import MarkdownPreview from './components/MarkdownPreview.vue'

/*
 * Accessibility Audit Spec (Issue #109 / Spec §10 / WCAG 2.2 AA)
 *
 * NOTE ON JSDOM LIMITATION:
 * jsdom does not calculate layout or computed styles, so axe's `color-contrast`
 * rule reports as 'incomplete' or skipped in jsdom runs. Structural rules
 * (labels, ARIA validity, roles, headings, landmarks) are fully evaluated here.
 * Real color contrast verification is covered in E2E browser tests and documented
 * in docs/visual-identity.md.
 */

describe('Accessibility Audit (axe-core)', () => {
  it('Sidebar has no serious or critical structural accessibility violations', async () => {
    const wrapper = mount(Sidebar, {
      attachTo: document.body,
      props: {
        notes: [],
        selectedNoteId: null,
        currentUser: null,
      },
    })
    const results = await axe.run(wrapper.element, {
      rules: {
        'color-contrast': { enabled: false }, // Handled in browser E2E / design tokens
      },
    })
    wrapper.unmount()
    const seriousOrCritical = results.violations.filter(v => ['serious', 'critical'].includes(v.impact || ''))
    expect(seriousOrCritical).toEqual([])
  })

  it('LoginModal has no serious or critical structural accessibility violations', async () => {
    const wrapper = mount(LoginModal, {
      attachTo: document.body,
      props: { show: true },
    })
    const results = await axe.run(wrapper.element, {
      rules: {
        'color-contrast': { enabled: false },
      },
    })
    wrapper.unmount()
    const seriousOrCritical = results.violations.filter(v => ['serious', 'critical'].includes(v.impact || ''))
    expect(seriousOrCritical).toEqual([])
  })

  it('CommandPalette has no serious or critical structural accessibility violations', async () => {
    const wrapper = mount(CommandPalette, {
      attachTo: document.body,
      props: { notes: [] },
    })
    ;(wrapper.vm as any).open()
    await wrapper.vm.$nextTick()
    const paletteEl = document.querySelector('.command-palette-backdrop') || wrapper.element
    const results = await axe.run(paletteEl as HTMLElement, {
      rules: {
        'color-contrast': { enabled: false },
      },
    })
    wrapper.unmount()
    const seriousOrCritical = results.violations.filter(v => ['serious', 'critical'].includes(v.impact || ''))
    expect(seriousOrCritical).toEqual([])
  })

  it('SearchResults has no serious or critical structural accessibility violations', async () => {
    const wrapper = mount(SearchResults, {
      attachTo: document.body,
      props: { query: 'test', results: [], filters: {}, availableTags: [] },
    })
    const results = await axe.run(wrapper.element, {
      rules: {
        'color-contrast': { enabled: false },
      },
    })
    wrapper.unmount()
    const seriousOrCritical = results.violations.filter(v => ['serious', 'critical'].includes(v.impact || ''))
    expect(seriousOrCritical).toEqual([])
  })

  it('BacklinksPanel has no serious or critical structural accessibility violations', async () => {
    const wrapper = mount(BacklinksPanel, {
      attachTo: document.body,
      props: { backlinks: [] },
    })
    const results = await axe.run(wrapper.element, {
      rules: {
        'color-contrast': { enabled: false },
      },
    })
    wrapper.unmount()
    const seriousOrCritical = results.violations.filter(v => ['serious', 'critical'].includes(v.impact || ''))
    expect(seriousOrCritical).toEqual([])
  })

  it('MarkdownPreview has no serious or critical structural accessibility violations', async () => {
    const wrapper = mount(MarkdownPreview, {
      attachTo: document.body,
      props: { content: '# Hello World\n\nThis is a test note.' },
    })
    const results = await axe.run(wrapper.element, {
      rules: {
        'color-contrast': { enabled: false },
      },
    })
    wrapper.unmount()
    const seriousOrCritical = results.violations.filter(v => ['serious', 'critical'].includes(v.impact || ''))
    expect(seriousOrCritical).toEqual([])
  })
})
