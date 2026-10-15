import 'leaflet/dist/leaflet.css'
import { Building2, MapPin, Search } from 'lucide-react'
import { useMemo, useState } from 'react'
import { CircleMarker, MapContainer, TileLayer, Tooltip as MapTooltip } from 'react-leaflet'
import { useTranslation } from 'react-i18next'
import { Badge, Card, CardTitle, Empty, Modal, PageHeader, Progress, Spinner } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'

type Region = { region: string; schools: number; participating_schools: number; employees: number; trained: number; coverage: number; gap: number; open_needs: number }
type SchoolPoint = {
  id: string; code?: string | null; name: string; region: string; type?: string | null; gender?: string | null; stage?: string | null; district?: string | null
  lat: number | null; lng: number | null; employees: number; participants: number; trained: number; coverage: number; open_needs: number
}
type TypeFilter = 'all' | 'government' | 'private' | 'other'
type GenderFilter = 'all' | 'boys' | 'girls' | 'mixed'

/** Sequential single-hue ramp (light → dark navy) for training coverage. */
const RAMP = ['#f1e3e7', '#dcb3c0', '#bd6f88', '#9d3a58', '#5a0e24']
const rampColor = (coverage: number) => RAMP[Math.min(RAMP.length - 1, Math.floor(coverage / 20))]
const ZONES = ['doha', 'al_rayyan', 'al_wakrah', 'al_khor', 'al_shamal', 'umm_salal', 'al_daayen', 'al_shahaniya']

const typeOf = (s: SchoolPoint): TypeFilter => (s.type === 'government' ? 'government' : s.type === 'private' ? 'private' : 'other')
const genderOf = (s: SchoolPoint): GenderFilter | null => (s.gender === 'boys' || s.gender === 'male' ? 'boys' : s.gender === 'girls' || s.gender === 'female' ? 'girls' : s.gender === 'mixed' ? 'mixed' : null)

