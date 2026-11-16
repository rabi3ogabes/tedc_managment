import clsx from 'clsx'
import { keepPreviousData } from '@tanstack/react-query'
import { Building2, CalendarSearch, Clock, DoorOpen, Layers, LayoutGrid, LightbulbOff, MonitorPlay, Plus, Search, Wand2, X } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { Button, Card, PageHeader, Progress, StatusBadge } from '@/components/ui'
import { DataView, type Column } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Paginated, Program } from '@/lib/types'
import { todayIso } from '@/pages/RoomScreen'
import DayRow from './programs/DayRow'
import { nearestLit, readTypedDate, type DayCell, type DayWindow } from './programs/days'
import { fullDay, noon, programsText, ROW, type Anchor } from './programs/dayText'
import { useDebounced } from './notifications/shared'
import { Pager } from './shared'

const ISO_DAY = /^\d{4}-\d{2}-\d{2}$/

export default function ProgramsAdmin() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const today = todayIso()
  const [params, setParams] = useSearchParams()
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [type, setType] = useState<'' | 'in_person' | 'online' | 'hybrid'>('')
  const [page, setPage] = useState(1)
  // The day of training on show lives in the address (?on=2026-11-16): a link to «the programmes of Monday» can be shared, and Back works.
  const on = ISO_DAY.test(params.get('on') ?? '') ? params.get('on') : null
  const [anchor, setAnchor] = useState<Anchor>(() => ({ around: on ?? today, ...ROW }))

  // A date typed in the search box is a date, not a programme name: it is never sent as search text.
  const typed = readTypedDate(q, today)
  // The server is asked once the typing pauses, not on every letter (the list and the row of days both follow the text).
  const term = useDebounced(typed ? '' : q.trim(), 300)
  const filters = { q: term || undefined, status: status || undefined, delivery_mode: type || undefined }

  // SABER+ changes wait here for whoever reviews and publishes them.
  const review = useGet<{ meta: { counts: Record<string, number> } }>(can('saber.publish') ? '/admin/saber/reviews' : null, { status: 'submitted' }, { staleTime: 30_000 })
  const waiting = review.data?.meta.counts.submitted ?? 0
  const { data, isLoading, isPlaceholderData } = useGet<Paginated<Program> & { types?: Record<'in_person' | 'online' | 'hybrid', number> }>('/admin/programs', { ...filters, on: on ?? undefined, page }, { placeholderData: keepPreviousData })
  const days = useGet<{ data: DayWindow }>('/admin/programs/days', { ...filters, ...anchor }, { placeholderData: keepPreviousData, staleTime: 30_000 })
  const win = days.data?.data

  /** Choose the day of training (null shows every date again). `move` brings the row of days to stand around it. */
  const pick = (date: string | null, move = false) => {
    setParams((p) => { const next = new URLSearchParams(p); if (date) next.set('on', date); else next.delete('on'); return next }, { replace: true })
    setPage(1)
    if (date && move) setAnchor({ around: date, ...ROW })
  }
  const search = (text: string) => {
    const date = readTypedDate(text, today)
    // A whole date (with its year) is applied the moment it is typed; day and month alone wait for Enter or a press on the offer below.
    if (date?.complete) { pick(date.iso, true); setQ('') } else setQ(text)
    setPage(1)
  }
  const applyTyped = () => { if (typed) { pick(typed.iso, true); setQ('') } }

  const columns: Column<Program>[] = [
    { key: 'code', header: t('admin.programs.code'), role: 'media', cell: (p) => <span className="inline-block rounded-lg bg-navy-900 px-2 py-1 font-mono text-[11px] font-bold text-gold-300" dir="ltr">{p.code}</span> },
    { key: 'title', header: t('programs.title'), role: 'title', cell: (p) => <Link to={`/admin/programs/${p.id}`} className="font-bold text-navy-900 hover:text-link">{p.title}</Link> },
    { key: 'meta', header: t('programs.mode'), role: 'subtitle', cell: (p) => <span className="text-xs text-slate-400">{t(`modes.${p.delivery_mode}`)} · {fmt.number(p.total_hours)} {t('common.hours')}</span> },
    // With a day chosen the list is that day's agenda: when each programme meets, and where.
    ...(on ? [{ key: 'day', header: t('progDay.column'), cell: (p: Program) => <DaySessions program={p} /> } satisfies Column<Program>] : []),
    { key: 'category', header: t('programs.category'), cell: (p) => <span className="text-slate-600">{p.category?.name ?? '—'}</span> },
    { key: 'start', header: t('programs.startsOn'), className: 'whitespace-nowrap', cell: (p) => fmt.date(p.start_date, { day: 'numeric', month: 'short', year: 'numeric' }) },
    { key: 'capacity', header: t('programs.capacity'), cell: (p) => <div className="w-32"><div className="mb-1 text-xs text-slate-500">{fmt.number(p.seats_taken ?? 0)} / {fmt.number(p.capacity)}</div><Progress value={((p.seats_taken ?? 0) / p.capacity) * 100} /></div> },
    { key: 'status', header: t('common.status'), role: 'badge', cell: (p) => <StatusBadge status={p.status} /> },
    { key: 'open', header: '', role: 'actions', hideInTable: true, cell: (p) => <Button size="sm" variant="outline" to={`/admin/programs/${p.id}`}>{t('common.details')}</Button> },
  ]

  // The rows of another day or another filter are never shown under the new heading: while the answer is on its way the list waits.
  const waitingRows = isLoading || isPlaceholderData
  const dark = !!on && !waitingRows && data?.data.length === 0
  const cell = on ? win?.days.find((d) => d.date === on) : undefined

  return (
    <>
      <PageHeader title={t('admin.menu.programs')} actions={can('programs.manage') && <div className="flex flex-wrap gap-2"><Button to="/admin/programs/smart" variant="gold" icon={<Wand2 className="size-4" />}>{t('mgmt.wizard.smartCreate')}</Button><Button to="/admin/programs/new" variant="outline" icon={<Plus className="size-4" />}>{t('admin.programs.new')}</Button></div>} />
      {can('saber.publish') && (
        <Link to="/admin/programs/saber-review" className={clsx('mb-4 flex flex-wrap items-center gap-3 rounded-2xl border px-4 py-3 transition hover:shadow-md', waiting ? 'border-gold-300 bg-gradient-to-l from-gold-50 to-white' : 'border-navy-100 bg-white')}>
          <span className="grid size-10 place-items-center rounded-xl bg-navy-900 text-gold-300"><Layers className="size-5" /></span>
          <span className="min-w-0 flex-1">
            <span className="block font-bold text-navy-900">{t('saber.review.nav')}</span>
            <span className="block text-xs text-slate-500">{waiting ? t('saber.review.banner', { n: waiting }) : t('saber.review.subtitle')}</span>
          </span>
          {waiting > 0 && <span className="grid min-w-7 place-items-center rounded-full bg-rose-600 px-2 text-sm font-bold leading-7 text-white">{waiting}</span>}
          <span className="text-sm font-bold text-navy-700">{t('saber.review.open')}</span>
        </Link>
      )}

      {/* The day of training: a lit day has programmes, a dark one has none, the days off are not there */}
      <DayRow on={on} win={win} today={today} loading={days.isFetching} onPick={pick} onAnchor={setAnchor} />

      {/* The three kinds of program: choose one to see only those */}
      <div className="mb-4 grid grid-cols-2 gap-3 lg:grid-cols-4" role="tablist" aria-label={t('programs.mode')}>
        {([['', LayoutGrid], ['in_person', Building2], ['online', MonitorPlay], ['hybrid', Layers]] as const).map(([k, Icon]) => {
          const count = k === '' ? Object.values(data?.types ?? {}).reduce((a, b) => a + b, 0) : data?.types?.[k]
          const active = type === k
          return (
            <button key={k || 'all'} type="button" role="tab" aria-selected={active} onClick={() => { setType(k); setPage(1) }}
              className={clsx('flex items-center gap-3 rounded-2xl border p-3.5 text-start transition', active ? 'border-gold-400 bg-navy-900 text-white shadow-lg' : 'border-navy-100 bg-white text-navy-900 hover:border-gold-300')}>
              <span className={clsx('grid size-10 shrink-0 place-items-center rounded-xl', active ? 'bg-gold-400 text-navy-950' : 'bg-navy-50 text-navy-700')}><Icon className="size-5" /></span>
              <span className="min-w-0"><span className="block truncate text-sm font-bold">{k === '' ? t('common.all') : t(`modes.${k}`)}</span><span className={clsx('block text-xl font-extrabold tabular-nums', active ? 'text-gold-300' : 'text-navy-900')}>{count === undefined ? '—' : fmt.number(count)}</span></span>
            </button>
          )
        })}
      </div>
      <Card padded={false}>
        <div className="flex flex-wrap gap-3 border-b border-navy-100 p-4">
          <div className="relative min-w-60 flex-1">
            <Search className="absolute start-3 top-3 size-4 text-slate-400" />
            <input className="input ps-9" placeholder={String(t('progDay.search.placeholder'))} value={q} onChange={(e) => search(e.target.value)} onKeyDown={(e) => { if (e.key === 'Enter' && typed) { e.preventDefault(); applyTyped() } }} />
            {typed && (
              <button type="button" onClick={applyTyped} className="absolute inset-x-0 top-full z-10 mt-1.5 flex items-center gap-2 rounded-xl border border-gold-300 bg-white px-3.5 py-2.5 text-start text-sm font-bold text-navy-900 shadow-lg transition hover:bg-gold-50">
                <CalendarSearch className="size-4 shrink-0 text-gold-600" />{t('progDay.search.apply', { day: fullDay(typed.iso) })}
              </button>
            )}
          </div>
          <select className="input w-auto" value={status} onChange={(e) => { setStatus(e.target.value); setPage(1) }}>
            <option value="">{t('common.all')}</option>
            {['draft', 'published', 'registration_open', 'in_progress', 'completed', 'archived'].map((s) => <option key={s} value={s}>{t(`status.${s}`)}</option>)}
          </select>
        </div>

        {on && (
          <div className="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-navy-100 bg-ivory px-4 py-3">
            <h2 className="text-sm font-bold text-navy-900">{t('progDay.heading', { day: fullDay(on) })}</h2>
            {!dark && !waitingRows && data?.meta && <span className="rounded-full bg-navy-900 px-2.5 py-0.5 text-xs font-bold text-white tabular-nums">{programsText(data.meta.total, t)}</span>}
            {cell?.off && <span className="rounded-full bg-slate-200 px-2.5 py-0.5 text-xs font-bold text-slate-700">{cell.title ?? t('progDay.dayOff')}</span>}
            <button type="button" onClick={() => pick(null)} className="ms-auto inline-flex items-center gap-1 text-xs font-bold text-navy-800 hover:text-link"><X className="size-3.5" />{t('progDay.clear')}</button>
          </div>
        )}

        {dark && on ? <DarkDay on={on} win={win} off={!!cell?.off} filtered={!!(term || status || type)} onPick={pick} />
          : <DataView id="admin.programs" rows={waitingRows ? undefined : data?.data} total={waitingRows ? undefined : data?.meta?.total} loading={waitingRows} columns={columns} rowKey={(p) => p.id} defaultMode="cards" />}
        {!waitingRows && <Pager page={page} last={data?.meta?.last_page} onChange={setPage} />}
      </Card>
    </>
  )
}

