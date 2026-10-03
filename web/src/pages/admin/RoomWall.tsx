import clsx from 'clsx'
import { CalendarDays, Expand, ExternalLink, Monitor, Radio, Shrink } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, Empty, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { DEFAULT_TEMPLATE, RoomScreenView, todayIso, type Day } from '../RoomScreen'

type Wall = { id: string } & Day
type State = 'live' | 'soon' | 'free'

const stateOf = (d: Wall, now: Date): State => {
  if (d.sessions.some((s) => s.state === 'live')) return 'live'
  const next = d.sessions.find((s) => s.state === 'next' || s.state === 'upcoming')
  return d.is_today && next && new Date(next.starts_at).getTime() - now.getTime() <= 4 * 3600_000 ? 'soon' : 'free'
}

/** One room's screen, shrunk to a tile; the real screen is drawn at 1920×1080 and scaled to the tile. */
function Tile({ room, now, state, date }: { room: Wall; now: Date; state: State; date: string }) {
  const { t } = useTranslation()
  const frame = useRef<HTMLDivElement>(null)
  const [scale, setScale] = useState(0.25)
  useEffect(() => {
    const el = frame.current
    if (!el) return
    const update = () => setScale(el.clientWidth / 1920)
    update()
    const ro = new ResizeObserver(update)
    ro.observe(el)
    return () => ro.disconnect()
  }, [])
  const tone = { live: 'bg-emerald-500', soon: 'bg-amber-400', free: 'bg-slate-400' }[state]

  return (
    <a href={`/admin/rooms/${room.id}/screen`} target="_blank" rel="noreferrer" className="group block overflow-hidden rounded-2xl border border-navy-100 bg-white shadow-sm transition hover:-translate-y-0.5 hover:shadow-glass">
      <div ref={frame} className="relative w-full overflow-hidden bg-navy-950" style={{ aspectRatio: '16 / 9' }}>
        <div className="pointer-events-none absolute start-0 top-0 origin-top-left rtl:origin-top-right" style={{ width: 1920, height: 1080, transform: `scale(${scale})` }}>
          <RoomScreenView preview day={room} now={now} date={date} today={todayIso()} template={room.template ?? DEFAULT_TEMPLATE} />
        </div>
        <span className="absolute end-2 top-2 inline-flex items-center gap-1 rounded-full bg-black/55 px-2 py-0.5 text-[10px] font-bold text-white opacity-0 backdrop-blur transition group-hover:opacity-100"><ExternalLink className="size-3" />{t('studio.wall.open')}</span>
      </div>
      <div className="flex items-center gap-2 px-3 py-2">
        <span className={clsx('size-2.5 shrink-0 rounded-full', tone, state === 'live' && 'animate-pulse')} />
        <span className="min-w-0 flex-1 truncate text-sm font-bold text-navy-900">{room.room.name}</span>
        <span className="shrink-0 text-xs font-semibold text-slate-500">{t(`studio.wall.states.${state}`)}</span>
      </div>
    </a>
  )
}

/** Every classroom screen on one page, live: who is in which room, and which rooms are free. */
export default function RoomWall() {
  const { t } = useTranslation()
  const [date, setDate] = useState(todayIso())
  const [filter, setFilter] = useState<'all' | State>('all')
  const [now, setNow] = useState(new Date())
  const [full, setFull] = useState(false)
  const root = useRef<HTMLDivElement>(null)
  const { data, isLoading, isFetching } = useGet<{ data: Wall[] }>('/admin/rooms/wall', { date }, { refetchInterval: 10_000, staleTime: 0 })
  useEffect(() => { const id = setInterval(() => setNow(new Date()), 1000); return () => clearInterval(id) }, [])
  useEffect(() => { const f = () => setFull(!!document.fullscreenElement); document.addEventListener('fullscreenchange', f); return () => document.removeEventListener('fullscreenchange', f) }, [])

  const rooms = useMemo(() => data?.data ?? [], [data])
  const withState = useMemo(() => rooms.map((r) => ({ r, s: stateOf(r, now) })), [rooms, now])
  const counts = { live: withState.filter((x) => x.s === 'live').length, soon: withState.filter((x) => x.s === 'soon').length, free: withState.filter((x) => x.s === 'free').length }
  const shown = withState.filter((x) => filter === 'all' || x.s === filter)

  return (
    <div ref={root} className={clsx(full && 'min-h-screen overflow-auto bg-ivory p-6')}>
      <PageHeader
        title={<span className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-navy-900 text-gold-300"><Monitor className="size-5" /></span>{t('studio.wall.title')}</span>}
        subtitle={t('studio.wall.subtitle')}
        actions={<button type="button" onClick={() => void (document.fullscreenElement ? document.exitFullscreen() : root.current?.requestFullscreen())} className="inline-flex items-center gap-2 rounded-xl border border-navy-100 bg-white px-4 py-2.5 text-sm font-bold text-navy-900 hover:border-gold-400">{full ? <Shrink className="size-4" /> : <Expand className="size-4" />}{t('studio.wall.wallMode')}</button>}
      />

      <div className="mb-5 flex flex-wrap items-center gap-2">
        {([['all', rooms.length, null], ['live', counts.live, 'bg-emerald-500'], ['soon', counts.soon, 'bg-amber-400'], ['free', counts.free, 'bg-slate-400']] as const).map(([k, n, dot]) => (
          <button key={k} type="button" aria-pressed={filter === k} onClick={() => setFilter(k)} className={clsx('inline-flex items-center gap-2 rounded-xl border px-4 py-2 text-sm font-bold transition', filter === k ? 'border-navy-900 bg-navy-900 text-white shadow' : 'border-navy-100 bg-white text-navy-800 hover:border-gold-400')}>
            {dot && <span className={clsx('size-2.5 rounded-full', dot, k === 'live' && 'animate-pulse')} />}{t(`studio.wall.filters.${k}`)}<span className={clsx('rounded-full px-2 text-xs', filter === k ? 'bg-white/15' : 'bg-navy-100/70')}>{n}</span>
          </button>
        ))}
        <label className="relative ms-auto inline-flex items-center gap-2 rounded-xl border border-navy-100 bg-white px-4 py-2 text-sm font-semibold"><CalendarDays className="size-4 text-gold-600" />{date}<input type="date" value={date} onChange={(e) => e.target.value && setDate(e.target.value)} className="absolute inset-0 cursor-pointer opacity-0" aria-label="date" /></label>
        <span className="inline-flex items-center gap-2 text-xs text-slate-500"><Radio className={clsx('size-3.5', isFetching ? 'text-gold-600' : 'text-emerald-500')} />{t('studio.wall.live')}</span>
      </div>

      {isLoading ? <Spinner /> : shown.length === 0 ? <Card><Empty text={t('studio.wall.empty')} /></Card> : (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
          {shown.map(({ r, s }) => <Tile key={r.id} room={r} now={now} state={s} date={date} />)}
        </div>
      )}
    </div>
  )
}
