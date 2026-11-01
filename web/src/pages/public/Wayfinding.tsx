import clsx from 'clsx'
import { Accessibility, ArrowDownUp, ArrowLeft, ArrowRight, ArrowUp, Building2, Car, Check, ChevronDown, ChevronLeft, ChevronRight, CornerUpLeft, CornerUpRight, DoorOpen, FlaskConical, Footprints, Hand, Library, LocateFixed, MapPin, Navigation, QrCode, Search, Sparkles, Undo2, Users, X } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type ComponentType, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { LogoMark } from '@/components/public/Logo'
import { CENTRE_MAP, directionsUrl, distanceTo, getEngine, originOf, signKey, stepText, useDestinations, useWayfinding, type Destination, type Origin } from '@/features/wayfinding/data'
import type { FloorId, Pt, Step } from '@/features/wayfinding/engine'
import FloorPlan, { type Marker } from '@/features/wayfinding/FloorPlan'

type Filter = 'all' | 'train' | 'lab' | 'meet' | 'services'
const FILTERS: Filter[] = ['all', 'train', 'lab', 'meet', 'services']

/**
 * «دليل المركز» — the centre's indoor guide (docs/wayfinding.md). A visitor picks where to go (their training room by name, a lab,
 * the prayer room…), says where they are (the entrance, a "you are here" sign's QR code, a tap on the plan) and is walked there step by
 * step across both floors, the plan always framing where they stand and where they are going. Open to everyone, no sign-in.
 */
