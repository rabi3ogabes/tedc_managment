/**
 * How long ago something happened, in words ("3 hours ago", "قبل 3 ساعات") with Western digits. Pure on purpose: the clock and the locale
 * are given, so it can be tested without a browser. Seconds are not counted, and a time a little in the future (a clock that is ahead) is "now".
 */
const UNITS: [Intl.RelativeTimeFormatUnit, number][] = [['year', 365 * 86_400_000], ['month', 30 * 86_400_000], ['week', 7 * 86_400_000], ['day', 86_400_000], ['hour', 3_600_000], ['minute', 60_000]]

export function relativeTime(from: string | Date | null | undefined, now: Date, locale: string): string {
  const at = from ? new Date(from) : null
  if (!at || Number.isNaN(at.getTime())) return ''
  const elapsed = now.getTime() - at.getTime()
  if (elapsed < 60_000) return new Intl.RelativeTimeFormat(locale, { numeric: 'auto' }).format(0, 'second')   // "now" / "الآن"
  const formatter = new Intl.RelativeTimeFormat(locale, { numeric: 'always' })   // "1 day ago", not "yesterday": the same shape in every unit
  const [unit, size] = UNITS.find(([, s]) => elapsed >= s) ?? UNITS[UNITS.length - 1]
  return formatter.format(-Math.floor(elapsed / size), unit)
}
