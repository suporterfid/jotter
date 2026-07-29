import axios from 'axios'
import type { Workspace, NoteMeta, NoteDetail, SearchResult, AuthUser, AttachmentItem, SearchFilters, NoteRevisionMeta, NoteRevisionDetail, NoteProperty } from './types'

axios.defaults.withCredentials = true

const api = axios.create({
  baseURL: '/api',
  headers: {
    'Accept': 'application/json',
    'Content-Type': 'application/json'
  },
  withCredentials: true
})

let onUnauthenticatedHandler: (() => void) | null = null

export function setUnauthenticatedHandler(handler: () => void) {
  onUnauthenticatedHandler = handler
}

api.interceptors.response.use(
  (response) => response,
  (error) => {
    if (error.response && error.response.status === 401) {
      if (onUnauthenticatedHandler) {
        onUnauthenticatedHandler()
      }
    }
    return Promise.reject(error)
  }
)

export async function getCsrfCookie(): Promise<void> {
  await axios.get('/sanctum/csrf-cookie')
}

export async function login(email: string, password: string): Promise<AuthUser> {
  await getCsrfCookie().catch(() => {})
  const response = await api.post<{ data: AuthUser }>('/auth/login', { email, password })
  return response.data.data
}

export async function logout(): Promise<void> {
  await api.post('/auth/logout')
}

export async function getMe(): Promise<AuthUser | null> {
  try {
    const response = await api.get<{ data: AuthUser }>('/auth/me')
    return response.data.data
  } catch {
    return null
  }
}

export async function getWorkspaces(): Promise<Workspace[]> {
  const response = await api.get<{ data: Workspace[] }>('/workspaces')
  return response.data.data
}

export async function getNotes(workspaceId: number): Promise<NoteMeta[]> {
  const response = await api.get<{ data: NoteMeta[] }>(`/workspaces/${workspaceId}/notes`)
  return response.data.data
}

export async function getNote(workspaceId: number, noteId: number): Promise<NoteDetail> {
  const response = await api.get<{ data: NoteDetail }>(`/workspaces/${workspaceId}/notes/${noteId}`)
  return response.data.data
}

export async function createNote(workspaceId: number, path: string, content: string): Promise<NoteMeta> {
  const response = await api.post<{ data: NoteMeta }>(`/workspaces/${workspaceId}/notes`, {
    path,
    content
  })
  return response.data.data
}

export async function updateNote(workspaceId: number, noteId: number, content: string): Promise<NoteMeta> {
  const response = await api.put<{ data: NoteMeta }>(`/workspaces/${workspaceId}/notes/${noteId}`, {
    content
  })
  return response.data.data
}

export async function deleteNote(workspaceId: number, noteId: number): Promise<void> {
  await api.delete(`/workspaces/${workspaceId}/notes/${noteId}`)
}

export async function searchNotes(
  workspaceId: number,
  query: string,
  filters: SearchFilters = {}
): Promise<SearchResult[]> {
  const params = new URLSearchParams()
  if (query.trim()) params.set('q', query.trim())
  if (filters.title?.trim()) params.set('title', filters.title.trim())
  if (filters.modifiedAfter) params.set('modified_after', filters.modifiedAfter)
  if (filters.modifiedBefore) params.set('modified_before', filters.modifiedBefore)
  for (const tag of filters.tags ?? []) {
    if (tag.trim()) params.append('tags[]', tag.trim())
  }

  if ([...params.keys()].length === 0) return []

  const response = await api.get<{ data: SearchResult[] }>(
    `/workspaces/${workspaceId}/search?${params.toString()}`
  )
  return response.data.data
}

export async function getNoteRevisions(workspaceId: number, noteId: number): Promise<NoteRevisionMeta[]> {
  const response = await api.get<{ data: NoteRevisionMeta[] }>(`/workspaces/${workspaceId}/notes/${noteId}/revisions`)
  return response.data.data
}

export async function getNoteRevision(workspaceId: number, noteId: number, revisionId: number): Promise<NoteRevisionDetail> {
  const response = await api.get<{ data: NoteRevisionDetail }>(`/workspaces/${workspaceId}/notes/${noteId}/revisions/${revisionId}`)
  return response.data.data
}

export async function restoreNoteRevision(workspaceId: number, noteId: number, revisionId: number): Promise<void> {
  await api.post(`/workspaces/${workspaceId}/notes/${noteId}/revisions/${revisionId}/restore`)
}

export async function setNoteProperty(
  workspaceId: number,
  noteId: number,
  name: string,
  value: unknown
): Promise<NoteDetail> {
  const response = await api.post<{ data: NoteDetail }>(
    `/workspaces/${workspaceId}/notes/${noteId}/properties`,
    { name, value }
  )
  return response.data.data
}

export async function deleteNoteProperty(workspaceId: number, noteId: number, name: string): Promise<NoteDetail> {
  const response = await api.delete<{ data: NoteDetail }>(
    `/workspaces/${workspaceId}/notes/${noteId}/properties/${encodeURIComponent(name)}`
  )
  return response.data.data
}

export async function getWorkspaceProperties(workspaceId: number): Promise<Pick<NoteProperty, 'name' | 'type'>[]> {
  const response = await api.get<{ data: Pick<NoteProperty, 'name' | 'type'>[] }>(`/workspaces/${workspaceId}/properties`)
  return response.data.data
}

export async function getAttachments(workspaceId: number): Promise<AttachmentItem[]> {
  const response = await api.get<{ data: AttachmentItem[] }>(`/workspaces/${workspaceId}/attachments`)
  return response.data.data
}

export async function uploadAttachment(workspaceId: number, file: File, path?: string): Promise<AttachmentItem> {
  const formData = new FormData()
  formData.append('file', file)
  if (path) {
    formData.append('path', path)
  }
  const response = await api.post<{ data: AttachmentItem }>(`/workspaces/${workspaceId}/attachments`, formData, {
    headers: {
      'Content-Type': 'multipart/form-data'
    }
  })
  return response.data.data
}

export async function deleteAttachment(workspaceId: number, attachmentIdOrPath: number | string): Promise<void> {
  await api.delete(`/workspaces/${workspaceId}/attachments/${encodeURIComponent(attachmentIdOrPath)}`)
}