export default function Wayfinding() {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const [params, setParams] = useSearchParams()
  const { data, loaded } = useWayfinding()
  const destinations = useDestinations(data)
  const engine = getEngine()

  const set = (patch: Record<string, string | null>) => setParams((p) => {
    const next = new URLSearchParams(p)
    Object.entries(patch).forEach(([k, v]) => (v === null ? next.delete(k) : next.set(k, v)))
    return next
  }, { replace: true })

  const avoidStairs = params.get('stairs') === '0'
  const origin: Origin = originOf(params.get('from'), data) ?? originOf(data.entrances[0]?.id ?? CENTRE_MAP.entrances[0].id, data)!
  const toKey = params.get('to') ?? (params.get('room') ? `room:${params.get('room')}` : null)
  // A place id finds the room that stands there too (?to=f-room-14 when «القاعة 14» is on the map under its own name).
  const dest = toKey ? destinations.find((d) => d.key === toKey) ?? destinations.find((d) => d.place?.id === toKey) ?? null : null
  const indoor = dest && dest.place ? dest : null
  const route = useMemo(() => indoor ? engine.route({ floor: origin.floor, at: origin.at }, indoor, { avoidStairs }) : null, [engine, indoor, origin.floor, origin.at, avoidStairs])
  // Where the visitor is in the walk; a new route (another destination, start or step-free choice) starts again from its first step.
  const routeKey = `${toKey ?? ''}|${origin.key}|${avoidStairs ? 1 : 0}`
  const [nav, setNav] = useState<{ key: string; step: number; arrived: boolean; floor: FloorId | null }>({ key: routeKey, step: 0, arrived: false, floor: null })
  const walk = nav.key === routeKey ? nav : { key: routeKey, step: 0, arrived: false, floor: null }
  const { step, arrived } = walk
  const manualFloor = walk.floor
  const setStep = (i: number) => setNav({ ...walk, step: i, floor: null })
  const setManualFloor = (f: FloorId | null) => setNav({ ...walk, floor: f })
  const setArrived = (a: boolean) => setNav({ ...walk, arrived: a })
  const [sheet, setSheet] = useState(true)
  const [picker, setPicker] = useState(false)
  const [picking, setPicking] = useState(false)
  const [scan, setScan] = useState(false)
  useEffect(() => { document.title = `${t('wayfinding.title')} — ${t('wayfinding.subtitle')}` }, [t])

  const current: Step | null = route?.steps[step] ?? null
  const floor: FloorId = manualFloor ?? current?.floor ?? (indoor && !route ? indoor.floor : origin.floor)
  const leg = route?.legs.find((l) => l.floor === floor)?.points ?? null
  const destTitle = dest ? (en ? dest.title.en : dest.title.ar) : ''
  const fromTitle = en ? origin.title.en : origin.title.ar

  const you: Pt | null = current ? (current.floor === floor ? current.at : null) : origin.floor === floor ? origin.at : null
  const next = route?.steps.slice(step + 1).find((s) => s.floor === floor)
  // The plan always frames where the visitor stands and where they are going on this floor (the door, or the stairs that lead to it).
  const goal = indoor && indoor.floor === floor ? indoor.at : leg && route?.legs[0].floor === floor ? leg[leg.length - 1] : null
  const focus: Pt[] | null = route
    ? [...(you ? [you] : leg ? [leg[0]] : []), ...(next ? [next.at] : []), ...(goal ? [goal] : [])]
    : indoor && you && indoor.floor === floor ? [you, indoor.at] : null   // nothing chosen yet: the whole floor, the visitor's dot on it
  const inset = useInset(sheet)

  const markers: Marker[] = [
    ...(you ? [{ at: you, kind: 'you' as const }] : []),
    ...(indoor && indoor.floor === floor ? [{ at: indoor.at, kind: 'target' as const }] : []),
  ]
  const floors = CENTRE_MAP.floors

  return (
    <div className="relative h-[100dvh] overflow-hidden bg-[radial-gradient(circle_at_1px_1px,rgba(26,26,26,.07)_1px,transparent_0)] bg-[length:22px_22px] bg-[#f3f1ec]">
      <FloorPlan map={CENTRE_MAP} floor={floor} route={leg} markers={markers} highlight={indoor?.floor === floor ? indoor.id : null} focus={focus} inset={inset} picking={picking} className="absolute inset-0"
        onPick={({ place, at }) => {
          if (picking) { set({ from: `${floor}:${Math.round(at[0])},${Math.round(at[1])}` }); setPicking(false); return }
          if (place) { const d = destinations.find((x) => x.place?.id === place.id); if (d) { set({ to: d.key }); setSheet(true) } }
        }}>
        {picking && (
          <div className="pointer-events-auto absolute inset-x-4 top-24 mx-auto flex max-w-md items-center gap-3 rounded-2xl bg-navy-900 px-4 py-3 text-sm font-semibold text-white shadow-glass">
            <Hand className="size-5 shrink-0 text-gold-300" /><span className="flex-1">{t('wayfinding.tapWhere')}</span>
            <button type="button" onClick={() => setPicking(false)} className="rounded-lg p-1 hover:bg-white/10" aria-label={t('common.cancel')}><X className="size-4" /></button>
          </div>
        )}
      </FloorPlan>

      {/* Top bar */}
      <header className="pointer-events-none absolute inset-x-0 top-0 z-10 flex flex-wrap items-start justify-between gap-2 p-3 sm:p-4">
        <div className="pointer-events-auto flex items-center gap-2 rounded-2xl bg-white/90 p-1.5 shadow-glass ring-1 ring-navy-100 backdrop-blur sm:gap-3 sm:py-2 sm:pe-4 sm:ps-2">
          <Link to="/" className="rounded-xl transition hover:opacity-80" aria-label={t('nav.home', { defaultValue: 'Home' })}><LogoMark className="size-8 sm:size-9" /></Link>
          <div className="hidden leading-tight min-[420px]:block">
            <div className="text-sm font-bold text-navy-900">{t('wayfinding.title')}</div>
            <div className="hidden text-[11px] text-slate-500 sm:block">{t('wayfinding.subtitle')}</div>
          </div>
        </div>
        <div className="pointer-events-auto flex items-center gap-2">
          <div className="flex rounded-2xl bg-white/90 p-1 shadow-glass ring-1 ring-navy-100 backdrop-blur" role="tablist" aria-label={t('wayfinding.floor')}>
            {[...floors].reverse().map((f) => (
              <button key={f.id} type="button" role="tab" aria-selected={floor === f.id} onClick={() => setManualFloor(f.id === (current?.floor ?? null) ? null : f.id)}
                className={clsx('relative rounded-xl px-3 py-2 text-xs font-bold transition sm:px-4', floor === f.id ? 'bg-navy-900 text-white shadow' : 'text-navy-800 hover:bg-gold-50')}>
                {en ? f.en : f.ar}
                {route?.legs.some((l) => l.floor === f.id) && floor !== f.id && <span className="absolute -top-0.5 end-1 size-2 rounded-full bg-gold-500 ring-2 ring-white" />}
              </button>
            ))}
          </div>
          <button type="button" onClick={() => set({ stairs: avoidStairs ? null : '0' })} aria-pressed={avoidStairs} title={t('wayfinding.avoidStairs')}
            className={clsx('grid size-11 place-items-center rounded-2xl shadow-glass ring-1 backdrop-blur transition', avoidStairs ? 'bg-navy-900 text-gold-300 ring-navy-900' : 'bg-white/90 text-navy-800 ring-navy-100 hover:bg-gold-50')}>
            <Accessibility className="size-5" /><span className="sr-only">{t('wayfinding.avoidStairs')}</span>
          </button>
        </div>
      </header>

      {/* The sheet: where to, the route, the steps */}
      <aside className={clsx('absolute z-20 flex flex-col bg-white/95 shadow-[0_-12px_40px_-12px_rgba(0,0,0,.25)] ring-1 ring-navy-100 backdrop-blur-xl transition-all duration-300',
        'inset-x-0 bottom-0 rounded-t-[28px] lg:inset-x-auto lg:bottom-4 lg:start-4 lg:top-24 lg:w-[420px] lg:rounded-[28px]',
        sheet ? 'max-h-[64dvh] lg:max-h-none' : 'max-h-[118px] lg:max-h-none')}>
        <button type="button" onClick={() => setSheet((s) => !s)} className="mx-auto mt-2 h-1.5 w-12 shrink-0 rounded-full bg-navy-100 lg:hidden" aria-label={t('wayfinding.toggleSheet')} />
        {!dest ? (
          <Choose destinations={destinations} origin={fromTitle} onOrigin={() => setPicker(true)} onPick={(d) => { set({ to: d.key }); setSheet(true) }} loaded={loaded} en={en} />
        ) : !indoor ? (
          <Outdoor dest={dest} en={en} onBack={() => set({ to: null, room: null })} />
        ) : arrived ? (
          <Arrived dest={destTitle} onAgain={() => { set({ to: null, room: null }) }} />
        ) : (
          <RouteSheet route={route} step={step} setStep={setStep} from={fromTitle} dest={destTitle} destFloor={floors.find((f) => f.id === indoor.floor)!}
            onFrom={() => setPicker(true)} onTo={() => set({ to: null, room: null })} onArrive={() => setArrived(true)} centre={data.centre} en={en} />
        )}
      </aside>

      {picker && <OriginPicker data={data} destinations={destinations} en={en} onClose={() => setPicker(false)}
        onPick={(key) => { set({ from: key }); setPicker(false) }} onTap={() => { setPicker(false); setPicking(true); setSheet(false) }} onScan={() => { setPicker(false); setScan(true) }} />}
      {scan && <SignScanner onClose={() => setScan(false)} onFound={(key) => { set({ from: key }); setScan(false) }} />}
    </div>
  )
}

