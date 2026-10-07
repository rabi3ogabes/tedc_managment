import clsx from 'clsx'
import { Activity, CalendarDays, Clock, Download, Laptop, MapPinned, Power, Tablet, TabletSmartphone, LogIn, LogOut, FileBarChart, Globe2, Lock, Monitor, MoonStar, Radio, Search, ShieldCheck, Smartphone, Timer, TrendingUp, Users, UsersRound } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Avatar, Badge, Button, Card, Empty, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { downloadFile, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { api } from '@/lib/api'
import LiveMap, { flag, type Place } from './live/LiveMap'
import { dialogs } from '@/lib/dialogs'

type Team = 'staff' | 'members'
type Online = {
  session_id: string; user_id: string; name: string; email: string | null; role: string | null; team: Team; platform: 'web' | 'mobile'; device: string | null
  path: string | null; started_at: string; last_seen_at: string; minutes: number; status: 'active' | 'idle' | 'locked'; hits: number
  source?: 'app' | 'desktop' | 'mobile_web' | 'tablet'; country?: string | null; region?: string | null; city?: string | null; lat?: number | null; lng?: number | null
}
type Point = { t: string; staff: number; members: number }
type Live = {
  enabled?: boolean; settings?: { enabled: boolean; locations: boolean }
  places?: Place[]; by_source?: Record<string, number>; by_country?: { country: string; count: number }[]; unlocated?: number
  generated_at: string
  online: { total: number; staff: number; members: number; web: number; mobile: number; active: number }
  users: Online[]
  top_pages: { path: string; count: number }[]
  recent: { user_id: string; name: string; team: Team; platform: string; last_seen_at: string; minutes: number }[]
  timeline: Point[]
  today: { unique_users: number; sessions: number; avg_minutes: number; peak: number }
}
type Summary = {
  sessions: number; unique_users: number; total_minutes: number; avg_minutes: number
  by_team: Record<Team, number>; by_platform: { web: number; mobile: number }
  by_day: { day: string; sessions: number; users: number }[]; by_hour: { hour: number; sessions: number }[]
  top_pages: { path: string; sessions: number }[]; top_users: { name: string; team: Team; sessions: number; minutes: number }[]
}
type Row = { name: string; email: string | null; team: Team; role: string | null; platform: string; device: string | null; started_at: string; last_seen_at: string; minutes: number; hits: number; path: string | null }

const MENU_ROUTES: [string, string][] = [
  ['/admin/analytics', 'analytics'], ['/admin/needs-hub', 'needs'], ['/admin/programs', 'programs'], ['/admin/kits', 'kits'], ['/admin/calendar', 'calendar'], ['/admin/registrations', 'registrations'],
  ['/admin/certificates', 'certificates'], ['/admin/live', 'live'], ['/admin/geo', 'geo'], ['/admin/ai', 'ai'], ['/admin/communication', 'communication'], ['/admin/schools', 'schools'],
  ['/admin/employees', 'employees'], ['/admin/trainers', 'trainers'], ['/admin/rooms', 'rooms'], ['/admin/users', 'users'], ['/admin/audit', 'audit'], ['/admin/settings', 'settings'],
]

function usePageLabel() {
  const { t } = useTranslation()
  return (path: string | null) => {
    if (!path) return '—'
    const clean = path.split('?')[0].replace(/\/$/, '') || '/'
    if (clean === '/admin') return t('admin.menu.dashboard')
    const hit = MENU_ROUTES.find(([p]) => clean === p || clean.startsWith(`${p}/`))
    if (hit) return t(`admin.menu.${hit[1]}`)
    if (clean.startsWith('/app/')) return t(`mgmt.live.mobileScreens.${clean.slice(5)}`, { defaultValue: clean })
    if (clean.startsWith('/portal')) return t('nav.portal')
    return clean
  }
}

function useAgo() {
  const { t } = useTranslation()
  return (iso: string) => {
    const s = Math.max(0, Math.round((Date.now() - new Date(iso).getTime()) / 1000))
    if (s < 15) return t('mgmt.live.ago.now')
    if (s < 60) return t('mgmt.live.ago.sec', { n: s })
    if (s < 3600) return t('mgmt.live.ago.min', { n: Math.round(s / 60) })
    return t('mgmt.live.ago.hour', { n: Math.round(s / 3600) })
  }
}

/** Live now: who is online (administration team and app users), and a usage report that can be exported. */
export default function LiveNow() {
  const { t } = useTranslation()
  const [tab, setTab] = useState<'live' | 'report'>('live')
  return (
    <div>
      <PageHeader
        title={<span className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-navy-900 text-gold-300"><Radio className="size-5" /></span>{t('mgmt.live.title')}</span>}
        subtitle={t('mgmt.live.subtitle')}
        actions={
          <div role="tablist" className="inline-flex rounded-xl border border-navy-100 bg-white p-1">
            {([['live', Activity], ['report', FileBarChart]] as const).map(([id, Icon]) => (
              <button key={id} type="button" role="tab" aria-selected={tab === id} onClick={() => setTab(id)} className={clsx('inline-flex items-center gap-2 rounded-lg px-4 py-2 text-sm font-bold transition', tab === id ? 'bg-navy-900 text-white' : 'text-slate-500 hover:text-navy-900')}>
                <Icon className="size-4" />{t(`mgmt.live.tabs.${id}`)}
              </button>
            ))}
          </div>
        }
      />
      {tab === 'live' ? <LiveTab /> : <ReportTab />}
    </div>
  )
}

function LiveTab() {
  const { t } = useTranslation()
  const pageLabel = usePageLabel()
  const ago = useAgo()
  const i18nLang = useTranslation().i18n.language
  const { data, isLoading, isFetching, dataUpdatedAt, error, refetch } = useGet<{ data: Live }>('/admin/presence/live', undefined, { refetchInterval: 5_000, staleTime: 0 })
  const [switching, setSwitching] = useState(false)
  const [feed, setFeed] = useState<{ id: string; name: string; kind: 'joined' | 'left'; at: number }[]>([])
  const known = useRef<Map<string, string> | null>(null)
  const [filter, setFilter] = useState<'all' | Team>('all')
  const [q, setQ] = useState('')
  const [, tick] = useState(0)
  useEffect(() => { const id = setInterval(() => tick((n) => n + 1), 5000); return () => clearInterval(id) }, [])

  const live = data?.data
  const users = useMemo(() => (live?.users ?? []).filter((u) => (filter === 'all' || u.team === filter) && (!q || `${u.name} ${u.email} ${u.role}`.toLowerCase().includes(q.toLowerCase()))), [live, filter, q])

  // Arrivals and departures, found by comparing each refresh with the one before.
  useEffect(() => {
    if (!live?.users) return
    const now = new Map(live.users.map((u) => [u.user_id, u.name]))
    if (known.current) {
      const events: { id: string; name: string; kind: 'joined' | 'left'; at: number }[] = []
      now.forEach((name, id) => { if (!known.current!.has(id)) events.push({ id: `j${id}${Date.now()}`, name, kind: 'joined', at: Date.now() }) })
      known.current.forEach((name, id) => { if (!now.has(id)) events.push({ id: `l${id}${Date.now()}`, name, kind: 'left', at: Date.now() }) })
      if (events.length) setFeed((f) => [...events, ...f].slice(0, 12))
    }
    known.current = now
  }, [live])

  const setEnabled = async (patch: { enabled?: boolean; locations?: boolean }) => {
    setSwitching(true)
    try { await api.put('/admin/presence/settings', patch); await refetch() } finally { setSwitching(false) }
  }

  if (isLoading) return <Spinner />
  if (error || !live) return <Card><p className="py-10 text-center text-slate-500">{errorMessage(error)}</p></Card>
  if (live.enabled === false) {
    return (
      <Card className="mx-auto max-w-xl py-12 text-center">
        <span className="mx-auto grid size-16 place-items-center rounded-full bg-slate-100 text-slate-400"><Power className="size-8" /></span>
        <h2 className="mt-4 text-xl font-bold text-navy-900">{t('liveGeo.offTitle')}</h2>
        <p className="mx-auto mt-1 max-w-sm text-sm text-slate-500">{t('liveGeo.offText')}</p>
        <Button className="mt-5" variant="gold" loading={switching} icon={<Power className="size-4" />} onClick={() => setEnabled({ enabled: true })}>{t('liveGeo.turnOn')}</Button>
      </Card>
    )
  }
  const { online } = live
  const places = live.places ?? []
  const staffShare = online.total ? Math.round((online.staff / Math.max(online.staff + online.members, 1)) * 100) : 0
  const updated = Math.max(0, Math.round((Date.now() - dataUpdatedAt) / 1000))

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-4 rounded-2xl border border-navy-100 bg-white px-4 py-3">
        <button type="button" role="switch" aria-checked onClick={async () => await dialogs.confirm(t('liveGeo.turnOff')) && setEnabled({ enabled: false })} disabled={switching} className="flex items-center gap-3">
          <span className="relative inline-flex h-6 w-11 shrink-0 items-center rounded-full bg-emerald-500 p-0.5"><span className="size-5 rounded-full bg-white shadow ltr:translate-x-5 rtl:-translate-x-5" /></span>
          <span className="text-sm font-bold text-navy-900">{t('liveGeo.toggle')} · <span className="text-emerald-700">{t('liveGeo.on')}</span></span>
        </button>
        <button type="button" role="switch" aria-checked={live.settings?.locations ?? true} onClick={() => setEnabled({ locations: !(live.settings?.locations ?? true) })} disabled={switching} className="flex items-center gap-3">
          <span className={clsx('relative inline-flex h-6 w-11 shrink-0 items-center rounded-full p-0.5 transition', (live.settings?.locations ?? true) ? 'bg-emerald-500' : 'bg-slate-300')}><span className={clsx('size-5 rounded-full bg-white shadow transition', (live.settings?.locations ?? true) && 'ltr:translate-x-5 rtl:-translate-x-5')} /></span>
          <span><span className="block text-sm font-bold text-navy-900">{t('liveGeo.locations')}</span><span className="block text-[11px] text-slate-500">{t('liveGeo.locationsHint')}</span></span>
        </button>
        <span className="ms-auto text-xs text-slate-400">{t('liveGeo.auto')}</span>
      </div>

      {/* Realtime hero */}
      <section className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-navy-950 via-navy-900 to-navy-800 p-6 text-white shadow-glass sm:p-8">
        <div className="pointer-events-none absolute -end-24 -top-28 size-80 rounded-full bg-gold-500/15 blur-3xl" />
        <div className="relative grid gap-8 lg:grid-cols-[minmax(0,1fr)_minmax(0,1.4fr)]">
          <div>
            <div className="flex items-center gap-2 text-sm font-semibold text-emerald-300">
              <span className="relative flex size-3"><span className="absolute inline-flex size-3 rounded-full bg-emerald-400" style={{ animation: 'live-ping 1.6s ease-out infinite' }} /><span className="relative inline-flex size-3 rounded-full bg-emerald-400" /></span>
              {t('mgmt.live.live')}
              <span className="ms-2 rounded-full bg-white/10 px-2 py-0.5 text-[11px] font-medium text-white/70">{isFetching ? t('mgmt.live.updating') : t('mgmt.live.updated', { n: updated })}</span>
            </div>
            <div className="mt-3 flex items-end gap-3">
              <span className="text-7xl font-black leading-none tabular-nums sm:text-8xl">{fmt.number(online.total)}</span>
              <span className="pb-2 text-lg text-white/70">{t('mgmt.live.onlineNow')}</span>
            </div>
            <p className="mt-2 text-sm text-white/60">{t('mgmt.live.activeNow', { active: online.active })}</p>

            <div className="mt-6">
              <div className="flex h-3 overflow-hidden rounded-full bg-white/10" role="img" aria-label={`${online.staff} / ${online.members}`}>
                <div className="bg-gold-400 transition-all duration-700" style={{ width: `${online.total ? staffShare : 0}%` }} />
                <div className="bg-sky-400 transition-all duration-700" style={{ width: `${online.total ? 100 - staffShare : 0}%` }} />
              </div>
              <div className="mt-3 grid grid-cols-2 gap-3">
                <div className="rounded-2xl bg-white/10 p-3"><div className="flex items-center gap-2 text-xs text-white/70"><span className="size-2.5 rounded-full bg-gold-400" /><ShieldCheck className="size-3.5" />{t('mgmt.live.team.staff')}</div><div className="mt-1 text-2xl font-extrabold tabular-nums">{fmt.number(online.staff)}</div></div>
                <div className="rounded-2xl bg-white/10 p-3"><div className="flex items-center gap-2 text-xs text-white/70"><span className="size-2.5 rounded-full bg-sky-400" /><UsersRound className="size-3.5" />{t('mgmt.live.team.members')}</div><div className="mt-1 text-2xl font-extrabold tabular-nums">{fmt.number(online.members)}</div></div>
              </div>
              <div className="mt-3 flex gap-2 text-xs">
                <span className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1"><Monitor className="size-3.5" />{t('mgmt.live.platform.web')} {online.web}</span>
                <span className="inline-flex items-center gap-1.5 rounded-full bg-white/10 px-3 py-1"><Smartphone className="size-3.5" />{t('mgmt.live.platform.mobile')} {online.mobile}</span>
              </div>
            </div>
          </div>
          <div className="min-w-0">
            <div className="mb-2 flex items-center justify-between text-xs text-white/60"><span>{t('mgmt.live.last30')}</span>
              <span className="flex gap-3"><span className="inline-flex items-center gap-1"><span className="size-2 rounded-full bg-gold-400" />{t('mgmt.live.team.staff')}</span><span className="inline-flex items-center gap-1"><span className="size-2 rounded-full bg-sky-400" />{t('mgmt.live.team.members')}</span></span></div>
            <Timeline points={live.timeline} />
          </div>
        </div>
      </section>

      {/* Today */}
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        {[
          { icon: Users, label: t('mgmt.live.today.users'), value: fmt.number(live.today.unique_users) },
          { icon: Globe2, label: t('mgmt.live.today.sessions'), value: fmt.number(live.today.sessions) },
          { icon: Clock, label: t('mgmt.live.today.avg'), value: `${fmt.number(live.today.avg_minutes, 1)} ${t('mgmt.live.min')}` },
          { icon: TrendingUp, label: t('mgmt.live.today.peak'), value: fmt.number(live.today.peak) },
        ].map((k) => (
          <div key={k.label} className="flex items-center gap-4 rounded-2xl border border-navy-100 bg-white p-4 shadow-sm">
            <span className="grid size-11 place-items-center rounded-xl bg-navy-100/60 text-navy-800"><k.icon className="size-5" /></span>
            <div><div className="text-xs font-semibold text-slate-500">{k.label}</div><div className="text-2xl font-extrabold text-navy-900">{k.value}</div></div>
          </div>
        ))}
      </div>

      {(live.settings?.locations ?? true) && (
        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
          <Card padded={false} className="overflow-hidden">
            <div className="flex items-center gap-3 p-5 pb-3"><MapPinned className="size-5 text-gold-600" /><div><h2 className="text-lg font-bold text-navy-900">{t('liveGeo.mapTitle')}</h2><p className="text-xs text-slate-500">{t('liveGeo.mapHint')}</p></div></div>
            <div className="relative h-[26rem]">
              <LiveMap places={places} />
              {places.length === 0 && <div className="pointer-events-none absolute inset-0 z-[500] grid place-items-center bg-white/55 p-6 text-center text-sm font-semibold text-slate-600 backdrop-blur-[1px]">{t('liveGeo.noMap')}</div>}
            </div>
            {(live.unlocated ?? 0) > 0 && <div className="px-5 py-2 text-xs text-slate-500">{t('liveGeo.unlocated', { count: live.unlocated })}</div>}
          </Card>
          <div className="space-y-6">
            <Card>
              <h3 className="mb-3 font-bold text-navy-900">{t('liveGeo.sourceTitle')}</h3>
              <ul className="space-y-3">
                {([['app', Smartphone], ['desktop', Laptop], ['mobile_web', TabletSmartphone], ['tablet', Tablet]] as const).map(([k, Icon]) => {
                  const n = live.by_source?.[k] ?? 0
                  const total = Math.max(1, online.total)
                  return <li key={k}><div className="flex items-center justify-between text-sm"><span className="inline-flex items-center gap-2 font-semibold text-navy-900"><Icon className="size-4 text-gold-600" />{t(`liveGeo.sources.${k}`)}</span><span className="font-bold tabular-nums">{n}</span></div><div className="mt-1 h-1.5 overflow-hidden rounded-full bg-navy-100/60"><div className="h-full rounded-full bg-gradient-to-r from-gold-500 to-gold-300 transition-all duration-700" style={{ width: `${(n / total) * 100}%` }} /></div></li>
                })}
              </ul>
            </Card>
            <Card>
              <h3 className="mb-3 font-bold text-navy-900">{t('liveGeo.countries')}</h3>
              {(live.by_country ?? []).length === 0 ? <p className="text-sm text-slate-400">—</p> : (
                <ul className="space-y-2">{(live.by_country ?? []).slice(0, 6).map((c) => <li key={c.country} className="flex items-center justify-between text-sm"><span className="font-semibold text-navy-900">{flag(c.country)} {new Intl.DisplayNames([i18nLang], { type: 'region' }).of(c.country)}</span><span className="font-bold tabular-nums">{c.count}</span></li>)}</ul>
              )}
            </Card>
            <Card>
              <h3 className="mb-3 font-bold text-navy-900">{t('liveGeo.feed')}</h3>
              {feed.length === 0 ? <p className="text-sm text-slate-400">{t('liveGeo.feedEmpty')}</p> : (
                <ul className="space-y-2">{feed.map((e) => <li key={e.id} className="flex items-center gap-2 text-sm"><span className={clsx('grid size-6 place-items-center rounded-full', e.kind === 'joined' ? 'bg-emerald-100 text-emerald-700' : 'bg-slate-100 text-slate-500')}>{e.kind === 'joined' ? <LogIn className="size-3.5" /> : <LogOut className="size-3.5" />}</span><span className="min-w-0 flex-1 truncate font-semibold text-navy-900">{e.name}</span><span className="text-xs text-slate-400">{t(`liveGeo.${e.kind}`)}</span></li>)}</ul>
              )}
            </Card>
          </div>
        </div>
      )}

      <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <Card padded={false}>
          <div className="flex flex-wrap items-center gap-3 p-5 pb-3">
            <h2 className="text-lg font-bold text-navy-900">{t('mgmt.live.people')} <span className="text-slate-400">({users.length})</span></h2>
            <div role="tablist" className="ms-auto inline-flex rounded-xl border border-navy-100 p-1 text-xs font-bold">
              {(['all', 'staff', 'members'] as const).map((f) => (
                <button key={f} type="button" role="tab" aria-selected={filter === f} onClick={() => setFilter(f)} className={clsx('rounded-lg px-3 py-1.5 transition', filter === f ? 'bg-navy-900 text-white' : 'text-slate-500')}>
                  {f === 'all' ? t('mgmt.labels.presets.all') : t(`mgmt.live.team.${f}`)}
                </button>
              ))}
            </div>
            <div className="relative w-full sm:w-56"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input !ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('mgmt.live.search')} aria-label={t('mgmt.live.search')} /></div>
          </div>
          {users.length === 0 ? <div className="p-8"><Empty text={t('mgmt.live.nobody')} /></div> : (
            <ul className="divide-y divide-navy-100">
              {users.map((u) => (
                <li key={u.session_id} className="flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3.5 transition hover:bg-ivory/60">
                  <div className="relative"><Avatar name={u.name} size={42} /><span className={clsx('absolute -bottom-0.5 -end-0.5 size-3.5 rounded-full ring-2 ring-white', u.status === 'active' ? 'bg-emerald-500' : u.status === 'idle' ? 'bg-amber-400' : 'bg-slate-400')} /></div>
                  <div className="min-w-0 flex-1 basis-48">
                    <div className="flex items-center gap-2"><span className="truncate font-bold text-navy-900">{u.name}</span>
                      <Badge color={u.team === 'staff' ? 'gold' : 'navy'}>{t(`mgmt.live.team.${u.team}`)}</Badge></div>
                    <div className="truncate text-xs text-slate-500">{u.role ?? '—'}{u.email && <> · <span dir="ltr">{u.email}</span></>}</div>
                  </div>
                  <div className="flex min-w-36 items-center gap-2 text-sm text-navy-800">{u.platform === 'web' ? <Monitor className="size-4 text-slate-400" /> : <Smartphone className="size-4 text-slate-400" />}
                    <div><div className="font-semibold">{pageLabel(u.path)}</div>{u.country && <div className="text-[11px] text-slate-500">{flag(u.country)} {u.city ?? new Intl.DisplayNames([i18nLang], { type: 'region' }).of(u.country)}</div>}<div className="text-[11px] text-slate-400">{u.device ?? t(`mgmt.live.platform.${u.platform}`)}</div></div></div>
                  <StatusPill status={u.status} />
                  <div className="w-24 text-end text-xs text-slate-500"><div className="inline-flex items-center gap-1"><Timer className="size-3" />{t('mgmt.live.minutesOnline', { n: u.minutes })}</div><div>{ago(u.last_seen_at)}</div></div>
                </li>
              ))}
            </ul>
          )}
        </Card>

        <div className="space-y-6">
          <Card>
            <h3 className="mb-3 font-bold text-navy-900">{t('mgmt.live.topPages')}</h3>
            {live.top_pages.length === 0 ? <p className="text-sm text-slate-400">{t('mgmt.live.nobody')}</p> : (
              <ul className="space-y-3">{live.top_pages.map((p) => (
                <li key={p.path}><div className="flex justify-between text-sm"><span className="truncate font-semibold text-navy-900">{pageLabel(p.path)}</span><span className="font-bold text-navy-900">{p.count}</span></div>
                  <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-navy-100/60"><div className="h-full rounded-full bg-gold-500 transition-all duration-700" style={{ width: `${(p.count / Math.max(...live.top_pages.map((x) => x.count))) * 100}%` }} /></div></li>
              ))}</ul>
            )}
          </Card>
          <Card>
            <h3 className="mb-3 flex items-center gap-2 font-bold text-navy-900"><MoonStar className="size-4 text-slate-400" />{t('mgmt.live.recent')}</h3>
            {live.recent.length === 0 ? <p className="text-sm text-slate-400">—</p> : (
              <ul className="space-y-2.5">{live.recent.map((r) => (
                <li key={r.user_id} className="flex items-center gap-3 text-sm"><Avatar name={r.name} size={30} /><span className="min-w-0 flex-1 truncate font-semibold text-navy-900">{r.name}</span><span className="text-xs text-slate-400">{ago(r.last_seen_at)}</span></li>
              ))}</ul>
            )}
          </Card>
        </div>
      </div>
    </div>
  )
}

