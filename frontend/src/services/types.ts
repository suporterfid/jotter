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

export interface NoteDetail extends NoteMeta {
  content: string
  backlinks: Backlink[]
}

export interface SearchResult {
  note_id: number
  title: string
  path: string
  snippet: string
  score: number
}

export interface AuthUser {
  subject_id: string
  email: string
  name: string
  is_admin: boolean
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