/** What the panels cover: the side panel on a wide screen (on the reading side), the bottom sheet on a phone. */
function useInset(open: boolean) {
  const [vw, setVw] = useState(() => (typeof window === 'undefined' ? 1280 : window.innerWidth))
  const [vh, setVh] = useState(() => (typeof window === 'undefined' ? 800 : window.innerHeight))
  useEffect(() => {
    const on = () => { setVw(window.innerWidth); setVh(window.innerHeight) }
    window.addEventListener('resize', on)
    return () => window.removeEventListener('resize', on)
  }, [])
  const rtl = typeof document !== 'undefined' && document.documentElement.dir === 'rtl'
  if (vw >= 1024) return rtl ? { top: 80, right: 452 } : { top: 80, left: 452 }
  return { top: 72, bottom: open ? Math.round(vh * 0.64) : 118 }
}

// ------------------------------------------------------------------ choose a destination

function Choose({ destinations, origin, onOrigin, onPick, loaded, en }: { destinations: Destination[]; origin: string; onOrigin: () => void; onPick: (d: Destination) => void; loaded: boolean; en: boolean }) {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const [filter, setFilter] = useState<Filter>('all')
  const norm = (s: string) => s.toLowerCase().replace(/[أإآ]/g, 'ا').replace(/ة/g, 'ه').replace(/ى/g, 'ي').replace(/[٠-٩]/g, (d) => String('٠١٢٣٤٥٦٧٨٩'.indexOf(d)))
  const list = destinations.filter((d) => {
    const kind = d.kind === 'room' ? 'train' : d.kind
    if (filter === 'train' && kind !== 'train') return false
    if (filter === 'lab' && kind !== 'lab') return false
    if (filter === 'meet' && kind !== 'meet') return false
    if (filter === 'services' && !['public', 'pray', 'wc', 'office'].includes(kind)) return false
    if (!q.trim()) return true
    const hay = norm(`${d.title.ar} ${d.title.en} ${d.room?.code ?? ''} ${d.place?.number ?? ''}`)
    return norm(q).split(/\s+/).every((w) => hay.includes(w))
  }).sort((a, b) => rank(a) - rank(b) || Number(a.place?.number ?? 99) - Number(b.place?.number ?? 99) || a.title.ar.localeCompare(b.title.ar, 'ar'))

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <div className="space-y-3 px-5 pb-3 pt-4">
        <h1 className="text-xl font-bold text-navy-900">{t('wayfinding.whereTo')}</h1>
        <label className="relative block">
          <Search className="pointer-events-none absolute start-3.5 top-1/2 size-5 -translate-y-1/2 text-slate-400" />
          <input className="input !rounded-2xl !py-3 !ps-11 text-base" placeholder={t('wayfinding.search')} value={q} onChange={(e) => setQ(e.target.value)} enterKeyHint="search" />
        </label>
        <button type="button" onClick={onOrigin} className="flex w-full items-center gap-2 rounded-2xl bg-ivory px-3 py-2.5 text-start text-sm ring-1 ring-navy-100 transition hover:ring-gold-400">
          <span className="grid size-8 place-items-center rounded-xl bg-navy-900 text-white"><LocateFixed className="size-4" /></span>
          <span className="min-w-0 flex-1"><span className="block text-[11px] font-semibold text-slate-500">{t('wayfinding.startFrom')}</span><span className="block truncate font-bold text-navy-900">{origin}</span></span>
          <ChevronDown className="size-4 text-slate-400" />
        </button>
        <div className="-mx-5 flex gap-2 overflow-x-auto px-5 pb-1 [scrollbar-width:none]">
          {FILTERS.map((f) => (
            <button key={f} type="button" onClick={() => setFilter(f)} aria-pressed={filter === f}
              className={clsx('shrink-0 rounded-full px-3.5 py-1.5 text-xs font-bold ring-1 transition', filter === f ? 'bg-navy-900 text-white ring-navy-900' : 'bg-white text-navy-800 ring-navy-100 hover:ring-gold-400')}>{t(`wayfinding.filter.${f}`)}</button>
          ))}
        </div>
      </div>
      <ul className="min-h-0 flex-1 space-y-1 overflow-y-auto px-3 pb-5">
        {!loaded && destinations.length === 0 && Array.from({ length: 5 }).map((_, i) => <li key={i} className="mx-2 h-14 animate-pulse rounded-2xl bg-ivory" />)}
        {list.map((d) => (
          <li key={d.key}>
            <button type="button" onClick={() => onPick(d)} className="group flex w-full items-center gap-3 rounded-2xl px-2 py-2 text-start transition hover:bg-gold-50">
              <PlaceTile d={d} />
              <span className="min-w-0 flex-1">
                <span className="block truncate font-bold text-navy-900">{en ? d.title.en : d.title.ar}</span>
                <span className="block truncate text-xs text-slate-500">{d.place ? floorName(d.floor, en) : t('wayfinding.outside')}{d.room?.capacity ? ` · ${t('wayfinding.seats', { n: d.room.capacity })}` : ''}</span>
              </span>
              {en ? <ChevronRight className="size-4 text-slate-300 transition group-hover:text-gold-600" /> : <ChevronLeft className="size-4 text-slate-300 transition group-hover:text-gold-600" />}
            </button>
          </li>
        ))}
        {loaded && list.length === 0 && <li className="px-4 py-10 text-center text-sm text-slate-500">{t('wayfinding.none')}</li>}
      </ul>
    </div>
  )
}

