import type { BrandConfig } from './brand'
import axios from 'axios'
import type { Workspace, Tenant, NoteMeta, TrashNoteMeta, NoteDetail, SearchResult, AuthUser, AttachmentItem, SearchFilters, NoteRevisionMeta, NoteRevisionDetail, NoteRevisionComparison, NoteProperty, NoteComment, AuditLogEntry, LinkReport, NotificationItem, CollectionPage, UnlinkedMention, OutgoingLink, FolderPosition, SortItem, Board, NoteChecklistItem, NoteActivityEntry, NoteAccessPayload, NoteAclEntry, WorkspaceGroup, NoteShareState, WorkspaceAnalytics, NoteReviewSummary } from './types'

axios.defaults.withCredentials = true

export const api = axios.create({
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

export async function changePassword(currentPassword: string, newPassword: string): Promise<void> {
  await api.post('/auth/change-password', {
    current_password: currentPassword,
    new_password: newPassword,
  })
}

export async function updateLocale(locale: string): Promise<void> {
  await api.post('/user/locale', { locale })
}

export async function adminResetPassword(userId: number, newPassword: string): Promise<void> {
  await api.post(`/admin/users/${userId}/reset-password`, { new_password: newPassword })
}

export async function getMe(): Promise<AuthUser | null> {
  try {
    const response = await api.get<{ data: AuthUser }>('/auth/me')
    return response.data.data
  } catch {
    return null
  }
}

export interface AuthConfig {
  provider: 'local' | 'grandpasson' | 'oidc'
  sso_login_url: string | null
  version: string | null
  external_embed_domains?: string[]
  brand?: BrandConfig
}

export async function getAuthConfig(): Promise<AuthConfig> {
  const response = await api.get<{ data: AuthConfig }>('/auth/config')
  return response.data.data
}

export async function getWorkspaces(tenantId?: number): Promise<Workspace[]> {
  const response = await api.get<{ data: Workspace[] }>('/workspaces', {
    params: tenantId !== undefined ? { tenant_id: tenantId } : undefined,
  })
  return response.data.data
}

export async function getTenants(): Promise<Tenant[]> {
  const response = await api.get<{ data: Tenant[] }>('/tenants')
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

export async function getNoteReview(workspaceId: number, noteId: number): Promise<NoteReviewSummary> {
  const response = await api.get<{ data: NoteReviewSummary }>(`/workspaces/${workspaceId}/notes/${noteId}/review`)
  return response.data.data
}

export async function assignNoteReviewer(workspaceId: number, noteId: number, reviewerId: number | null): Promise<NoteReviewSummary> {
  const response = await api.put<{ data: NoteReviewSummary }>(`/workspaces/${workspaceId}/notes/${noteId}/reviewer`, { reviewer_id: reviewerId })
  return response.data.data
}

export async function submitNoteReview(workspaceId: number, noteId: number): Promise<NoteReviewSummary> {
  const response = await api.post<{ data: NoteReviewSummary }>(`/workspaces/${workspaceId}/notes/${noteId}/review/submit`)
  return response.data.data
}

export async function approveNoteReview(workspaceId: number, noteId: number): Promise<NoteReviewSummary> {
  const response = await api.post<{ data: NoteReviewSummary }>(`/workspaces/${workspaceId}/notes/${noteId}/review/approve`)
  return response.data.data
}

export async function requestNoteChanges(workspaceId: number, noteId: number, reason: string): Promise<NoteReviewSummary> {
  const response = await api.post<{ data: NoteReviewSummary }>(`/workspaces/${workspaceId}/notes/${noteId}/review/request-changes`, { reason })
  return response.data.data
}

export async function getNoteAcl(workspaceId: number, noteId: number): Promise<NoteAccessPayload> {
  const response = await api.get<{ data: NoteAccessPayload }>(`/workspaces/${workspaceId}/notes/${noteId}/acl`)
  return response.data.data
}

export async function replaceNoteAcl(
  workspaceId: number,
  noteId: number,
  entries: NoteAclEntry[]
): Promise<NoteAccessPayload> {
  const response = await api.put<{ data: NoteAccessPayload }>(`/workspaces/${workspaceId}/notes/${noteId}/acl`, { entries })
  return response.data.data
}

export async function getNoteShare(workspaceId: number, noteId: number): Promise<NoteShareState> {
  const response = await api.get<{ data: NoteShareState }>(`/workspaces/${workspaceId}/notes/${noteId}/share`)
  return response.data.data
}

export async function createNoteShare(
  workspaceId: number,
  noteId: number,
  expiresAt: string | null,
): Promise<NoteShareState> {
  const response = await api.post<{ data: NoteShareState }>(
    `/workspaces/${workspaceId}/notes/${noteId}/share`,
    { expires_at: expiresAt },
  )
  return response.data.data
}

export async function revokeNoteShare(workspaceId: number, noteId: number): Promise<void> {
  await api.delete(`/workspaces/${workspaceId}/notes/${noteId}/share`)
}

export async function getWorkspaceGroups(workspaceId: number): Promise<WorkspaceGroup[]> {
  const response = await api.get<{ data: WorkspaceGroup[] }>(`/workspaces/${workspaceId}/groups`)
  return response.data.data
}

export async function createWorkspaceGroup(workspaceId: number, name: string, memberIds: number[] = []): Promise<WorkspaceGroup> {
  const response = await api.post<{ data: WorkspaceGroup }>(`/workspaces/${workspaceId}/groups`, { name, member_ids: memberIds })
  return response.data.data
}

export async function updateWorkspaceGroup(workspaceId: number, groupId: number, attrs: { name?: string; member_ids?: number[] }): Promise<WorkspaceGroup> {
  const response = await api.put<{ data: WorkspaceGroup }>(`/workspaces/${workspaceId}/groups/${groupId}`, attrs)
  return response.data.data
}

export async function deleteWorkspaceGroup(workspaceId: number, groupId: number): Promise<void> {
  await api.delete(`/workspaces/${workspaceId}/groups/${groupId}`)
}

export async function deleteNote(workspaceId: number, noteId: number): Promise<void> {
  await api.delete(`/workspaces/${workspaceId}/notes/${noteId}`)
}

export async function getTrash(workspaceId: number): Promise<TrashNoteMeta[]> {
  const response = await api.get<{ data: TrashNoteMeta[] }>(`/workspaces/${workspaceId}/trash`)
  return response.data.data
}

export async function restoreTrashNote(workspaceId: number, noteId: number): Promise<NoteMeta> {
  const response = await api.post<{ data: NoteMeta }>(`/workspaces/${workspaceId}/trash/${noteId}/restore`)
  return response.data.data
}

export async function permanentlyDeleteTrashNote(workspaceId: number, noteId: number): Promise<void> {
  await api.delete(`/workspaces/${workspaceId}/trash/${noteId}`)
}

export async function moveNote(workspaceId: number, noteId: number, newPath: string): Promise<NoteMeta> {
  const response = await api.post<{ data: NoteMeta }>(`/workspaces/${workspaceId}/notes/${noteId}/move`, {
    new_path: newPath
  })
  return response.data.data
}

export async function reorderNoteTree(workspaceId: number, folderPath: string, items: SortItem[]): Promise<void> {
  await api.put(`/workspaces/${workspaceId}/note-tree/order`, { folder_path: folderPath, items })
}

export async function getFolderPositions(workspaceId: number): Promise<FolderPosition[]> {
  const response = await api.get<{ data: FolderPosition[] }>(`/workspaces/${workspaceId}/note-tree/order`)
  return response.data.data
}

export async function getUnlinkedMentions(workspaceId: number, noteId: number): Promise<UnlinkedMention[]> {
  const response = await api.get<{ data: UnlinkedMention[] }>(`/workspaces/${workspaceId}/notes/${noteId}/unlinked-mentions`)
  return response.data.data
}

export async function getOutgoingLinks(workspaceId: number, noteId: number): Promise<OutgoingLink[]> {
  const response = await api.get<{ data: OutgoingLink[] }>(`/workspaces/${workspaceId}/notes/${noteId}/outgoing-links`)
  return response.data.data
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

export async function compareNoteRevisions(
  workspaceId: number,
  noteId: number,
  fromRevisionId: number,
  toRevisionId: number | 'current'
): Promise<NoteRevisionComparison> {
  const params = new URLSearchParams({
    from: String(fromRevisionId),
    to: String(toRevisionId)
  })
  const response = await api.get<{ data: NoteRevisionComparison }>(
    `/workspaces/${workspaceId}/notes/${noteId}/revisions/compare?${params.toString()}`
  )
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

export async function createNoteFromTemplate(
  workspaceId: number,
  templatePath: string,
  targetPath: string,
  title?: string
): Promise<{ id: number }> {
  const response = await api.post<{ data: { id: number } }>(`/workspaces/${workspaceId}/notes/from-template`, {
    template_path: templatePath,
    target_path: targetPath,
    title: title || undefined
  })
  return response.data.data
}

export async function getOrCreateDailyNote(workspaceId: number, dateStr?: string): Promise<{ id: number }> {
  const url = dateStr
    ? `/workspaces/${workspaceId}/daily/${dateStr}`
    : `/workspaces/${workspaceId}/daily`
  const response = await api.post<{ data: { id: number } }>(url)
  return response.data.data
}

export async function getNoteComments(workspaceId: number, noteId: number): Promise<NoteComment[]> {
  const response = await api.get<{ data: NoteComment[] }>(`/workspaces/${workspaceId}/notes/${noteId}/comments`)
  return response.data.data
}

export async function addNoteComment(
  workspaceId: number,
  noteId: number,
  content: string,
  anchorLine?: number
): Promise<NoteComment> {
  const response = await api.post<{ data: NoteComment }>(`/workspaces/${workspaceId}/notes/${noteId}/comments`, {
    content,
    anchor_line: anchorLine ?? undefined
  })
  return response.data.data
}

export async function deleteNoteComment(workspaceId: number, noteId: number, commentId: number): Promise<void> {
  await api.delete(`/workspaces/${workspaceId}/notes/${noteId}/comments/${commentId}`)
}

export async function getChecklistItems(workspaceId: number, noteId: number): Promise<NoteChecklistItem[]> {
  const response = await api.get<{ data: NoteChecklistItem[] }>(`/workspaces/${workspaceId}/notes/${noteId}/checklist-items`)
  return response.data.data
}

export async function createChecklistItem(workspaceId: number, noteId: number, text: string): Promise<NoteChecklistItem> {
  const response = await api.post<{ data: NoteChecklistItem }>(`/workspaces/${workspaceId}/notes/${noteId}/checklist-items`, { text })
  return response.data.data
}

export async function updateChecklistItem(
  workspaceId: number,
  noteId: number,
  itemId: number,
  attrs: Partial<{ text: string; done: boolean }>
): Promise<NoteChecklistItem> {
  const response = await api.put<{ data: NoteChecklistItem }>(`/workspaces/${workspaceId}/notes/${noteId}/checklist-items/${itemId}`, attrs)
  return response.data.data
}

export async function deleteChecklistItem(workspaceId: number, noteId: number, itemId: number): Promise<void> {
  await api.delete(`/workspaces/${workspaceId}/notes/${noteId}/checklist-items/${itemId}`)
}

export async function getNoteActivity(workspaceId: number, noteId: number): Promise<NoteActivityEntry[]> {
  const response = await api.get<{ data: NoteActivityEntry[] }>(`/workspaces/${workspaceId}/notes/${noteId}/activity`)
  return response.data.data
}

export interface ImportResult {
  status: string
  extracted_count: number
  skipped_count: number
  errors: string[]
}

export type ImportSource = 'generic' | 'obsidian' | 'notion'

export async function importWorkspaceArchive(
  workspaceId: number,
  archive: File,
  overwrite: boolean,
  source: ImportSource = 'generic'
): Promise<ImportResult> {
  const formData = new FormData()
  formData.append('archive', archive)
  formData.append('overwrite', overwrite ? '1' : '0')
  formData.append('source', source)

  const response = await api.post<ImportResult>(`/workspaces/${workspaceId}/import`, formData, {
    headers: {
      'Content-Type': 'multipart/form-data'
    }
  })
  return response.data
}

export async function getAuditLogs(workspaceId: number): Promise<AuditLogEntry[]> {
  const response = await api.get<{ workspace_id: number; audit_logs: AuditLogEntry[] }>(
    `/workspaces/${workspaceId}/audit-logs`
  )
  return response.data.audit_logs
}

export async function getWorkspaceAnalytics(
  workspaceId: number,
  params: { days?: number; limit?: number } = {}
): Promise<WorkspaceAnalytics> {
  const response = await api.get<WorkspaceAnalytics>(`/workspaces/${workspaceId}/analytics`, { params })
  return response.data
}

export interface PublishResult {
  message: string
  workspace: string
  notes_published: number
  site_url: string
}

export async function publishWorkspace(workspaceId: number): Promise<PublishResult> {
  const response = await api.post<PublishResult>(`/workspaces/${workspaceId}/publish`)
  return response.data
}

export interface PdfExportStatus {
  id: string
  status: 'queued' | 'processing' | 'ready' | 'failed' | 'expired'
  scope: 'workspace' | 'note'
  queued_at?: string | null
  started_at?: string | null
  completed_at?: string | null
  expires_at?: string | null
  failure_reason?: string | null
}

export async function queueWorkspacePdfExport(workspaceId: number): Promise<PdfExportStatus> {
  const response = await api.post<PdfExportStatus>(`/workspaces/${workspaceId}/pdf-exports`)
  return response.data
}

export async function getWorkspacePdfExport(workspaceId: number, exportId: string): Promise<PdfExportStatus> {
  const response = await api.get<PdfExportStatus>(`/workspaces/${workspaceId}/pdf-exports/${encodeURIComponent(exportId)}`)
  return response.data
}

export function downloadWorkspacePdfExport(workspaceId: number, exportId: string): void {
  window.location.href = `/api/workspaces/${workspaceId}/pdf-exports/${encodeURIComponent(exportId)}/download`
}

export async function getNotifications(workspaceId: number): Promise<NotificationItem[]> {
  const response = await api.get<{ data: NotificationItem[] }>(`/workspaces/${workspaceId}/notifications`)
  return response.data.data
}

export async function markNotificationRead(workspaceId: number, notificationId: number): Promise<NotificationItem> {
  const response = await api.post<{ data: NotificationItem }>(`/workspaces/${workspaceId}/notifications/${notificationId}/read`)
  return response.data.data
}

export async function deleteNotification(workspaceId: number, notificationId: number): Promise<void> {
  await api.delete(`/workspaces/${workspaceId}/notifications/${notificationId}`)
}

export async function getNotificationPreferences(): Promise<import('./types').NotificationPreference[]> {
  const response = await api.get<{ data: import('./types').NotificationPreference[] }>('/user/notification-preferences')
  return response.data.data
}

export async function updateNotificationPreference(
  type: import('./types').NotificationType,
  mode: import('./types').NotificationEmailMode,
): Promise<import('./types').NotificationPreference> {
  const response = await api.put<{ data: import('./types').NotificationPreference }>(
    `/user/notification-preferences/${type}`,
    { mode },
  )
  return response.data.data
}

export async function setNoteWatching(workspaceId: number, noteId: number, watching: boolean): Promise<boolean> {
  const response = await api.put<{ data: { watching: boolean } }>(
    `/workspaces/${workspaceId}/notes/${noteId}/watch`,
    { watching },
  )
  return response.data.data.watching
}

export async function getCollection(
  workspaceId: number,
  filters: { property?: string; value?: string; page?: number; includeArchived?: boolean } = {}
): Promise<CollectionPage> {
  const params = new URLSearchParams()
  if (filters.property) params.set('property', filters.property)
  if (filters.value) params.set('value', filters.value)
  if (filters.page) params.set('page', String(filters.page))
  if (filters.includeArchived) params.set('include_archived', '1')
  params.set('per_page', '50')

  const response = await api.get<CollectionPage>(`/workspaces/${workspaceId}/collections?${params.toString()}`)
  return response.data
}

export async function getBoards(workspaceId: number): Promise<Board[]> {
  const response = await api.get<{ data: Board[] }>(`/workspaces/${workspaceId}/boards`)
  return response.data.data
}

export async function createBoard(
  workspaceId: number,
  attrs: { name: string; group_property?: string | null; swimlane_property?: string | null; filter_property?: string | null; filter_value?: string | null }
): Promise<Board> {
  const response = await api.post<{ data: Board }>(`/workspaces/${workspaceId}/boards`, attrs)
  return response.data.data
}

export async function updateBoard(
  workspaceId: number,
  boardId: number,
  attrs: Partial<{ name: string; group_property: string | null; swimlane_property: string | null; filter_property: string | null; filter_value: string | null; column_config: Board['column_config'] }>
): Promise<Board> {
  const response = await api.put<{ data: Board }>(`/workspaces/${workspaceId}/boards/${boardId}`, attrs)
  return response.data.data
}

export async function deleteBoard(workspaceId: number, boardId: number): Promise<void> {
  await api.delete(`/workspaces/${workspaceId}/boards/${boardId}`)
}

export async function getLinkReport(workspaceId: number): Promise<LinkReport> {
  const response = await api.get<LinkReport>(`/workspaces/${workspaceId}/link-report`)
  return response.data
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
