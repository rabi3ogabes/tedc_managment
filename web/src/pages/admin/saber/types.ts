import type { Forms } from './FormLinks'

/** Shapes returned by /admin/saber (backend: SaberController). */
export type Day = {
  idx: number; extra: boolean; session_id: string | null; date: string | null; start_time: string | null; end_time: string | null
  room_id: string | null; trainer_id: string | null; status: string | null; locked: boolean; changed?: boolean
}

export type Group = {
  id: string; program_id: string; number: number; title: string | null; color: string | null; term: 'first' | 'second' | 'year' | null
  term_effective: 'first' | 'second' | 'year' | null; status: string; cancelled: boolean; validated_at: string | null; validated_by_name: string | null; days: Day[]
  live?: boolean; changed?: boolean; supervisor_id?: string | null
}

export type Program = {
  id: string; code: string; title: string; title_en: string | null; type: string | null; method: string | null; hours: number; days: number; status: string; cancelled: boolean
  kit_ready: boolean; kit_ready_set: boolean; supervisor: { id: string; name: string } | null
  start_date: string | null; end_date: string | null; lights: { total: number; dates: number; rooms: number; trainers: number }; groups: Group[]
  draft?: DraftInfo | null
}

export type DraftInfo = {
  id: string; status: 'draft' | 'submitted' | 'published' | 'discarded'; source: string; program: { id: string; code: string; title: string; status: string }
  author: string | null; editor: string | null; submitted_by: string | null; reviewed_by: string | null; created_at: string | null; updated_at: string | null
  submitted_at: string | null; reviewed_at: string | null; review_note: string | null; mine: boolean; counts?: { groups: number; days: number; fields: number } | null
}

export type Option = { id: string; name: string; pending?: boolean }
export type RoomOption = Option & { capacity: number | null; building: string | null; floor: string | null; tag: string | null }

export type Overview = {
  stats: { programs_month: number; awaiting_approval: number; booked_rooms: number; trainers: number }
  rooms: RoomOption[]; trainers: Option[]; supervisors: Option[]; palette: string[]; offday_pending: number; me: string; drafts: { mine: number; submitted: number }
  settings: { supervisor_edit_enabled: boolean; group_delete_enabled: boolean }
  day: { hours: number; day_start: string; day_end: string }
  can: Record<string, boolean>
}

export type Notice = { type: 'success' | 'warning' | 'info'; text: string }

export type Availability = { day: { training_allowed: boolean; kind: string; entry: { title: string | null } | null }; busy_rooms: Record<string, string>; busy_trainers: string[]; trainer_holders?: Record<string, string> }

/** Recomputes a programme's status lights after one of its groups changed. */
export function withGroup(p: Program, g: Group): Program {
  const groups = p.groups.map((x) => (x.id === g.id ? g : x))
  return { ...p, groups, lights: lightsOf(groups, p.days) }
}

export function lightsOf(groups: Group[], days: number): Program['lights'] {
  const all = groups.flatMap((g) => g.days.filter((d) => !d.extra))
  return { total: groups.length * days, dates: all.filter((d) => d.date).length, rooms: all.filter((d) => d.room_id).length, trainers: all.filter((d) => d.trainer_id).length }
}

export type PlanRow = { date: string; status: 'free' | 'busy' | 'off'; label: string | null; allow_events: boolean; weekly: boolean; busy: string[] }

export type OffdayRequest = {
  id: string; date: string; reason: string; status: 'pending' | 'approved' | 'rejected'; day_idx: number | null
  program: { id: string; title: string } | null; group: { id: string; number: number; title: string | null } | null
  requested_by: string | null; decided_by: string | null; decided_at: string | null; decision_note: string | null; created_at: string | null
}

export type CancelledList = {
  programs: { id: string; code: string; title: string; groups: number; days: number; reason: string | null; requested_by: string | null; cancelled_at: string | null; cancelled_by: string | null; has_attachment: boolean }[]
  groups: { id: string; number: number; title: string | null; program: { id: string; code: string; title: string }; reason: string | null; requested_by: string | null; cancelled_at: string | null; cancelled_by: string | null; has_attachment: boolean }[]
}

export type DetailDay = Day & { room: string | null; trainer: string | null }
export type Details = Omit<Program, 'groups'> & {
  summary: string | null; objectives: unknown; audience: unknown; level: string | null; capacity: number | null; category: string | null
  kits: { id: string; title: string; status: string }[]
  forms?: Forms
  cancel: { at: string; reason: string | null; requested_by: string | null; has_attachment: boolean } | null
  groups: (Omit<Group, 'days'> & { registrations: number; capacity: number | null; status_reason: string | null; days: DetailDay[]; cancel: { at: string; requested_by: string | null; has_attachment: boolean } | null })[]
}

/** The day a closed-day request is about. */
export type OffdayTarget = { date: string; groupId: string; idx: number; programTitle: string; groupLabel: string }