const rank = (d: Destination) => (d.room ? 0 : d.kind === 'train' ? 1 : d.kind === 'lab' ? 2 : d.kind === 'meet' ? 3 : d.kind === 'public' ? 4 : d.kind === 'pray' ? 5 : 6)
const floorName = (id: FloorId, en: boolean) => { const f = CENTRE_MAP.floors.find((x) => x.id === id); return f ? (en ? f.en : f.ar) : '' }

const KIND_ICON: Record<string, ComponentType<{ className?: string }>> = { lab: FlaskConical, meet: Users, public: Library, office: Building2, pray: Sparkles, wc: DoorOpen, room: MapPin }

function PlaceTile({ d }: { d: Destination }) {
  const Icon = KIND_ICON[d.kind] ?? MapPin
  const number = d.place?.number && (d.kind === 'train' || d.kind === 'lab') ? d.place.number : null
  return (
    <span className={clsx('grid size-12 shrink-0 place-items-center rounded-2xl text-lg font-extrabold ring-1', d.kind === 'train' ? 'bg-navy-900 text-white ring-navy-900' : d.kind === 'lab' ? 'bg-navy-50 text-navy-900 ring-navy-100' : 'bg-gold-50 text-gold-800 ring-gold-200')}>
      {number ?? <Icon className="size-5" />}
    </span>
  )
}

// ------------------------------------------------------------------ the route

function RouteSheet({ route, step, setStep, from, dest, destFloor, onFrom, onTo, onArrive, centre, en }: {
  route: ReturnType<ReturnType<typeof getEngine>['route']>; step: number; setStep: (i: number) => void; from: string; dest: string
  destFloor: { ar: string; en: string }; onFrom: () => void; onTo: () => void; onArrive: () => void; centre: { lat: number; lng: number } | null; en: boolean
}) {
  const { t } = useTranslation()
  const list = useRef<HTMLOListElement>(null)
  useEffect(() => { list.current?.querySelector<HTMLElement>(`[data-step="${step}"]`)?.scrollIntoView({ block: 'nearest', behavior: 'smooth' }) }, [step])
  if (!route) return <div className="p-6 text-center text-sm text-slate-500">{t('wayfinding.noRoute')}</div>
  const last = step === route.steps.length - 1
  const minutes = Math.max(1, Math.round(route.seconds / 60))
  const conn = route.connector

  return (
    <div className="flex min-h-0 flex-1 flex-col">
      <div className="px-5 pt-4">
        <div className="relative rounded-2xl bg-ivory p-1 ring-1 ring-navy-100">
          <button type="button" onClick={onFrom} className="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-start transition hover:bg-white">
            <span className="grid size-6 place-items-center"><span className="size-3 rounded-full bg-navy-700 ring-4 ring-navy-100" /></span>
            <span className="min-w-0 flex-1"><span className="block text-[10px] font-semibold text-slate-500">{t('wayfinding.from')}</span><span className="block truncate text-sm font-bold text-navy-900">{from}</span></span>
            <ArrowDownUp className="size-4 text-slate-300" />
          </button>
          <span className="ms-6 block h-3 w-px border-s-2 border-dotted border-navy-200" />
          <button type="button" onClick={onTo} className="flex w-full items-center gap-3 rounded-xl px-3 py-2 text-start transition hover:bg-white">
            <span className="grid size-6 place-items-center text-gold-600"><MapPin className="size-5" /></span>
            <span className="min-w-0 flex-1"><span className="block text-[10px] font-semibold text-slate-500">{t('wayfinding.to')}</span><span className="block truncate text-sm font-bold text-navy-900">{dest}</span></span>
            <X className="size-4 text-slate-300" />
          </button>
        </div>
        <div className="mt-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
          <span className="text-2xl font-extrabold text-navy-900">{t('wayfinding.minutes', { n: minutes })}</span>
          <span className="flex items-center gap-1 text-slate-500"><Footprints className="size-4" />{t('wayfinding.metres', { n: Math.round(route.metres) })}</span>
          <span className="text-slate-500">{en ? destFloor.en : destFloor.ar}{conn ? ` · ${t(`wayfinding.via_${conn.kind}`, { name: en ? conn.en : conn.ar })}` : ''}</span>
        </div>
      </div>

      <ol ref={list} className="mt-3 min-h-0 flex-1 space-y-1 overflow-y-auto px-3 pb-3">
        {route.steps.map((s, i) => (
          <li key={i} data-step={i}>
            <button type="button" onClick={() => setStep(i)} className={clsx('flex w-full items-start gap-3 rounded-2xl px-3 py-2.5 text-start transition', i === step ? 'bg-navy-900 text-white shadow-glass' : i < step ? 'text-slate-400 hover:bg-ivory' : 'text-navy-900 hover:bg-gold-50')}>
              <span className={clsx('grid size-9 shrink-0 place-items-center rounded-xl', i === step ? 'bg-white/10 text-gold-300' : 'bg-ivory text-navy-800')}><StepIcon s={s} /></span>
              <span className="pt-1.5 text-sm font-semibold leading-6">{stepText(s, t, en, dest, from)}</span>
            </button>
          </li>
        ))}
      </ol>

      <div className="flex items-center gap-2 border-t border-navy-50 p-3">
        <button type="button" disabled={step === 0} onClick={() => setStep(step - 1)} className="grid size-12 place-items-center rounded-2xl bg-ivory text-navy-900 ring-1 ring-navy-100 transition hover:ring-gold-400 disabled:opacity-40" aria-label={t('wayfinding.prev')}>
          {en ? <ArrowLeft className="size-5" /> : <ArrowRight className="size-5" />}
        </button>
        {last
          ? <button type="button" onClick={onArrive} className="flex h-12 flex-1 items-center justify-center gap-2 rounded-2xl bg-emerald-600 font-bold text-white shadow-lg transition hover:bg-emerald-700"><Check className="size-5" />{t('wayfinding.arrived')}</button>
          : <button type="button" onClick={() => setStep(step + 1)} className="flex h-12 flex-1 items-center justify-center gap-2 rounded-2xl bg-navy-900 font-bold text-white shadow-glass transition hover:bg-navy-800">{t('wayfinding.next')}{en ? <ArrowRight className="size-5" /> : <ArrowLeft className="size-5" />}</button>}
        {centre && <a href={directionsUrl(centre.lat, centre.lng)} target="_blank" rel="noreferrer" className="grid size-12 place-items-center rounded-2xl bg-ivory text-navy-900 ring-1 ring-navy-100 transition hover:ring-gold-400" title={t('wayfinding.toCentre')} aria-label={t('wayfinding.toCentre')}><Car className="size-5" /></a>}
      </div>
    </div>
  )
}

