import 'leaflet/dist/leaflet.css'
import clsx from 'clsx'
import L from 'leaflet'
import { Building2, Check, Copy, Crosshair, Globe, GraduationCap, Mail, MapPin, Navigation, Phone, Search, Star, X } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { CircleMarker, MapContainer, TileLayer, Tooltip, ZoomControl, useMap } from 'react-leaflet'
import { Badge, Spinner } from '@/components/ui'

export type MapSchool = {
  id: string; code: string; name_ar: string; name_en: string; type: string; stage: string; gender: string | null; region: string; district: string | null
  lat: number; lng: number; phone: string | null; email: string | null; address: string | null; website: string | null; curriculum: string | null
  partner: boolean; staff: number; source: string | null
}
export type SchoolsMapData = { schools: MapSchool[]; synced_at: string | null }

/** Marker colour by where the record comes from. */
export const KIND_COLOR: Record<string, string> = { moe_gov: '#1e3a5f', moe_private: '#c9a227', moe_special: '#0f9d78', manual: '#8b5cf6' }
const kindOf = (s: MapSchool) => (s.source && KIND_COLOR[s.source] ? s.source : 'manual')

const TILES = {
  street: { url: 'https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', attribution: '&copy; OpenStreetMap contributors' },
  satellite: { url: 'https://server.arcgisonline.com/ArcGIS/rest/services/World_Imagery/MapServer/tile/{z}/{y}/{x}', attribution: 'Tiles &copy; Esri' },
}

/** Moves the view: to everything shown when the filters change, or to the picked school. */
function View({ schools, focus, fitKey }: { schools: MapSchool[]; focus: MapSchool | null; fitKey: string }) {
  const map = useMap()
  const last = useRef('')
  useEffect(() => {
    if (last.current === fitKey || schools.length === 0) return
    last.current = fitKey
    map.fitBounds(L.latLngBounds(schools.map((s) => [s.lat, s.lng] as [number, number])), { padding: [40, 40], maxZoom: 13, animate: true })
  }, [fitKey, schools, map])
  useEffect(() => { if (focus) map.flyTo([focus.lat, focus.lng], Math.max(map.getZoom(), 15), { duration: 0.8 }) }, [focus, map])
  useEffect(() => { setTimeout(() => map.invalidateSize(), 50) }, [map])
  return null
}

