export interface Workspace {
  id: number
  tenant_id: number
  slug: string
  name: string
}

export interface Tenant {
  id: number
  slug: string
  name: string
}

export interface NoteMeta {
  id: number
  path: string
  title: string
  frontmatter: Record<string, unknown> | null
  sort_position: number | null
  updated_at: string
  watching?: boolean
  access?: NoteAccessMeta | null
  review?: NoteReviewSummary | null
}

export type NoteReviewState = 'draft' | 'in_review' | 'changes_requested' | 'approved'

export interface NoteReviewSummary {
  state: NoteReviewState
  stale: boolean
  reviewer: { id: number; name: string; email: string } | null
  submitted_at: string | null
  approved_at: string | null
  can_assign: boolean
  can_submit: boolean
  can_approve: boolean
  can_request_changes: boolean
}

export interface NoteAccessMeta {
  restricted: boolean
  can_view: boolean
  can_edit: boolean
  can_manage?: boolean
}

export type NoteAclPrincipalType = 'user' | 'group'
export type NoteAclPermission = 'view' | 'edit'

export interface NoteAclEntry {
  id?: number
  principal_type: NoteAclPrincipalType
  principal_id: number
  permission: NoteAclPermission
  principal?: { id: number; name: string; email?: string | null } | null
}

export interface WorkspaceGroup {
  id: number
  name: string
  members: Array<{ id: number; name: string; email: string }>
}

export interface NoteAccessPayload extends NoteAccessMeta {
  entries: NoteAclEntry[]
}

export interface NoteShareState {
  active: boolean
  url: string | null
  token?: string
  expires_at: string | null
  revoked_at: string | null
}

export interface TrashNoteMeta {
  id: number
  title: string
  original_path: string | null
  frontmatter: Record<string, unknown> | null
  deleted_at: string | null
}

export interface FolderPosition {
  folder_path: string
  sort_position: number
}

export type SortItem = { type: 'note'; id: number } | { type: 'folder'; path: string }

export interface Backlink {
  id: number
  path: string
  title: string
  target_ref?: string
}

export interface OutgoingLink {
  id: number | null
  path: string | null
  title: string | null
  target_ref: string
  target_block: string | null
  resolved: boolean
}

export interface LocalGraphNeighbor {
  id: number
  title: string
  path: string
  direction: 'backlink' | 'outgoing'
}

export interface UnlinkedMention {
  id: number
  path: string
  title: string
  matched_phrase: string
  snippet: string
}

export type NotePropertyType = 'string' | 'numeric' | 'boolean' | 'datetime' | 'list' | 'json'

export interface NoteProperty {
  name: string
  type: NotePropertyType
  value: string | number | boolean | string[] | Record<string, unknown> | null
}

export interface NoteDetail extends NoteMeta {
  content: string
  backlinks: Backlink[]
  properties?: NoteProperty[]
}

export interface SearchResult {
  note_id: number
  title: string
  path: string
  snippet: string
  score: number
}

export interface SearchFilters {
  title?: string
  tags?: string[]
  modifiedAfter?: string
  modifiedBefore?: string
}

export interface AuthUser {
  subject_id: string
  email: string
  name: string
  is_admin: boolean
  locale: string
}

export interface NoteRevisionMeta {
  id: number
  note_id: number
  content_hash: string
  actor_id: string | null
  created_at: string
}

export interface NoteRevisionDetail extends NoteRevisionMeta {
  workspace_id: number
  content: string
}

export interface NoteComment {
  id: number
  workspace_id: number
  note_id: number
  user_id: number | null
  parent_comment_id?: number | null
  actor_name: string
  content: string
  anchor_line: number | null
  created_at: string
}

export interface AuditLogEntry {
  id: number
  actor_subject_id: string | null
  event: string
  metadata: Record<string, unknown> | null
  ip_address: string | null
  created_at: string | null
}

export interface WorkspaceAnalytics {
  workspace_id: number
  period: {
    days: number
    from: string
    to: string
  }
  most_active_notes: Array<{
    note_id: number
    path: string
    title: string
    count: number
    last_seen_at: string | null
  }>
  activity_over_time: Array<{
    period_start: string
    count: number
  }>
  activity_by_user: Array<{
    actor_subject_id: string
    count: number
  }>
  stale_notes: Array<{
    note_id: number
    path: string
    title: string
    updated_at: string
    days_stale: number
  }>
}

export interface BrokenLinkSource {
  id: number
  path: string
  title: string
}

export interface BrokenLinkGroup {
  target_ref: string
  count: number
  sources: BrokenLinkSource[]
}

export interface OrphanNote {
  id: number
  path: string
  title: string
}

export interface LinkReport {
  broken_links: BrokenLinkGroup[]
  orphans: OrphanNote[]
}

export type NotificationType = 'mention' | 'note_commented' | 'comment_reply' | 'note_edited' | 'note_moved' | 'note_deleted'
export type NotificationEmailMode = 'immediate' | 'digest' | 'off'

export interface NotificationPreference {
  type: NotificationType
  mode: NotificationEmailMode
  explicit: boolean
}

export interface NotificationData {
  actor_id?: string
  actor_name?: string
  comment_snippet?: string
  note_id?: number
  note_path?: string
  note_title?: string
  comment_id?: number
  parent_comment_id?: number
  target_kind?: 'note' | 'trash'
  [key: string]: unknown
}

export interface NotificationItem {
  id: number
  workspace_id: number
  user_id: number
  type: NotificationType
  title: string
  data: NotificationData | null
  read_at: string | null
  created_at: string
}

export interface RawNoteProperty {
  id: number
  note_id: number
  name: string
  type: NotePropertyType
  value_string: string | null
  value_numeric: number | null
  value_boolean: boolean | null
  value_datetime: string | null
  value_json: unknown | null
}

export interface CollectionTag {
  id: number
  name: string
}

export interface CollectionNote {
  id: number
  path: string
  title: string
  properties: RawNoteProperty[]
  tags?: CollectionTag[]
  checklist_total?: number
  checklist_done?: number
  comments_count?: number
}

export interface BoardColumnConfig {
  key: string
  label?: string | null
  color?: string | null
  wip_limit?: number | null
  collapsed?: boolean
  auto_archive?: boolean
}

export interface NoteActivityEntry {
  id: number
  event: string
  metadata: Record<string, unknown> | null
  actor_subject_id: string | null
  created_at: string | null
}

export interface NoteChecklistItem {
  id: number
  note_id: number
  text: string
  done: boolean
  sort_position: number
}

export interface Board {
  id: number
  workspace_id: number
  name: string
  group_property: string | null
  swimlane_property: string | null
  filter_property: string | null
  filter_value: string | null
  column_config: BoardColumnConfig[] | null
  sort_position: number
}

export interface CollectionPage {
  data: CollectionNote[]
  current_page: number
  last_page: number
  per_page: number
  total: number
}

export interface AttachmentItem {
  id: number
  workspace_id: number
  path: string
  mime: string
  size: number
  created_at: string
  url: string
}
