import { CircleMarker, MapContainer, TileLayer, Tooltip as MapTooltip } from 'react-leaflet'
import { useTranslation } from 'react-i18next'
import { Card, CardTitle, PageHeader, Progress, Spinner } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'

type Region = { region: string; schools: number; participating_schools: number; employees: number; trained: number; coverage: number; gap: number; open_needs: number }
type SchoolPoint = { id: string; name: string; region: string; lat: number | null; lng: number | null; employees: number; participants: number; trained: number; coverage: number; open_needs: number }

/** Sequential single-hue ramp (light → dark navy) for training coverage. */
const RAMP = ['#f1e3e7', '#dcb3c0', '#bd6f88', '#9d3a58', '#5a0e24']
const rampColor = (coverage: number) => RAMP[Math.min(RAMP.length - 1, Math.floor(coverage / 20))]

export default function Geographic() {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: { regions: Region[]; schools: SchoolPoint[]; lowest_coverage: SchoolPoint[] } }>('/admin/analytics/geographic')
  if (isLoading || !data) return <Spinner />
  const d = data.data

  return (
    <>
      <PageHeader title={t('admin.geo.title')} subtitle={t('admin.geo.subtitle')} />
      <div className="grid gap-6 xl:grid-cols-3">
        <Card className="xl:col-span-2" padded={false}>
          <div className="h-[520px] overflow-hidden rounded-2xl">
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
      <Card className="mt-6" padded={false}>
        <DataView id="admin.geo.regions" rows={d.regions} rowKey={(r) => r.region} columns={[
          { key: 'region', header: t('admin.schools.region'), role: 'title', cell: (r) => <span className="font-bold text-navy-900">{t(`regions.${r.region}`)}</span> },
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
    </>
  )
}