function StatusPill({ status }: { status: Online['status'] }) {
  const { t } = useTranslation()
  const style = { active: 'bg-emerald-50 text-emerald-700', idle: 'bg-amber-50 text-amber-700', locked: 'bg-slate-100 text-slate-600' }[status]
  return <span className={clsx('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-xs font-bold', style)}>{status === 'locked' ? <Lock className="size-3" /> : <span className={clsx('size-1.5 rounded-full', status === 'active' ? 'animate-pulse bg-emerald-500' : 'bg-amber-500')} />}{t(`mgmt.live.status.${status}`)}</span>
}

/** Stacked area chart of people online during the last 30 minutes. */
function Timeline({ points }: { points: Point[] }) {
  const { t } = useTranslation()
  const [hover, setHover] = useState<number | null>(null)
  const W = 600, H = 170, P = 8
  const max = Math.max(3, ...points.map((p) => p.staff + p.members))
  const x = (i: number) => P + (i / Math.max(points.length - 1, 1)) * (W - P * 2)
  const y = (v: number) => H - P - (v / max) * (H - P * 2 - 10)
  const area = (pick: (p: Point) => [number, number]) => {
    const top = points.map((p, i) => `${x(i)},${y(pick(p)[1])}`).join(' L')
    const bottom = [...points].reverse().map((p, i) => `${x(points.length - 1 - i)},${y(pick(p)[0])}`).join(' L')
    return `M${top} L${bottom} Z`
  }
  const active = hover !== null ? points[hover] : points[points.length - 1]
  return (
    <div>
      <div className="relative">
        <svg viewBox={`0 0 ${W} ${H}`} className="h-44 w-full" role="img" aria-label={t('mgmt.live.last30')} onMouseLeave={() => setHover(null)}
          onMouseMove={(e) => { const r = e.currentTarget.getBoundingClientRect(); const rel = (e.clientX - r.left) / r.width; setHover(Math.max(0, Math.min(points.length - 1, Math.round((document.dir === 'rtl' ? 1 - rel : rel) * (points.length - 1))))) }}>
          {[0.25, 0.5, 0.75].map((g) => <line key={g} x1={P} x2={W - P} y1={y(max * g)} y2={y(max * g)} stroke="rgba(255,255,255,.08)" />)}
          <path d={area((p) => [0, p.staff])} fill="rgba(226,181,79,.55)" stroke="#e2b54f" strokeWidth="1.5" />
          <path d={area((p) => [p.staff, p.staff + p.members])} fill="rgba(56,189,248,.5)" stroke="#38bdf8" strokeWidth="1.5" />
          {hover !== null && <line x1={x(hover)} x2={x(hover)} y1={P} y2={H - P} stroke="rgba(255,255,255,.4)" strokeDasharray="3 3" />}
        </svg>
      </div>
      <div className="mt-1 flex items-center justify-between text-[11px] text-white/60">
        <span>{t('mgmt.live.minutesAgo', { n: 30 })}</span>
        <span className="rounded-full bg-white/10 px-2.5 py-0.5 text-white/80">{fmt.time(active?.t)} · {t('mgmt.live.team.staff')} {active?.staff ?? 0} · {t('mgmt.live.team.members')} {active?.members ?? 0}</span>
        <span>{t('mgmt.live.ago.now')}</span>
      </div>
    </div>
  )
}

