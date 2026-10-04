import clsx from 'clsx'
import { Building2, CalendarDays, Clock, Layers, MonitorPlay, UserRound } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { BrandMark } from '@/components/public/Logo'

export type LobbyRow = {
  id: string; program: { code: string; title_ar: string; title_en: string }; starts_at: string; ends_at: string; state: 'live' | 'upcoming' | 'ended'; mode: string; platform: string | null
  room: { code: string; name_ar: string; name_en: string; floor: string | null; building: string | null } | null; coordinator: { name_ar: string; name_en: string } | null; attendees: number
}
export type LobbySlide = { id: string; title: string; url: string; seconds: number; transition: Transition }
export type Transition = 'fade' | 'slide' | 'zoom' | 'none'
export type LobbySettings = { enabled: boolean; show_programs: boolean; programs_seconds: number; programs_per_slide: number; slide_seconds: number; transition: Transition; transition_ms: number; show_clock: boolean; show_progress: boolean; language: 'ar' | 'en' }
export type LobbyData = { date: string; settings: LobbySettings; center: { name_ar: string; name_en: string }; programs: LobbyRow[]; slides: LobbySlide[] }

/** The stage is always 1080 × 1920; it is scaled to whatever box (a TV, a browser window, a small preview) it is put in. */
export const STAGE = { w: 1080, h: 1920 }

type Item = { key: string; seconds: number; transition: Transition; kind: 'programs' | 'image'; page?: number; pages?: number; slide?: LobbySlide }

const TEXT = {
  ar: { today: 'برامج اليوم', room: 'القاعة', floor: 'الطابق', coordinator: 'المنسق', live: 'جارٍ الآن', upcoming: 'قريباً', ended: 'انتهى', none: 'لا توجد برامج تدريبية اليوم', noneHint: 'نتطلع لاستقبالكم في برامجنا القادمة', online: 'عن بُعد', hybridNote: 'جلسة إلكترونية', page: 'الصفحة', attendees: 'مسجّل' },
  en: { today: "Today's programs", room: 'Room', floor: 'Floor', coordinator: 'Coordinator', live: 'Live now', upcoming: 'Upcoming', ended: 'Finished', none: 'No training programs today', noneHint: 'We look forward to welcoming you to our next programs', online: 'Online', hybridNote: 'Online session', page: 'Page', attendees: 'registered' },
} as const

/**
 * The screen decides how to show today's programs from how many there are, so the page never needs touching:
 * a few programs get big rich cards, more get smaller ones, and a long day becomes compact rows that rotate in pages.
 */
export type Density = { variant: 'rich' | 'row'; k: number; per: number; compactHeader: boolean; clamp: 1 | 2; showRoomName: boolean }
export function densityFor(n: number): Density {
  if (n <= 2) return { variant: 'rich', k: 1, per: 2, compactHeader: false, clamp: 2, showRoomName: true }
  if (n === 3) return { variant: 'rich', k: 0.76, per: 3, compactHeader: false, clamp: 2, showRoomName: false }
  if (n <= 5) return { variant: 'rich', k: 0.6, per: 5, compactHeader: true, clamp: 1, showRoomName: false }
  if (n <= 10) return { variant: 'row', k: 0.9, per: 10, compactHeader: true, clamp: 1, showRoomName: false }
  return { variant: 'row', k: 0.64, per: 14, compactHeader: true, clamp: 1, showRoomName: false }
}
/** Pages are balanced, so a long day never ends with a page holding a single program. */
export function pagesFor(n: number) {
  const pages = Math.max(1, Math.ceil(n / densityFor(n).per))
  return { pages, size: Math.max(1, Math.ceil(n / pages)) }
}

/** The order of the show: the automatic program pages first, then the administrator's images. */
export function buildItems(data: LobbyData): Item[] {
  const s = data.settings
  const items: Item[] = []
  if (s.show_programs) {
    const { pages } = pagesFor(data.programs.length)
    for (let p = 0; p < pages; p++) items.push({ key: `p${p}-${pages}`, seconds: s.programs_seconds, transition: s.transition, kind: 'programs', page: p, pages })
  }
  data.slides.forEach((x) => items.push({ key: `i${x.id}`, seconds: x.seconds, transition: x.transition, kind: 'image', slide: x }))
  if (items.length === 0) items.push({ key: 'p0-1', seconds: s.programs_seconds, transition: 'none', kind: 'programs', page: 0, pages: 1 })

  return items
}

