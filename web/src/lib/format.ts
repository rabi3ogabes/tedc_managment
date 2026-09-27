import i18n from '@/i18n'

const locale = () => (i18n.language === 'en' ? 'en-GB' : 'ar-QA')

export const fmt = {
  number: (n: number | null | undefined, digits = 0) =>
    n === null || n === undefined ? '—' : new Intl.NumberFormat(locale(), { maximumFractionDigits: digits }).format(n),
  percent: (n: number | null | undefined, digits = 0) =>
    n === null || n === undefined ? '—' : `${new Intl.NumberFormat(locale(), { maximumFractionDigits: digits }).format(n)}%`,
  date: (d: string | null | undefined, opts: Intl.DateTimeFormatOptions = { day: 'numeric', month: 'long', year: 'numeric' }) =>
    d ? new Intl.DateTimeFormat(locale(), opts).format(new Date(d)) : '—',
  time: (d: string | null | undefined) => (d ? new Intl.DateTimeFormat(locale(), { hour: '2-digit', minute: '2-digit' }).format(new Date(d)) : '—'),
  dateTime: (d: string | null | undefined) =>
    d ? new Intl.DateTimeFormat(locale(), { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(d)) : '—',
  month: (ym: string) => new Intl.DateTimeFormat(locale(), { month: 'short' }).format(new Date(`${ym}-01T00:00:00`)),
}

export function toDateInput(d?: string | null) {
  return d ? d.slice(0, 10) : ''
}

export function toDateTimeInput(d?: string | null) {
  if (!d) return ''
  const date = new Date(d)
  const pad = (n: number) => String(n).padStart(2, '0')
  return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}T${pad(date.getHours())}:${pad(date.getMinutes())}`
}
