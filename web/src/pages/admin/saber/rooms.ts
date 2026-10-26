/** Shapes and helpers of the rooms month (backend: Saber\Meetings::month). */
export type MonthRoom = { id: string; name: string; type: 'training' | 'meeting'; tag: string | null; capacity: number | null; building: string | null; floor: string | null; layout: string | null }
export type MonthDay = { date: string; kind: string; training_allowed: boolean; events_allowed: boolean; label: string | null }
export type MonthEvent = {
  type: 'session' | 'booking'; id: string; room_id: string; date: string; start: string; end: string; title: string | null
  program_id?: string; group?: string | null; color?: string | null; day?: number | null; trainer?: string | null
  purpose?: string; requested_by?: string | null; reason?: string | null; booked_by?: string | null; booked_by_id?: string | null; series_id?: string | null; series_size?: number; recurrence?: string | null
}
export type MonthData = { month: string; hours: { first: number; last: number }; rooms: MonthRoom[]; days: MonthDay[]; events: MonthEvent[] }

/** Which of the day's hour slots (first … last-1) an event list holds. */
export function slotsOf(events: MonthEvent[], first: number, last: number): boolean[] {
  return Array.from({ length: last - first }, (_, i) => {
    const h = first + i
    return events.some((e) => {
      const [sh, sm] = e.start.split(':').map(Number)
      const [eh, em] = e.end.split(':').map(Number)
      return sh * 60 + sm < (h + 1) * 60 && eh * 60 + em > h * 60
    })
  })
}

export type RoomState = 'free' | 'partial' | 'full' | 'closed'

export function roomState(day: MonthDay | undefined, events: MonthEvent[], first: number, last: number): RoomState {
  if (day && !day.events_allowed && events.length === 0) return 'closed'
  const slots = slotsOf(events, first, last)
  const n = slots.filter(Boolean).length
  return n === 0 ? 'free' : n === slots.length ? 'full' : 'partial'
}

export const STATE_STYLE: Record<RoomState, string> = {
  free: 'bg-emerald-50 text-emerald-700 ring-emerald-600/20',
  partial: 'bg-amber-50 text-amber-700 ring-amber-600/20',
  full: 'bg-rose-50 text-rose-700 ring-rose-600/20',
  closed: 'bg-slate-100 text-slate-500 ring-slate-500/15',
}

export const monthOf = (iso: string) => iso.slice(0, 7)

export const isoToday = () => new Date(Date.now() - new Date().getTimezoneOffset() * 60_000).toISOString().slice(0, 10)
