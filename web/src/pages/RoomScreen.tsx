import clsx from 'clsx'
import { CalendarDays, ChevronLeft, ChevronRight, Clock, DoorOpen, Expand, MapPin, Radio, Shrink, UserRound, Users } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type CSSProperties } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { BrandMark } from '@/components/public/Logo'
import { api } from '@/lib/api'
import { fmt } from '@/lib/format'
import { customPatternTile } from '@/lib/theme'
import { useCenterName, useTheme } from '@/lib/ThemeProvider'

export type Trainee = { name: string; school: string | null; status: 'present' | 'late' | 'expected' | 'absent'; check_in_at: string | null }
export type Session = {
  id: string; title: string; sequence: number; mode: string; program: { code: string; title: string }; trainer: { name: string; photo: string | null } | null
  starts_at: string; ends_at: string; minutes: number; state: 'ended' | 'live' | 'next' | 'upcoming'
  counts: { expected: number; present: number; late: number; absent: number }; trainees: Trainee[]
}
export type ScreenTemplate = {
  layout: 'classic' | 'spotlight' | 'minimal'; theme: 'brand' | 'midnight' | 'custom'; background: string; accent: string
  show_logo: boolean; show_center_name: boolean; show_clock: boolean; show_trainer: boolean; show_trainees: boolean; show_school: boolean; show_progress: boolean; show_attendance_ring: boolean
  footer_ar: string; footer_en: string
  idle_enabled: boolean; idle_show_next: boolean; idle_title_ar: string; idle_title_en: string; idle_text_ar: string; idle_text_en: string
  bg_image: string; bg_mode: 'tile' | 'cover'; bg_opacity: number; bg_size: number; bg_tint: string
}
export const DEFAULT_TEMPLATE: ScreenTemplate = { layout: 'classic', theme: 'brand', background: '', accent: '', show_logo: true, show_center_name: true, show_clock: true, show_trainer: true, show_trainees: true, show_school: true, show_progress: true, show_attendance_ring: true, footer_ar: '', footer_en: '', idle_enabled: true, idle_show_next: true, idle_title_ar: 'القاعة شاغرة الآن', idle_title_en: 'This room is empty', idle_text_ar: 'احجزها الآن لتدريبك القادم', idle_text_en: 'Book it now for your next training', bg_image: '', bg_mode: 'tile', bg_opacity: 25, bg_size: 120, bg_tint: '' }
export type Day = { template?: ScreenTemplate; room: { name: string; code: string; building: string | null; floor: string | null; capacity: number }; date: string; is_today: boolean; now: string; sessions: Session[] }

const STATUS = {
  present: { dot: 'bg-emerald-400', chip: 'border-emerald-400/30 bg-emerald-400/10 text-emerald-100' },
  late: { dot: 'bg-amber-400', chip: 'border-amber-400/30 bg-amber-400/10 text-amber-100' },
  expected: { dot: 'bg-white/30', chip: 'border-white/10 bg-white/5 text-white/70' },
  absent: { dot: 'bg-red-400', chip: 'border-red-400/30 bg-red-400/10 text-red-100' },
} as const

