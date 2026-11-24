/**
 * «برامجي»: what the server tells a person about the programme groups they supervise or teach (GET /me/duties), and the
 * small decisions the page makes from it. Kept free of the screen so the decisions can be tested alone.
 */
export type Bi = { ar: string; en: string }
export type Capacity = 'supervisor' | 'trainer'
export type Phase = 'running' | 'upcoming' | 'ended'
/** Where a session stands against the clock: over, happening now, later today, or on a later day. */
export type When = 'past' | 'now' | 'today' | 'later'

export type DutySession = {
  id: string; sequence: number | null; title_ar: string | null; title_en: string | null; starts_at: string; ends_at: string; status: string; mode: string | null
  when: When; focus: boolean; place: Bi | null; present: number; expected: number
}
export type FormState = { url: string; enabled: boolean; status: string; opens_at?: string | null; mode?: string; expected?: number; assigned?: number; answered: number }
export type DutyGroup = {
  id: string; capacities: Capacity[]; phase: Phase
  program: { id: string; code: string; title_ar: string; title_en: string; status: string; delivery_mode: string }
  group: { id: string; title_ar: string; title_en: string; status: string; only: boolean }
  from: string | null; to: string | null
  supervisor: (Bi & { me: boolean }) | null; trainers: (Bi & { me: boolean })[]; rooms: Bi[]; trainees: number
  sessions: DutySession[]
  forms: { trainee: FormState; reflection: FormState } | null
}
export type Duties = { groups: DutyGroup[]; summary: { total: number; supervising: number; teaching: number; running: number; upcoming: number; today: number }; now: string }

export type Filter = { capacity: 'all' | Capacity; phase: 'all' | Phase }

/** The session of a group to act on: the one the server marked (now, else the next, else the last held). */
export const focusOf = (g: Pick<DutyGroup, 'sessions'>): DutySession | null => g.sessions.find((s) => s.focus) ?? null

/** How much of the room came, as a whole percentage; nobody expected is 0, never a division by zero. */
export const attendancePercent = (present: number, expected: number): number => (expected > 0 ? Math.min(100, Math.round((present / expected) * 100)) : 0)

/** The attendance code is shown for a session of today: the one running now, or one later today. */
export const showsQr = (s: Pick<DutySession, 'when' | 'mode'>): boolean => (s.when === 'now' || s.when === 'today') && s.mode !== 'online'

/** The groups left after the two filters of the page. */
export function filterGroups(groups: readonly DutyGroup[], f: Filter): DutyGroup[] {
  return groups.filter((g) => (f.capacity === 'all' || g.capacities.includes(f.capacity)) && (f.phase === 'all' || g.phase === f.phase))
}

/**
 * The one session to put in front of the person on their dashboard: the session happening now, else the soonest that has
 * not ended, across every group. Nothing when every session is over.
 */
export function nextUp(groups: readonly DutyGroup[]): { group: DutyGroup; session: DutySession } | null {
  let best: { group: DutyGroup; session: DutySession } | null = null
  for (const group of groups) {
    for (const session of group.sessions) {
      if (session.when === 'past') continue
      if (session.when === 'now') return { group, session }
      if (!best || session.starts_at < best.session.starts_at) best = { group, session }
    }
  }
  return best
}

/** Which wording counts today's sessions: none, one, two, a few (3 to 10) or many, as Arabic counts them. */
export const todayKey = (n: number): 'today0' | 'today1' | 'today2' | 'todayFew' | 'todayMany' => (n <= 0 ? 'today0' : n === 1 ? 'today1' : n === 2 ? 'today2' : n <= 10 ? 'todayFew' : 'todayMany')

/** A person's own entry first ("أنت"), then the others, so a long list of trainers starts with what matters to the reader. */
export const meFirst = <T extends { me: boolean }>(people: readonly T[]): T[] => [...people].sort((a, b) => Number(b.me) - Number(a.me))
