import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi, beforeEach } from 'vitest'
import NoteReviewPanel from './NoteReviewPanel.vue'
import type { NoteReviewSummary } from '../services/types'

const review = (): NoteReviewSummary => ({
  state: 'draft',
  stale: false,
  reviewer: null,
  submitted_at: null,
  approved_at: null,
  can_assign: true,
  can_submit: true,
  can_approve: false,
  can_request_changes: false,
})

const api = vi.hoisted(() => ({
  getNoteReview: vi.fn(),
  assignNoteReviewer: vi.fn(),
  submitNoteReview: vi.fn(),
  approveNoteReview: vi.fn(),
  requestNoteChanges: vi.fn(),
}))

vi.mock('../services/api', () => api)

describe('NoteReviewPanel', () => {
  beforeEach(() => {
    vi.clearAllMocks()
    api.getNoteReview.mockResolvedValue(review())
    api.submitNoteReview.mockResolvedValue({ ...review(), state: 'in_review', can_submit: false, can_approve: true, can_request_changes: true })
  })

  it('loads and renders the current review state', async () => {
    const wrapper = mount(NoteReviewPanel, { props: { workspaceId: 1, noteId: 2 } })
    await flushPromises()

    expect(api.getNoteReview).toHaveBeenCalledWith(1, 2)
    expect(wrapper.get('[data-testid="review-state"]').text()).toContain('Draft')
    expect(wrapper.find('[data-testid="review-submit"]').exists()).toBe(true)
  })

  it('submits the note and emits the refreshed review summary', async () => {
    const wrapper = mount(NoteReviewPanel, { props: { workspaceId: 1, noteId: 2 } })
    await flushPromises()
    await wrapper.get('[data-testid="review-submit"]').trigger('click')
    await flushPromises()

    expect(api.submitNoteReview).toHaveBeenCalledWith(1, 2)
    expect(wrapper.get('[data-testid="review-state"]').text()).toContain('In review')
    expect(wrapper.emitted('updated')).toHaveLength(1)
  })

  it('sends a change request reason from the review panel', async () => {
    api.getNoteReview.mockResolvedValue({ ...review(), state: 'in_review', can_submit: false, can_request_changes: true })
    api.requestNoteChanges.mockResolvedValue({ ...review(), state: 'changes_requested' })
    const wrapper = mount(NoteReviewPanel, { props: { workspaceId: 1, noteId: 2 } })
    await flushPromises()

    await wrapper.get('[data-testid="review-reason"]').setValue('Add a source.')
    await wrapper.get('[data-testid="review-request-changes"]').trigger('click')
    await flushPromises()

    expect(api.requestNoteChanges).toHaveBeenCalledWith(1, 2, 'Add a source.')
  })
})
