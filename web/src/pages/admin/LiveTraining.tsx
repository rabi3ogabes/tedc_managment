import clsx from 'clsx'
import { BellOff, BellRing, Boxes, CalendarDays, ChevronLeft, ChevronRight, DoorOpen, Expand, GraduationCap, LayoutGrid, LightbulbOff, Minus, PackageOpen, Plus, Radio, RotateCcw, ShieldCheck, Shrink, Users, Video } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { keepPreviousData } from '@tanstack/react-query'
import { Button, Card, ErrorState, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { playChime } from '@/lib/chime'
import { fmt, intlLocale } from '@/lib/format'
import { relativeTime } from '@/lib/relativeTime'
import { todayIso } from '../RoomScreen'
import { lastBoundary, pollEvery, roomsOnTheClock, sessionOnTheClock, trainersCheckIn } from './liveTraining/live'
import RoomBoard from './liveTraining/RoomBoard'
import Stage, { type StageHandle } from './liveTraining/Stage'
import { StateChip } from './liveTraining/StateChip'
import { trainedText } from './liveTraining/text'
import TrainingCard from './liveTraining/TrainingCard'
import type { Board, BoardRoom, RoomState, SceneRoom, SessionBrief } from './liveTraining/types'

const STAGE_HEIGHT = 'h-[clamp(520px,calc(100dvh-17.5rem),900px)]'
const STATE_ORDER: Record<RoomState, number> = { live: 0, soon: 1, later: 2, ended: 3, idle: 4 }
type View = 'boxes' | 'model'
const DOT: Record<RoomState, string> = { live: 'bg-emerald-500', soon: 'bg-amber-400', later: 'bg-navy-300', ended: 'bg-slate-400', idle: 'bg-slate-300' }

const shiftDay = (iso: string, days: number) => {
  const d = new Date(`${iso}T12:00:00`)
  d.setDate(d.getDate() + days)
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
}

/** The session a room shows: the one the administrator picked, else the running one, the next one or the last one. */
const sessionOf = (room: BoardRoom, picked?: string): SessionBrief | null =>
  room.sessions.find((s) => s.id === picked) ?? room.sessions.find((s) => s.id === room.focus_session_id) ?? null

/** How a room looks when it shows the given session (the room's own state belongs to its running or next session). */
const stateFor = (room: BoardRoom, s: SessionBrief | null): RoomState =>
  !s ? 'idle' : s.id === room.focus_session_id ? room.state : s.state === 'live' ? 'live' : s.state === 'ended' ? 'ended' : 'later'

/**
 * Live training: the centre's classrooms of one day, as a board of boxes or drawn in 3D. A lit room has training in it, a dark one has
 * none; every trainee who checks in takes a seat. Pressing a room opens its training card. The clock runs on the page: a room lights up,
 * counts down and goes dark on the second, and the server is asked again as often as the day needs (see `pollEvery`).
 */
export default function LiveTraining() {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const rtl = i18n.dir() === 'rtl'
  // No day chosen means today, and stays today when midnight passes on a screen left open.
  const [day, setDay] = useState<string | null>(null)
  const [view, setView] = useState<View>(() => { try { return localStorage.getItem('tedc.liveTraining.view') === 'model' ? 'model' : 'boxes' } catch { return 'boxes' } })
  const [selected, setSelected] = useState<string | null>(null)
  const [picked, setPicked] = useState<Record<string, string>>({})
  const [tip, setTip] = useState<{ id: string; x: number; y: number } | null>(null)
  const [tick, setTick] = useState(() => Date.now())
  const [full, setFull] = useState(false)
  const [sound, setSound] = useState(() => { try { return localStorage.getItem('tedc.liveTraining.sound') === '1' } catch { return false } })
  const root = useRef<HTMLDivElement>(null)
  const stage = useRef<StageHandle>(null)
  const today = todayIso()
  const date = day ?? today
  const isToday = date === today

  const { data, isLoading, isFetching, isError, error, refetch, dataUpdatedAt } = useGet<{ data: Board }>('/admin/live-training', { date }, {
    // As often as the day needs: every 5 seconds around a session, slower away from one, never for a day that is not today.
    refetchInterval: (query) => {
      const b = query.state.data?.data
      if (!isToday) return false
      return b ? pollEvery(b.rooms, b.unplaced, Date.now() + (Date.parse(b.now) - query.state.dataUpdatedAt)) : 5_000
    },
    staleTime: 0, placeholderData: keepPreviousData,
  })
  const board = data?.data

  // The clock of the page moves every second, set to the server's time so a browser that is a few minutes off still counts down right.
  useEffect(() => { const id = setInterval(() => setTick(Date.now()), 1_000); return () => clearInterval(id) }, [])
  const offset = useMemo(() => (board && dataUpdatedAt ? Date.parse(board.now) - dataUpdatedAt : 0), [board, dataUpdatedAt])
  const nowMs = tick + offset
  const now = useMemo(() => new Date(nowMs), [nowMs])
  useEffect(() => { const f = () => setFull(!!document.fullscreenElement); document.addEventListener('fullscreenchange', f); return () => document.removeEventListener('fullscreenchange', f) }, [])
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && !document.fullscreenElement) setSelected(null) }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [])

  const goTo = useCallback((to: string) => { setDay(to === todayIso() ? null : to); setPicked({}); setTip(null) }, [])
  const show = (next: View) => { setView(next); setTip(null); try { localStorage.setItem('tedc.liveTraining.view', next) } catch { /* storage unavailable */ } }
  const toggleSound = () => { const on = !sound; setSound(on); try { localStorage.setItem('tedc.liveTraining.sound', on ? '1' : '0') } catch { /* storage unavailable */ } if (on) playChime() }
  const toggleFull = () => void (document.fullscreenElement ? document.exitFullscreen() : root.current?.requestFullscreen())

  // A soft chime when somebody new checks in (only for today, and only when the administrator switched it on).
  const seen = useRef<{ date: string; ids: Set<string> } | null>(null)
  useEffect(() => {
    if (!board || board.date !== date) return
    const ids = new Set(board.arrivals.map((a) => a.id))
    if (seen.current?.date === board.date && board.is_today && sound && [...ids].some((id) => !seen.current!.ids.has(id))) playChime()
    seen.current = { date: board.date, ids: new Set([...(seen.current?.date === board.date ? seen.current.ids : []), ...ids]) }
  }, [board, date, sound])

  const tagLine = useCallback((state: RoomState, s: SessionBrief | null) => {
    if (!s) return String(t('liveT.tag.off'))
    if (state === 'live') return String(t('liveT.tag.live'))
    if (state === 'ended') return String(t('liveT.tag.ended', { time: fmt.time(s.ends_at) }))
    return String(t('liveT.tag.starts', { time: fmt.time(s.starts_at) }))
  }, [t])

  const fresh = board && board.date === date ? board : undefined
  // While another day is on its way the rooms stay where they are with their lights off, instead of vanishing and coming back.
  const loadingDay = !!board && board.date !== date
  const served = useMemo<BoardRoom[]>(() => fresh?.rooms ?? (board?.rooms ?? []).map((r) => ({ ...r, state: 'idle', focus_session_id: null, sessions: [] })), [fresh, board])
  // Today the rooms are read on the clock: a session that starts or ends between two answers of the server changes its room at once.
  // `epoch` moves only when something changes state, so the rooms are not worked out again every second.
  const clocked = !!fresh && fresh.is_today && isToday
  const epoch = clocked ? lastBoundary(served, fresh.unplaced, nowMs) : -1
  const rooms = useMemo(() => (epoch < 0 ? served : roomsOnTheClock(served, epoch)), [served, epoch])
  const unplaced = useMemo(() => (fresh?.unplaced ?? []).map((s) => (epoch < 0 ? s : sessionOnTheClock(s, epoch))), [fresh, epoch])
  const checkIns = useMemo(() => trainersCheckIn(rooms, unplaced), [rooms, unplaced])
  // A start or an end has just passed: ask the server now instead of waiting for the next round.
  const lastEpoch = useRef(epoch)
  useEffect(() => { if (epoch > 0 && lastEpoch.current > 0 && epoch !== lastEpoch.current) void refetch(); lastEpoch.current = epoch }, [epoch, refetch])
  const sceneRooms = useMemo<SceneRoom[]>(() => rooms.map((room) => {
    const s = sessionOf(room, picked[room.id])
    const state = stateFor(room, s)
    return {
      id: room.id, name: room.name, floor: room.floor, state, capacity: room.capacity, sessionKey: s?.id ?? 'none',
      title: s?.program.title ?? null, status: tagLine(state, s), expected: s?.counts.expected ?? 0, presentCount: s?.present.length ?? 0, seating: s?.seating ?? null,
      trainers: (s?.trainers ?? []).map((tr) => ({ id: tr.id, name: tr.name, here: tr.checked_in })), supervisor: s?.supervisor?.name ?? null,
      present: (s?.present ?? []).map((p) => ({ id: p.id, name: p.name, late: p.status === 'late', seat: p.seat })),
    }
  }), [rooms, picked, tagLine])

  const selectedRoom = rooms.find((r) => r.id === selected) ?? null
  const active = useMemo(() => rooms.filter((r) => r.sessions.length > 0).sort((a, b) => STATE_ORDER[a.state] - STATE_ORDER[b.state] || a.name.localeCompare(b.name, intlLocale())), [rooms])
  const dark = rooms.filter((r) => r.sessions.length === 0)
  const tipRoom = tip ? rooms.find((r) => r.id === tip.id) ?? null : null
  const totals = fresh?.totals

  const dayName = (iso: string) => iso === todayIso() ? t('liveT.day.today') : iso === shiftDay(todayIso(), -1) ? t('liveT.day.yesterday') : iso === shiftDay(todayIso(), 1) ? t('liveT.day.tomorrow') : fmt.date(`${iso}T12:00:00`, { weekday: 'long' })
  const Prev = rtl ? ChevronRight : ChevronLeft
  const Next = rtl ? ChevronLeft : ChevronRight
  const lightBtn = 'grid size-9 place-items-center rounded-xl text-navy-900 transition hover:bg-navy-50'
  // How fresh the picture is: seconds since the server last answered, and whether it has stopped answering.
  const age = dataUpdatedAt ? Math.max(0, Math.round((tick - dataUpdatedAt) / 1000)) : 0
  const lost = isToday && !!board && (isError || age > 45)
  const iconBtn = 'grid size-9 place-items-center rounded-xl bg-white/10 text-white backdrop-blur transition hover:bg-white/20 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-300'

  return (
    <div ref={root} className={clsx(full && 'min-h-screen overflow-auto bg-ivory p-5')}>
      <PageHeader
        title={<span className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-navy-900 text-gold-300"><Radio className="size-5" /></span>{t('liveT.title')}</span>}
        subtitle={t('liveT.subtitle')}
        actions={totals && (
          <dl className="flex flex-wrap items-stretch divide-x divide-navy-100 rounded-2xl border border-navy-100 bg-white rtl:divide-x-reverse">
            {([
              [isToday ? t('liveT.stats.roomsLive') : t('liveT.stats.roomsUsed'), isToday ? rooms.filter((r) => r.state === 'live').length : active.length, t('liveT.stats.of', { n: totals.rooms })],
              [t('liveT.stats.sessions'), totals.sessions, null],
              [isToday ? t('liveT.stats.inRooms') : t('liveT.stats.attended'), isToday ? totals.in_rooms_now : totals.present, t('liveT.stats.of', { n: totals.expected })],
              [t('liveT.stats.attendance'), fmt.percent(totals.percent), null],
            ] as const).map(([label, value, hint]) => (
              <div key={String(label)} className="px-4 py-2">
                <dt className="text-[11px] text-slate-500">{label}</dt>
                <dd className="font-display text-xl font-bold leading-tight text-navy-900 tabular-nums">{value}{hint && <span className="ms-1.5 font-sans text-xs font-semibold text-slate-500">{hint}</span>}</dd>
              </div>
            ))}
          </dl>
        )}
      />

      {/* The day: yesterday, today, tomorrow, or any day from the calendar. */}
      <div className="mb-4 flex flex-wrap items-center gap-3">
        <div className="flex items-center gap-1 rounded-2xl border border-navy-100 bg-white p-1">
          <button type="button" onClick={() => goTo(shiftDay(date, -1))} aria-label={String(t('liveT.day.prev'))} className="grid size-9 place-items-center rounded-xl text-navy-900 hover:bg-navy-50"><Prev className="size-5" /></button>
          <div className="min-w-[11.5rem] px-2 text-center">
            <p className="text-sm font-bold text-navy-900">{dayName(date)}</p>
            <p className="text-xs text-slate-500">{fmt.date(`${date}T12:00:00`)}</p>
          </div>
          <button type="button" onClick={() => goTo(shiftDay(date, 1))} aria-label={String(t('liveT.day.next'))} className="grid size-9 place-items-center rounded-xl text-navy-900 hover:bg-navy-50"><Next className="size-5" /></button>
        </div>

        <div className="flex items-center gap-1 overflow-x-auto rounded-2xl border border-navy-100 bg-white p-1" role="group" aria-label={String(t('liveT.day.pick'))}>
          {(fresh?.strip ?? board?.strip ?? []).map((d) => (
            <button key={d.date} type="button" aria-pressed={d.date === date} onClick={() => goTo(d.date)} title={d.sessions ? String(t('liveT.day.sessions', { n: d.sessions })) : String(t('liveT.day.none'))}
              className={clsx('flex w-12 shrink-0 flex-col items-center rounded-xl py-1.5 transition', d.date === date ? 'bg-navy-900 text-white' : 'text-navy-900 hover:bg-navy-50', d.date === todayIso() && d.date !== date && 'ring-1 ring-inset ring-gold-400')}>
              <span className={clsx('text-[10px] font-semibold', d.date === date ? 'text-white/75' : 'text-slate-500')}>{fmt.date(`${d.date}T12:00:00`, { weekday: 'short' })}</span>
              <span className="text-sm font-bold tabular-nums">{fmt.date(`${d.date}T12:00:00`, { day: 'numeric' })}</span>
              <span className={clsx('mt-0.5 h-1 rounded-full transition-all', d.sessions ? (d.date === date ? 'w-4 bg-gold-300' : 'w-4 bg-gold-500') : 'w-1 bg-slate-200')} />
            </button>
          ))}
        </div>

        <label className="relative inline-flex cursor-pointer items-center gap-2 rounded-2xl border border-navy-100 bg-white px-4 py-2.5 text-sm font-bold text-navy-900 hover:border-gold-400">
          <CalendarDays className="size-4 text-gold-600" />{t('liveT.day.pick')}
          <input type="date" value={date} onChange={(e) => e.target.value && goTo(e.target.value)} className="absolute inset-0 cursor-pointer opacity-0" aria-label={String(t('liveT.day.pick'))} />
        </label>
        {!isToday && <Button variant="outline" size="sm" onClick={() => goTo(todayIso())}>{t('liveT.day.backToday')}</Button>}

        <div className="ms-auto flex flex-wrap items-center gap-2">
          <span className={clsx('inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-bold tabular-nums', !isToday ? 'bg-slate-100 text-slate-600' : lost ? 'bg-amber-50 text-amber-800' : 'bg-emerald-50 text-emerald-700')} role="status">
            <span className={clsx('size-2 rounded-full', !isToday ? 'bg-slate-400' : lost ? 'bg-amber-500' : 'bg-emerald-500', isToday && isFetching && 'motion-safe:animate-ping')} />
            {!isToday ? t('liveT.frozen') : lost ? t('liveT.live.lost') : age < 2 ? t('liveT.live.now') : t('liveT.live.ago', { s: age })}
          </span>

          <div className="flex items-center gap-1 rounded-2xl border border-navy-100 bg-white p-1" role="group" aria-label={String(t('liveT.view.label'))}>
            {([['boxes', LayoutGrid], ['model', Boxes]] as const).map(([key, Icon]) => (
              <button key={key} type="button" aria-pressed={view === key} onClick={() => show(key)}
                className={clsx('inline-flex h-9 items-center gap-2 rounded-xl px-3 text-xs font-bold transition', view === key ? 'bg-navy-900 text-white' : 'text-navy-900 hover:bg-navy-50')}>
                <Icon className="size-4" />{t(`liveT.view.${key}`)}
              </button>
            ))}
          </div>
          {view === 'boxes' && (
            <div className="flex items-center gap-1 rounded-2xl border border-navy-100 bg-white p-1">
              <button type="button" className={lightBtn} aria-pressed={sound} onClick={toggleSound} aria-label={String(t(sound ? 'liveT.stage.soundOn' : 'liveT.stage.soundOff'))} title={String(t(sound ? 'liveT.stage.soundOn' : 'liveT.stage.soundOff'))}>{sound ? <BellRing className="size-4 text-gold-600" /> : <BellOff className="size-4" />}</button>
              <button type="button" className={lightBtn} onClick={toggleFull} aria-label={String(t(full ? 'liveT.stage.exitFullscreen' : 'liveT.stage.fullscreen'))} title={String(t(full ? 'liveT.stage.exitFullscreen' : 'liveT.stage.fullscreen'))}>{full ? <Shrink className="size-4" /> : <Expand className="size-4" />}</button>
            </div>
          )}
        </div>
      </div>

      {isLoading && !board ? <Spinner /> : error && !board ? <Card><ErrorState error={error} onRetry={() => void refetch()} home="/admin" /></Card> : rooms.length === 0 && fresh ? (
        <Card className="py-12 text-center">
          <span className="mx-auto grid size-14 place-items-center rounded-2xl bg-ivory-dark text-slate-400"><DoorOpen className="size-7" /></span>
          <p className="mx-auto mt-4 max-w-sm text-sm leading-7 text-slate-600">{t('liveT.rail.noRooms')}</p>
          {can('programs.view') && <Button to="/admin/rooms" variant="outline" size="sm" className="mt-4">{t('liveT.rail.manageRooms')}</Button>}
        </Card>
      ) : view === 'boxes' ? (
        <div className={clsx('grid items-start gap-4', selectedRoom && 'xl:grid-cols-[minmax(0,1fr)_25rem]')}>
          {/* The rooms as boxes; on a narrow screen the training card takes their place while it is open */}
          <div className={clsx('min-w-0', selectedRoom && 'hidden xl:block')}>
            <RoomBoard rooms={rooms} unplaced={unplaced} arrivals={fresh?.arrivals ?? []} picked={picked} nowMs={nowMs} today={clocked} checkIns={checkIns} selected={selected} loading={loadingDay} narrow={!!selectedRoom} onOpen={setSelected} />
          </div>
          {selectedRoom && (
            <div className="xl:sticky xl:top-4 xl:max-h-[calc(100dvh-2rem)] xl:overflow-y-auto xl:pe-1">
              <TrainingCard key={selectedRoom.id} room={selectedRoom} session={sessionOf(selectedRoom, picked[selectedRoom.id])} date={date} now={now} onBack={() => setSelected(null)} onPick={(id) => setPicked((p) => ({ ...p, [selectedRoom.id]: id }))} />
            </div>
          )}
        </div>
      ) : (
        <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_25rem]">
          {/* The 3D stage */}
          <div className={clsx('relative overflow-hidden rounded-3xl border border-navy-950/40 shadow-glass', STAGE_HEIGHT, full && '!h-[calc(100dvh-13rem)]')} style={{ background: 'radial-gradient(120% 85% at 50% 0%, #43182b 0%, #210d18 52%, #12070d 100%)' }}>
            <Stage ref={stage} rooms={sceneRooms} selected={selected} onSelect={setSelected} onHover={(id, x, y) => setTip(id ? { id, x, y } : null)} />

            <div className="pointer-events-none absolute inset-x-3 top-3 flex items-start justify-between gap-3">
              <ul className="hidden flex-wrap gap-1.5 text-[11px] font-semibold text-white/85 sm:flex">
                {([['bg-[#ffe2a0]', 'on'], ['bg-[#8f7d86]', 'dim'], ['bg-[#3a2832] ring-1 ring-white/25', 'off']] as const).map(([dot, k]) => (
                  <li key={k} className="inline-flex items-center gap-1.5 rounded-full bg-black/30 px-2.5 py-1 backdrop-blur"><span className={clsx('size-2 rounded-full', dot)} />{t(`liveT.legend.${k}`)}</li>
                ))}
              </ul>
              <div className="pointer-events-auto ms-auto flex shrink-0 items-center gap-1.5">
                {selected && <button type="button" onClick={() => setSelected(null)} className="inline-flex h-9 items-center gap-1.5 rounded-xl bg-white px-3 text-xs font-bold text-navy-900 shadow hover:bg-gold-50">{t('liveT.stage.all')}</button>}
                <button type="button" className={iconBtn} onClick={() => stage.current?.zoom(0.8)} aria-label={String(t('liveT.stage.zoomIn'))} title={String(t('liveT.stage.zoomIn'))}><Plus className="size-4" /></button>
                <button type="button" className={iconBtn} onClick={() => stage.current?.zoom(1.25)} aria-label={String(t('liveT.stage.zoomOut'))} title={String(t('liveT.stage.zoomOut'))}><Minus className="size-4" /></button>
                <button type="button" className={iconBtn} onClick={() => stage.current?.reset()} aria-label={String(t('liveT.stage.reset'))} title={String(t('liveT.stage.reset'))}><RotateCcw className="size-4" /></button>
                <button type="button" className={iconBtn} aria-pressed={sound} onClick={toggleSound} aria-label={String(t(sound ? 'liveT.stage.soundOn' : 'liveT.stage.soundOff'))} title={String(t(sound ? 'liveT.stage.soundOn' : 'liveT.stage.soundOff'))}>{sound ? <BellRing className="size-4 text-gold-300" /> : <BellOff className="size-4" />}</button>
                <button type="button" className={iconBtn} onClick={toggleFull} aria-label={String(t(full ? 'liveT.stage.exitFullscreen' : 'liveT.stage.fullscreen'))} title={String(t(full ? 'liveT.stage.exitFullscreen' : 'liveT.stage.fullscreen'))}>{full ? <Shrink className="size-4" /> : <Expand className="size-4" />}</button>
              </div>
            </div>

            {loadingDay && <div className="pointer-events-none absolute inset-0 grid place-items-center" role="status"><span className="rounded-full bg-black/45 px-4 py-2 text-sm font-semibold text-white backdrop-blur">{t('common.loading')}</span></div>}

            <div className="pointer-events-none absolute inset-x-3 bottom-3 flex items-end justify-between gap-4">
              {fresh && fresh.arrivals.length > 0 ? (
                <div className="max-w-[60%] rounded-2xl bg-black/35 px-3.5 py-2.5 text-white backdrop-blur" aria-live="polite">
                  <p className="mb-1.5 text-[11px] font-bold text-gold-300">{t('liveT.arrivals.title')}</p>
                  <ul className="space-y-1">
                    {fresh.arrivals.slice(0, 3).map((a) => (
                      <li key={a.id} className="flex items-baseline gap-2 text-xs">
                        <span className="truncate font-bold">{a.name}</span>
                        <span className="truncate text-white/70">{a.room ? t('liveT.arrivals.into', { room: a.room }) : a.program}</span>
                        {a.status === 'late' && <span className="shrink-0 rounded-full bg-amber-400/90 px-1.5 text-[10px] font-bold text-black">{t('liveT.arrivals.late')}</span>}
                        <span className="ms-auto shrink-0 text-[11px] tabular-nums text-white/60">{isToday ? relativeTime(a.at, now, intlLocale()) : fmt.time(a.at)}</span>
                      </li>
                    ))}
                  </ul>
                </div>
              ) : <span />}
              <p className="hidden max-w-[16rem] text-end text-[11px] leading-5 text-white/55 md:block">{t('liveT.stage.hint')}</p>
            </div>
          </div>

          {/* The room list, or the training card of the room in focus */}
          <div className={clsx('xl:h-[clamp(520px,calc(100dvh-17.5rem),900px)] xl:overflow-y-auto xl:pe-1', full && 'xl:!h-[calc(100dvh-13rem)]')}>
            {selectedRoom ? (
              <TrainingCard key={selectedRoom.id} room={selectedRoom} session={sessionOf(selectedRoom, picked[selectedRoom.id])} date={date} now={now} onBack={() => setSelected(null)} onPick={(id) => setPicked((p) => ({ ...p, [selectedRoom.id]: id }))} />
            ) : (
              <Card padded={false} className="overflow-hidden">
                <h2 className="px-5 pb-2 pt-4 text-sm font-bold text-navy-900">{t('liveT.rail.title')}</h2>
                {loadingDay ? <Spinner className="!py-8" /> : active.length === 0 && unplaced.length === 0 && <p className="px-5 pb-4 text-sm leading-7 text-slate-600">{t('liveT.rail.nothing')}</p>}
                <ul>
                  {active.map((room) => <RoomRow key={room.id} room={room} session={sessionOf(room, picked[room.id])} onOpen={() => setSelected(room.id)} />)}
                </ul>

                {unplaced.length > 0 && (
                  <div className="border-t border-navy-100/70 px-5 py-3">
                    <h3 className="mb-2 flex items-center gap-2 text-xs font-bold text-slate-600"><Video className="size-3.5" />{t('liveT.rail.outside')}</h3>
                    <ul className="space-y-2">
                      {unplaced.map((s) => (
                        <li key={s.id} className="text-sm">
                          <p className="truncate font-semibold text-ink">{s.program.title}</p>
                          <p className="flex items-center gap-2 text-xs text-slate-500"><span className="tabular-nums">{fmt.time(s.starts_at)} – {fmt.time(s.ends_at)}</span><span>{s.mode === 'online' ? t('liveT.rail.online') : s.location ?? t('liveT.rail.noRoom')}</span><span className="ms-auto tabular-nums">{t('liveT.rail.count', { present: s.counts.present, expected: s.counts.expected })}</span></p>
                        </li>
                      ))}
                    </ul>
                  </div>
                )}

                {dark.length > 0 && (
                  <div className="border-t border-navy-100/70 px-5 py-3">
                    <h3 className="mb-2 flex items-center gap-2 text-xs font-bold text-slate-600"><LightbulbOff className="size-3.5" />{t('liveT.rail.dark')}<span className="rounded-full bg-slate-100 px-1.5 tabular-nums">{dark.length}</span></h3>
                    <div className="flex flex-wrap gap-1.5">
                      {dark.map((room) => <button key={room.id} type="button" onClick={() => setSelected(room.id)} className="rounded-lg bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600 transition hover:bg-navy-900 hover:text-white">{room.name}</button>)}
                    </div>
                  </div>
                )}
              </Card>
            )}
          </div>
        </div>
      )}

      {/* The small details of a room, under the pointer */}
      {view === 'model' && tipRoom && tip && tipRoom.id !== selected && <HoverTip room={tipRoom} session={sessionOf(tipRoom, picked[tipRoom.id])} x={tip.x} y={tip.y} />}
    </div>
  )
}