export default function Geographic() {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: { regions: Region[]; schools: SchoolPoint[]; lowest_coverage: SchoolPoint[] } }>('/admin/analytics/geographic')
  const [zone, setZone] = useState<string | null>(null)
  if (isLoading || !data) return <Spinner />
  const d = data.data
  const byRegion = new Map(d.regions.map((r) => [r.region, r]))
  const zones = [...ZONES.filter((z) => byRegion.has(z)), ...d.regions.map((r) => r.region).filter((r) => !ZONES.includes(r))]

  return (
    <>
      <PageHeader title={t('admin.geo.title')} subtitle={t('admin.geo.subtitle')} />
      <div className="grid gap-6 xl:grid-cols-3">
        <Card className="xl:col-span-2" padded={false}>
          <div className="relative isolate z-0 h-[520px] overflow-hidden rounded-2xl">
            <MapContainer center={[25.35, 51.3]} zoom={9} scrollWheelZoom className="h-full w-full">
              <TileLayer attribution='&copy; OpenStreetMap contributors' url="https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png" />
              {d.schools.filter((s) => s.lat && s.lng).map((s) => (
                <CircleMarker key={s.id} center={[s.lat!, s.lng!]} radius={8 + Math.sqrt(s.employees) * 1.6} pathOptions={{ color: '#ffffff', weight: 2, fillColor: rampColor(s.coverage), fillOpacity: 0.9 }}>
                  <MapTooltip>
                    <div className="text-xs">
                      <div className="font-bold">{s.name}</div>
                      <div>{t('admin.geo.coverage')}: {fmt.percent(s.coverage, 1)}</div>
                      <div>{t('admin.kpis.participants')}: {s.participants} / {s.employees}</div>
                      <div>{t('admin.geo.openNeeds')}: {s.open_needs}</div>
                    </div>
                  </MapTooltip>
                </CircleMarker>
              ))}
            </MapContainer>
          </div>
          <div className="flex items-center gap-3 p-4 text-xs text-slate-500">
            <span>{t('admin.geo.coverage')}</span>
            {RAMP.map((c, i) => <span key={c} className="flex items-center gap-1"><span className="size-3 rounded-sm" style={{ background: c }} />{i * 20}–{i * 20 + 20}%</span>)}
          </div>
        </Card>
        <div className="space-y-6">
          <Card>
            <CardTitle subtitle={t('admin.geo.zonesHint')}>{t('admin.geo.zones')}</CardTitle>
            <div className="grid grid-cols-2 gap-2">
              {zones.map((z) => {
                const r = byRegion.get(z)!
                return (
                  <button key={z} type="button" onClick={() => setZone(z)}
                    className="group rounded-xl border border-slate-200 bg-white px-3 py-2.5 text-start transition hover:border-gold-400 hover:shadow-sm focus-visible:outline focus-visible:outline-2 focus-visible:outline-gold-400">
                    <div className="flex items-center justify-between gap-2">
                      <span className="font-semibold text-navy-900">{t(`regions.${z}`, { defaultValue: z })}</span>
                      <MapPin className="size-3.5 text-slate-300 group-hover:text-gold-500" />
                    </div>
                    <div className="mt-1 flex items-center justify-between text-xs text-slate-500">
                      <span>{t('admin.geo.schoolsCount', { count: r.schools })}</span>
                      <span className="font-mono">{fmt.percent(r.coverage, 0)}</span>
                    </div>
                    <Progress value={r.coverage} tone="navy" className="mt-1.5" />
                  </button>
                )
              })}
            </div>
          </Card>
          <Card>
            <CardTitle>{t('admin.geo.lowest')}</CardTitle>
            <ul className="space-y-3">
              {d.lowest_coverage.map((s) => (
                <li key={s.id}>
                  <div className="flex justify-between text-sm"><span className="font-semibold text-navy-900">{s.name}</span><span className="text-slate-500">{fmt.percent(s.coverage, 1)}</span></div>
                  <Progress value={s.coverage} tone="navy" className="mt-1" />
                </li>
              ))}
            </ul>
          </Card>
        </div>
      </div>
      <Card className="mt-6" padded={false}>
        <DataView id="admin.geo.regions" rows={d.regions} rowKey={(r) => r.region} columns={[
          { key: 'region', header: t('admin.schools.region'), role: 'title', cell: (r) => <button type="button" className="font-bold text-navy-900 hover:text-gold-700 hover:underline" onClick={() => setZone(r.region)}>{t(`regions.${r.region}`, { defaultValue: r.region })}</button> },
          { key: 'coverage', header: t('admin.geo.coverage'), role: 'badge', cell: (r) => <span className="font-mono text-sm font-bold text-navy-900">{fmt.percent(r.coverage, 1)}</span> },
          { key: 'schools', header: t('admin.menu.schools'), cell: (r) => r.schools },
          { key: 'participation', header: t('admin.analytics.participation'), cell: (r) => `${r.participating_schools} / ${r.schools}` },
          { key: 'employees', header: t('admin.employees.title'), cell: (r) => fmt.number(r.employees) },
          { key: 'trained', header: t('admin.analytics.trained'), cell: (r) => fmt.number(r.trained) },
          { key: 'bar', header: t('admin.geo.coverage'), hideInCards: true, cell: (r) => <div className="w-28"><Progress value={r.coverage} tone="navy" /></div> },
          { key: 'gap', header: t('admin.geo.gap'), cell: (r) => <span className="font-semibold text-danger">{fmt.number(r.gap)}</span> },
          { key: 'needs', header: t('admin.geo.openNeeds'), cell: (r) => fmt.number(r.open_needs) },
        ]} />
      </Card>
      <ZoneSchools zone={zone} schools={d.schools} onClose={() => setZone(null)} />
    </>
  )
}