const css = `
@keyframes lobby-fade-in{from{opacity:0}to{opacity:1}}
@keyframes lobby-fade-out{from{opacity:1}to{opacity:0}}
@keyframes lobby-slide-in{from{transform:translateX(calc(100% * var(--lobby-dir,1)))}to{transform:translateX(0)}}
@keyframes lobby-slide-out{from{transform:translateX(0)}to{transform:translateX(calc(-100% * var(--lobby-dir,1)))}}
@keyframes lobby-zoom-in{from{opacity:0;transform:scale(1.12)}to{opacity:1;transform:scale(1)}}
@keyframes lobby-zoom-out{from{opacity:1;transform:scale(1)}to{opacity:0;transform:scale(.94)}}
@keyframes lobby-bar{from{transform:scaleX(0)}to{transform:scaleX(1)}}
@keyframes lobby-pulse{0%,100%{opacity:1}50%{opacity:.35}}
`

/**
 * The lobby slideshow. `playing` can be switched off for a paused preview. It owns the timing: each item stays for its
 * own number of seconds, then the next arrives with the item's transition.
 */
export default function LobbyView({ data, now, playing = true }: { data: LobbyData; now?: Date; playing?: boolean }) {
  const items = useMemo(() => buildItems(data), [data])
  const [index, setIndex] = useState(0)
  const [prev, setPrev] = useState<number | null>(null)
  const lastKey = useRef(items[0]?.key)
  const rtl = data.settings.language === 'ar'
  const current = items[Math.min(index, items.length - 1)]

  // When the data changes (a new image, a program ended) stay on the same slide if it still exists.
  useEffect(() => {
    const at = items.findIndex((i) => i.key === lastKey.current)
    if (at === -1) { setIndex(0); setPrev(null); lastKey.current = items[0]?.key } else setIndex(at)
  }, [items])
  useEffect(() => { lastKey.current = current?.key }, [current])

  useEffect(() => {
    if (!playing || items.length < 2 || !current) return
    const id = window.setTimeout(() => {
      setPrev(index)
      setIndex((index + 1) % items.length)
    }, current.seconds * 1000)
    return () => window.clearTimeout(id)
  }, [index, items, current, playing])
  useEffect(() => {
    if (prev === null) return
    const id = window.setTimeout(() => setPrev(null), Math.max(0, data.settings.transition_ms) + 80)
    return () => window.clearTimeout(id)
  }, [prev, data.settings.transition_ms])

  const layer = (item: Item, role: 'in' | 'out' | 'still') => {
    const ms = data.settings.transition_ms
    const animation = role === 'still' || item.transition === 'none' || ms === 0 ? undefined : `lobby-${item.transition}-${role} ${ms}ms ease both`
    return (
      <div key={`${item.key}-${role}`} className="absolute inset-0" style={{ animation, zIndex: role === 'in' ? 2 : 1 }}>
        {item.kind === 'programs' ? <ProgramsSlide data={data} page={item.page ?? 0} pages={item.pages ?? 1} now={now} /> : <ImageSlide slide={item.slide!} />}
      </div>
    )
  }

  const outgoing = prev !== null && prev !== index ? items[prev] : null
  return (
    <div className="relative size-full overflow-hidden bg-black" dir={rtl ? 'rtl' : 'ltr'} style={{ ['--lobby-dir' as string]: rtl ? -1 : 1 }}>
      <style>{css}</style>
      {outgoing && layer(outgoing, 'out')}
      {current && layer(current, outgoing ? 'in' : 'still')}
      {data.settings.show_progress && current && items.length > 1 && playing && (
        <div className="absolute inset-x-0 bottom-0 z-10 h-2 bg-white/10"><div key={current.key} className="h-full origin-left bg-gold-400" style={{ animation: `lobby-bar ${current.seconds}s linear both`, transformOrigin: rtl ? 'right' : 'left' }} /></div>
      )}
    </div>
  )
}

