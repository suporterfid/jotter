import { mount } from '@vue/test-utils'
import { describe, expect, it, beforeEach } from 'vitest'
import CommentsPanel from './components/CommentsPanel.vue'

const comment = {
  id: 1,
  workspace_id: 1,
  note_id: 1,
  user_id: 1,
  actor_name: 'Alex',
  content: 'Looks good',
  anchor_line: null,
  created_at: '2026-07-01T00:00:00Z'
}

describe('CommentsPanel', () => {
  beforeEach(() => {
    localStorage.clear()
  })

  it('shows the empty state when there are no comments', () => {
    const wrapper = mount(CommentsPanel, { props: { comments: [] } })
    expect(wrapper.text()).toContain('No comments on this note yet.')
  })

  it('renders one entry per comment with author and content', () => {
    const wrapper = mount(CommentsPanel, { props: { comments: [comment] } })
    expect(wrapper.text()).toContain('Alex')
    expect(wrapper.text()).toContain('Looks good')
  })

  it('shows the anchor line when set', () => {
    const wrapper = mount(CommentsPanel, {
      props: { comments: [{ ...comment, anchor_line: 12 }] }
    })
    expect(wrapper.text()).toContain('line 12')
  })

  it('emits add-comment with the trimmed content and clears the input', async () => {
    const wrapper = mount(CommentsPanel, { props: { comments: [] } })
    const textarea = wrapper.get('[data-testid="comment-input"]')
    await textarea.setValue('  nice work  ')
    await wrapper.get('.comment-form').trigger('submit')

    expect(wrapper.emitted('add-comment')![0]).toEqual(['nice work'])
    expect((textarea.element as HTMLTextAreaElement).value).toBe('')
  })

  it('does not emit add-comment for a blank comment', async () => {
    const wrapper = mount(CommentsPanel, { props: { comments: [] } })
    await wrapper.get('.comment-form').trigger('submit')
    expect(wrapper.emitted('add-comment')).toBeFalsy()
  })

  it('emits delete-comment with the comment id', async () => {
    const wrapper = mount(CommentsPanel, { props: { comments: [comment] } })
    await wrapper.get('[data-testid="comment-delete-btn"]').trigger('click')
    expect(wrapper.emitted('delete-comment')![0]).toEqual([1])
  })

  it('shows an error message when provided', () => {
    const wrapper = mount(CommentsPanel, {
      props: { comments: [], errorMessage: 'You can only delete your own comments.' }
    })
    expect(wrapper.text()).toContain('You can only delete your own comments.')
  })

  it('hides the body when collapsed', async () => {
    const wrapper = mount(CommentsPanel, { props: { comments: [] } })
    await wrapper.find('[data-testid="panel-collapse-toggle"]').trigger('click')
    expect((wrapper.find('.comments-body').element as HTMLElement).style.display).toBe('none')
  })

  it('shows the body by default and re-shows it after toggling twice', async () => {
    const wrapper = mount(CommentsPanel, { props: { comments: [] } })
    expect((wrapper.find('.comments-body').element as HTMLElement).style.display).not.toBe('none')
    await wrapper.find('[data-testid="panel-collapse-toggle"]').trigger('click')
    await wrapper.find('[data-testid="panel-collapse-toggle"]').trigger('click')
    expect((wrapper.find('.comments-body').element as HTMLElement).style.display).not.toBe('none')
  })
})
