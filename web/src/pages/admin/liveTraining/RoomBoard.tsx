import clsx from 'clsx'
import { AlertTriangle, BellRing, Clock, GraduationCap, Info, Lightbulb, LightbulbOff, PackageOpen, ShieldCheck, UserPlus, Video } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { fmt, intlLocale } from '@/lib/format'
import { relativeTime } from '@/lib/relativeTime'
import { countdown, isFresh, needsAttention, signalsFor, timing, type Signal } from './live'
import { StateChip } from './StateChip'
import { trainedText } from './text'
import type { Arrival, BoardRoom, RoomState, SessionBrief } from './types'

type Filter = 'all' | 'on' | 'attention' | 'off'
type Look = 'lit' | 'dim' | 'off'

/** Above this many seats the dots would be too small to read: a bar takes their place. */
const MAX_DOTS = 60

/** The session a room shows: the one the administrator picked, else the running one, the next one or the last one. */
const shown = (room: BoardRoom, picked?: string): SessionBrief | null =>
  room.sessions.find((s) => s.id === picked) ?? room.sessions.find((s) => s.id === room.focus_session_id) ?? null

const stateOf = (room: BoardRoom, s: SessionBrief | null): RoomState =>
  !s ? 'idle' : s.id === room.focus_session_id ? room.state : s.state === 'live' ? 'live' : s.state === 'ended' ? 'ended' : 'later'

const TONE: Record<Signal['tone'], string> = {
  alert: 'bg-red-50 text-red-800 ring-red-600/25', warn: 'bg-amber-50 text-amber-800 ring-amber-600/30', info: 'bg-navy-50 text-navy-800 ring-navy-600/15',
}

/**
 * The rooms of the day as boxes, floor by floor: a lit box has training in it now, a dark one has none. Each box carries the details of
 * its session (programme, time left, who is in the room, trainer, supervisor, training kit) and what deserves a look. The clock runs on
 * the page, so a box lights up, counts down and goes dark on the second; pressing one opens its training card.
 */