/** The schools of one zone, filtered by official type (government / private) and gender (boys / girls / mixed). */
function ZoneSchools({ zone, schools, onClose }: { zone: string | null; schools: SchoolPoint[]; onClose: () => void }) {
  const { t } = useTranslation()
  const [type, setType] = useState<TypeFilter>('all')
  const [gender, setGender] = useState<GenderFilter>('all')
  const [q, setQ] = useState('')
  const inZone = useMemo(() => schools.filter((s) => s.region === zone), [schools, zone])
  const rows = useMemo(() => {
    const needle = q.trim().toLowerCase()
    return inZone
      .filter((s) => type === 'all' || typeOf(s) === type)
      .filter((s) => gender === 'all' || genderOf(s) === gender)
      .filter((s) => !needle || s.name.toLowerCase().includes(needle) || (s.code ?? '').toLowerCase().includes(needle))
      .sort((a, b) => a.name.localeCompare(b.name, 'ar'))
  }, [inZone, type, gender, q])
  const count = (pred: (s: SchoolPoint) => boolean) => inZone.filter(pred).length
  const zoneName = zone ? t(`regions.${zone}`, { defaultValue: zone }) : ''
  const chip = (active: boolean) => `rounded-full border px-3 py-1 text-xs font-semibold transition ${active ? 'border-navy-900 bg-navy-900 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-navy-300'}`
  const typeLabel = (s: SchoolPoint) => (s.type ? t(`schoolTypes.${s.type}`, { defaultValue: s.type }) : t('admin.geo.notSet'))
  const genderLabel = (s: SchoolPoint) => { const g = genderOf(s); return g ? t(`admin.geo.gender${g[0].toUpperCase()}${g.slice(1)}`) : t('admin.geo.notSet') }

  return (
    <Modal open={!!zone} onClose={onClose} title={<span className="flex items-center gap-2"><Building2 className="size-5 text-gold-600" />{t('admin.geo.zoneSchools', { zone: zoneName })}<Badge color="gold">{t('admin.geo.schoolsCount', { count: rows.length })}</Badge></span>} wide>
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <div className="flex flex-wrap gap-1.5" role="group" aria-label={t('admin.geo.typeAll')}>
          {([['all', t('admin.geo.typeAll'), () => true], ['government', t('admin.geo.typeGovernment'), (s: SchoolPoint) => typeOf(s) === 'government'], ['private', t('admin.geo.typePrivate'), (s: SchoolPoint) => typeOf(s) === 'private'], ['other', t('admin.geo.typeOther'), (s: SchoolPoint) => typeOf(s) === 'other']] as [TypeFilter, string, (s: SchoolPoint) => boolean][])
            .filter(([k, , pred]) => k !== 'other' || count(pred) > 0)
            .map(([k, label, pred]) => <button key={k} type="button" className={chip(type === k)} onClick={() => setType(k)}>{label} <span className="opacity-70">{count(pred)}</span></button>)}
        </div>
        <span className="hidden h-5 w-px bg-slate-200 sm:block" />
        <div className="flex flex-wrap gap-1.5" role="group" aria-label={t('admin.geo.genderAll')}>
          {([['all', t('admin.geo.genderAll'), () => true], ['boys', t('admin.geo.genderBoys'), (s: SchoolPoint) => genderOf(s) === 'boys'], ['girls', t('admin.geo.genderGirls'), (s: SchoolPoint) => genderOf(s) === 'girls'], ['mixed', t('admin.geo.genderMixed'), (s: SchoolPoint) => genderOf(s) === 'mixed']] as [GenderFilter, string, (s: SchoolPoint) => boolean][])
            .map(([k, label, pred]) => <button key={k} type="button" className={chip(gender === k)} onClick={() => setGender(k)}>{label} <span className="opacity-70">{count(pred)}</span></button>)}
        </div>
      </div>
      <div className="relative mb-3">
        <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
        <input className="input ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('admin.geo.search'))} />
      </div>
      {!rows.length ? <Empty text={String(t('admin.geo.noSchools'))} icon={<Building2 className="size-6" />} /> : (
        <div className="max-h-[60vh] overflow-auto rounded-xl border border-slate-200">
          <table className="w-full text-sm">
            <thead className="sticky top-0 bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr>
              <th className="px-3 py-2 text-start">{t('admin.menu.schools')}</th><th className="px-3 py-2 text-start">{t('admin.schools.type', { defaultValue: 'Type' })}</th>
              <th className="px-3 py-2 text-start">{t('admin.geo.gender')}</th><th className="px-3 py-2 text-start">{t('admin.geo.stage')}</th>
              <th className="px-3 py-2 text-end">{t('admin.geo.staff')}</th><th className="px-3 py-2 text-end">{t('admin.geo.coverage')}</th>
            </tr></thead>
            <tbody className="divide-y divide-slate-100">
              {rows.map((s) => (
                <tr key={s.id} className="hover:bg-slate-50">
                  <td className="px-3 py-2"><div className="font-semibold text-navy-900">{s.name}</div><div className="text-xs text-slate-400">{[s.code, s.district].filter(Boolean).join(' · ')}</div></td>
                  <td className="px-3 py-2"><Badge color={typeOf(s) === 'government' ? 'navy' : typeOf(s) === 'private' ? 'gold' : 'gray'}>{typeLabel(s)}</Badge></td>
                  <td className="px-3 py-2"><Badge color={genderOf(s) === 'boys' ? 'blue' : genderOf(s) === 'girls' ? 'red' : 'gray'}>{genderLabel(s)}</Badge></td>
                  <td className="px-3 py-2 text-slate-600">{s.stage ? t(`stages.${s.stage}`, { defaultValue: s.stage }) : t('admin.geo.notSet')}</td>
                  <td className="px-3 py-2 text-end tabular-nums">{fmt.number(s.employees)}</td>
                  <td className="px-3 py-2 text-end font-mono tabular-nums">{fmt.percent(s.coverage, 0)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
    </Modal>
  )
}