function RoomRow({ room, session, onOpen }: { room: BoardRoom; session: SessionBrief | null; onOpen: () => void }) {
  const { t } = useTranslation()
  if (!session) return null
  const state = stateFor(room, session)
  return (
    <li className="border-t border-navy-100/70 first:border-t-0">
      <button type="button" onClick={onOpen} className="block w-full px-5 py-3 text-start transition hover:bg-ivory focus-visible:bg-ivory focus-visible:outline-none">
        <span className="flex items-center gap-2">
          <span className={clsx('size-2.5 shrink-0 rounded-full', DOT[state], state === 'live' && 'animate-pulse')} />
          <span className="min-w-0 flex-1 truncate text-sm font-bold text-navy-900">{room.name}</span>
          <span className="shrink-0 text-xs tabular-nums text-slate-500">{fmt.time(session.starts_at)} – {fmt.time(session.ends_at)}</span>
        </span>
        <span className="mt-1 block truncate text-sm text-ink">{session.program.title}</span>
        <span className="mt-0.5 flex items-center gap-3 text-xs text-slate-500">
          {session.trainers[0] && <span className="inline-flex min-w-0 items-center gap-1"><GraduationCap className="size-3.5 shrink-0" /><span className="truncate">{session.trainers[0].name}</span></span>}
          <span className="ms-auto inline-flex shrink-0 items-center gap-1 font-semibold tabular-nums text-navy-800"><Users className="size-3.5" />{t('liveT.rail.count', { present: session.counts.present, expected: session.counts.expected })}</span>
        </span>
        <span className="mt-2 block h-1 overflow-hidden rounded-full bg-navy-100/70"><span className={clsx('block h-full rounded-full transition-all duration-700', state === 'live' ? 'bg-emerald-500' : 'bg-navy-400')} style={{ width: `${Math.min(100, session.counts.percent)}%` }} /></span>
      </button>
    </li>
  )
}

