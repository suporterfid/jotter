export interface Workspace {
  id: number
  tenant_id: number
  slug: string
  name: string
}

export interface NoteMeta {
  id: number
  path: string
  title: string
  frontmatter: Record<string, unknown> | null
  updated_at: string
}

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

export interface NotificationItem {
  id: number
  workspace_id: number
  user_id: number
  type: string
  title: string
  data: { actor_id?: string; comment_snippet?: string; [key: string]: unknown } | null
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

export interface CollectionNote {
  id: number
  path: string
  title: string
  properties: RawNoteProperty[]
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