function StepIcon({ s }: { s: Step }) {
  const cls = 'size-5'
  switch (s.kind) {
    case 'start': return <Navigation className={cls} />
    case 'arrive': return <MapPin className={cls} />
    case 'left': case 'slight-left': return <CornerUpLeft className={cls} />
    case 'right': case 'slight-right': return <CornerUpRight className={cls} />
    case 'u-turn': return <Undo2 className={cls} />
    case 'stairs': return <svg viewBox="0 0 24 24" className={cls} fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><path d="M3 20h5v-5h5v-5h5V5h3" /></svg>
    case 'lift': return <svg viewBox="0 0 24 24" className={cls} fill="none" stroke="currentColor" strokeWidth={2} strokeLinecap="round" strokeLinejoin="round"><rect x="4" y="3" width="16" height="18" rx="3" /><path d="M9 10l3-3 3 3M9 14l3 3 3-3" /></svg>
    default: return <ArrowUp className={cls} />
  }
}

function Arrived({ dest, onAgain }: { dest: string; onAgain: () => void }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-col items-center gap-3 px-6 py-8 text-center">
      <span className="grid size-16 place-items-center rounded-full bg-emerald-50 text-emerald-600 ring-8 ring-emerald-50/60"><Check className="size-8" /></span>
      <h2 className="text-xl font-bold text-navy-900">{t('wayfinding.welcome', { dest })}</h2>
      <p className="max-w-xs text-sm text-slate-500">{t('wayfinding.welcomeHint')}</p>
      <button type="button" onClick={onAgain} className="mt-2 rounded-2xl bg-navy-900 px-5 py-3 text-sm font-bold text-white shadow-glass hover:bg-navy-800">{t('wayfinding.another')}</button>
    </div>
  )
}

// ------------------------------------------------------------------ outside the centre