function ImageSlide({ slide }: { slide: LobbySlide }) {
  return (
    <div className="relative size-full bg-black">
      <img src={slide.url} alt="" aria-hidden className="absolute inset-0 size-full scale-110 object-cover opacity-60 blur-3xl" />
      <img src={slide.url} alt={slide.title} className="relative size-full object-contain" draggable={false} />
    </div>
  )
}

const hhmm = (iso: string) => new Date(iso).toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false })

function ProgramsSlide({ data, page, pages, now }: { data: LobbyData; page: number; pages: number; now?: Date }) {
  const s = data.settings
  const lang = s.language
  const x = TEXT[lang]
  const clock = useClock(now)
  const d = densityFor(data.programs.length)
  const size = pagesFor(data.programs.length).size
  const rows = data.programs.slice(page * size, (page + 1) * size)
  const c = d.compactHeader
  const date = new Date(`${data.date}T12:00:00`)
  const long = new Intl.DateTimeFormat(lang === 'ar' ? 'ar-u-nu-latn' : 'en-GB', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' }).format(date)
  const center = lang === 'ar' ? data.center.name_ar : data.center.name_en

  return (
    <div className="relative flex size-full flex-col overflow-hidden text-white" style={{ background: 'radial-gradient(120% 70% at 50% 0%, #8A1538 0%, #5A0E24 55%, #3A0716 100%)', fontFamily: "'Qatar Sans','Tajawal',var(--font-sans),sans-serif" }}>
      <div className="pointer-events-none absolute -end-40 -top-40 size-[640px] rounded-full bg-gold-400/15 blur-3xl" />
      <div className="pointer-events-none absolute -start-48 bottom-24 size-[620px] rounded-full bg-white/5 blur-3xl" />

      {/* Header: the logo, the date and the clock (smaller when the day is full, to leave room for the programs) */}
      <header className={clsx('relative px-[72px]', c ? 'pb-6 pt-[56px]' : 'pb-10 pt-[88px]')}>
        <div className="flex items-center justify-between gap-8">
          <BrandMark onDark className={c ? 'h-[84px] max-w-[320px]' : 'h-[120px] max-w-[420px]'} markClassName={c ? 'size-[84px]' : 'size-[120px]'} />
          {s.show_clock && <div className="text-end"><div className={clsx('font-black leading-none tabular-nums tracking-tight', c ? 'text-[68px]' : 'text-[96px]')} dir="ltr">{clock}</div></div>}
        </div>
        <div className={clsx('flex items-center gap-4 font-semibold text-gold-200', c ? 'mt-5 text-[30px]' : 'mt-10 text-[38px]')}><CalendarDays className={c ? 'size-8' : 'size-10'} strokeWidth={1.6} />{long}{c && <span className="text-[24px] font-medium text-white/50">· {center}</span>}</div>
        {!c && <div className="mt-2 text-[28px] font-medium text-white/60">{center}</div>}
        <div className={clsx('h-[3px] w-full rounded-full bg-gradient-to-r from-gold-400/80 via-gold-300/40 to-transparent rtl:bg-gradient-to-l', c ? 'mt-5' : 'mt-8')} />
        <div className={clsx('flex items-end justify-between', c ? 'mt-5' : 'mt-8')}>
          <h1 className={clsx('font-black leading-none', c ? 'text-[60px]' : 'text-[84px]')}>{x.today}</h1>
          <span className={clsx('rounded-full bg-white/10 font-bold tabular-nums text-gold-200', c ? 'px-6 py-2 text-[28px]' : 'px-8 py-3 text-[34px]')}>{data.programs.length}</span>
        </div>
      </header>

      {/* The programs */}
      <main className={clsx('relative flex min-h-0 flex-1 flex-col justify-center overflow-hidden px-[72px]', d.variant === 'row' ? (d.k < 0.8 ? 'gap-2.5 pb-16' : 'gap-3 pb-20') : d.k < 0.8 ? 'gap-4 pb-16' : 'gap-7 pb-24')}>
        {rows.length === 0 ? (
          <div className="grid flex-1 place-items-center text-center">
            <div><Layers className="mx-auto size-40 text-gold-300/70" strokeWidth={1.2} /><p className="mt-10 text-[64px] font-extrabold leading-tight">{x.none}</p><p className="mt-5 text-[34px] text-white/60">{x.noneHint}</p></div>
          </div>
        ) : rows.map((r) => d.variant === 'rich' ? <ProgramCard key={r.id} row={r} lang={lang} d={d} /> : <ProgramRow key={r.id} row={r} lang={lang} d={d} />)}
      </main>

      {pages > 1 && (
        <footer className="relative flex items-center justify-center gap-4 pb-14" aria-label={`${x.page} ${page + 1}/${pages}`}>
          {Array.from({ length: pages }, (_, i) => <span key={i} className={clsx('h-3 rounded-full transition-all', i === page ? 'w-14 bg-gold-300' : 'w-3 bg-white/30')} />)}
        </footer>
      )}
    </div>
  )
}

const Chip = ({ label, value, icon, ltr = true }: { label: string; value: string; icon: React.ReactNode; ltr?: boolean }) => (
  <div className="flex items-center gap-[1.4em] rounded-[2.4em] bg-black/20 px-[1.8em] py-[1.2em]">
    {icon}
    <div className="leading-tight"><div className="text-[2.6em] text-white/60">{label}</div><div className="text-[4.4em] font-black tabular-nums" dir={ltr ? 'ltr' : 'auto'}>{value}</div></div>
  </div>
)

/** Everything inside is in em, so one number (d.k) makes the whole card larger or smaller. */
function ProgramCard({ row, lang, d }: { row: LobbyRow; lang: 'ar' | 'en'; d: Density }) {
  const x = TEXT[lang]
  const live = row.state === 'live'
  const ended = row.state === 'ended'
  const title = lang === 'ar' ? row.program.title_ar : row.program.title_en
  const online = row.mode === 'online' || !row.room
  const coordinator = row.coordinator ? (lang === 'ar' ? row.coordinator.name_ar : row.coordinator.name_en) : null

  return (
    <article style={{ fontSize: `${d.k * 10}px` }} className={clsx('relative shrink-0 overflow-hidden rounded-[4.4em] border p-[4.4em] backdrop-blur-sm', live ? 'border-gold-300/70 bg-white/[0.16] shadow-[0_0_80px_rgba(201,185,141,.25)]' : 'border-white/15 bg-white/[0.09]', ended && 'opacity-55')}>
      <span className={clsx('absolute inset-y-0 start-0 w-[1.2em]', live ? 'bg-gold-300' : 'bg-white/25')} />
      <div className="flex items-center justify-between gap-[2.4em]">
        <span className="inline-flex items-center gap-[1.2em] text-[4em] font-extrabold tabular-nums text-gold-200" dir="ltr"><Clock className="size-[0.9em]" strokeWidth={1.8} />{hhmm(row.starts_at)} – {hhmm(row.ends_at)}</span>
        <span className={clsx('inline-flex items-center gap-[1.2em] rounded-full px-[2.4em] py-[0.9em] text-[2.8em] font-bold', live ? 'bg-emerald-400/20 text-emerald-200' : ended ? 'bg-white/10 text-white/60' : 'bg-gold-300/15 text-gold-200')}>
          {live && <span className="size-[0.6em] rounded-full bg-emerald-300" style={{ animation: 'lobby-pulse 1.6s ease-in-out infinite' }} />}{x[row.state]}
        </span>
      </div>
      <h2 className={clsx('mt-[1.1em] text-[6.2em] font-black leading-[1.2]', d.clamp === 1 ? 'line-clamp-1' : 'line-clamp-2')} dir="auto">{title}</h2>
      <div className="mt-[1.3em] flex flex-wrap items-center gap-x-[2em] gap-y-[1em]">
        <Chip label={online ? x.online : x.room} value={online ? (row.platform ?? '—') : row.room!.code} icon={online ? <MonitorPlay className="size-[4em] text-gold-300" strokeWidth={1.6} /> : <Building2 className="size-[4em] text-gold-300" strokeWidth={1.6} />} />
        {!online && row.room?.floor && <Chip label={x.floor} value={row.room.floor} icon={<Layers className="size-[4em] text-gold-300" strokeWidth={1.6} />} />}
        <div className="ms-auto flex min-w-0 items-center gap-[1.4em] leading-tight">
          <UserRound className="size-[4em] shrink-0 text-gold-300" strokeWidth={1.6} />
          <div className="min-w-0"><div className="text-[2.6em] text-white/60">{x.coordinator}</div><div className="truncate text-[3.8em] font-bold" dir="auto">{coordinator ?? '—'}</div></div>
        </div>
      </div>
      {d.showRoomName && !online && row.room && <div className="mt-[1em] text-[2.8em] text-white/55" dir="auto">{lang === 'ar' ? row.room.name_ar : row.room.name_en}{row.room.building ? ` · ${row.room.building}` : ''}</div>}
    </article>
  )
}

/** A busy day: each program takes two lines, so many fit on one page. */
function ProgramRow({ row, lang, d }: { row: LobbyRow; lang: 'ar' | 'en'; d: Density }) {
  const x = TEXT[lang]
  const live = row.state === 'live'
  const ended = row.state === 'ended'
  const title = lang === 'ar' ? row.program.title_ar : row.program.title_en
  const online = row.mode === 'online' || !row.room
  const coordinator = row.coordinator ? (lang === 'ar' ? row.coordinator.name_ar : row.coordinator.name_en) : null
  const where = online ? `${x.online}${row.platform ? ` · ${row.platform}` : ''}` : `${x.room} ${row.room!.code}${row.room!.floor ? ` · ${x.floor} ${row.room!.floor}` : ''}`

  return (
    <article style={{ fontSize: `${d.k * 10}px` }} className={clsx('relative shrink-0 overflow-hidden rounded-[2.6em] border px-[3em] py-[1.5em]', live ? 'border-gold-300/70 bg-white/[0.16] shadow-[0_0_50px_rgba(201,185,141,.22)]' : 'border-white/15 bg-white/[0.08]', ended && 'opacity-55')}>
      <span className={clsx('absolute inset-y-0 start-0 w-[0.8em]', live ? 'bg-gold-300' : 'bg-white/25')} />
      <div className="flex items-center gap-[2em]">
        <span className="shrink-0 text-[3.4em] font-extrabold tabular-nums text-gold-200" dir="ltr">{hhmm(row.starts_at)}–{hhmm(row.ends_at)}</span>
        <h2 className="min-w-0 flex-1 truncate text-[3.8em] font-black leading-[1.25]" dir="auto">{title}</h2>
        <span className={clsx('inline-flex shrink-0 items-center gap-[0.8em] rounded-full px-[1.6em] py-[0.5em] text-[2.4em] font-bold', live ? 'bg-emerald-400/20 text-emerald-200' : ended ? 'bg-white/10 text-white/60' : 'bg-gold-300/15 text-gold-200')}>
          {live && <span className="size-[0.5em] rounded-full bg-emerald-300" style={{ animation: 'lobby-pulse 1.6s ease-in-out infinite' }} />}{x[row.state]}
        </span>
      </div>
      <div className="mt-[0.5em] flex items-center gap-[2.4em] text-[2.8em] text-white/75">
        <span className="inline-flex items-center gap-[0.5em] font-bold text-white" dir="auto">{online ? <MonitorPlay className="size-[1em] text-gold-300" /> : <Building2 className="size-[1em] text-gold-300" />}{where}</span>
        <span className="inline-flex min-w-0 items-center gap-[0.5em]"><UserRound className="size-[1em] shrink-0 text-gold-300" /><span className="truncate" dir="auto">{coordinator ?? '—'}</span></span>
      </div>
    </article>
  )
}

function useClock(fixed?: Date) {
  const [time, setTime] = useState(() => fixed ?? new Date())
  useEffect(() => {
    if (fixed) { setTime(fixed); return }
    const id = window.setInterval(() => setTime(new Date()), 10_000)
    return () => window.clearInterval(id)
  }, [fixed])
  return time.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', hour12: false })
}
