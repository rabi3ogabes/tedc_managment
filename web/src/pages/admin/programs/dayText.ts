import type { TFunction } from 'i18next'
import { fmt } from '@/lib/format'
import { relativeDay } from './days'

/** Where the row of days stands: the day it was asked around, and how many shown days before and after it. */
export type Anchor = { around: string; before: number; after: number }
export const ROW = { before: 5, after: 9 }

/** Noon of a day, so formatting it never slips to the day before or after in another timezone. */
export const noon = (iso: string) => `${iso}T12:00:00`

/** «3 برامج», «برنامجان», «لا تدريب»: the Arabic number forms. */
export function programsText(n: number, t: TFunction): string {
  const key = n <= 0 ? 'none' : n === 1 ? 'one' : n === 2 ? 'two' : n <= 10 ? 'few' : 'many'
  return String(t(`progDay.count.${key}`, { n }))
}

/** A day as people say it: «اليوم», «غداً», «أمس», else the weekday; with the full date when asked. */
export function dayName(iso: string, today: string, t: TFunction, full = false): string {
  const rel = relativeDay(iso, today)
  const name = rel ? String(t(`progDay.${rel}`)) : fmt.date(noon(iso), { weekday: 'long' })
  return full ? `${name} ${fmt.date(noon(iso), { day: 'numeric', month: 'long', year: 'numeric' })}` : name
}

/** A day written out for a sentence: the weekday and the whole date, never «today» (so «برامج يوم الاثنين 16 نوفمبر 2026» reads right on any day). */
export const fullDay = (iso: string) => fmt.date(noon(iso), { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })
