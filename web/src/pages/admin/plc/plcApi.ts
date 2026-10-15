/** Types and small helpers shared by the PLC hub screens. */
export type PlcState = 'draft' | 'submitted' | 'needs_changes' | 'approved' | 'scheduled' | 'ready' | 'in_progress' | 'completed' | 'awaiting_evaluation' | 'closed' | 'archived' | 'rejected' | 'cancelled'
export type PlcSource = 'rased' | 'centre' | 'entity' | 'school'
export type Scenario = 'shared' | 'internal'

export type SchoolRef = { id: string; name: string; name_en?: string | null; gender?: string | null; region?: string | null; role?: string; status?: string }
export type WorkshopSummary = {
  id: string; code: string; title_ar: string; title_en?: string | null; delivery_mode: string; start_date?: string | null; end_date?: string | null; capacity?: number
  scenario: Scenario; source: PlcSource; source_ref?: string | null; priority: string; state: PlcState; state_changed_at?: string | null
  host_school?: SchoolRef | null; community?: { id: string; title: string } | null; coordinator?: string | null; updated_at?: string
}
export type Step = { key: string; permission: string; status: 'pending' | 'approved' | 'rejected'; by?: string | null; at?: string | null; note?: string | null }
export type WorkshopDetail = WorkshopSummary & {
  summary_ar?: string | null; summary_en?: string | null; objectives?: string[] | null; total_hours: number; program_status: string
  organiser?: string | null; expected_participants?: number | null; prerequisites?: string | null; outcomes?: string | null; target_audience?: string | null; domain?: string | null
  need?: { id: string; title: string; reference_no?: string | null; source: PlcSource } | null; coordinator_user?: { id: string; name: string } | null
  schools: SchoolRef[]; approval_path?: Step[] | null; pending_step?: { key: string; permission: string; mine: boolean } | null
  can: { edit: boolean; submit: boolean; review: boolean; cancel: boolean }; transitions: PlcState[]
  decisions: { kind: string; decision: string; reason?: string | null; data?: Record<string, unknown> | null; by?: string | null; at: string }[]
  decision_note?: string | null; created_by?: string | null
  sessions?: { count: number; next?: string | null }
}
export type Need = {
  id: string; source: PlcSource; reference_no?: string | null; title: string; description?: string | null; domain?: string | null; target_audience?: string | null
  received_at?: string | null; review_status: 'new' | 'under_review' | 'converted' | 'declined'; source_url?: string | null; notes?: string | null; school_ids: string[]
  entity_name?: string | null; proposer_name?: string | null; justification?: string | null; expected_outcomes?: string | null; workshops: number
}
export type Community = {
  id: string; code: string; title_ar: string; title_en?: string | null; description_ar?: string | null; domain?: string | null; status: 'active' | 'archived'
  lead?: string | null; host_school?: SchoolRef | null; schools: number; workshops: number; school_list?: SchoolRef[] | null
}
export type Catalog = {
  states: PlcState[]; sources: PlcSource[]; scenarios: Scenario[]; priorities: string[]; delivery_modes: string[]; rased_integration: boolean
  approval_paths: Record<Scenario, { key: string; permission: string }[]>
  can: { manage: boolean; internal: boolean; approve: boolean; approve_school: boolean; communities: boolean; settings: boolean; exceptions: boolean }
}

/** Badge tone per workflow state (keys of the UI kit's tone map). */
export const STATE_TONE: Record<PlcState, 'green' | 'red' | 'amber' | 'blue' | 'navy' | 'gold' | 'gray'> = {
  draft: 'gray', submitted: 'amber', needs_changes: 'amber', approved: 'green', scheduled: 'blue', ready: 'blue', in_progress: 'gold',
  completed: 'green', awaiting_evaluation: 'amber', closed: 'navy', archived: 'gray', rejected: 'red', cancelled: 'red',
}

export const fmtDate = (d?: string | null, locale = 'ar') => (d ? new Date(d).toLocaleDateString(locale === 'en' ? 'en-GB' : 'ar-QA', { year: 'numeric', month: 'short', day: 'numeric' }) : '')
