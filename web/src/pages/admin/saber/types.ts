/** Shapes returned by /admin/saber (backend: SaberController). */
export type Day = {
  idx: number; extra: boolean; session_id: string | null; date: string | null; start_time: string | null; end_time: string | null
  room_id: string | null; trainer_id: string | null; status: string | null; locked: boolean
}

export type Group = {
  id: string; program_id: string; number: number; title: string | null; color: string | null; term: 'first' | 'second' | 'year' | null
  term_effective: 'first' | 'second' | 'year' | null; status: string; cancelled: boolean; validated_at: string | null; validated_by_name: string | null; days: Day[]
}

export type Program = {
  id: string; code: string; title: string; title_en: string | null; type: string | null; method: string | null; hours: number; days: number; status: string; cancelled: boolean
  kit_ready: boolean; kit_ready_set: boolean; survey_link: string | null; survey_edit_link: string | null; supervisor: { id: string; name: string } | null
  start_date: string | null; end_date: string | null; lights: { total: number; dates: number; rooms: number; trainers: number }; groups: Group[]
}

export type Option = { id: string; name: string }
export type RoomOption = Option & { capacity: number | null; building: string | null; floor: string | null; tag: string | null }

export type Overview = {
  stats: { programs_month: number; awaiting_approval: number; booked_rooms: number; trainers: number }
  rooms: RoomOption[]; trainers: Option[]; supervisors: Option[]; palette: string[]
  settings: { supervisor_edit_enabled: boolean; group_delete_enabled: boolean }
  day: { hours: number; day_start: string; day_end: string }
  can: Record<string, boolean>
}

export type Notice = { type: 'success' | 'warning' | 'info'; text: string }

export type Availability = { day: { training_allowed: boolean; kind: string; entry: { title: string | null } | null }; busy_rooms: Record<string, string>; busy_trainers: string[] }

/** Recomputes a programme's status lights after one of its groups changed. */
export function withGroup(p: Program, g: Group): Program {
  const groups = p.groups.map((x) => (x.id === g.id ? g : x))
  return { ...p, groups, lights: lightsOf(groups, p.days) }
}

export function lightsOf(groups: Group[], days: number): Program['lights'] {
  const all = groups.flatMap((g) => g.days.filter((d) => !d.extra))
  return { total: groups.length * days, dates: all.filter((d) => d.date).length, rooms: all.filter((d) => d.room_id).length, trainers: all.filter((d) => d.trainer_id).length }
}
