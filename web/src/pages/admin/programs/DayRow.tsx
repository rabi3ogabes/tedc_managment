import clsx from 'clsx'
import { CalendarDays, ChevronLeft, ChevronRight, ChevronsLeft, ChevronsRight, Lightbulb, LightbulbOff, X } from 'lucide-react'
import { useEffect, useRef } from 'react'
import { useTranslation } from 'react-i18next'
import { fmt } from '@/lib/format'
import { neighbour, type DayCell, type DayWindow } from './days'
import { dayName, noon, programsText, ROW, type Anchor } from './dayText'

/**
 * The row of days above the programme list. A day with training is lit and carries the number of programmes; a working day without
 * any has its light off; the days off are not in the row at all. Choosing a day shows the programmes that train on it; the arrows go
 * to the day before or after (over the weekend), and any other day can be picked from the calendar.
 */
export default function DayRow({ on, win, today, loading, onPick, onAnchor }: {
  /** The chosen day, or null when the list shows every date. */
  on: string | null
  win: DayWindow | undefined
  today: string
  loading: boolean
  /** Choose a day (null clears it). `move` also brings the row to stand around that day. */
  onPick: (date: string | null, move?: boolean) => void
  onAnchor: (anchor: Anchor) => void
}) {
  const { t, i18n } = useTranslation()
  const rtl = i18n.dir() === 'rtl'
  const Prev = rtl ? ChevronRight : ChevronLeft
  const Next = rtl ? ChevronLeft : ChevronRight
  const Earlier = rtl ? ChevronsRight : ChevronsLeft
  const Later = rtl ? ChevronsLeft : ChevronsRight
  const chosen = useRef<HTMLButtonElement>(null)

  // Keep the chosen day (today when none is chosen) in the middle of the row when the row is wider than the screen. Only the row
  // moves: the page itself is never scrolled.
  useEffect(() => {
    const chip = chosen.current
    const list = chip?.closest('ol')
    if (!chip || !list) return
    const c = chip.getBoundingClientRect()
    const l = list.getBoundingClientRect()
    list.scrollLeft += c.left + c.width / 2 - (l.left + l.width / 2)
  }, [on, win?.around, win?.days.length])

  const step = (dir: -1 | 1) => {
    if (!win) return
    const to = neighbour(win, on ?? today, dir)
    if (to) onPick(to, !win.days.some((d) => d.date === to))
  }
  const canStep = (dir: -1 | 1) => !!win && neighbour(win, on ?? today, dir) !== null
  const iconBtn = 'grid size-9 shrink-0 place-items-center rounded-xl text-navy-900 transition hover:bg-navy-50 disabled:cursor-not-allowed disabled:text-slate-300 disabled:hover:bg-transparent'

  return (
    <section aria-label={String(t('progDay.title'))} className="mb-4 rounded-3xl border border-navy-100 bg-white p-3 sm:p-4">
      <div className="flex flex-wrap items-center gap-2.5">
        <div className="flex items-center gap-1 rounded-2xl border border-navy-100 p-1">
          <button type="button" className={iconBtn} disabled={!canStep(-1)} onClick={() => step(-1)} aria-label={String(t('progDay.prev'))} title={String(t('progDay.prev'))}><Prev className="size-5" /></button>
          <div className="min-w-[12.5rem] px-2 text-center" aria-live="polite">
            <p className="text-sm font-bold text-navy-900">{on ? dayName(on, today, t) : t('progDay.all')}</p>
            <p className="text-xs text-slate-500">{on ? fmt.date(noon(on)) : t('progDay.allHint')}</p>
          </div>
          <button type="button" className={iconBtn} disabled={!canStep(1)} onClick={() => step(1)} aria-label={String(t('progDay.next'))} title={String(t('progDay.next'))}><Next className="size-5" /></button>
        </div>

        {on !== today && <button type="button" onClick={() => onPick(today, true)} className="rounded-2xl border border-navy-100 px-4 py-2.5 text-sm font-bold text-navy-900 transition hover:border-gold-400">{t('progDay.backToday')}</button>}
        <label className="relative inline-flex cursor-pointer items-center gap-2 rounded-2xl border border-navy-100 px-4 py-2.5 text-sm font-bold text-navy-900 transition hover:border-gold-400">
          <CalendarDays className="size-4 text-gold-600" />{t('progDay.pick')}
          <input type="date" value={on ?? ''} onChange={(e) => e.target.value && onPick(e.target.value, true)} className="absolute inset-0 cursor-pointer opacity-0" aria-label={String(t('progDay.pick'))} />
        </label>
        {on && <button type="button" onClick={() => onPick(null)} className="inline-flex items-center gap-1.5 rounded-2xl bg-navy-50 px-4 py-2.5 text-sm font-bold text-navy-900 transition hover:bg-navy-100"><X className="size-4" />{t('progDay.clear')}</button>}

        <ul className="ms-auto hidden items-center gap-3 text-[11px] font-semibold text-slate-500 lg:flex">
          <li className="inline-flex items-center gap-1.5"><span className="size-3 rounded-full bg-navy-900" />{t('progDay.legend.lit')}</li>
          <li className="inline-flex items-center gap-1.5"><span className="size-3 rounded-full bg-gold-100 ring-1 ring-inset ring-gold-400" />{t('progDay.legend.off')}</li>
        </ul>
      </div>

      <div className="mt-3 flex items-stretch gap-1.5">
        <button type="button" className={clsx(iconBtn, 'h-auto self-stretch border border-navy-100')} disabled={!win?.prev} onClick={() => win?.prev && onAnchor({ around: win.prev, before: ROW.before + ROW.after, after: 0 })} aria-label={String(t('progDay.earlier'))} title={String(t('progDay.earlier'))}><Earlier className="size-4" /></button>
        <ol className={clsx('flex min-w-0 flex-1 gap-2 overflow-x-auto px-0.5 py-1 transition-opacity', loading && win && 'opacity-60')}>
          {!win ? Array.from({ length: ROW.before + ROW.after + 1 }, (_, i) => <li key={i} className="h-[6.4rem] w-[4.4rem] shrink-0 animate-pulse rounded-2xl bg-navy-50 sm:flex-1" />)
            : win.days.map((d) => <li key={d.date} className="shrink-0 sm:min-w-[4.4rem] sm:flex-1"><DayChip day={d} today={today} selected={d.date === on} innerRef={d.date === (on ?? today) ? chosen : undefined} onClick={() => onPick(d.date === on ? null : d.date)} /></li>)}
        </ol>
        <button type="button" className={clsx(iconBtn, 'h-auto self-stretch border border-navy-100')} disabled={!win?.next} onClick={() => win?.next && onAnchor({ around: win.next, before: 0, after: ROW.before + ROW.after })} aria-label={String(t('progDay.later'))} title={String(t('progDay.later'))}><Later className="size-4" /></button>
      </div>
    </section>
  )
}

