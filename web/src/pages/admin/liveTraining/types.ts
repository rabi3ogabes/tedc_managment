import type { SeatingLayout } from './layout'

export type RoomState = 'live' | 'soon' | 'later' | 'ended' | 'idle'
export type SessionState = 'live' | 'upcoming' | 'ended'

export type TrainerBrief = { id: string; name: string; role: string; photo: string | null; source: string | null; rating: number | null; programs_count: number; checked_in: boolean }
export type Person = { id: string; name: string; email: string | null }
export type KitBrief = { id: string; code: string | null; title: string; version: number; status: string }
export type Counts = { expected: number; present: number; late: number; absent: number; left: number; percent: number }
export type PresentTrainee = { id: string; name: string; status: 'present' | 'late'; at: string | null; seat: string | null }

export type SessionBrief = {
  id: string; title: string | null; sequence: number | null; mode: string; state: SessionState; starts_at: string; ends_at: string; location: string | null
  program: { id: string; code: string | null; title: string | null }
  group: { id: string; title: string } | null
  trainers: TrainerBrief[]; supervisor: Person | null; kit: KitBrief | null
  counts: Counts; seating: SeatingLayout | null; present: PresentTrainee[]
}

export type BoardRoom = {
  id: string; name: string; code: string | null; floor: string | null; office: string | null; building: string | null; capacity: number; room_type: string
  state: RoomState; focus_session_id: string | null; sessions: SessionBrief[]
}

export type Arrival = { id: string; name: string; status: 'present' | 'late'; at: string; session_id: string; room_id: string | null; room: string | null; program: string | null }

export type Board = {
  date: string; is_today: boolean; now: string; timezone: string
  totals: { rooms: number; rooms_live: number; sessions: number; sessions_live: number; expected: number; present: number; late: number; in_rooms_now: number; percent: number }
  rooms: BoardRoom[]; unplaced: SessionBrief[]; arrivals: Arrival[]; strip: { date: string; sessions: number }[]
}

export type RosterRow = { id: string; name: string; school: string | null; status: 'present' | 'late' | 'left' | 'excused' | 'expected' | 'absent'; check_in_at: string | null; check_out_at: string | null; method: string | null; seat: string | null }

export type Card = {
  session: { id: string; title: string | null; number: number | null; of: number; starts_at: string; ends_at: string; minutes: number; mode: string | null; state: SessionState; location: string | null; attendance_locked: boolean }
  program: { id: string; code: string | null; title: string | null; summary: string | null; objectives: string[]; category: string | null; type: string | null; level: string | null; delivery_mode: string | null; total_hours: number | null; start_date: string | null; end_date: string | null; units: { title: string | null; hours: number | null }[] }
  group: { id: string; title: string; code: string | null; capacity: number | null; start_date: string | null; end_date: string | null; status: string } | null
  room: { id: string; name: string; code: string | null; floor: string | null; office: string | null; building: string | null; capacity: number; equipment: { key: string; qty: number; label: string }[] } | null
  trainers: TrainerBrief[]; supervisor: Person | null; kit: KitBrief | null; counts: Counts; seating: SeatingLayout | null
  schedule: { id: string; title: string | null; starts_at: string; ends_at: string; state: SessionState; room: string | null; current: boolean }[]
  roster: RosterRow[]; now: string
}

/** One room as the 3D scene draws it: already reduced to the session on show. */
export type SceneRoom = {
  id: string; name: string; floor: string | null; state: RoomState; capacity: number
  /** Changes when the room shows another session, so the scene does not animate people who were already there. */
  sessionKey: string
  title: string | null; status: string; expected: number; presentCount: number
  seating: SeatingLayout | null
  trainers: { id: string; name: string; here: boolean }[]
  supervisor: string | null
  present: { id: string; name: string; late: boolean; seat: string | null }[]
}

export type SceneText = { trainer: string; supervisor: string; floor: (name: string) => string }