const clock = (d: Date) => d.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })
const addDays = (iso: string, n: number) => { const d = new Date(`${iso}T12:00:00`); d.setDate(d.getDate() + n); return d.toISOString().slice(0, 10) }
export const todayIso = () => { const d = new Date(); return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}` }

/** The screen's own picture, with its opacity and optional tint, drawn behind everything. */
function ScreenBackground({ tpl }: { tpl: ScreenTemplate }) {
  const [tile, setTile] = useState<string>('none')
  useEffect(() => {
    let alive = true
    if (!tpl.bg_image) { setTile('none'); return }
    void customPatternTile({ type: 'custom', image: tpl.bg_image, opacity: tpl.bg_opacity, tint: tpl.bg_tint || null, size: tpl.bg_size, color: '#000' }).then((u) => alive && setTile(u))
    return () => { alive = false }
  }, [tpl.bg_image, tpl.bg_opacity, tpl.bg_tint, tpl.bg_size])
  if (!tpl.bg_image || tile === 'none') return null
  return <div className="pointer-events-none absolute inset-0" style={{ backgroundImage: tile, backgroundSize: tpl.bg_mode === 'cover' ? 'cover' : `${tpl.bg_size}px auto`, backgroundRepeat: tpl.bg_mode === 'cover' ? 'no-repeat' : 'repeat', backgroundPosition: 'center', opacity: tile.startsWith('url("data:') ? 1 : tpl.bg_opacity / 100 }} />
}

/** Background and accent of the template; "brand" follows the colours set in Brand Studio. */
export function screenStyle(t: ScreenTemplate): CSSProperties {
  const accent = t.accent || 'var(--color-gold-400)'
  const base: CSSProperties = { ['--sc-accent' as string]: accent, ['--sc-accent-soft' as string]: t.accent ? `color-mix(in srgb, ${t.accent} 65%, white)` : 'var(--color-gold-300)' }
  if (t.theme === 'midnight') return { ...base, background: 'linear-gradient(135deg,#070b14 0%,#10162a 55%,#1b2340 100%)' }
  if (t.theme === 'custom' && t.background) return { ...base, background: `linear-gradient(135deg, ${t.background} 0%, color-mix(in srgb, ${t.background} 62%, black) 100%)` }
  return { ...base, background: 'linear-gradient(135deg, var(--color-navy-950) 0%, var(--color-navy-900) 55%, var(--color-navy-800) 100%)' }
}

function Ring({ value, total }: { value: number; total: number }) {
  const r = 52
  const c = 2 * Math.PI * r
  const pct = total ? value / total : 0
  return (
    <div className="relative grid place-items-center">
      <svg viewBox="0 0 120 120" className="size-36 -rotate-90"><circle cx="60" cy="60" r={r} fill="none" strokeWidth="9" className="stroke-white/10" /><circle cx="60" cy="60" r={r} fill="none" strokeWidth="9" strokeLinecap="round" className="transition-all duration-700" style={{ stroke: 'var(--sc-accent)' }} strokeDasharray={c} strokeDashoffset={c - c * pct} /></svg>
      <div className="absolute text-center" dir="ltr"><div className="text-4xl font-extrabold tabular-nums text-white">{value}<span className="text-lg font-semibold text-white/50"> / {total}</span></div></div>
    </div>
  )
}

/** Nothing is running: the ministry logo and an invitation to book the room, with what comes next today. */
function IdleScreen({ day, now, tpl, next }: { day: Day; now: Date; tpl: ScreenTemplate; next: Session | null }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const title = (ar ? tpl.idle_title_ar : tpl.idle_title_en) || (ar ? 'القاعة شاغرة الآن' : 'This room is empty')
  const text = ar ? tpl.idle_text_ar : tpl.idle_text_en
  const minutes = next ? Math.max(0, Math.ceil((new Date(next.starts_at).getTime() - now.getTime()) / 60000)) : 0
  return (
    <div className="grid flex-1 place-items-center py-6 text-center">
      <div className="mx-auto flex max-w-3xl flex-col items-center gap-7">
        <div className="rounded-[2.5rem] border border-white/15 bg-white/[.07] p-8 shadow-2xl backdrop-blur"><BrandMark onDark className="h-32 max-w-[22rem] sm:h-44" markClassName="size-36" /></div>
        <div>
          <div className="inline-flex items-center gap-2 rounded-full bg-emerald-500/90 px-5 py-1.5 text-sm font-bold"><span className="relative flex size-2.5"><span className="absolute inline-flex size-full animate-ping rounded-full bg-white opacity-70" /><span className="relative inline-flex size-2.5 rounded-full bg-white" /></span>{t('studio.screen.available')}</div>
          <h2 className="mt-5 text-5xl font-extrabold leading-tight sm:text-7xl">{title}</h2>
          {text && <p className="mt-3 text-2xl font-semibold sm:text-3xl" style={{ color: 'var(--sc-accent-soft)' }}>{text}</p>}
        </div>
        <p className="text-lg text-white/70">{day.room.name}{day.room.capacity ? ` · ${t('studio.screen.capacity', { count: day.room.capacity })}` : ''}</p>
        {tpl.idle_show_next && next && (
          <div className="rounded-2xl border border-white/15 bg-white/[.06] px-6 py-4 text-start backdrop-blur">
            <div className="text-xs font-semibold text-white/55">{t('studio.screen.nextToday')}</div>
            <div className="mt-1 flex flex-wrap items-center gap-x-4 gap-y-1"><span className="text-3xl font-extrabold tabular-nums" dir="ltr">{clock(new Date(next.starts_at))}</span><span className="text-lg font-semibold">{next.program.title}</span></div>
            <div className="mt-1 text-sm text-white/65">{t('studio.screen.freeFor', { time: minutes >= 60 ? t('studio.screen.hm', { h: Math.floor(minutes / 60), m: minutes % 60 }) : t('studio.screen.minutes', { m: minutes }) })}</div>
          </div>
        )}
      </div>
    </div>
  )
}

type ViewProps = {
  day: Day | null; error?: boolean; now: Date; date: string; today: string; onDate?: (iso: string) => void; template: ScreenTemplate
  fullscreen?: { active: boolean; toggle: () => void }; preview?: boolean; forceIdle?: boolean
}

/** The screen itself: shared by the live screen and by the settings preview. */
export function RoomScreenView({ day, error, now, date, today, onDate, template: tpl, fullscreen, preview, forceIdle }: ViewProps) {
  const { t, i18n } = useTranslation()
  const centerName = useCenterName()
  const { active } = useTheme()
  const sessions = useMemo(() => day?.sessions ?? [], [day])
  const featured = useMemo(() => sessions.find((s) => s.state === 'live') ?? sessions.find((s) => s.state === 'next') ?? sessions.find((s) => s.state === 'upcoming') ?? sessions.at(-1) ?? null, [sessions])
  const isToday = date === today
  const start = featured ? new Date(featured.starts_at) : null
  const end = featured ? new Date(featured.ends_at) : null
  const progress = featured && start && end ? Math.min(100, Math.max(0, ((now.getTime() - start.getTime()) / (end.getTime() - start.getTime())) * 100)) : 0
  const minutesLeft = end ? Math.max(0, Math.ceil((end.getTime() - now.getTime()) / 60000)) : 0
  const minutesTo = start ? Math.max(0, Math.ceil((start.getTime() - now.getTime()) / 60000)) : 0
  // Today with nothing in progress: the room is free.
  const idle = !!day && tpl.idle_enabled && isToday && (forceIdle || !sessions.some((s) => s.state === 'live'))
  const upcoming = sessions.find((s) => s.state === 'next' || s.state === 'upcoming') ?? null
  const minimal = tpl.layout === 'minimal'
  const spotlight = tpl.layout === 'spotlight'
  const footer = i18n.language === 'ar' ? tpl.footer_ar : tpl.footer_en

  return (
    <div dir={i18n.language === 'ar' ? 'rtl' : 'ltr'} style={screenStyle(tpl)} className={clsx('relative overflow-hidden text-white', preview ? 'h-full w-full' : 'min-h-screen')}>
      {tpl.bg_image ? <ScreenBackground tpl={tpl} /> : null}
      <div className={clsx("pattern-bg pointer-events-none absolute inset-0", tpl.bg_image ? 'hidden' : '', active.pattern.type === 'custom' ? 'opacity-100' : 'opacity-[.07]')} />
      <div className="pointer-events-none absolute -end-40 -top-40 size-[34rem] rounded-full blur-3xl" style={{ background: 'var(--sc-accent)', opacity: 0.16 }} />

      <div className={clsx('relative mx-auto flex max-w-[110rem] flex-col gap-6 p-5 sm:p-8', preview ? 'h-full' : 'min-h-screen')}>
        {/* Header: brand, room, clock */}
        <header className="flex flex-wrap items-center gap-4">
          {(tpl.show_logo || tpl.show_center_name) && (
            <div className="flex items-center gap-3 border-white/15 pe-5 sm:border-e">
              {tpl.show_logo && <BrandMark onDark className="h-12 max-w-[170px]" markClassName="size-12" />}
              {tpl.show_center_name && <span className="hidden max-w-[14rem] text-sm font-bold leading-snug text-white/85 md:block">{centerName}</span>}
            </div>
          )}
          <div className="flex min-w-0 items-center gap-4">
            <span className="grid size-14 shrink-0 place-items-center rounded-2xl text-navy-950 shadow-lg" style={{ background: 'var(--sc-accent)' }}><DoorOpen className="size-7" /></span>
            <div className="min-w-0">
              <h1 className="truncate text-3xl font-extrabold sm:text-4xl">{day?.room.name ?? '…'}</h1>
              <p className="flex flex-wrap items-center gap-x-3 text-sm text-white/65">
                {(day?.room.building || day?.room.floor) && <span className="inline-flex items-center gap-1"><MapPin className="size-4" />{[day?.room.building, day?.room.floor].filter(Boolean).join(' · ')}</span>}
                {day && !minimal && <span className="inline-flex items-center gap-1"><Users className="size-4" />{t('studio.screen.capacity', { count: day.room.capacity })}</span>}
              </p>
            </div>
          </div>
          <div className="ms-auto flex items-center gap-5">
            {tpl.show_clock && <div className="text-end"><div className="text-5xl font-extrabold tabular-nums leading-none tracking-tight" dir="ltr">{clock(now)}</div><div className="mt-1 text-sm text-white/65">{fmt.date(now.toISOString(), { weekday: 'long', day: 'numeric', month: 'long' })}</div></div>}
            {fullscreen && <button type="button" aria-label="fullscreen" onClick={fullscreen.toggle} className="grid size-11 place-items-center rounded-xl border border-white/15 bg-white/5 hover:bg-white/10">{fullscreen.active ? <Shrink className="size-5" /> : <Expand className="size-5" />}</button>}
          </div>
        </header>

        {/* Day selector */}
        {onDate && (
          <div className="flex flex-wrap items-center gap-2">
            <button type="button" aria-label="previous day" onClick={() => onDate(addDays(date, -1))} className="grid size-10 place-items-center rounded-xl border border-white/15 bg-white/5 hover:bg-white/10"><ChevronRight className="size-5 ltr:rotate-180" /></button>
            <label className="relative inline-flex items-center gap-2 rounded-xl border border-white/15 bg-white/5 px-4 py-2 text-sm font-semibold"><CalendarDays className="size-4" style={{ color: 'var(--sc-accent-soft)' }} />{fmt.date(`${date}T12:00:00`, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}<input type="date" value={date} onChange={(e) => e.target.value && onDate(e.target.value)} className="absolute inset-0 cursor-pointer opacity-0" aria-label="date" /></label>
            <button type="button" aria-label="next day" onClick={() => onDate(addDays(date, 1))} className="grid size-10 place-items-center rounded-xl border border-white/15 bg-white/5 hover:bg-white/10"><ChevronLeft className="size-5 ltr:rotate-180" /></button>
            {!isToday && <button type="button" onClick={() => onDate(today)} className="rounded-xl px-4 py-2 text-sm font-bold text-navy-950" style={{ background: 'var(--sc-accent)' }}>{t('studio.screen.today')}</button>}
            <span className="ms-auto inline-flex items-center gap-2 text-xs text-white/55"><span className={clsx('size-2 rounded-full', error ? 'bg-red-400' : 'animate-pulse bg-emerald-400')} />{error ? t('studio.screen.offline') : t('studio.screen.updating')}</span>
          </div>
        )}

        {idle && day ? <IdleScreen day={day} now={now} tpl={tpl} next={upcoming} /> : !day ? <div className="grid flex-1 place-items-center text-white/60">{error ? t('studio.screen.notFound') : '…'}</div> : sessions.length === 0 ? (
          <div className="grid flex-1 place-items-center text-center"><div><CalendarDays className="mx-auto size-16" style={{ color: 'var(--sc-accent-soft)' }} /><p className="mt-4 text-3xl font-extrabold">{t('studio.screen.none')}</p><p className="mt-1 text-white/60">{t('studio.screen.noneHint')}</p></div></div>
        ) : featured && (
          <div className="grid flex-1 gap-6">
            <main className="flex min-w-0 flex-col gap-6">
              <section className={clsx('rounded-3xl border border-white/10 bg-white/[.06] shadow-2xl backdrop-blur', spotlight ? 'p-8 sm:p-12' : 'p-6 sm:p-8')}>
                <div className="flex flex-wrap items-center gap-3">
                  {featured.state === 'live' && <span className="inline-flex items-center gap-2 rounded-full bg-emerald-500 px-4 py-1 text-sm font-bold"><Radio className="size-4 animate-pulse" />{t('studio.screen.live')}</span>}
                  {featured.state === 'next' && <span className="rounded-full px-4 py-1 text-sm font-bold text-navy-950" style={{ background: 'var(--sc-accent)' }}>{t('studio.screen.next')}</span>}
                  {featured.state === 'ended' && <span className="rounded-full bg-white/15 px-4 py-1 text-sm font-bold">{t('studio.screen.ended')}</span>}
                  {featured.mode === 'online' && <span className="rounded-full border border-white/20 px-3 py-1 text-xs">{t('studio.screen.online')}</span>}
                  <span className="font-mono text-sm text-white/55" dir="ltr">{featured.program.code}</span>
                </div>
                <h2 className={clsx('mt-4 font-extrabold leading-tight', spotlight ? 'text-5xl sm:text-7xl' : 'text-4xl sm:text-5xl')}>{featured.program.title}</h2>
                {!minimal && <p className="mt-1 text-xl" style={{ color: 'var(--sc-accent-soft)' }}>{featured.title}</p>}

                <div className="mt-8 grid gap-6 lg:grid-cols-[1fr_auto] lg:items-center">
                  <div className="space-y-6">
                    <div className="flex flex-wrap items-center gap-x-8 gap-y-4">
                      <div className="flex items-center gap-3"><Clock className={spotlight ? 'size-10' : 'size-8'} style={{ color: 'var(--sc-accent-soft)' }} /><div className={clsx('font-extrabold tabular-nums', spotlight ? 'text-6xl sm:text-7xl' : 'text-4xl sm:text-5xl')} dir="ltr">{start && clock(start)} <span className="text-white/40">→</span> {end && clock(end)}</div></div>
                      {tpl.show_trainer && featured.trainer && (
                        <div className="flex items-center gap-3">
                          {featured.trainer.photo ? <img src={featured.trainer.photo} alt="" className="size-14 rounded-full object-cover ring-2" style={{ ['--tw-ring-color' as string]: 'var(--sc-accent)' }} /> : <span className="grid size-14 place-items-center rounded-full bg-white/10 ring-2" style={{ color: 'var(--sc-accent-soft)', ['--tw-ring-color' as string]: 'var(--sc-accent)' }}><UserRound className="size-7" /></span>}
                          <div><div className="text-xs text-white/55">{t('studio.screen.trainer')}</div><div className="text-2xl font-bold">{featured.trainer.name}</div></div>
                        </div>
                      )}
                    </div>
                    {tpl.show_progress && isToday && featured.state !== 'ended' && (
                      <div>
                        <div className="mb-2 flex justify-between text-sm text-white/70"><span>{featured.state === 'live' ? t('studio.screen.remaining', { minutes: minutesLeft }) : t('studio.screen.startsIn', { minutes: minutesTo })}</span><span dir="ltr">{featured.minutes} {t('studio.screen.min')}</span></div>
                        <div className="h-3 overflow-hidden rounded-full bg-white/10"><div className="h-full rounded-full transition-all duration-1000" style={{ width: `${featured.state === 'live' ? progress : 0}%`, background: 'linear-gradient(90deg, var(--sc-accent), var(--sc-accent-soft))' }} /></div>
                      </div>
                    )}
                  </div>
                  {tpl.show_attendance_ring && !minimal && (
                    <div className="flex items-center gap-6 lg:ps-6">
                      <Ring value={featured.counts.present} total={featured.counts.expected} />
                      <ul className="space-y-1.5 text-sm">
                        <li className="flex items-center gap-2"><span className="size-2.5 rounded-full bg-emerald-400" />{t('studio.screen.present')} <b>{featured.counts.present - featured.counts.late}</b></li>
                        <li className="flex items-center gap-2"><span className="size-2.5 rounded-full bg-amber-400" />{t('studio.screen.late')} <b>{featured.counts.late}</b></li>
                        <li className="flex items-center gap-2"><span className="size-2.5 rounded-full bg-white/30" />{t(featured.state === 'ended' ? 'studio.screen.absent' : 'studio.screen.expected')} <b>{featured.state === 'ended' ? featured.counts.absent : featured.counts.expected - featured.counts.present}</b></li>
                      </ul>
                    </div>
                  )}
                </div>
              </section>

              {tpl.show_trainees && !minimal && (
                <section className="flex-1 rounded-3xl border border-white/10 bg-white/[.04] p-6">
                  <h3 className="mb-4 flex items-center gap-2 text-lg font-bold"><Users className="size-5" style={{ color: 'var(--sc-accent-soft)' }} />{t('studio.screen.trainees')}<span className="text-white/50">({featured.counts.expected})</span></h3>
                  {featured.trainees.length === 0 ? <p className="text-white/55">{t('studio.screen.noTrainees')}</p> : (
                    <ul className={clsx('grid gap-2 sm:grid-cols-2', spotlight ? 'xl:grid-cols-4' : 'xl:grid-cols-3 2xl:grid-cols-4')}>
                      {featured.trainees.map((p, i) => (
                        <li key={i} className={clsx('flex items-center gap-3 rounded-xl border px-3 py-2.5 transition-colors duration-500', STATUS[p.status].chip)}>
                          <span className={clsx('size-2.5 shrink-0 rounded-full', STATUS[p.status].dot, p.status === 'present' && 'shadow-[0_0_10px_2px_rgba(52,211,153,.6)]')} />
                          <span className="min-w-0 flex-1"><span className="block truncate font-semibold">{p.name}</span>{tpl.show_school && p.school && <span className="block truncate text-xs opacity-60">{p.school}</span>}</span>
                          {p.check_in_at && <span className="text-xs tabular-nums opacity-70" dir="ltr">{clock(new Date(p.check_in_at))}</span>}
                        </li>
                      ))}
                    </ul>
                  )}
                </section>
              )}
            </main>
          </div>
        )}

        {footer && <footer className="mt-auto rounded-2xl border border-white/10 bg-white/[.06] px-6 py-3 text-center text-lg font-semibold">{footer}</footer>}
      </div>
    </div>
  )
}

/**
 * The live screen of one classroom for one day. Shown at the room's door by its secret address, or inside the admin
 * area. It follows the template chosen in Settings → Room screen and the brand of Brand Studio.
 */
export default function RoomScreen({ admin = false }: { admin?: boolean }) {
  const { token, id } = useParams()
  const [date, setDate] = useState(todayIso())
  const [day, setDay] = useState<Day | null>(null)
  const [error, setError] = useState(false)
  const [now, setNow] = useState(new Date())
  const [full, setFull] = useState(false)
  const [today, setToday] = useState(todayIso())
  const root = useRef<HTMLDivElement>(null)
  const url = admin ? `/admin/rooms/${id}/screen` : `/public/room-screen/${token}`

  useEffect(() => {
    let alive = true
    const load = () => api.get<{ data: Day }>(url, { params: { date } }).then((r) => { if (alive) { setDay(r.data.data); setError(false) } }).catch(() => alive && setError(true))
    void load()
    const poll = window.setInterval(load, 15_000)
    const tick = window.setInterval(() => setNow(new Date()), 1000)
    return () => { alive = false; window.clearInterval(poll); window.clearInterval(tick) }
  }, [url, date])

  // A screen left on today moves to the new day at midnight.
  useEffect(() => { const d = todayIso(); if (d !== today) { if (date === today) setDate(d); setToday(d) } }, [now, today, date])
  useEffect(() => { const f = () => setFull(!!document.fullscreenElement); document.addEventListener('fullscreenchange', f); return () => document.removeEventListener('fullscreenchange', f) }, [])

  return (
    <div ref={root}>
      <RoomScreenView day={day} error={error} now={now} date={date} today={today} onDate={setDate} template={day?.template ?? DEFAULT_TEMPLATE}
        fullscreen={{ active: full, toggle: () => void (document.fullscreenElement ? document.exitFullscreen() : root.current?.requestFullscreen()) }} />
    </div>
  )
}
