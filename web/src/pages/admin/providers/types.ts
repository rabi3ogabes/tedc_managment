export type ConnectionState = 'connected' | 'training' | 'attention' | 'down' | 'not_set_up' | 'off'

export interface Localised {
  ar: string
  en: string
}

export interface ProviderField {
  k: string
  type?: string
  secret?: boolean
  required?: boolean
  default?: unknown
  options?: string[]
  hint?: string
  doc?: Localised
}

export interface ProviderRun {
  status: 'running' | 'ok' | 'partial' | 'failed'
  trigger: 'schedule' | 'manual'
  started_at: string
  finished_at: string | null
  courses_seen: number
  courses_added: number
  completions_seen: number
  completions_applied: number
  issues_found: number
  error: string | null
}

export interface Provider {
  key: string
  name: Localised
  driver: 'http' | 'fake'
  drivers: string[]
  enabled: boolean
  connection: ConnectionState
  missing: string[]
  health: 'ok' | 'degraded' | 'down' | 'unknown'
  last_check_at: string | null
  last_error: string | null
  latency_ms: number | null
  fields: ProviderField[]
  settings: Record<string, unknown>
  secrets_set: Record<string, boolean>
  last_run: ProviderRun | null
  catalogue: { total: number; imported: number; retired: number }
  open_issues: number
}

export interface CatalogueCourse {
  id: string
  provider: string
  external_id: string
  title: string
  url: string
  hours: number
  level: string | null
  language: string | null
  image_url: string | null
  status: 'available' | 'retired' | 'removed'
  program_id: string | null
  synced_at: string | null
  summary: string | null
}

export interface CompletionIssue {
  id: string
  provider: string
  external_course_id: string
  course_title: string | null
  learner_ref: string
  reason: 'unknown_employee' | 'not_registered'
  occurrences: number
  first_seen_at: string
  last_seen_at: string
  resolved_at: string | null
}

export interface Paged<T> {
  data: T[]
  current_page: number
  last_page: number
  total: number
}