function ReportTab() {
  const { t } = useTranslation()
  const pageLabel = usePageLabel()
  const today = new Date().toISOString().slice(0, 10)
  const daysAgo = (n: number) => new Date(Date.now() - n * 86400000).toISOString().slice(0, 10)
  const [range, setRange] = useState<'today' | '7' | '30' | 'custom'>('7')
  const [from, setFrom] = useState(daysAgo(6))
  const [to, setTo] = useState(today)
  const [team, setTeam] = useState('')
  const [platform, setPlatform] = useState('')
  const [exporting, setExporting] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const pick = (r: typeof range) => {
    setRange(r)
    if (r === 'today') { setFrom(today); setTo(today) } else if (r === '7') { setFrom(daysAgo(6)); setTo(today) } else if (r === '30') { setFrom(daysAgo(29)); setTo(today) }
  }
  const params = { from, to, ...(team ? { team } : {}), ...(platform ? { platform } : {}) }
  const { data, isLoading } = useGet<{ data: { summary: Summary; sessions: Row[] } }>('/admin/presence/report', params)
  const s = data?.data.summary

  const exportCsv = async () => {
    setExporting(true)
    setError(null)
    try {
      await downloadFile(`/admin/presence/export?${new URLSearchParams(params as Record<string, string>).toString()}`, `online-report-${from}_${to}.csv`)
    } catch (e) { setError(errorMessage(e)) } finally { setExporting(false) }
  }

  const maxHour = Math.max(1, ...(s?.by_hour.map((h) => h.sessions) ?? [1]))
  const maxDay = Math.max(1, ...(s?.by_day.map((d) => d.sessions) ?? [1]))

  return (
    <div className="space-y-6">
      <Card>
        <div className="flex flex-wrap items-end gap-3">
          <div role="tablist" className="inline-flex rounded-xl border border-navy-100 p-1 text-sm font-bold">
            {(['today', '7', '30', 'custom'] as const).map((r) => (
              <button key={r} type="button" role="tab" aria-selected={range === r} onClick={() => pick(r)} className={clsx('rounded-lg px-3.5 py-1.5 transition', range === r ? 'bg-navy-900 text-white' : 'text-slate-500')}>{t(`mgmt.live.range.${r}`)}</button>
            ))}
          </div>
          <label className="text-xs font-semibold text-slate-500">{t('common.from')}<input type="date" className="input mt-1" value={from} max={to} onChange={(e) => { setFrom(e.target.value); setRange('custom') }} /></label>
          <label className="text-xs font-semibold text-slate-500">{t('common.to')}<input type="date" className="input mt-1" value={to} min={from} max={today} onChange={(e) => { setTo(e.target.value); setRange('custom') }} /></label>
          <label className="text-xs font-semibold text-slate-500">{t('mgmt.live.filterTeam')}
            <select className="input mt-1" value={team} onChange={(e) => setTeam(e.target.value)}><option value="">{t('mgmt.labels.presets.all')}</option><option value="staff">{t('mgmt.live.team.staff')}</option><option value="members">{t('mgmt.live.team.members')}</option></select></label>
          <label className="text-xs font-semibold text-slate-500">{t('mgmt.live.filterPlatform')}
            <select className="input mt-1" value={platform} onChange={(e) => setPlatform(e.target.value)}><option value="">{t('mgmt.labels.presets.all')}</option><option value="web">{t('mgmt.live.platform.web')}</option><option value="mobile">{t('mgmt.live.platform.mobile')}</option></select></label>
          <Button className="ms-auto" variant="gold" icon={<Download className="size-4" />} loading={exporting} onClick={exportCsv}>{t('mgmt.live.exportCsv')}</Button>
        </div>
        {error && <p className="mt-3 text-sm text-danger">{error}</p>}
      </Card>

      {isLoading || !s ? <Spinner /> : (
        <>
          <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            {[
              { icon: Users, label: t('mgmt.live.report.users'), value: fmt.number(s.unique_users), hint: `${t('mgmt.live.team.staff')} ${s.by_team.staff} · ${t('mgmt.live.team.members')} ${s.by_team.members}` },
              { icon: Globe2, label: t('mgmt.live.report.sessions'), value: fmt.number(s.sessions), hint: `${t('mgmt.live.platform.web')} ${s.by_platform.web} · ${t('mgmt.live.platform.mobile')} ${s.by_platform.mobile}` },
              { icon: Clock, label: t('mgmt.live.report.avg'), value: `${fmt.number(s.avg_minutes, 1)} ${t('mgmt.live.min')}`, hint: '' },
              { icon: CalendarDays, label: t('mgmt.live.report.hours'), value: fmt.number(Math.round(s.total_minutes / 60)), hint: t('mgmt.live.report.hoursHint') },
            ].map((k) => (
              <div key={k.label} className="rounded-2xl border border-navy-100 bg-white p-4 shadow-sm">
                <div className="flex items-center gap-2 text-xs font-semibold text-slate-500"><k.icon className="size-4" />{k.label}</div>
                <div className="mt-1 text-3xl font-extrabold text-navy-900">{k.value}</div>{k.hint && <div className="mt-0.5 text-[11px] text-slate-400">{k.hint}</div>}
              </div>
            ))}
          </div>

          <div className="grid gap-6 lg:grid-cols-2">
            <Card>
              <h3 className="mb-4 font-bold text-navy-900">{t('mgmt.live.report.byDay')}</h3>
              {s.by_day.length === 0 ? <Empty text={t('mgmt.live.report.none')} /> : (
                <div className="flex h-40 items-end gap-1.5" role="img" aria-label={t('mgmt.live.report.byDay')}>
                  {s.by_day.map((d) => (
                    <div key={d.day} className="group flex min-w-0 flex-1 flex-col items-center gap-1" title={`${d.day}: ${d.sessions} / ${d.users}`}>
                      <span className="text-[10px] font-bold text-navy-900 opacity-0 transition group-hover:opacity-100">{d.sessions}</span>
                      <div className="w-full rounded-t-md bg-gradient-to-t from-navy-900 to-navy-700 transition-all group-hover:from-gold-600 group-hover:to-gold-400" style={{ height: `${Math.max(4, (d.sessions / maxDay) * 100)}%` }} />
                      <span className="text-[10px] text-slate-400">{d.day.slice(5)}</span>
                    </div>
                  ))}
                </div>
              )}
            </Card>
            <Card>
              <h3 className="mb-4 font-bold text-navy-900">{t('mgmt.live.report.byHour')}</h3>
              <div className="flex h-40 items-end gap-1" role="img" aria-label={t('mgmt.live.report.byHour')}>
                {s.by_hour.map((h) => (
                  <div key={h.hour} className="group flex flex-1 flex-col items-center gap-1" title={`${h.hour}:00 — ${h.sessions}`}>
                    <div className="w-full rounded-t bg-gold-500/80 transition-all group-hover:bg-gold-600" style={{ height: `${Math.max(3, (h.sessions / maxHour) * 100)}%` }} />
                    <span className="text-[9px] text-slate-400">{h.hour % 3 === 0 ? h.hour : ''}</span>
                  </div>
                ))}
              </div>
            </Card>
          </div>

          <div className="grid gap-6 lg:grid-cols-2">
            <Card>
              <h3 className="mb-3 font-bold text-navy-900">{t('mgmt.live.report.topUsers')}</h3>
              <ul className="divide-y divide-navy-100">{s.top_users.map((u, i) => (
                <li key={i} className="flex items-center gap-3 py-2.5"><Avatar name={u.name} size={32} /><span className="min-w-0 flex-1 truncate font-semibold text-navy-900">{u.name}</span>
                  <Badge color={u.team === 'staff' ? 'gold' : 'navy'}>{t(`mgmt.live.team.${u.team}`)}</Badge><span className="w-24 text-end text-xs text-slate-500">{u.sessions} · {u.minutes} {t('mgmt.live.min')}</span></li>
              ))}</ul>
            </Card>
            <Card>
              <h3 className="mb-3 font-bold text-navy-900">{t('mgmt.live.report.topPages')}</h3>
              <ul className="space-y-3">{s.top_pages.map((p) => (
                <li key={p.path}><div className="flex justify-between text-sm"><span className="truncate font-semibold text-navy-900">{pageLabel(p.path)}</span><span className="font-bold">{p.sessions}</span></div>
                  <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-navy-100/60"><div className="h-full rounded-full bg-navy-800" style={{ width: `${(p.sessions / Math.max(...s.top_pages.map((x) => x.sessions))) * 100}%` }} /></div></li>
              ))}</ul>
            </Card>
          </div>

          <Card padded={false}>
            <div className="flex items-center justify-between p-5 pb-3"><h3 className="font-bold text-navy-900">{t('mgmt.live.report.sessionsTitle')}</h3><span className="text-xs text-slate-400">{t('mgmt.live.report.latest', { count: data?.data.sessions.length ?? 0 })}</span></div>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <thead><tr className="border-y border-navy-100 bg-ivory/60 text-xs text-slate-500">{['name', 'team', 'platform', 'started', 'minutes', 'page'].map((c) => <th key={c} className="px-5 py-2.5 text-start font-semibold">{t(`mgmt.live.cols.${c}`)}</th>)}</tr></thead>
                <tbody className="divide-y divide-navy-100">
                  {data?.data.sessions.slice(0, 50).map((r, i) => (
                    <tr key={i} className="hover:bg-ivory/50">
                      <td className="px-5 py-2.5"><div className="font-semibold text-navy-900">{r.name}</div><div className="text-xs text-slate-400" dir="ltr">{r.email}</div></td>
                      <td className="px-5 py-2.5"><Badge color={r.team === 'staff' ? 'gold' : 'navy'}>{t(`mgmt.live.team.${r.team}`)}</Badge></td>
                      <td className="px-5 py-2.5 text-slate-600">{r.platform === 'web' ? <Monitor className="me-1.5 inline size-4" /> : <Smartphone className="me-1.5 inline size-4" />}{r.device ?? r.platform}</td>
                      <td className="px-5 py-2.5 text-slate-600" dir="ltr">{r.started_at}</td>
                      <td className="px-5 py-2.5 font-semibold">{r.minutes}</td>
                      <td className="px-5 py-2.5 text-slate-600">{pageLabel(r.path)}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </Card>
        </>
      )}
    </div>
  )
}
