import { flushPromises, mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'

const api = vi.hoisted(() => ({
  getNoteAcl: vi.fn(),
  getWorkspaceGroups: vi.fn(),
  replaceNoteAcl: vi.fn(),
}))

vi.mock('./services/api', () => api)

import NoteAccessPanel from './components/NoteAccessPanel.vue'

describe('NoteAccessPanel', () => {
  it('shows inherited access and hides management controls for readers', async () => {
    api.getNoteAcl.mockResolvedValueOnce({ restricted: false, entries: [], can_view: true, can_edit: false, can_manage: false })

    const wrapper = mount(NoteAccessPanel, {
      props: { workspaceId: 1, noteId: 7, access: { restricted: false, can_view: true, can_edit: false, can_manage: false } },
    })
    await flushPromises()

    expect(wrapper.text()).toContain('Workspace access')
    expect(wrapper.text()).toContain('Only workspace owners and admins can change restrictions.')
    expect(wrapper.find('[data-testid="note-access-save"]').exists()).toBe(false)
  })

  it('adds a group grant and replaces the ACL atomically', async () => {
    api.getNoteAcl.mockResolvedValueOnce({ restricted: false, entries: [], can_view: true, can_edit: true, can_manage: true })
    api.getWorkspaceGroups.mockResolvedValueOnce([{ id: 3, name: 'Editors', members: [] }])
    api.replaceNoteAcl.mockResolvedValueOnce({ restricted: true, entries: [{ principal_type: 'group', principal_id: 3, permission: 'edit' }], can_view: true, can_edit: true, can_manage: true })

    const wrapper = mount(NoteAccessPanel, {
      props: { workspaceId: 1, noteId: 7, access: { restricted: false, can_view: true, can_edit: true, can_manage: true } },
    })
    await flushPromises()

    await wrapper.get('[data-testid="note-access-principal"]').setValue('3')
    await wrapper.get('[data-testid="note-access-permission"]').setValue('edit')
    await wrapper.get('[data-testid="note-access-add"]').trigger('click')
    await wrapper.get('[data-testid="note-access-save"]').trigger('click')
    await flushPromises()

    expect(api.replaceNoteAcl).toHaveBeenCalledWith(1, 7, [{ principal_type: 'group', principal_id: 3, permission: 'edit' }])
    expect(wrapper.emitted('updated')?.[0]?.[0]).toMatchObject({ restricted: true })
  })
})