function HoverTip({ room, session, x, y }: { room: BoardRoom; session: SessionBrief | null; x: number; y: number }) {
  const { t } = useTranslation()
  const state = stateFor(room, session)
  const left = Math.max(12, Math.min(x + 18, window.innerWidth - 316))
  const top = Math.max(12, Math.min(y + 18, window.innerHeight - 270))
  const trainer = session?.trainers[0]
  return (
    <div role="tooltip" className="pointer-events-none fixed z-40 w-[300px] rounded-2xl border border-navy-100 bg-white/97 p-4 text-sm shadow-glass backdrop-blur" style={{ left, top }}>
      <div className="flex items-center justify-between gap-2">
        <p className="truncate text-base font-bold text-navy-900">{room.name}</p>
        <StateChip state={state} label={String(t(`liveT.state.${state}`))} />
      </div>
      {!session ? (
        <>
          <p className="mt-2 text-slate-600">{t('liveT.tip.dark')}</p>
          <p className="mt-1 text-xs text-slate-500">{t('liveT.tip.capacity', { n: room.capacity })}</p>
        </>
      ) : (
        <>
          <p className="mt-1.5 font-semibold leading-snug text-ink">{session.program.title}</p>
          <p className="mt-0.5 text-xs text-slate-500"><span className="tabular-nums">{fmt.time(session.starts_at)} – {fmt.time(session.ends_at)}</span></p>
          <ul className="mt-3 space-y-2 text-[13px]">
            <li className="flex items-start gap-2"><Users className="mt-0.5 size-4 shrink-0 text-gold-600" /><span className="font-semibold tabular-nums text-ink">{session.state === 'upcoming' ? t('liveT.tip.expectedOnly', { expected: session.counts.expected }) : t('liveT.tip.trainees', { present: session.counts.present, expected: session.counts.expected })}</span></li>
            <li className="flex items-start gap-2"><GraduationCap className="mt-0.5 size-4 shrink-0 text-gold-600" /><span className="min-w-0">{trainer ? <><span className="block truncate font-semibold text-ink">{trainer.name}</span><span className="block text-xs text-slate-500">{trainedText(trainer.programs_count, t)}</span></> : <span className="text-slate-500">{t('liveT.tip.noTrainer')}</span>}</span></li>
            <li className="flex items-start gap-2"><ShieldCheck className="mt-0.5 size-4 shrink-0 text-gold-600" /><span className="min-w-0 truncate">{session.supervisor ? <><span className="text-slate-500">{t('liveT.tip.supervisor')}: </span><span className="font-semibold text-ink">{session.supervisor.name}</span></> : <span className="text-slate-500">{t('liveT.tip.noSupervisor')}</span>}</span></li>
            <li className="flex items-start gap-2"><PackageOpen className="mt-0.5 size-4 shrink-0 text-gold-600" /><span className="min-w-0 truncate">{session.kit ? <span className="font-semibold text-ink">{session.kit.title}</span> : <span className="text-slate-500">{t('liveT.tip.noKit')}</span>}</span></li>
          </ul>
        </>
      )}
      <p className="mt-3 border-t border-navy-100/70 pt-2 text-[11px] font-semibold text-navy-800">{t('liveT.tip.press')}</p>
    </div>
  )
}