/** When the programme meets on the chosen day: its hours, and the room or «online». */
function DaySessions({ program }: { program: Program }) {
  const { t } = useTranslation()
  const sessions = program.day_sessions ?? []
  if (!sessions.length) return <span className="text-slate-400">—</span>
  return (
    <ul className="space-y-1 text-xs">
      {sessions.slice(0, 2).map((s) => {
        const place = s.room ?? (s.mode === 'online' ? t('modes.online') : s.location)
        return (
          <li key={s.id} className="flex flex-wrap items-center gap-x-2.5 gap-y-0.5">
            <span className="inline-flex items-center gap-1 font-bold tabular-nums text-navy-900"><Clock className="size-3.5 text-gold-600" />{fmt.time(s.starts_at)} – {fmt.time(s.ends_at)}</span>
            {place && <span className="inline-flex items-center gap-1 text-slate-600"><DoorOpen className="size-3.5 text-slate-400" />{place}</span>}
          </li>
        )
      })}
      {sessions.length > 2 && <li className="text-slate-500">{t('progDay.more', { n: sessions.length - 2 })}</li>}
    </ul>
  )
}

/** A day without training: the lights are off, and the nearest days that do have training are one press away. */
function DarkDay({ on, win, off, filtered, onPick }: { on: string; win: DayWindow | undefined; off: boolean; filtered: boolean; onPick: (date: string, move?: boolean) => void }) {
  const { t } = useTranslation()
  const near = win ? nearestLit(win, on) : { before: null, after: null }
  const day = fullDay(on)
  const offer = (d: DayCell, side: 'before' | 'after') => (
    <button key={d.date} type="button" onClick={() => onPick(d.date)} className="rounded-2xl border border-amber-300/50 bg-white/5 px-4 py-2.5 text-start transition hover:bg-white/10">
      <span className="block text-[11px] text-white/55">{t(`progDay.empty.${side}`)}</span>
      <span className="block text-sm font-bold text-white">{fmt.date(noon(d.date), { weekday: 'long', day: 'numeric', month: 'long' })}</span>
      <span className="block text-xs font-semibold text-amber-300">{programsText(d.programs, t)}</span>
    </button>
  )
  return (
    <div className="m-4 rounded-3xl bg-[radial-gradient(120%_90%_at_50%_0%,#2e1422_0%,#1d0c15_55%,#150910_100%)] px-6 py-10 text-center text-white">
      <span className="mx-auto grid size-14 place-items-center rounded-2xl bg-white/5 text-white/50"><LightbulbOff className="size-7" /></span>
      <h3 className="mt-4 text-lg font-bold">{t('progDay.empty.title')}</h3>
      <p className="mx-auto mt-1.5 max-w-md text-sm leading-7 text-white/65">{off ? t('progDay.empty.offDay', { day }) : filtered ? t('progDay.empty.filtered', { day }) : t('progDay.empty.text', { day })}</p>
      {(near.before || near.after) && (
        <div className="mt-6">
          <p className="mb-2.5 text-xs font-bold text-amber-300">{t('progDay.empty.nearest')}</p>
          <div className="flex flex-wrap justify-center gap-2.5">{near.before && offer(near.before, 'before')}{near.after && offer(near.after, 'after')}</div>
        </div>
      )}
    </div>
  )
}
