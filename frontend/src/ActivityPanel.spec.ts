import { mount } from '@vue/test-utils'
import { readFileSync } from 'node:fs'
import { describe, expect, it } from 'vitest'
import ActivityPanel from './components/ActivityPanel.vue'
import type { NoteActivityEntry } from './services/types'

const source = readFileSync('src/components/ActivityPanel.vue', 'utf8')

describe('ActivityPanel identity localization', () => {
  it('formats activity timestamps using the selected locale', () => {
    expect(source).toContain('new Intl.DateTimeFormat(locale.value')
  })
})

const entries: NoteActivityEntry[] = [
  { id: 1, event: 'note.updated', metadata: { action: 'property_set', name: 'status', value: 'done' }, actor_subject_id: '1', created_at: '2026-08-04T12:00:00Z' },
  { id: 2, event: 'note.updated', metadata: { action: 'checklist_item_checked', text: 'Ship it' }, actor_subject_id: '1', created_at: '2026-08-04T11:00:00Z' },
  { id: 3, event: 'note.updated', metadata: { action: 'checklist_item_created', text: 'Write tests' }, actor_subject_id: '1', created_at: '2026-08-04T10:00:00Z' },
]

describe('ActivityPanel', () => {
  it('shows an empty state when there is no activity', () => {
    const wrapper = mount(ActivityPanel, { props: { entries: [] } })
    expect(wrapper.text()).toContain('No activity yet.')
  })

  it('renders a human-readable description for each entry type', () => {
    const wrapper = mount(ActivityPanel, { props: { entries } })
    const items = wrapper.findAll('[data-testid="activity-item"]')
    expect(items).toHaveLength(3)
    expect(items[0].text()).toContain('status')
    expect(items[0].text()).toContain('done')
    expect(items[1].text()).toContain('Ship it')
    expect(items[2].text()).toContain('Write tests')
  })
})