function Outdoor({ dest, en, onBack }: { dest: Destination; en: boolean; onBack: () => void }) {
  const { t } = useTranslation()
  const [here, setHere] = useState<{ lat: number; lng: number } | null>(null)
  const [error, setError] = useState(false)
  const target = dest.room?.latitude && dest.room.longitude ? { lat: dest.room.latitude, lng: dest.room.longitude } : null
  const locate = () => navigator.geolocation?.getCurrentPosition((p) => setHere({ lat: p.coords.latitude, lng: p.coords.longitude }), () => setError(true), { enableHighAccuracy: true, timeout: 12000 })
  const km = here && target ? distanceTo(here, target) / 1000 : null
  return (
    <div className="space-y-4 p-5">
      <button type="button" onClick={onBack} className="text-xs font-bold text-link hover:underline">{t('wayfinding.change')}</button>
      <div className="flex items-center gap-3"><PlaceTile d={dest} /><div><h2 className="text-lg font-bold text-navy-900">{en ? dest.title.en : dest.title.ar}</h2><p className="text-xs text-slate-500">{t('wayfinding.outsideHint')}</p></div></div>
      {target ? (
        <>
          {km !== null && <p className="rounded-2xl bg-ivory p-3 text-sm font-semibold text-navy-900">{t('wayfinding.away', { km: km < 1 ? km.toFixed(2) : km.toFixed(1) })}</p>}
          {error && <p className="text-xs text-amber-700">{t('wayfinding.noGps')}</p>}
          <div className="flex gap-2">
            <a href={directionsUrl(target.lat, target.lng)} target="_blank" rel="noreferrer" className="flex h-12 flex-1 items-center justify-center gap-2 rounded-2xl bg-navy-900 font-bold text-white shadow-glass hover:bg-navy-800"><Car className="size-5" />{t('wayfinding.directions')}</a>
            {!here && <button type="button" onClick={locate} className="grid size-12 place-items-center rounded-2xl bg-ivory text-navy-900 ring-1 ring-navy-100 hover:ring-gold-400" aria-label={t('wayfinding.howFar')} title={t('wayfinding.howFar')}><LocateFixed className="size-5" /></button>}
          </div>
        </>
      ) : <p className="text-sm text-slate-500">{t('wayfinding.noLocation')}</p>}
    </div>
  )
}

// ------------------------------------------------------------------ where am I

function OriginPicker({ data, destinations, en, onClose, onPick, onTap, onScan }: { data: ReturnType<typeof useWayfinding>['data']; destinations: Destination[]; en: boolean; onClose: () => void; onPick: (key: string) => void; onTap: () => void; onScan: () => void }) {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const places = destinations.filter((d) => d.place && (!q || `${d.title.ar} ${d.title.en}`.toLowerCase().includes(q.toLowerCase()))).slice(0, 40)
  return (
    <Sheet title={t('wayfinding.whereAreYou')} onClose={onClose}>
      <div className="grid grid-cols-2 gap-2">
        <BigChoice icon={QrCode} title={t('wayfinding.scanSign')} hint={t('wayfinding.scanHint')} onClick={onScan} />
        <BigChoice icon={Hand} title={t('wayfinding.tapMap')} hint={t('wayfinding.tapHint')} onClick={onTap} />
      </div>
      <Group title={t('wayfinding.entrances')}>
        {[...data.entrances, ...data.signs].map((e) => <Row key={e.id} icon={DoorOpen} title={en ? e.en || e.ar : e.ar} sub={floorName(e.floor, en)} onClick={() => onPick(e.id)} />)}
      </Group>
      <Group title={t('wayfinding.nearPlace')}>
        <input className="input mb-2 !rounded-xl" placeholder={t('wayfinding.search')} value={q} onChange={(e) => setQ(e.target.value)} />
        {places.map((d) => <Row key={d.key} icon={MapPin} title={en ? d.title.en : d.title.ar} sub={floorName(d.floor, en)} onClick={() => onPick(d.place!.id)} />)}
      </Group>
    </Sheet>
  )
}

