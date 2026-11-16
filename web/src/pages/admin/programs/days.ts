/**
 * The programme list by day: reading a date typed in the search box, and moving from one day of the row to the next.
 * Plain functions, no React: the clock is given, so they can be tested without a browser.
 */

export type DayCell = {
  date: string
  weekday: number
  /** Programmes that train on the day (with the filters of the list). 0 is the day shown with its light off. */
  programs: number
  sessions: number
  /** A day off (weekend, weekly holiday, vacation). It is in the row only because it has training, or because it was picked. */
  off: boolean
  kind: 'workday' | 'weekend' | 'vacation' | 'exam' | 'normal'
  title: string | null
}

export type DayWindow = { around: string; today: string; days: DayCell[]; prev: string | null; next: string | null }

const pad = (n: number) => String(n).padStart(2, '0')
export const isoOf = (y: number, m: number, d: number) => `${y}-${pad(m)}-${pad(d)}`

/** A real day of the calendar (31 April is not one). */
const real = (y: number, m: number, d: number) => {
  if (y < 2000 || y > 2100 || m < 1 || m > 12 || d < 1 || d > 31) return false
  const date = new Date(Date.UTC(y, m - 1, d))
  return date.getUTCFullYear() === y && date.getUTCMonth() === m - 1 && date.getUTCDate() === d
}

/** Arabic-Indic and Persian digits typed from an Arabic keyboard, as Western digits. */
const western = (text: string) => text.replace(/[٠-٩]/g, (c) => String(c.charCodeAt(0) - 0x0660)).replace(/[۰-۹]/g, (c) => String(c.charCodeAt(0) - 0x06F0))

export type TypedDate = {
  iso: string
  /** The year was typed too: the date is complete and can be applied without asking. */
  complete: boolean
}

/**
 * A date typed in the search box, the way people write it here: day first (16/11/2026, 16-11-2026, 16.11.2026), the ISO form
 * (2026-11-16), or day and month alone (16/11), which is read as the nearest such day: this year, or next year when this year's is
 * more than half a year behind. Anything else (a programme code such as LDR-12, a word) is not a date and returns null.
 */
export function readTypedDate(text: string, todayIso: string): TypedDate | null {
  const t = western(text).trim()
  let m = /^(\d{4})[-/.](\d{1,2})[-/.](\d{1,2})$/.exec(t)
  if (m) {
    const [y, mo, d] = [Number(m[1]), Number(m[2]), Number(m[3])]
    return real(y, mo, d) ? { iso: isoOf(y, mo, d), complete: true } : null
  }
  m = /^(\d{1,2})[-/.](\d{1,2})[-/.](\d{4})$/.exec(t)
  if (m) {
    const [d, mo, y] = [Number(m[1]), Number(m[2]), Number(m[3])]
    return real(y, mo, d) ? { iso: isoOf(y, mo, d), complete: true } : null
  }
  m = /^(\d{1,2})[/.](\d{1,2})$/.exec(t)
  if (m) {
    const [d, mo] = [Number(m[1]), Number(m[2])]
    const year = Number(todayIso.slice(0, 4))
    const candidates = [year, year + 1].filter((y) => real(y, mo, d)).map((y) => isoOf(y, mo, d))
    if (!candidates.length) return null
    const farBehind = daysBetween(candidates[0], todayIso) > 183
    return { iso: farBehind && candidates[1] ? candidates[1] : candidates[0], complete: false }
  }
  return null
}

/** Whole days from one date to another (positive when `to` is later). */
export function daysBetween(from: string, to: string): number {
  return Math.round((Date.parse(`${to}T00:00:00Z`) - Date.parse(`${from}T00:00:00Z`)) / 86_400_000)
}

export function shiftIso(iso: string, days: number): string {
  const d = new Date(`${iso}T00:00:00Z`)
  d.setUTCDate(d.getUTCDate() + days)
  return isoOf(d.getUTCFullYear(), d.getUTCMonth() + 1, d.getUTCDate())
}

/**
 * The day to go to from `current` in the row: the shown day right before (-1) or right after (+1). Beyond the end of the row it is
 * the day the server named (`prev` / `next`); null when there is none within reach.
 */
export function neighbour(win: DayWindow, current: string, step: -1 | 1): string | null {
  if (step < 0) {
    const before = win.days.filter((d) => d.date < current)
    return before.length ? before[before.length - 1].date : win.prev
  }
  const after = win.days.find((d) => d.date > current)
  return after ? after.date : win.next
}

/** The nearest days with training on each side of a day that has none, to offer instead of an empty list. */
export function nearestLit(win: DayWindow, current: string): { before: DayCell | null; after: DayCell | null } {
  const lit = win.days.filter((d) => d.programs > 0)
  const before = lit.filter((d) => d.date < current)
  return { before: before.length ? before[before.length - 1] : null, after: lit.find((d) => d.date > current) ?? null }
}

/** «اليوم», «غداً», «أمس» for the days around today; null for any other day (the caller shows the weekday). */
export function relativeDay(iso: string, todayIso: string): 'today' | 'tomorrow' | 'yesterday' | null {
  const n = daysBetween(todayIso, iso)
  return n === 0 ? 'today' : n === 1 ? 'tomorrow' : n === -1 ? 'yesterday' : null
}