/** All schools in Qatar on a map: filter, search, click a school for its details and directions. */
export default function SchoolsMap({ data, loading }: { data?: SchoolsMapData; loading: boolean }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const [q, setQ] = useState('')
  const [region, setRegion] = useState('')
  const [kind, setKind] = useState('')
  const [stage, setStage] = useState('')
  const [gender, setGender] = useState('')
  const [selected, setSelected] = useState<MapSchool | null>(null)
  const [focus, setFocus] = useState<MapSchool | null>(null)
  const [base, setBase] = useState<'street' | 'satellite'>('street')
  const [copied, setCopied] = useState(false)
  const [open, setOpen] = useState(false)
  const [fitTick, setFitTick] = useState(0)

  const name = (s: MapSchool) => (ar ? s.name_ar : s.name_en) || s.name_ar
  const all = data?.schools ?? []
  const shown = useMemo(() => {
    const needle = q.trim().toLowerCase()
    return all.filter((s) => (!region || s.region === region) && (!kind || kindOf(s) === kind) && (!stage || s.stage === stage) && (!gender || s.gender === gender)
      && (!needle || `${s.name_ar} ${s.name_en} ${s.district ?? ''} ${s.code}`.toLowerCase().includes(needle)))
  }, [all, q, region, kind, stage, gender])
  const suggestions = useMemo(() => (q.trim().length >= 2 && open ? shown.slice(0, 7) : []), [shown, q, open])
  const counts = useMemo(() => Object.keys(KIND_COLOR).map((k) => ({ k, n: shown.filter((s) => kindOf(s) === k).length })).filter((c) => c.n > 0), [shown])
  const fitKey = `${region}|${kind}|${stage}|${gender}|${all.length}|${fitTick}`
  const filtered = Boolean(q || region || kind || stage || gender)

  const pick = (s: MapSchool) => { setSelected(s); setFocus(s); setOpen(false) }
  const copy = (s: MapSchool) => { void navigator.clipboard?.writeText(`${s.lat}, ${s.lng}`); setCopied(true); setTimeout(() => setCopied(false), 1500) }

  if (loading || !data) return <div className="grid h-[32rem] place-items-center"><Spinner /></div>

  return (
    <div className="space-y-4">
      {/* Filters */}
      <div className="flex flex-wrap items-center gap-3">
        <div className="relative min-w-64 flex-1">
          <Search className="pointer-events-none absolute start-3 top-3 size-4 text-slate-400" />
          <input className="input !ps-9" value={q} onChange={(e) => { setQ(e.target.value); setOpen(true) }} onFocus={() => setOpen(true)} onBlur={() => setTimeout(() => setOpen(false), 150)} placeholder={t('schoolsMap.searchPlaceholder')} aria-label={t('schoolsMap.searchPlaceholder')} />
          {suggestions.length > 0 && (
            <ul className="absolute inset-x-0 top-full z-[1000] mt-1 overflow-hidden rounded-2xl border border-navy-100 bg-white shadow-glass">
              {suggestions.map((s) => (
                <li key={s.id}><button type="button" onMouseDown={() => pick(s)} className="flex w-full items-center gap-3 px-4 py-2.5 text-start text-sm hover:bg-ivory">
                  <span className="size-2.5 shrink-0 rounded-full" style={{ background: KIND_COLOR[kindOf(s)] }} />
                  <span className="min-w-0 flex-1 truncate font-semibold text-navy-900">{name(s)}</span>
                  <span className="text-xs text-slate-400">{t(`regions.${s.region}`)}</span>
                </button></li>
              ))}
            </ul>
          )}
        </div>
        <select className="input w-auto" value={region} onChange={(e) => setRegion(e.target.value)} aria-label={t('admin.schools.region')}><option value="">{t('admin.schools.region')}</option>{['doha', 'al_rayyan', 'al_wakrah', 'al_khor', 'al_shamal', 'umm_salal', 'al_daayen', 'al_shahaniya'].map((r) => <option key={r} value={r}>{t(`regions.${r}`)}</option>)}</select>
        <select className="input w-auto" value={kind} onChange={(e) => setKind(e.target.value)} aria-label={t('admin.schools.type')}><option value="">{t('admin.schools.type')}</option>{Object.keys(KIND_COLOR).map((k) => <option key={k} value={k}>{t(`schoolsMap.kinds.${k}`)}</option>)}</select>
        <select className="input w-auto" value={stage} onChange={(e) => setStage(e.target.value)} aria-label={t('admin.schools.stage')}><option value="">{t('admin.schools.stage')}</option>{['kindergarten', 'primary', 'preparatory', 'secondary', 'multi'].map((s) => <option key={s} value={s}>{t(`stages.${s}`)}</option>)}</select>
        <select className="input w-auto" value={gender} onChange={(e) => setGender(e.target.value)} aria-label={t('schoolsMap.genderLabel')}><option value="">{t('schoolsMap.genderLabel')}</option>{['boys', 'girls', 'mixed'].map((g) => <option key={g} value={g}>{t(`schoolsMap.gender.${g}`)}</option>)}</select>
        {filtered && <button type="button" className="text-sm font-bold text-gold-700 hover:underline" onClick={() => { setQ(''); setRegion(''); setKind(''); setStage(''); setGender('') }}>{t('schoolsMap.reset')}</button>}
      </div>

      {/* Map */}
      <div className="relative h-[34rem] overflow-hidden rounded-3xl border border-navy-100 shadow-glass lg:h-[40rem]">
        <MapContainer center={[25.3, 51.2]} zoom={9} minZoom={7} maxZoom={19} preferCanvas scrollWheelZoom zoomControl={false} className="h-full w-full" attributionControl={false}>
          <ZoomControl position={ar ? 'bottomright' : 'bottomleft'} />
          <TileLayer key={base} url={TILES[base].url} attribution={TILES[base].attribution} />
          <View schools={shown} focus={focus} fitKey={fitKey} />
          {shown.map((s) => {
            const on = selected?.id === s.id
            return (
              <CircleMarker key={s.id} center={[s.lat, s.lng]} radius={on ? 11 : s.partner ? 8 : 6} eventHandlers={{ click: () => setSelected(s) }}
                pathOptions={{ color: on ? '#ffffff' : s.partner ? '#fbbf24' : '#ffffff', weight: on ? 3 : 1.5, fillColor: KIND_COLOR[kindOf(s)], fillOpacity: on ? 1 : 0.88 }}>
                <Tooltip direction="top" offset={[0, -6]}>{name(s)}</Tooltip>
              </CircleMarker>
            )
          })}
        </MapContainer>

        {/* Legend + count */}
        <div className="pointer-events-none absolute start-3 top-3 z-[500] flex max-w-[calc(100%-1.5rem)] flex-wrap gap-2">
          <span className="pointer-events-auto rounded-full bg-white/95 px-3.5 py-1.5 text-sm font-extrabold text-navy-900 shadow ring-1 ring-navy-100">{t('schoolsMap.total', { count: shown.length })}</span>
          {counts.map((c) => (
            <button key={c.k} type="button" onClick={() => setKind(kind === c.k ? '' : c.k)} className={clsx('pointer-events-auto inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-bold shadow ring-1 transition', kind === c.k ? 'bg-navy-900 text-white ring-navy-900' : 'bg-white/95 text-navy-800 ring-navy-100 hover:ring-gold-400')}>
              <span className="size-2.5 rounded-full" style={{ background: KIND_COLOR[c.k] }} />{t(`schoolsMap.kinds.${c.k}`)} <span className="tabular-nums opacity-70">{c.n}</span>
            </button>
          ))}
        </div>
        <div className="absolute end-3 top-3 z-[500] flex flex-col gap-2">
          <div role="tablist" className="inline-flex overflow-hidden rounded-xl bg-white/95 text-xs font-bold shadow ring-1 ring-navy-100">
            {(['street', 'satellite'] as const).map((b) => <button key={b} type="button" role="tab" aria-selected={base === b} onClick={() => setBase(b)} className={clsx('px-3 py-2 transition', base === b ? 'bg-navy-900 text-white' : 'text-slate-600 hover:text-navy-900')}>{t(`schoolsMap.basemap.${b}`)}</button>)}
          </div>
          <button type="button" onClick={() => setFitTick((n) => n + 1)} className="inline-flex items-center justify-center gap-1.5 rounded-xl bg-white/95 px-3 py-2 text-xs font-bold text-navy-800 shadow ring-1 ring-navy-100 hover:ring-gold-400"><Crosshair className="size-3.5" />{t('schoolsMap.fit')}</button>
        </div>
        {shown.length === 0 && <div className="pointer-events-none absolute inset-0 z-[400] grid place-items-center bg-white/60 text-sm font-semibold text-slate-600 backdrop-blur-[1px]">{t('schoolsMap.noResult')}</div>}

        {/* Details */}
        {selected && (
          <aside className="absolute inset-x-3 bottom-3 z-[600] max-h-[70%] overflow-y-auto rounded-3xl bg-white p-5 shadow-glass ring-1 ring-navy-100 sm:inset-x-auto sm:bottom-3 sm:end-3 sm:top-24 sm:max-h-none sm:w-80">
            <button type="button" onClick={() => setSelected(null)} aria-label={t('schoolsMap.details.close')} className="absolute end-3 top-3 grid size-8 place-items-center rounded-full text-slate-400 hover:bg-ivory hover:text-navy-900"><X className="size-4" /></button>
            <div className="flex items-start gap-3 pe-8">
              <span className="grid size-11 shrink-0 place-items-center rounded-2xl text-white" style={{ background: KIND_COLOR[kindOf(selected)] }}><Building2 className="size-5" /></span>
              <div className="min-w-0">
                <h3 className="font-extrabold leading-snug text-navy-900">{name(selected)}</h3>
                <p className="mt-0.5 text-xs text-slate-500" dir="auto">{ar ? selected.name_en : selected.name_ar}</p>
              </div>
            </div>
            <div className="mt-3 flex flex-wrap gap-1.5">
              <Badge color="navy">{t(`schoolsMap.kinds.${kindOf(selected)}`)}</Badge>
              <Badge color="gold">{t(`stages.${selected.stage}`)}</Badge>
              {selected.gender && <Badge color="navy">{t(`schoolsMap.gender.${selected.gender}`)}</Badge>}
              {selected.partner && <Badge color="gold"><Star className="me-1 inline size-3 fill-current" />{t('schoolsMap.details.partner')}</Badge>}
            </div>
            <dl className="mt-4 space-y-2.5 text-sm">
              <Row icon={MapPin} label={t('schoolsMap.details.address')}>{[t(`regions.${selected.region}`), selected.district].filter(Boolean).join(' · ')}{selected.address && selected.address !== selected.district ? <div className="text-xs text-slate-500">{selected.address}</div> : null}</Row>
              {selected.curriculum && <Row icon={GraduationCap} label={t('schoolsMap.details.curriculum')}>{selected.curriculum}</Row>}
              {selected.phone && <Row icon={Phone} label={t('schoolsMap.details.phone')}><a href={`tel:${selected.phone}`} dir="ltr" className="font-semibold text-navy-900 hover:underline">{selected.phone}</a></Row>}
              {selected.email && <Row icon={Mail} label={t('schoolsMap.details.email')}><a href={`mailto:${selected.email}`} dir="ltr" className="break-all font-semibold text-navy-900 hover:underline">{selected.email}</a></Row>}
              {selected.website && <Row icon={Globe} label={t('schoolsMap.details.website')}><a href={selected.website.startsWith('http') ? selected.website : `https://${selected.website}`} target="_blank" rel="noreferrer" dir="ltr" className="break-all font-semibold text-navy-900 hover:underline">{selected.website}</a></Row>}
            </dl>
            {selected.staff > 0 && <div className="mt-4 rounded-2xl bg-gold-100/60 px-4 py-2.5 text-sm font-bold text-navy-900">{t('schoolsMap.details.staff')}: {selected.staff}</div>}
            <div className="mt-4 flex gap-2">
              <a href={`https://www.google.com/maps/dir/?api=1&destination=${selected.lat},${selected.lng}`} target="_blank" rel="noreferrer" className="inline-flex flex-1 items-center justify-center gap-2 rounded-xl bg-navy-900 px-4 py-2.5 text-sm font-bold text-white hover:bg-navy-800"><Navigation className="size-4" />{t('schoolsMap.details.directions')}</a>
              <button type="button" onClick={() => copy(selected)} className="inline-flex items-center gap-2 rounded-xl border border-navy-100 px-3 py-2.5 text-sm font-bold text-navy-800 hover:border-gold-400" aria-label={t('schoolsMap.details.copy')} title={t('schoolsMap.details.copy')}>{copied ? <Check className="size-4 text-emerald-600" /> : <Copy className="size-4" />}</button>
            </div>
            <p className="mt-3 text-center text-[11px] text-slate-400" dir="ltr">{selected.code}</p>
          </aside>
        )}
      </div>
    </div>
  )
}

function Row({ icon: Icon, label, children }: { icon: typeof MapPin; label: string; children: React.ReactNode }) {
  return <div className="flex gap-3"><Icon className="mt-0.5 size-4 shrink-0 text-gold-600" aria-hidden /><div className="min-w-0"><dt className="sr-only">{label}</dt><dd className="text-navy-900">{children}</dd></div></div>
}
