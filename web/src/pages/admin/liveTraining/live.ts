import type { BoardRoom, RoomState, SessionBrief, SessionState } from './types'

/**
 * The board between two answers of the server. A session starts, ends or comes within the hour on the clock, not when the next refresh
 * happens to arrive: these functions read the same rules as the server (LiveTrainingService) with the time of the browser, so a room
 * lights up the second its session starts. Plain arithmetic, no React.
 */

/** A room whose next session starts within this many minutes is «starting soon» (the server's SOON_MINUTES). */
export const SOON_MINUTES = 60
/** How long somebody who has just checked in is shown as new. */
export const FRESH_MS = 20_000

const MIN = 60_000
const at = (iso: string) => Date.parse(iso)

type Span = Pick<SessionBrief, 'starts_at' | 'ends_at'>

/** Where a session stands at the given moment. */
export const phaseOf = (s: Span, nowMs: number): SessionState => at(s.ends_at) <= nowMs ? 'ended' : at(s.starts_at) <= nowMs ? 'live' : 'upcoming'

/**
 * The last moment, up to now, at which something on the board changed state: a start, an end, or the hour before a start. Between two
 * such moments the board is the same, so the rooms are worked out again only when this number moves (not every second).
 */
export function lastBoundary(rooms: BoardRoom[], unplaced: SessionBrief[], nowMs: number): number {
  let last = 0
  const see = (ms: number) => { if (ms <= nowMs && ms > last) last = ms }
  const each = (s: SessionBrief) => { see(at(s.starts_at)); see(at(s.ends_at)); see(at(s.starts_at) - SOON_MINUTES * MIN) }
  rooms.forEach((r) => r.sessions.forEach(each))
  unplaced.forEach(each)
  return last
}

/** A session with its state read on the clock (the same object when nothing changed). */
export const sessionOnTheClock = (s: SessionBrief, nowMs: number): SessionBrief => {
  const state = phaseOf(s, nowMs)
  return state === s.state ? s : { ...s, state }
}

/** The rooms with their state, and the session each one shows, read on the clock: running, else the next one, else the last one. */
export function roomsOnTheClock(rooms: BoardRoom[], nowMs: number): BoardRoom[] {
  return rooms.map((room) => {
    const sessions = room.sessions.map((s) => sessionOnTheClock(s, nowMs))
    const live = sessions.find((s) => s.state === 'live')
    const next = sessions.find((s) => s.state === 'upcoming')
    const last = [...sessions].reverse().find((s) => s.state === 'ended')
    const state: RoomState = live ? 'live' : next ? (at(next.starts_at) - nowMs <= SOON_MINUTES * MIN ? 'soon' : 'later') : last ? 'ended' : 'idle'
    return { ...room, sessions, state, focus_session_id: (live ?? next ?? last)?.id ?? null }
  })
}

export type Timing = {
  phase: SessionState
  /** Share of the session already behind us, 0 to 1. */
  share: number
  /** Time left until the end (running session) or until the start (coming session), in milliseconds; 0 once it has ended. */
  leftMs: number
}

export function timing(s: Span, nowMs: number): Timing {
  const start = at(s.starts_at)
  const end = at(s.ends_at)
  const phase = phaseOf(s, nowMs)
  const span = end - start
  return {
    phase,
    share: phase === 'ended' ? 1 : phase === 'upcoming' || span <= 0 ? 0 : Math.min(1, Math.max(0, (nowMs - start) / span)),
    leftMs: phase === 'live' ? end - nowMs : phase === 'upcoming' ? start - nowMs : 0,
  }
}

/** A running clock: 1:24:09 above an hour, 24:09 under it. Always Western digits, so the figures do not jump in width. */
export function countdown(ms: number): string {
  const total = Math.max(0, Math.floor(ms / 1000))
  const h = Math.floor(total / 3600)
  const m = Math.floor((total % 3600) / 60)
  const s = total % 60
  const two = (n: number) => String(n).padStart(2, '0')
  return h > 0 ? `${h}:${two(m)}:${two(s)}` : `${two(m)}:${two(s)}`
}

export type SignalKey = 'noTrainer' | 'trainerAbsent' | 'nobodyHere' | 'lowAttendance' | 'overCapacity' | 'endingSoon' | 'startingSoon'
export type Signal = { key: SignalKey; tone: 'alert' | 'warn' | 'info'; n?: number }