function DayChip({ day, today, selected, innerRef, onClick }: { day: DayCell; today: string; selected: boolean; innerRef?: React.Ref<HTMLButtonElement>; onClick: () => void }) {
  const { t } = useTranslation()
  const lit = day.programs > 0
  const isToday = day.date === today
  const label = String(t('progDay.chip', { day: dayName(day.date, today, t, true), count: programsText(day.programs, t) })) + (day.off ? ` (${day.title ?? t('progDay.dayOff')})` : '')
  return (
    <button ref={innerRef} type="button" onClick={onClick} aria-pressed={selected} aria-label={label} title={label}
      className={clsx('relative flex h-[6.4rem] w-[4.4rem] flex-col items-center overflow-hidden rounded-2xl border pb-2 pt-2.5 transition duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-500 motion-safe:hover:-translate-y-0.5 sm:w-full',
        // The two states in the colours of the brand: a day with training is the maroon, a day without is the light dune.
        lit ? 'border-navy-900 bg-navy-900 text-white shadow-[0_14px_24px_-16px_color-mix(in_oklab,var(--color-navy-950)_85%,transparent)]'
          : 'border-gold-200 bg-gold-100 text-gold-900',
        selected && 'ring-[3px] ring-gold-500 ring-offset-2')}>
      {/* The lamp of the day: on when programmes train on it, off when none does. */}
      <span aria-hidden className={clsx('absolute inset-x-3 top-0 h-1 rounded-b-full', lit ? 'bg-gold-300' : 'bg-gold-300/60')} />
      <span className={clsx('text-[11px] font-bold leading-none', lit ? (isToday ? 'text-gold-200' : 'text-white/75') : isToday ? 'text-navy-900' : 'text-gold-800')}>{isToday ? t('progDay.today') : fmt.date(noon(day.date), { weekday: 'short' })}</span>
      <span className="mt-1 font-display text-2xl font-bold leading-none tabular-nums">{fmt.date(noon(day.date), { day: 'numeric' })}</span>
      <span className={clsx('mt-0.5 text-[10px] leading-none', lit ? 'text-white/70' : 'text-gold-800')}>{day.off ? t('progDay.dayOff') : fmt.date(noon(day.date), { month: 'short' })}</span>
      <span className={clsx('mt-auto inline-flex items-center gap-1 text-xs font-bold tabular-nums', lit ? 'text-white' : 'text-gold-600')}>
        {lit ? <><Lightbulb className="size-3.5 text-gold-300" />{fmt.number(day.programs)}</> : <LightbulbOff className="size-3.5" />}
      </span>
    </button>
  )
}
