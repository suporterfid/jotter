import { mount } from '@vue/test-utils'
import { describe, expect, it, vi } from 'vitest'
import App from './App.vue'

vi.mock('./services/api', () => ({
  getWorkspaces: vi.fn().mockResolvedValue([
    { id: 1, tenant_id: 1, slug: 'default', name: 'Default Workspace' }
  ]),
  getNotes: vi.fn().mockResolvedValue([
    { id: 10, path: 'welcome.md', title: 'Welcome Note', frontmatter: null, sort_position: null, updated_at: '2026-07-27T00:00:00Z' }
  ]),
  getFolderPositions: vi.fn().mockResolvedValue([]),
  getNote: vi.fn().mockResolvedValue({
    id: 10,
    path: 'welcome.md',
    title: 'Welcome Note',
    frontmatter: null,
    sort_position: null,
    updated_at: '2026-07-27T00:00:00Z',
    content: '# Welcome to Jotter\n\n[[Other Note]]',
    backlinks: []
  }),
  createNote: vi.fn(),
  updateNote: vi.fn(),
  deleteNote: vi.fn(),
  searchNotes: vi.fn().mockResolvedValue([]),
  getMe: vi.fn().mockResolvedValue({
    subject_id: '1',
    email: 'admin@example.com',
    name: 'Admin',
    is_admin: true
  }),
  login: vi.fn(),
  logout: vi.fn(),
  getCsrfCookie: vi.fn().mockResolvedValue(undefined),
  setUnauthenticatedHandler: vi.fn()
}))

describe('App Component', () => {
  it('renders the Jotter sidebar brand title', () => {
    const wrapper = mount(App)
    expect(wrapper.find('.brand-title').text()).toBe('Jotter')
  })
})