/** Minutes after the start before a missing trainer, an empty room or a thin room is worth a word. */
export const GRACE = { trainer: 5, nobody: 10, low: 15 }
export const LOW_SHARE = 0.5
export const ENDING_MINUTES = 10
export const STARTING_MINUTES = 15

/**
 * What deserves a look in a room right now, most pressing first. Only for a session that is running or about to start: a session that
 * has ended is history, and its figures are on the training card. `trainerCheckIns` is `trainersCheckIn()` of the day.
 */
export function signalsFor(room: Pick<BoardRoom, 'capacity'>, s: SessionBrief | null, nowMs: number, trainerCheckIns = true): Signal[] {
  if (!s) return []
  const { phase, leftMs } = timing(s, nowMs)
  if (phase === 'ended') return []
  const out: Signal[] = []
  const since = (nowMs - at(s.starts_at)) / MIN
  const near = phase === 'live' || leftMs <= SOON_MINUTES * MIN

  if (s.trainers.length === 0) { if (near) out.push({ key: 'noTrainer', tone: phase === 'live' ? 'alert' : 'warn' }) }
  else if (trainerCheckIns && phase === 'live' && since >= GRACE.trainer && !s.trainers.some((t) => t.checked_in)) out.push({ key: 'trainerAbsent', tone: 'alert' })

  if (phase === 'live' && s.counts.expected > 0) {
    if (s.counts.present === 0 && since >= GRACE.nobody) out.push({ key: 'nobodyHere', tone: 'alert' })
    else if (since >= GRACE.low && s.counts.present / s.counts.expected < LOW_SHARE) out.push({ key: 'lowAttendance', tone: 'warn', n: Math.round((s.counts.present / s.counts.expected) * 100) })
  }
  if (near && room.capacity > 0 && s.counts.expected > room.capacity) out.push({ key: 'overCapacity', tone: 'warn', n: s.counts.expected - room.capacity })

  if (phase === 'live' && leftMs <= ENDING_MINUTES * MIN) out.push({ key: 'endingSoon', tone: 'info', n: Math.max(1, Math.ceil(leftMs / MIN)) })
  if (phase === 'upcoming' && leftMs <= STARTING_MINUTES * MIN) out.push({ key: 'startingSoon', tone: 'info', n: Math.max(1, Math.ceil(leftMs / MIN)) })

  const rank = { alert: 0, warn: 1, info: 2 }
  return out.sort((a, b) => rank[a.tone] - rank[b.tone])
}

/**
 * Whether the centre records its trainers' attendance on this day: at least one trainer has checked in somewhere. Where nobody ever does,
 * «the trainer has not checked in» would be raised in every room and teach people to ignore it, so it is kept for the days it means something.
 */
export const trainersCheckIn = (rooms: BoardRoom[], unplaced: SessionBrief[]) =>
  [...rooms.flatMap((r) => r.sessions), ...unplaced].some((s) => s.trainers.some((t) => t.checked_in))

/** A room that somebody should look at: something is wrong or thin, not merely about to start or end. */
export const needsAttention = (signals: Signal[]) => signals.some((s) => s.tone !== 'info')

/**
 * How often to ask the server again. Every 5 seconds while a session is running, about to start or just over; every 15 while the day
 * still has sessions ahead; every 30 once nothing more can happen in a room. The clock on the page keeps running in between.
 */
export function pollEvery(rooms: BoardRoom[], unplaced: SessionBrief[], nowMs: number): number {
  let ahead = false
  let busy = false
  const each = (s: SessionBrief) => {
    const start = at(s.starts_at)
    const end = at(s.ends_at)
    if (end > nowMs) ahead = true
    if (start - STARTING_MINUTES * MIN <= nowMs && nowMs <= end + 5 * MIN) busy = true
  }
  rooms.forEach((r) => r.sessions.forEach(each))
  unplaced.forEach(each)
  return busy ? 5_000 : ahead ? 15_000 : 30_000
}

/** Checked in within the last few seconds: drawn as new. A time slightly in the future (a clock that is ahead) still counts. */
export const isFresh = (iso: string | null | undefined, nowMs: number) => !!iso && nowMs - at(iso) < FRESH_MS && nowMs - at(iso) > -MIN