function SignScanner({ onClose, onFound }: { onClose: () => void; onFound: (key: string) => void }) {
  const { t } = useTranslation()
  const video = useRef<HTMLVideoElement>(null)
  const Detector = (window as unknown as { BarcodeDetector?: new (o: { formats: string[] }) => { detect: (v: HTMLVideoElement) => Promise<{ rawValue: string }[]> } }).BarcodeDetector
  const supported = !!Detector && !!navigator.mediaDevices?.getUserMedia
  const [state, setState] = useState<'starting' | 'on' | 'unsupported' | 'denied'>(supported ? 'starting' : 'unsupported')
  useEffect(() => {
    if (!Detector || !supported) return
    let stream: MediaStream | null = null
    let raf = 0
    let stop = false
    const detector = new Detector({ formats: ['qr_code'] })
    navigator.mediaDevices.getUserMedia({ video: { facingMode: 'environment' } }).then(async (s) => {
      if (stop) { s.getTracks().forEach((x) => x.stop()); return }
      stream = s
      if (!video.current) return
      video.current.srcObject = s
      await video.current.play()
      setState('on')
      const tick = async () => {
        if (stop || !video.current) return
        try {
          for (const code of await detector.detect(video.current)) {
            const key = signKey(code.rawValue)
            if (key) { onFound(key); return }
          }
        } catch { /* a frame that could not be read */ }
        raf = requestAnimationFrame(() => void tick())
      }
      void tick()
    }).catch(() => setState('denied'))
    return () => { stop = true; cancelAnimationFrame(raf); stream?.getTracks().forEach((x) => x.stop()) }
  }, [onFound, Detector, supported])
  return (
    <Sheet title={t('wayfinding.scanSign')} onClose={onClose}>
      <div className="relative aspect-square overflow-hidden rounded-3xl bg-navy-950">
        <video ref={video} className="size-full object-cover" playsInline muted />
        <div className="pointer-events-none absolute inset-10 rounded-3xl border-2 border-gold-300/80 shadow-[0_0_0_999px_rgba(0,0,0,.35)]" />
        {state !== 'on' && <p className="absolute inset-0 grid place-items-center p-8 text-center text-sm text-white/85">{t(`wayfinding.scan_${state}`)}</p>}
      </div>
    </Sheet>
  )
}

function Sheet({ title, onClose, children }: { title: string; onClose: () => void; children: ReactNode }) {
  const { t } = useTranslation()
  return (
    <div className="fixed inset-0 z-40 flex items-end justify-center bg-navy-950/40 backdrop-blur-sm sm:items-center" onClick={onClose}>
      <div className="max-h-[86dvh] w-full max-w-lg overflow-y-auto rounded-t-[28px] bg-white p-5 shadow-2xl sm:rounded-[28px]" onClick={(e) => e.stopPropagation()} role="dialog" aria-modal aria-label={title}>
        <div className="mb-4 flex items-center justify-between"><h2 className="text-lg font-bold text-navy-900">{title}</h2><button type="button" onClick={onClose} className="rounded-xl p-2 text-slate-400 hover:bg-ivory" aria-label={t('common.close', { defaultValue: 'Close' })}><X className="size-5" /></button></div>
        <div className="space-y-4">{children}</div>
      </div>
    </div>
  )
}

const BigChoice = ({ icon: Icon, title, hint, onClick }: { icon: ComponentType<{ className?: string }>; title: string; hint: string; onClick: () => void }) => (
  <button type="button" onClick={onClick} className="flex flex-col items-start gap-2 rounded-2xl bg-gradient-to-br from-navy-900 to-navy-800 p-4 text-start text-white shadow-glass transition hover:-translate-y-0.5">
    <Icon className="size-6 text-gold-300" /><span className="text-sm font-bold">{title}</span><span className="text-[11px] leading-4 text-white/70">{hint}</span>
  </button>
)
const Group = ({ title, children }: { title: string; children: ReactNode }) => <div><div className="mb-1.5 text-xs font-bold text-slate-500">{title}</div><div className="space-y-1">{children}</div></div>
const Row = ({ icon: Icon, title, sub, onClick }: { icon: ComponentType<{ className?: string }>; title: string; sub: string; onClick: () => void }) => (
  <button type="button" onClick={onClick} className="flex w-full items-center gap-3 rounded-xl px-2 py-2 text-start transition hover:bg-gold-50">
    <span className="grid size-9 place-items-center rounded-xl bg-ivory text-navy-800"><Icon className="size-4" /></span>
    <span className="min-w-0 flex-1"><span className="block truncate text-sm font-bold text-navy-900">{title}</span><span className="block text-[11px] text-slate-500">{sub}</span></span>
  </button>
)
