import clsx from 'clsx'
import { CalendarDays, ChevronLeft, ChevronRight, Clock, DoorOpen, Expand, MapPin, Radio, Shrink, UserRound, Users } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { api } from '@/lib/api'
import { fmt } from '@/lib/format'

type Trainee = { name: string; school: string | null; status: 'present' | 'late' | 'expected' | 'absent'; check_in_at: string | null }
type Session = {
  id: string; title: string; sequence: number; mode: string; program: { code: string; title: string }; trainer: { name: string; photo: string | null } | null
  starts_at: string; ends_at: string; minutes: number; state: 'ended' | 'live' | 'next' | 'upcoming'
  counts: { expected: number; present: number; late: number; absent: number }; trainees: Trainee[]
}
type Day = { room: { name: string; code: string; building: string | null; floor: string | null; capacity: number }; date: string; is_today: boolean; now: string; sessions: Session[] }

const STATUS = {
  present: { dot: 'bg-emerald-400', chip: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-100' },
  late: { dot: 'bg-amber-400', chip: 'border-amber-400/30 bg-amber-400/10 text-amber-100' },
  expected: { dot: 'bg-white/30', chip: 'border-white/10 bg-white/5 text-white/70' },
  absent: { dot: 'bg-red-400', chip: 'border-red-400/30 bg-red-400/10 text-red-100' },
} as const

const clock = (d: Date) => d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
const addDays = (iso: string, n: number) => { const d = new Date(`${iso}T12:00:00`); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10) }
const todayIso = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` }

function Ring({ value, total }: { value: number; total: number }) {
  const r = 52
  const c = 2 * Math.PI * r
  const pct = total ? value / total : 0
  return (
    <div className="relative grid place-items-center">
      <svg viewBox="0 0 120 120" className="size-36 -rotate-90"><circle cx="60" cy="60" r={r} fill="none" strokeWidth="9" className="stroke-white/10" /><circle cx="60" cy="60" r={r} fill="none" strokeWidth="9" strokeLinecap="round" className="stroke-gold-400 transition-all duration-700" strokeDasharray={c} strokeDashoffset={c - c * pct} /></svg>
      <div className="absolute text-center" dir="ltr"><div className="text-4xl font-extrabold tabular-nums text-white">{value}<span className="text-lg font-semibold text-white/50"> / {total}</span></div></div>
    </div>
  )
}

/**
 * The live screen of one classroom for one day: the session in progress (program, trainer, time, who is present),
 * and the rest of the day. Shown at the room's door by its secret address, or inside the admin area.
 */
export default function RoomScreen({ admin = false }: { admin?: boolean }) {
  const { token, id } = useParams()
  const { t, i18n } = useTranslation()
  const [date, setDate] = useState(todayIso())
  const [day, setDay] = useState<Day | null>(null)
  const [error, setError] = useState(false)
  const [now, setNow] = useState(new Date())
  const [picked, setPicked] = useState<string | null>(null)
  const [full, setFull] = useState(false)
  const root = useRef<HTMLDivElement>(null)
  const url = admin ? `/admin/rooms/${id}/screen` : `/public/room-screen/${token}`

  // Data: refreshed every 15 seconds; the clock ticks every second.
  useEffect(() => {
    let alive = true
    const load = () => api.get<{ data: Day }>(url, { params: { date } }).then((r) => { if (alive) { setDay(r.data.data); setError(false) } }).catch(() => alive && setError(true))
    void load()
    const poll = window.setInterval(load, 15_000)
    const tick = window.setInterval(() => setNow(new Date()), 1000)
    return () => { alive = false; window.clearInterval(poll); window.clearInterval(tick) }
  }, [url, date])

  // A screen left on today moves to the new day at midnight.
  const [today, setToday] = useState(todayIso())
  useEffect(() => { const d = todayIso(); if (d !== today) { if (date === today) setDate(d); setToday(d) } }, [now, today, date])
  useEffect(() => { const f = () => setFull(!!document.fullscreenElement); document.addEventListener('fullscreenchange', f); return () => document.removeEventListener('fullscreenchange', f) }, [])

  const sessions = useMemo(() => day?.sessions ?? [], [day])
  const featured = useMemo(() => sessions.find((s) => s.id === picked) ?? sessions.find((s) => s.state === 'live') ?? sessions.find((s) => s.state === 'next') ?? sessions.find((s) => s.state === 'upcoming') ?? sessions.at(-1) ?? null, [sessions, picked])
  const isToday = date === today
  const start = featured ? new Date(featured.starts_at) : null
  const end = featured ? new Date(featured.ends_at) : null
  const progress = featured && start && end ? Math.min(100, Math.max(0, ((now.getTime() - start.getTime()) / (end.getTime() - start.getTime())) * 100)) : 0
  const minutesLeft = end ? Math.max(0, Math.ceil((end.getTime() - now.getTime()) / 60000)) : 0
  const minutesTo = start ? Math.max(0, Math.ceil((start.getTime() - now.getTime()) / 60000)) : 0

  return (
    <div ref={root} dir={i18n.language === 'ar' ? 'rtl' : 'ltr'} className="relative min-h-screen overflow-hidden bg-gradient-to-br from-navy-950 via-navy-900 to-navy-800 text-white">
      <div className="pattern-bg pointer-events-none absolute inset-0 opacity-[.07]" />
      <div className="pointer-events-none absolute -end-40 -top-40 size-[34rem] rounded-full bg-gold-500/15 blur-3xl" />

      <div className="relative mx-auto flex min-h-screen max-w-[110rem] flex-col gap-6 p-5 sm:p-8">
        {/* Header */}
        <header className="flex flex-wrap items-center gap-4">
          <div className="flex min-w-0 items-center gap-4">
            <span className="grid size-14 shrink-0 place-items-center rounded-2xl bg-gold-500 text-navy-950 shadow-lg"><DoorOpen className="size-7" /></span>
            <div className="min-w-0">
              <h1 className="truncate text-3xl font-extrabold sm:text-4xl">{day?.room.name ?? '…'}</h1>
              <p className="flex flex-wrap items-center gap-x-3 text-sm text-white/65">
                {(day?.room.building || day?.room.floor) && <span className="inline-flex items-center gap-1"><MapPin className="size-4" />{[day?.room.building, day?.room.floor].filter(Boolean).join(' · ')}</span>}
                {day && <span className="inline-flex items-center gap-1"><Users className="size-4" />{t('studio.screen.capacity', { count: day.room.capacity })}</span>}
              </p>
            </div>
          </div>
          <div className="ms-auto flex items-center gap-5">
            <div className="text-end"><div className="text-5xl font-extrabold tabular-nums leading-none tracking-tight" dir="ltr">{clock(now)}</div><div className="mt-1 text-sm text-white/65">{fmt.date(now.toISOString(), { weekday: 'long', day: 'numeric', month: 'long' })}</div></div>
            <button type="button" aria-label="fullscreen" onClick={() => void (document.fullscreenElement ? document.exitFullscreen() : root.current?.requestFullscreen())} className="grid size-11 place-items-center rounded-xl border border-white/15 bg-white/5 hover:bg-white/10">{full ? <Shrink className="size-5" /> : <Expand className="size-5" />}</button>
          </div>
        </header>

        {/* Day selector */}
        <div className="flex flex-wrap items-center gap-2">
          <button type="button" aria-label="previous day" onClick={() => setDate(addDays(date, -1))} className="grid size-10 place-items-center rounded-xl border border-white/15 bg-white/5 hover:bg-white/10"><ChevronRight className="size-5 ltr:rotate-180" /></button>
          <label className="relative inline-flex items-center gap-2 rounded-xl border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold"><CalendarDays className="size-4 text-gold-300" />{fmt.date(`${date}T12:00:00`, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}<input type="date" value={date} onChange={(e) => e.target.value && setDate(e.target.value)} className="absolute inset-0 cursor-pointer opacity-0" aria-label="date" /></label>
          <button type="button" aria-label="next day" onClick={() => setDate(addDays(date, 1))} className="grid size-10 place-items-center rounded-xl border border-white/15 bg-white/5 hover:bg-white/10"><ChevronLeft className="size-5 ltr:rotate-180" /></button>
          {!isToday && <button type="button" onClick={() => setDate(today)} className="rounded-xl bg-gold-500 px-4 py-2 text-sm font-bold text-navy-950">{t('studio.screen.today')}</button>}
          <span className="ms-auto inline-flex items-center gap-2 text-xs text-white/55"><span className={clsx('size-2 rounded-full', error ? 'bg-red-400' : 'animate-pulse bg-emerald-400')} />{error ? t('studio.screen.offline') : t('studio.screen.updating')}</span>
        </div>

        {!day ? <div className="grid flex-1 place-items-center text-white/60">{error ? t('studio.screen.notFound') : '…'}</div> : sessions.length === 0 ? (
          <div className="grid flex-1 place-items-center text-center"><div><CalendarDays className="mx-auto size-16 text-gold-300/70" /><p className="mt-4 text-3xl font-extrabold">{t('studio.screen.none')}</p><p className="mt-1 text-white/60">{t('studio.screen.noneHint')}</p></div></div>
        ) : featured && (
          <div className="grid flex-1 gap-6 xl:grid-cols-[minmax(0,1fr)_24rem]">
            <main className="flex min-w-0 flex-col gap-6">
              {/* The session */}
              <section className="rounded-3xl border border-white/10 bg-white/[.06] p-6 shadow-2xl backdrop-blur sm:p-8">
                <div className="flex flex-wrap items-center gap-3">
                  {featured.state === 'live' && <span className="inline-flex items-center gap-2 rounded-full bg-emerald-500 px-4 py-1 text-sm font-bold"><Radio className="size-4 animate-pulse" />{t('studio.screen.live')}</span>}
                  {featured.state === 'next' && <span className="rounded-full bg-gold-500 px-4 py-1 text-sm font-bold text-navy-950">{t('studio.screen.next')}</span>}
                  {featured.state === 'ended' && <span className="rounded-full bg-white/15 px-4 py-1 text-sm font-bold">{t('studio.screen.ended')}</span>}
                  {featured.mode === 'online' && <span className="rounded-full border border-white/20 px-3 py-1 text-xs">{t('studio.screen.online')}</span>}
                  <span className="font-mono text-sm text-white/55" dir="ltr">{featured.program.code}</span>
                </div>
                <h2 className="mt-4 text-4xl font-extrabold leading-tight sm:text-5xl">{featured.program.title}</h2>
                <p className="mt-1 text-xl text-gold-300">{featured.title}</p>

                <div className="mt-8 grid gap-6 lg:grid-cols-[1fr_auto] lg:items-center">
                  <div className="space-y-6">
                    <div className="flex flex-wrap items-center gap-x-8 gap-y-4">
                      <div className="flex items-center gap-3"><Clock className="size-8 text-gold-300" /><div className="text-4xl font-extrabold tabular-nums sm:text-5xl" dir="ltr">{start && clock(start)} <span className="text-white/40">→</span> {end && clock(end)}</div></div>
                      {featured.trainer && (
                        <div className="flex items-center gap-3">
                          {featured.trainer.photo ? <img src={featured.trainer.photo} alt="" className="size-14 rounded-full object-cover ring-2 ring-gold-400" /> : <span className="grid size-14 place-items-center rounded-full bg-gold-500/20 text-gold-300 ring-2 ring-gold-400/60"><UserRound className="size-7" /></span>}
                          <div><div className="text-xs text-white/55">{t('studio.screen.trainer')}</div><div className="text-2xl font-bold">{featured.trainer.name}</div></div>
                        </div>
                      )}
                    </div>
                    {isToday && featured.state !== 'ended' && (
                      <div>
                        <div className="mb-2 flex justify-between text-sm text-white/70"><span>{featured.state === 'live' ? t('studio.screen.remaining', { minutes: minutesLeft }) : t('studio.screen.startsIn', { minutes: minutesTo })}</span><span dir="ltr">{featured.minutes} {t('studio.screen.min')}</span></div>
                        <div className="h-3 overflow-hidden rounded-full bg-white/10"><div className="h-full rounded-full bg-gradient-to-r from-gold-500 to-gold-300 transition-all duration-1000" style={{ width: `${featured.state === 'live' ? progress : 0}%` }} /></div>
                      </div>
                    )}
                  </div>
                  <div className="flex items-center gap-6 lg:ps-6">
                    <Ring value={featured.counts.present} total={featured.counts.expected} />
                    <ul className="space-y-1.5 text-sm">
                      <li className="flex items-center gap-2"><span className="size-2.5 rounded-full bg-emerald-400" />{t('studio.screen.present')} <b>{featured.counts.present - featured.counts.late}</b></li>
                      <li className="flex items-center gap-2"><span className="size-2.5 rounded-full bg-amber-400" />{t('studio.screen.late')} <b>{featured.counts.late}</b></li>
                      <li className="flex items-center gap-2"><span className="size-2.5 rounded-full bg-white/30" />{t(featured.state === 'ended' ? 'studio.screen.absent' : 'studio.screen.expected')} <b>{featured.state === 'ended' ? featured.counts.absent : featured.counts.expected - featured.counts.present}</b></li>
                    </ul>
                  </div>
                </div>
              </section>

              {/* Trainees */}
              <section className="flex-1 rounded-3xl border border-white/10 bg-white/[.04] p-6">
                <h3 className="mb-4 flex items-center gap-2 text-lg font-bold"><Users className="size-5 text-gold-300" />{t('studio.screen.trainees')}<span className="text-white/50">({featured.counts.expected})</span></h3>
                {featured.trainees.length === 0 ? <p className="text-white/55">{t('studio.screen.noTrainees')}</p> : (
                  <ul className="grid gap-2 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                    {featured.trainees.map((p, i) => (
                      <li key={i} className={clsx('flex items-center gap-3 rounded-xl border px-3 py-2.5 transition-colors duration-500', STATUS[p.status].chip)}>
                        <span className={clsx('size-2.5 shrink-0 rounded-full', STATUS[p.status].dot, p.status === 'present' && 'shadow-[0_0_10px_2px_rgba(52,211,153,.6)]')} />
                        <span className="min-w-0 flex-1"><span className="block truncate font-semibold">{p.name}</span>{p.school && <span className="block truncate text-xs opacity-60">{p.school}</span>}</span>
                        {p.check_in_at && <span className="text-xs tabular-nums opacity-70" dir="ltr">{clock(new Date(p.check_in_at))}</span>}
                      </li>
                    ))}
                  </ul>
                )}
              </section>
            </main>

            {/* The day */}
            <aside className="space-y-3">
              <h3 className="text-lg font-bold">{t('studio.screen.day')}</h3>
              <ol className="relative space-y-3 ps-6 before:absolute before:inset-y-2 before:start-2 before:w-px before:bg-white/15">
                {sessions.map((s) => (
                  <li key={s.id}>
                    <button type="button" onClick={() => setPicked(s.id === picked ? null : s.id)} className={clsx('relative w-full rounded-2xl border p-4 text-start transition', s.id === featured.id ? 'border-gold-400/70 bg-white/10' : 'border-white/10 bg-white/[.04] hover:bg-white/[.08]', s.state === 'ended' && 'opacity-60')}>
                      <span className={clsx('absolute -start-[1.65rem] top-5 size-3 rounded-full ring-4 ring-navy-900', s.state === 'live' ? 'bg-emerald-400' : s.state === 'next' ? 'bg-gold-400' : 'bg-white/30')} />
                      <div className="flex items-center justify-between gap-2"><span className="text-xl font-extrabold tabular-nums" dir="ltr">{clock(new Date(s.starts_at))} – {clock(new Date(s.ends_at))}</span>{s.state === 'live' && <span className="rounded-full bg-emerald-500 px-2 py-0.5 text-[10px] font-bold">{t('studio.screen.live')}</span>}</div>
                      <div className="mt-1 truncate font-semibold">{s.program.title}</div>
                      <div className="mt-0.5 flex items-center justify-between text-xs text-white/60"><span className="truncate">{s.trainer?.name ?? '—'}</span><span>{s.counts.present}/{s.counts.expected}</span></div>
                    </button>
                  </li>
                ))}
              </ol>
            </aside>
          </div>
        )}
      </div>
    </div>
  )
}
