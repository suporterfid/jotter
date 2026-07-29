import { describe, it, expect } from 'vitest'
import { mount } from '@vue/test-utils'
import axe from 'axe-core'
import Sidebar from './components/Sidebar.vue'
import LoginModal from './components/LoginModal.vue'
import CommandPalette from './components/CommandPalette.vue'
import SearchResults from './components/SearchResults.vue'
import BacklinksPanel from './components/BacklinksPanel.vue'
import MarkdownPreview from './components/MarkdownPreview.vue'
import AttachmentsPanel from './components/AttachmentsPanel.vue'
import HistoryPanel from './components/HistoryPanel.vue'
import PropertiesPanel from './components/PropertiesPanel.vue'
import CommentsPanel from './components/CommentsPanel.vue'
import AuditLogViewer from './components/AuditLogViewer.vue'
import LinkReportViewer from './components/LinkReportViewer.vue'

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

  it('AttachmentsPanel has no serious or critical structural accessibility violations', async () => {
    const wrapper = mount(AttachmentsPanel, {
      attachTo: document.body,
      props: {
        attachments: [
          { id: 1, workspace_id: 1, path: 'inbox/photo.png', mime: 'image/png', size: 2048, created_at: '2026-07-01T00:00:00Z', url: '/api/workspaces/1/attachments/inbox/photo.png' },
          { id: 2, workspace_id: 1, path: 'inbox/report.pdf', mime: 'application/pdf', size: 40960, created_at: '2026-07-02T00:00:00Z', url: '/api/workspaces/1/attachments/inbox/report.pdf' },
        ],
      },
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

  it('HistoryPanel has no serious or critical structural accessibility violations', async () => {
    const wrapper = mount(HistoryPanel, {
      attachTo: document.body,
      props: {
        revisions: [
          { id: 2, note_id: 1, content_hash: 'abc123def456', actor_id: null, created_at: '2026-07-02T00:00:00Z' },
          { id: 1, note_id: 1, content_hash: '789xyz000111', actor_id: null, created_at: '2026-07-01T00:00:00Z' },
        ],
        selectedRevisionId: 2,
        previewContent: '# Old content',
      },
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

  it('PropertiesPanel has no serious or critical structural accessibility violations', async () => {
    const wrapper = mount(PropertiesPanel, {
      attachTo: document.body,
      props: {
        properties: [
          { name: 'status', type: 'string', value: 'draft' },
          { name: 'priority', type: 'numeric', value: 3 },
        ],
      },
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

  it('CommentsPanel has no serious or critical structural accessibility violations', async () => {
    const wrapper = mount(CommentsPanel, {
      attachTo: document.body,
      props: {
        comments: [
          { id: 1, workspace_id: 1, note_id: 1, user_id: 1, actor_name: 'Alex', content: 'Looks good', anchor_line: null, created_at: '2026-07-01T00:00:00Z' },
        ],
      },
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

  it('AuditLogViewer has no serious or critical structural accessibility violations', async () => {
    const wrapper = mount(AuditLogViewer, {
      attachTo: document.body,
      props: {
        entries: [
          { id: 1, actor_subject_id: 'user:1', event: 'note.created', metadata: { path: 'a.md' }, ip_address: '127.0.0.1', created_at: '2026-07-01T00:00:00Z' },
          { id: 2, actor_subject_id: null, event: 'auth.login_success', metadata: null, ip_address: '127.0.0.1', created_at: '2026-07-01T00:01:00Z' },
        ],
      },
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

  it('LinkReportViewer has no serious or critical structural accessibility violations', async () => {
    const wrapper = mount(LinkReportViewer, {
      attachTo: document.body,
      props: {
        report: {
          broken_links: [
            { target_ref: 'missing-note', count: 2, sources: [{ id: 1, path: 'a.md', title: 'A' }] },
          ],
          orphans: [{ id: 2, path: 'b.md', title: 'B' }],
        },
      },
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
