import clsx from 'clsx'
import { ChevronLeft, ChevronRight } from 'lucide-react'
import { useMemo, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { intlLocale } from '@/lib/format'
import type { Session } from '@/lib/types'

/** Month grid used by the public calendar and the employee calendar. */
export default function CalendarGrid({ month, onMonth, sessions, onSelect, selected }: {
  month: Date; onMonth: (d: Date) => void; sessions: Session[]; onSelect: (d: Date) => void; selected: Date | null
}) {
  const { i18n, t } = useTranslation()
  const locale = intlLocale()
  const rtl = i18n.language === 'ar'

  const days = useMemo(() => {
    const first = new Date(month.getFullYear(), month.getMonth(), 1)
    const start = new Date(first)
    start.setDate(1 - first.getDay()) // week starts Sunday (Qatar work week)
    return Array.from({ length: 42 }, (_, i) => new Date(start.getFullYear(), start.getMonth(), start.getDate() + i))
  }, [month])

  const byDay = useMemo(() => {
    const map = new Map<string, Session[]>()
    sessions.forEach((s) => {
      const key = new Date(s.starts_at).toDateString()
      map.set(key, [...(map.get(key) ?? []), s])
    })
    return map
  }, [sessions])

  const weekdays = Array.from({ length: 7 }, (_, i) => new Intl.DateTimeFormat(locale, { weekday: 'short' }).format(new Date(2024, 0, 7 + i)))
  const Prev = rtl ? ChevronRight : ChevronLeft
  const Next = rtl ? ChevronLeft : ChevronRight
  const nav = (delta: number) => onMonth(new Date(month.getFullYear(), month.getMonth() + delta, 1))

  return (
    <div className="card p-4 sm:p-6">
      <div className="mb-4 flex items-center justify-between">
        <IconBtn label={t('common.previous')} onClick={() => nav(-1)}><Prev className="size-5" /></IconBtn>
        <h3 className="font-display text-xl font-bold text-navy-900">{new Intl.DateTimeFormat(locale, { month: 'long', year: 'numeric' }).format(month)}</h3>
        <IconBtn label={t('common.next')} onClick={() => nav(1)}><Next className="size-5" /></IconBtn>
      </div>
      <div className="grid grid-cols-7 gap-1 text-center text-xs font-semibold text-slate-400">{weekdays.map((w) => <div key={w} className="py-2">{w}</div>)}</div>
      <div className="grid grid-cols-7 gap-1">
        {days.map((d) => {
          const items = byDay.get(d.toDateString()) ?? []
          const inMonth = d.getMonth() === month.getMonth()
          const isToday = d.toDateString() === new Date().toDateString()
          const isSelected = selected?.toDateString() === d.toDateString()
          return (
            <button key={d.toISOString()} onClick={() => onSelect(d)}
              className={clsx('flex min-h-20 flex-col rounded-xl border p-1.5 text-start transition sm:min-h-24',
                isSelected ? 'border-gold-500 bg-gold-100/60' : 'border-transparent hover:bg-ivory', !inMonth && 'opacity-35')}>
              <span className={clsx('grid size-7 place-items-center rounded-full text-sm font-semibold', isToday ? 'bg-navy-900 text-white' : 'text-navy-900')}>{d.getDate()}</span>
              <div className="mt-1 space-y-0.5">
                {items.slice(0, 2).map((s) => <div key={s.id} className="truncate rounded-md bg-navy-900 px-1.5 py-0.5 text-[10px] text-gold-300">{s.program?.title ?? s.title}</div>)}
                {items.length > 2 && <div className="text-[10px] text-slate-500">+{items.length - 2}</div>}
              </div>
            </button>
          )
        })}
      </div>
    </div>
  )
}

function IconBtn({ onClick, label, children }: { onClick: () => void; label: string; children: ReactNode }) {
  return <button type="button" aria-label={label} title={label} onClick={onClick} className="grid size-10 place-items-center rounded-full border border-navy-100 text-navy-800 hover:border-gold-400">{children}</button>
}
