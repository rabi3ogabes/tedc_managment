export type KitStatus = 'draft' | 'in_development' | 'in_review' | 'changes_requested' | 'approved' | 'published' | 'archived'
export type KitRole = 'developer' | 'qa' | 'reviewer' | 'viewer'
export type FileKind = 'presentation' | 'document' | 'pdf' | 'image' | 'video' | 'other'
export type FileCategory = 'presentation' | 'trainer_guide' | 'handout' | 'assessment' | 'activity' | 'media' | 'other'

export const KIT_STATUSES: KitStatus[] = ['draft', 'in_development', 'in_review', 'changes_requested', 'approved', 'published']
export const FILE_CATEGORIES: FileCategory[] = ['presentation', 'trainer_guide', 'handout', 'assessment', 'activity', 'media', 'other']

export type KitMember = { user_id: string; name: string | null; email?: string | null; role: KitRole }

export type KitDelivery = 'standard' | 'online'

export type Kit = {
  id: string; code: string; title: string; title_ar: string; title_en: string; description?: string | null; description_ar?: string | null; description_en?: string | null
  status: KitStatus; delivery?: KitDelivery; audience?: string | null; duration_hours: number; objectives: string[]; tags: string[]; version: number; review_round: number
  program?: { id: string; code: string; title: string } | null; program_id?: string | null; category?: { id: string; name: string; color?: string } | null; category_id?: string | null
  owner?: { id: string; name: string } | null; owner_id: string; members?: KitMember[]; due_at?: string | null; submitted_at?: string | null; approved_at?: string | null; published_at?: string | null
  files_count?: number; open_comments?: number; blocking_comments?: number; progress?: number; my_role: KitRole | null
  can: { manage: boolean; edit: boolean; review: boolean; comment: boolean; generate: boolean; publish: boolean } | null
  created_at?: string; updated_at?: string
}

export type KitFile = {
  id: string; kit_id: string; name: string; original_name?: string | null; kind: FileKind; category: FileCategory; source: string; mime?: string | null; size: number
  version: number; revision: number; is_deck: boolean; slides_count: number | null; notes?: string | null; has_binary: boolean; url?: string; open_comments?: number
  uploaded_by?: string | null; updated_by?: string | null; created_at?: string; updated_at?: string
}

export type KitDetail = Kit & {
  files: KitFile[]
  completeness: { percent: number; items: { key: string; weight: number; done: boolean }[]; blocking_comments: number }
  review: { round: number; status: string; submitted_by?: string | null; submitted_at?: string | null; decided_by?: string | null; decided_at?: string | null; note?: string | null; summary?: Record<string, number> | null } | null
}

export type Severity = 'info' | 'minor' | 'major' | 'critical'
export type CommentStatus = 'open' | 'addressed' | 'resolved'
export type CommentCategory = 'content' | 'design' | 'language' | 'accuracy' | 'alignment' | 'accessibility' | 'other'
export const SEVERITIES: Severity[] = ['info', 'minor', 'major', 'critical']
export const CATEGORIES: CommentCategory[] = ['content', 'design', 'language', 'accuracy', 'alignment', 'accessibility', 'other']

export type Anchor = {
  type: 'slide' | 'element' | 'point' | 'page' | 'text' | 'time' | 'file'
  slide_id?: string | null; element_id?: string | null; slide_index?: number | null; x?: number | null; y?: number | null
  page?: number | null; quote?: string | null; at?: number | null; paragraph?: number | null
}

export type KitComment = {
  id: string; kit_id: string; file_id: string | null; file?: { id: string; name: string; kind: string } | null; parent_id: string | null
  author: { id: string; name: string; roles: string[] }; author_id: string; body: string; anchor: Anchor | null; file_version: number | null
  category: CommentCategory; severity: Severity; status: CommentStatus; assignee?: { id: string; name: string } | null; assignee_id: string | null
  resolved_at?: string | null; review_round: number; mentions: string[]; replies: KitComment[]; mine: boolean; created_at: string; updated_at: string
}

export type Activity = { id: string; kit_id: string; action: string; subject_type?: string | null; subject_id?: string | null; meta: Record<string, unknown>; user: { id: string; name: string } | null; created_at: string; kit?: { id: string; code: string; title_ar: string; title_en: string } | null }

export type Person = { id: string; name: string; email: string; suggested_role: KitRole; roles: string[] }

export type KitStats = {
  total: number; by_delivery: Record<KitDelivery, number>; by_status: Record<string, number>; awaiting_review: number; awaiting_my_review: number; needs_my_changes: number; my_open_comments: number; overdue: number; open_comments: number; recent: Activity[]
}

export type Suggestion = { key: string; action: 'generate_deck' | 'generate_video' | 'generate_image' | 'upload'; title_ar: string; title_en: string; hint_ar: string; hint_en: string; params: Record<string, unknown> }

export type Finding = { id: string; severity: Severity; category: CommentCategory; title: string; detail: string; suggestion: string; slide_id: string | null; slide_index: number | null; element_id: string | null }
export type Analysis = { score: number; findings: Finding[]; ai: boolean; stats: { slides: number; words: number; avg_words: number; images: number; notes: number } }

export type VersionRow = { id: string; version: number; source: string; note: string | null; size: number; slides: number | null; has_binary: boolean; author: string | null; created_at: string }

export type Asset = { id: string; name?: string | null; mime: string; width?: number | null; height?: number | null; prompt?: string | null; source: string; provider?: string | null; url: string }