export default function RoomBoard({ rooms, unplaced, arrivals, picked, nowMs, today, checkIns, selected, loading, narrow, onOpen }: {
  rooms: BoardRoom[]; unplaced: SessionBrief[]; arrivals: Arrival[]; picked: Record<string, string>
  /** The time of the centre, moving every second. */
  nowMs: number
  /** The day on show is today: boxes count down and somebody who checks in is announced. */
  today: boolean
  /** Trainers check in on this day (see `trainersCheckIn`). */
  checkIns: boolean
  selected: string | null; loading: boolean
  /** The training card is open beside the board: fewer boxes per row. */
  narrow: boolean
  onOpen: (roomId: string) => void
}) {
  const { t } = useTranslation()
  const [filter, setFilter] = useState<Filter>('all')

  const boxes = useMemo(() => rooms.map((room) => {
    const session = shown(room, picked[room.id])
    const state = stateOf(room, session)
    const signals = today ? signalsFor(room, session, nowMs, checkIns) : []
    // On another day nothing is running: a room that had (or will have) training is the lit one.
    const look: Look = !session ? 'off' : (today ? state === 'live' : true) ? 'lit' : 'dim'
    return { room, session, state, signals, look, attention: needsAttention(signals) }
  }), [rooms, picked, nowMs, today, checkIns])

  const count = { all: boxes.length, on: boxes.filter((b) => b.look === 'lit').length, attention: boxes.filter((b) => b.attention).length, off: boxes.filter((b) => b.look !== 'lit').length }
  // A filter that has emptied itself (the last alert was dealt with) falls back to every room instead of an empty wall.
  const active: Filter = filter !== 'all' && count[filter] === 0 ? 'all' : filter
  const visible = boxes.filter((b) => active === 'all' || (active === 'on' ? b.look === 'lit' : active === 'off' ? b.look !== 'lit' : b.attention))

  const floors = useMemo(() => {
    const groups: { floor: string | null; items: typeof visible }[] = []
    visible.forEach((b) => {
      const last = groups[groups.length - 1]
      if (last && last.floor === b.room.floor) last.items.push(b)
      else groups.push({ floor: b.room.floor, items: [b] })
    })
    return groups
  }, [visible])

  const now = new Date(nowMs)
  const chips: [Filter, string, string][] = [
    ['all', String(t('liveT.box.filter.all')), 'bg-navy-900'],
    ['on', String(t(today ? 'liveT.box.filter.on' : 'liveT.box.filter.used')), 'bg-amber-400'],
    ['attention', String(t('liveT.box.filter.attention')), 'bg-red-500'],
    ['off', String(t(today ? 'liveT.box.filter.off' : 'liveT.box.filter.unused')), 'bg-slate-400'],
  ]

  return (
    <div>
      <div className="mb-3 flex flex-wrap items-center gap-x-4 gap-y-2">
        {loading ? <p className="inline-flex items-center gap-2 py-1.5 text-xs font-bold text-slate-600" role="status"><span className="size-3.5 animate-spin rounded-full border-2 border-navy-200 border-t-navy-900" />{t('common.loading')}</p> : (
        <div className="flex flex-wrap items-center gap-1.5" role="group" aria-label={String(t('liveT.box.filter.label'))}>
          {chips.filter(([k]) => k !== 'attention' || (today && count.attention > 0)).map(([k, label, dot]) => (
            <button key={k} type="button" aria-pressed={active === k} onClick={() => setFilter(k)}
              className={clsx('inline-flex items-center gap-2 rounded-full border px-3.5 py-1.5 text-xs font-bold transition', active === k ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-navy-900 hover:border-gold-400')}>
              <span className={clsx('size-2 rounded-full', active === k && k === 'all' ? 'bg-gold-300' : dot, k === 'attention' && 'motion-safe:animate-pulse')} />{label}
              <span className={clsx('tabular-nums', active === k ? 'text-white/75' : 'text-slate-500')}>{count[k]}</span>
            </button>
          ))}
        </div>
        )}

        {today && !loading && arrivals.length > 0 && (
          <p className="ms-auto flex min-w-0 max-w-full items-center gap-2 text-xs text-slate-600" aria-live="polite">
            <BellRing className="size-3.5 shrink-0 text-gold-600" />
            <span className="shrink-0 font-bold text-navy-900">{t('liveT.arrivals.title')}</span>
            <span className="truncate">
              {arrivals.slice(0, 3).map((a, i) => (
                <span key={a.id}>{i > 0 && <span className="mx-1.5 text-slate-300">|</span>}<span className={clsx('font-semibold', isFresh(a.at, nowMs) ? 'text-emerald-700' : 'text-ink')}>{a.name}</span>{a.room && <span className="text-slate-500"> {t('liveT.arrivals.into', { room: a.room })}</span>} <span className="tabular-nums text-slate-400">{relativeTime(a.at, now, intlLocale())}</span></span>
              ))}
            </span>
          </p>
        )}
      </div>

      <div className="space-y-5">
        {floors.map((group, i) => (
          <section key={`${group.floor ?? 'none'}-${i}`} aria-label={group.floor ? String(t('liveT.stage.floor', { name: group.floor })) : undefined}>
            {group.floor && (
              <h2 className="mb-2.5 flex items-baseline gap-3 text-sm font-bold text-navy-900">
                {t('liveT.stage.floor', { name: group.floor })}
                {!loading && <span className="text-xs font-semibold text-slate-500 tabular-nums">{t(today ? 'liveT.box.floorLit' : 'liveT.box.floorUsed', { on: group.items.filter((b) => b.look === 'lit').length, n: group.items.length })}</span>}
                <span className="h-px flex-1 self-center bg-navy-100" />
              </h2>
            )}
            <ul className={clsx('grid gap-3 sm:grid-cols-2', narrow ? '2xl:grid-cols-3' : 'lg:grid-cols-3 2xl:grid-cols-4')}>
              {group.items.map((b) => (
                <li key={b.room.id} className="min-w-0">
                  <RoomBox {...b} loading={loading} nowMs={nowMs} today={today} selected={selected === b.room.id} fresh={today ? arrivals.find((a) => a.room_id === b.room.id && isFresh(a.at, nowMs)) ?? null : null} onOpen={() => onOpen(b.room.id)} />
                </li>
              ))}
            </ul>
          </section>
        ))}
      </div>

      {unplaced.length > 0 && active === 'all' && (
        <section className="mt-5">
          <h2 className="mb-2.5 flex items-center gap-2 text-sm font-bold text-navy-900"><Video className="size-4 text-gold-600" />{t('liveT.rail.outside')}<span className="h-px flex-1 bg-navy-100" /></h2>
          <ul className={clsx('grid gap-3 sm:grid-cols-2', narrow ? '2xl:grid-cols-3' : 'lg:grid-cols-3 2xl:grid-cols-4')}>
            {unplaced.map((s) => (
              <li key={s.id} className="rounded-2xl border border-navy-100 bg-white px-4 py-3">
                <p className="flex items-center justify-between gap-2"><span className="truncate text-sm font-bold text-ink">{s.program.title}</span><StateChip state={s.state} label={String(t(`liveT.state.${s.state === 'upcoming' ? 'later' : s.state}`))} /></p>
                <p className="mt-1 flex items-center gap-2 text-xs text-slate-500"><span className="tabular-nums">{fmt.time(s.starts_at)} – {fmt.time(s.ends_at)}</span><span className="truncate">{s.mode === 'online' ? t('liveT.rail.online') : s.location ?? t('liveT.rail.noRoom')}</span><span className="ms-auto shrink-0 font-semibold tabular-nums text-navy-800">{t('liveT.rail.count', { present: s.counts.present, expected: s.counts.expected })}</span></p>
              </li>
            ))}
          </ul>
        </section>
      )}
    </div>
  )
}

/** One seat per expected trainee: filled when its trainee is in the room, amber for a late arrival, and it pops in when somebody has just sat down. */
function Seats({ session, nowMs, ended, onDark }: { session: SessionBrief; nowMs: number; ended: boolean; onDark?: boolean }) {
  const here = session.present
  // After the end nobody is «in the room» any more: the seats show who attended.
  const filled = ended ? Math.max(here.length, session.counts.present) : here.length
  const total = Math.max(session.counts.expected, filled)
  if (total === 0) return null
  if (total > MAX_DOTS) {
    return <span className={clsx('block h-1.5 overflow-hidden rounded-full', onDark ? 'bg-white/15' : 'bg-navy-100/80')} aria-hidden><span className="block h-full rounded-full bg-navy-900 transition-[width] duration-700" style={{ width: `${Math.min(100, (filled / total) * 100)}%` }} /></span>
  }
  return (
    <span className="flex flex-wrap gap-[5px]" aria-hidden>
      {Array.from({ length: total }, (_, i) => {
        const p = here[i]
        const on = i < filled
        return (
          <span key={p?.id ?? `empty-${i}`}
            className={clsx('size-2.5 rounded-[4px] transition-colors duration-500', !on ? 'bg-navy-100' : p?.status === 'late' ? 'bg-amber-500' : 'bg-navy-900',
              p && isFresh(p.at, nowMs) && 'ring-2 ring-emerald-400 ring-offset-1 motion-safe:animate-[lt-seat_.7s_cubic-bezier(.2,1.5,.4,1)_both]')} />
        )
      })}
    </span>
  )
}

function RoomBox({ room, session, state, signals, look, attention, loading, nowMs, today, selected, fresh, onOpen }: {
  room: BoardRoom; session: SessionBrief | null; state: RoomState; signals: Signal[]; look: Look; attention: boolean
  /** Another day is being fetched: the room waits with its lights off and says nothing yet. */
  loading: boolean
  nowMs: number; today: boolean; selected: boolean; fresh: Arrival | null; onOpen: () => void
}) {
  const { t } = useTranslation()
  const dark = look === 'off'
  const time = session ? timing(session, nowMs) : null
  const trainer = session?.trainers[0]
  const next = session ? room.sessions.find((s) => s.id !== session.id && s.state === 'upcoming') : null
  const place = [room.code && room.code !== room.name ? room.code : null, room.capacity > 0 ? String(t('liveT.card.seats', { n: room.capacity })) : null].filter(Boolean)
  const stateLabel = String(t(`liveT.state.${state}`))
  const label = session
    ? `${room.name}: ${stateLabel}. ${session.program.title ?? ''}. ${t('liveT.tip.trainees', { present: session.counts.present, expected: session.counts.expected })}`
    : `${room.name}: ${stateLabel}`

  return (
    <button type="button" onClick={onOpen} aria-pressed={selected} aria-label={label} title={String(t('liveT.tip.press'))}
      className={clsx('group relative flex h-full w-full flex-col overflow-hidden rounded-3xl border p-4 text-start transition duration-300 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-500 motion-safe:hover:-translate-y-0.5',
        look === 'lit' && 'border-amber-300/80 bg-[radial-gradient(130%_80%_at_50%_0%,#fff3cf_0%,#fffaf0_38%,#ffffff_72%)] shadow-[0_22px_44px_-26px_rgb(180_120_20/0.75)]',
        look === 'dim' && 'border-navy-100 bg-white hover:border-gold-400',
        dark && 'border-white/5 bg-[radial-gradient(120%_90%_at_50%_0%,#2e1422_0%,#1d0c15_55%,#150910_100%)] text-white/80 hover:border-white/25',
        selected && 'ring-2 ring-navy-900 ring-offset-2',
        fresh && 'motion-safe:animate-[lt-arrive_1.6s_ease-out_both]')}>
      {/* The ceiling light: glowing, switched off, or gone dark. */}
      <span aria-hidden className={clsx('absolute inset-x-6 top-0 h-1 rounded-b-full', look === 'lit' ? 'bg-amber-400 shadow-[0_0_18px_4px_rgb(251_191_36/0.65)]' : look === 'dim' ? 'bg-slate-200' : 'bg-white/10')} />

      <span className="flex items-start gap-3">
        <span className={clsx('grid size-9 shrink-0 place-items-center rounded-xl', look === 'lit' ? 'bg-amber-400 text-navy-950 shadow-[0_0_0_4px_rgb(251_191_36/0.22)]' : look === 'dim' ? 'bg-slate-100 text-slate-500' : 'bg-white/5 text-white/45')}>
          {look === 'lit' ? <Lightbulb className="size-[18px]" /> : <LightbulbOff className="size-[18px]" />}
        </span>
        <span className="min-w-0 flex-1">
          <span className={clsx('block truncate text-base font-bold leading-tight', dark ? 'text-white' : 'text-navy-900')}>{room.name}</span>
          {place.length > 0 && <span className={clsx('mt-0.5 block truncate text-[11px]', dark ? 'text-white/45' : 'text-slate-500')}>{place.join(' · ')}</span>}
        </span>
        {dark
          ? !loading && <span className="shrink-0 rounded-full bg-white/8 px-2.5 py-0.5 text-xs font-bold text-white/60 ring-1 ring-inset ring-white/10">{t('liveT.box.off')}</span>
          : <span className="shrink-0"><StateChip state={state} label={stateLabel} /></span>}
      </span>

      {!session || !time ? (
        loading ? <span className="mt-5 block h-2.5 w-2/3 animate-pulse rounded-full bg-white/10" /> : <span className="mt-4 block text-[13px] leading-6 text-white/50">{t('liveT.tip.dark')}</span>
      ) : (
        <>
          <span className="mt-3.5 block">
            <span className="line-clamp-2 text-sm font-bold leading-snug text-ink">{session.program.title}</span>
            {session.group && <span className="mt-0.5 block truncate text-xs text-slate-500">{session.group.title}</span>}
          </span>

          {/* The time: when it runs, how much is behind and how long is left. */}
          <span className="mt-3 flex items-baseline justify-between gap-3">
            <span className="inline-flex items-center gap-1.5 text-xs font-semibold tabular-nums text-slate-600"><Clock className="size-3.5 self-center text-gold-600" />{fmt.time(session.starts_at)} – {fmt.time(session.ends_at)}</span>
            {time.phase === 'ended' || !today
              ? <span className="text-xs text-slate-500">{time.phase === 'ended' ? t('liveT.card.endedAt', { time: fmt.time(session.ends_at) }) : fmt.date(session.starts_at, { weekday: 'long' })}</span>
              : <span className="text-xs text-slate-500">{t(time.phase === 'live' ? 'liveT.box.left' : 'liveT.box.startsIn')} <bdi dir="ltr" className={clsx('font-display text-base font-bold tabular-nums', time.phase === 'live' ? 'text-navy-900' : 'text-slate-700')}>{countdown(time.leftMs)}</bdi></span>}
          </span>
          <span className="mt-1.5 block h-1 overflow-hidden rounded-full bg-navy-100/70" role="presentation">
            <span className={clsx('block h-full rounded-full', time.phase === 'live' ? 'bg-amber-500' : 'bg-slate-300')} style={{ width: `${time.share * 100}%` }} />
          </span>

          {/* Who is in the room */}
          <span className="mt-3.5 flex items-end justify-between gap-3">
            <span className="flex items-baseline gap-1.5">
              <span className="font-display text-3xl font-bold leading-none tabular-nums text-navy-900">{time.phase === 'upcoming' ? session.counts.expected : session.counts.present}</span>
              <span className="text-xs font-semibold text-slate-500">{time.phase === 'upcoming' ? t('liveT.box.expected') : t('liveT.box.ofExpected', { n: session.counts.expected })}</span>
            </span>
            <span className="flex flex-wrap items-center justify-end gap-1 text-[11px] font-bold">
              {time.phase !== 'upcoming' && <span className="rounded-full bg-navy-900 px-2 py-0.5 tabular-nums text-white">{fmt.percent(session.counts.percent)}</span>}
              {session.counts.late > 0 && <span className="rounded-full bg-amber-100 px-2 py-0.5 tabular-nums text-amber-800">{t('liveT.box.late', { n: session.counts.late })}</span>}
              {session.counts.left > 0 && <span className="rounded-full bg-slate-100 px-2 py-0.5 tabular-nums text-slate-600">{t('liveT.box.leftRoom', { n: session.counts.left })}</span>}
            </span>
          </span>
          <span className="mt-2 block"><Seats session={session} nowMs={nowMs} ended={time.phase === 'ended'} /></span>

          {/* The people and the kit */}
          <span className="mt-3.5 grid grid-cols-1 gap-1.5 border-t border-navy-100/70 pt-3 text-[13px]">
            <span className="flex items-start gap-2">
              <GraduationCap className="mt-0.5 size-4 shrink-0 text-gold-600" />
              {trainer ? (
                <span className="min-w-0 flex-1">
                  <span className="flex items-center gap-1.5">
                    <span className="min-w-0 truncate font-semibold text-ink">{trainer.name}</span>
                    {session.trainers.length > 1 && <span className="shrink-0 text-slate-500">+{session.trainers.length - 1}</span>}
                    {trainer.checked_in && <span className="size-1.5 shrink-0 rounded-full bg-emerald-500" title={String(t('liveT.card.checkedIn'))} />}
                  </span>
                  <span className="block truncate text-[11px] text-slate-500">{trainedText(trainer.programs_count, t)}</span>
                </span>
              ) : <span className="text-slate-500">{t('liveT.tip.noTrainer')}</span>}
            </span>
            <span className="flex items-center gap-2">
              <ShieldCheck className="size-4 shrink-0 text-gold-600" />
              {session.supervisor ? <><span className="shrink-0 text-slate-500">{t('liveT.tip.supervisor')}</span><span className="min-w-0 truncate font-semibold text-ink">{session.supervisor.name}</span></> : <span className="text-slate-500">{t('liveT.tip.noSupervisor')}</span>}
            </span>
            <span className="flex items-center gap-2">
              <PackageOpen className="size-4 shrink-0 text-gold-600" />
              {session.kit ? <span className="min-w-0 truncate font-semibold text-ink">{session.kit.title}</span> : <span className="text-slate-500">{t('liveT.tip.noKit')}</span>}
            </span>
          </span>

          {(signals.length > 0 || fresh || next) && (
            <span className="mt-auto flex flex-wrap items-center gap-1.5 pt-3">
              {signals.map((s) => (
                <span key={s.key} className={clsx('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-bold ring-1 ring-inset', TONE[s.tone])}>
                  {s.tone === 'info' ? <Info className="size-3" /> : <AlertTriangle className="size-3" />}{t(`liveT.box.signal.${s.key}`, { n: s.n })}
                </span>
              ))}
              {fresh && <span className="inline-flex min-w-0 items-center gap-1 rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-bold text-emerald-800 ring-1 ring-inset ring-emerald-600/25"><UserPlus className="size-3 shrink-0" /><span className="truncate">{t('liveT.box.joined', { name: fresh.name })}</span></span>}
              {next && !fresh && <span className="text-[11px] text-slate-500">{t('liveT.box.next', { time: fmt.time(next.starts_at) })}</span>}
            </span>
          )}
        </>
      )}
      {attention && <span aria-hidden className="absolute end-3 top-3 size-2 rounded-full bg-red-500 motion-safe:animate-ping" />}
    </button>
  )
}
