import { Archive, BarChart3, Building2, ClipboardCheck, ClipboardList, FileSearch, LayoutDashboard, MessagesSquare, Plus, Search, Users, CalendarDays } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import PlcCalendar from './PlcCalendar'
import PlcReports, { type Report } from './PlcReports'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { Badge, Button, Card, CardTitle, Empty, ErrorState, Field, Modal, PageHeader, Spinner, StatCard, Tabs } from '@/components/ui'
import Communities from '@/pages/Communities'
import { useGet, useSend } from '@/hooks/useApi'
import { errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { STATE_TONE, fmtDate, type Catalog, type Community, type Need, type PlcSource, type PlcState, type SchoolRef, type WorkshopSummary } from './plcApi'

type Tab = 'overview' | 'communities' | 'workshops' | 'needs' | 'calendar' | 'reports' | 'spaces'
const TABS: Tab[] = ['overview', 'communities', 'workshops', 'calendar', 'needs', 'reports', 'spaces']

/** The PLC hub: everything about learning communities on one page, with the discussion spaces as its last tab. */
export default function PlcHub() {
  const { t } = useTranslation()
  const [params, setParams] = useSearchParams()
  const tab = (TABS.includes(params.get('tab') as Tab) ? params.get('tab') : 'overview') as Tab
  const setTab = (next: Tab) => setParams((p) => { p.set('tab', next); return p }, { replace: true })
  const catalog = useGet<{ data: Catalog }>('/admin/plc/workshops/catalog')
  const icons: Record<Tab, React.ReactNode> = { overview: <LayoutDashboard className="size-4" />, communities: <Users className="size-4" />, workshops: <ClipboardList className="size-4" />, needs: <FileSearch className="size-4" />, calendar: <CalendarDays className="size-4" />, reports: <BarChart3 className="size-4" />, spaces: <MessagesSquare className="size-4" /> }

  return (
    <>
      <PageHeader title={t('plc.title')} subtitle={t('plc.subtitle')} />
      <Tabs<Tab> value={tab} onChange={setTab} tabs={TABS.map((id) => ({ id, label: <span className="inline-flex items-center gap-1.5">{icons[id]}{t(`plc.tabs.${id}`)}</span> }))} />
      {tab === 'overview' && <Overview catalog={catalog.data?.data} onTab={setTab} />}
      {tab === 'communities' && <CommunitiesTab catalog={catalog.data?.data} />}
      {tab === 'workshops' && <WorkshopsTab catalog={catalog.data?.data} />}
      {tab === 'calendar' && <PlcCalendar />}
      {tab === 'needs' && <NeedsTab catalog={catalog.data?.data} />}
      {tab === 'reports' && <PlcReports states={catalog.data?.data.states} sources={catalog.data?.data.sources} initial={{ school_id: params.get('school_id') ?? '', community_id: params.get('community_id') ?? '' }} />}
      {tab === 'spaces' && <Communities />}
    </>
  )
}

// Overview ---------------------------------------------------------------------------------------------------------

type Dashboard = {
  communities: number; workshops: { total: number; by_state: Record<string, number>; by_source: Record<string, number>; by_scenario: Record<string, number> }
  needs: { total: number; by_status: Record<string, number>; by_source: Record<string, number>; unconverted: number }
  schools_involved: number; my_reviews: WorkshopSummary[]; upcoming: WorkshopSummary[]; rased_integration: boolean
  kpis?: Pick<Report, 'attendance' | 'evaluation' | 'people' | 'sessions' | 'impact'> | null
}

function Overview({ catalog, onTab }: { catalog?: Catalog; onTab: (t: Tab) => void }) {
  const { t, i18n } = useTranslation()
  const res = useGet<{ data: Dashboard }>('/admin/plc/dashboard', undefined, { staleTime: 0 })
  if (res.isLoading) return <Spinner />
  if (res.error || !res.data) return <ErrorState error={res.error} onRetry={() => res.refetch()} />
  const d = res.data.data
  const states = catalog?.states ?? (Object.keys(d.workshops.by_state) as PlcState[])
  return (
    <div className="space-y-6">
      <p className="rounded-2xl border border-gold-200 bg-gold-50/60 px-4 py-3 text-sm text-navy-900">{t('plc.phase')}</p>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        <StatCard label={t('plc.overview.communities')} value={d.communities} icon={<Users className="size-5" />} accent />
        <StatCard label={t('plc.overview.workshops')} value={d.workshops.total} icon={<ClipboardList className="size-5" />} />
        <StatCard label={t('plc.overview.needs')} value={d.needs.total} icon={<FileSearch className="size-5" />} />
        <StatCard label={t('plc.overview.unconverted')} value={d.needs.unconverted} icon={<FileSearch className="size-5" />} />
        <StatCard label={t('plc.overview.schools')} value={d.schools_involved} icon={<Building2 className="size-5" />} />
      </div>
      {d.kpis && (
        <Card>
          <CardTitle action={<Button variant="outline" size="sm" icon={<BarChart3 className="size-3.5" />} onClick={() => onTab('reports')}>{t('plc.reports.openReports')}</Button>}>{t('plc.reports.kpis')}</CardTitle>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label={t('plc.reports.attendance')} value={d.kpis.attendance.rate != null ? `${d.kpis.attendance.rate}%` : '—'} icon={<ClipboardCheck className="size-5" />} hint={t('plc.reports.attendanceHint', { n: d.kpis.attendance.meeting, of: d.kpis.attendance.people })} />
            <StatCard label={t('plc.reports.evaluation')} value={d.kpis.evaluation.workshop.rate != null ? `${d.kpis.evaluation.workshop.rate}%` : '—'} icon={<ClipboardCheck className="size-5" />} hint={t('plc.reports.answersHint', { n: d.kpis.evaluation.workshop.submitted, of: d.kpis.evaluation.workshop.assigned })} />
            <StatCard label={t('plc.reports.people')} value={d.kpis.people.seated} icon={<Users className="size-5" />} hint={t('plc.reports.peopleHint', { cap: d.kpis.people.capacity, done: d.kpis.people.completed, u: d.kpis.people.unique })} />
            <StatCard label={t('plc.reports.sessions')} value={d.kpis.sessions.total} hint={t('plc.reports.sessionsHint', { held: d.kpis.sessions.held, h: d.kpis.sessions.hours })} />
          </div>
        </Card>
      )}
      <div className="grid gap-4 lg:grid-cols-3">
        <Card><CardTitle>{t('plc.overview.byState')}</CardTitle>
          <ul className="space-y-2 text-sm">
            {states.filter((s) => d.workshops.by_state[s]).map((s) => (
              <li key={s} className="flex items-center justify-between gap-3"><Badge color={STATE_TONE[s] ?? 'gray'}>{t(`plc.state.${s}`)}</Badge><b className="tabular-nums">{d.workshops.by_state[s]}</b></li>
            ))}
            {!d.workshops.total && <li className="text-slate-500">{t('plc.workshops.empty')}</li>}
          </ul>
        </Card>
        <Card><CardTitle>{t('plc.overview.bySource')}</CardTitle>
          <ul className="space-y-2 text-sm">
            {(catalog?.sources ?? (Object.keys(d.workshops.by_source) as PlcSource[])).map((s) => (
              <li key={s} className="flex items-center justify-between gap-3"><span>{t(`plc.source.${s}`)}</span><b className="tabular-nums">{d.workshops.by_source[s] ?? 0}</b></li>
            ))}
          </ul>
          {!d.rased_integration && <p className="mt-3 text-xs text-slate-500">{t('plc.overview.rasedOff')}</p>}
        </Card>
        <Card><CardTitle>{t('plc.overview.byScenario')}</CardTitle>
          <ul className="space-y-2 text-sm">
            {(['shared', 'internal'] as const).map((s) => <li key={s} className="flex items-center justify-between gap-3"><span>{t(`plc.scenario.${s}`)}</span><b className="tabular-nums">{d.workshops.by_scenario[s] ?? 0}</b></li>)}
          </ul>
        </Card>
      </div>
      <div className="grid gap-4 lg:grid-cols-2">
        <Card><CardTitle subtitle={d.my_reviews.length ? undefined : t('plc.overview.noReviews')}>{t('plc.overview.myReviews')}</CardTitle>
          <WorkshopList rows={d.my_reviews} />
        </Card>
        <Card><CardTitle subtitle={d.upcoming.length ? undefined : t('plc.overview.noUpcoming')} action={<Button variant="outline" size="sm" onClick={() => onTab('workshops')}>{t('plc.tabs.workshops')}</Button>}>{t('plc.overview.upcoming')}</CardTitle>
          <WorkshopList rows={d.upcoming} />
        </Card>
      </div>
      <p className="text-xs text-slate-400">{i18n.language === 'en' ? 'Figures are counted live inside your scope.' : 'الأرقام محسوبة لحظياً ضمن نطاقك.'}</p>
    </div>
  )
}

function WorkshopList({ rows }: { rows: WorkshopSummary[] }) {
  const { t, i18n } = useTranslation()
  if (!rows.length) return null
  return (
    <ul className="divide-y divide-slate-100">
      {rows.map((w) => (
        <li key={w.id}>
          <Link to={`/admin/communities/workshops/${w.id}`} className="flex items-center justify-between gap-3 py-2.5 hover:bg-slate-50">
            <div className="min-w-0">
              <div className="truncate font-semibold text-navy-900">{i18n.language === 'en' && w.title_en ? w.title_en : w.title_ar}</div>
              <div className="text-xs text-slate-500">{w.host_school?.name ?? t('plc.workshops.noDate')} · {w.start_date ? fmtDate(w.start_date, i18n.language) : t('plc.workshops.noDate')}</div>
            </div>
            <Badge color={STATE_TONE[w.state] ?? 'gray'}>{t(`plc.state.${w.state}`)}</Badge>
          </Link>
        </li>
      ))}
    </ul>
  )
}

// Workshops --------------------------------------------------------------------------------------------------------

function WorkshopsTab({ catalog }: { catalog?: Catalog }) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const [state, setState] = useState('')
  const [scenario, setScenario] = useState('')
  const res = useGet<{ data: WorkshopSummary[]; meta: { total: number } }>('/admin/plc/workshops', { q: q || undefined, state: state || undefined, scenario: scenario || undefined, per_page: 50 }, { staleTime: 0 })
  const canCreate = catalog?.can.manage || catalog?.can.internal
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-52 flex-1">
          <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
          <input className="input ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('plc.workshops.search'))} />
        </div>
        <select className="input w-auto" value={scenario} onChange={(e) => setScenario(e.target.value)}>
          <option value="">{t('plc.workshops.all')}</option>
          {(catalog?.scenarios ?? ['shared', 'internal']).map((s) => <option key={s} value={s}>{t(`plc.scenario.${s}`)}</option>)}
        </select>
        <select className="input w-auto" value={state} onChange={(e) => setState(e.target.value)}>
          <option value="">{t('plc.workshops.all')}</option>
          {(catalog?.states ?? []).map((s) => <option key={s} value={s}>{t(`plc.state.${s}`)}</option>)}
        </select>
        {canCreate && <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => navigate('/admin/communities/workshops/new')}>{t('plc.workshops.create')}</Button>}
      </div>
      {res.isLoading ? <Spinner /> : res.error ? <ErrorState error={res.error} onRetry={() => res.refetch()} /> : !res.data?.data.length ? <Empty text={String(t('plc.workshops.empty'))} icon={<ClipboardList className="size-6" />} /> : (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {res.data.data.map((w) => (
            <Link key={w.id} to={`/admin/communities/workshops/${w.id}`} className="group rounded-2xl border border-slate-200 bg-white p-4 transition hover:border-navy-300 hover:shadow-sm">
              <div className="mb-2 flex items-start justify-between gap-2">
                <Badge color={STATE_TONE[w.state] ?? 'gray'}>{t(`plc.state.${w.state}`)}</Badge>
                <span className="font-mono text-[11px] text-slate-400">{w.code}</span>
              </div>
              <div className="line-clamp-2 font-display text-base font-bold text-navy-900 group-hover:text-navy-700">{i18n.language === 'en' && w.title_en ? w.title_en : w.title_ar}</div>
              <div className="mt-2 flex flex-wrap gap-1.5 text-xs">
                <Badge color="navy">{t(`plc.scenario.${w.scenario}`)}</Badge>
                <Badge color="gray">{t(`plc.source.${w.source}`)}{w.source_ref ? ` · ${w.source_ref}` : ''}</Badge>
                <Badge color="gray">{t(`plc.mode.${w.delivery_mode}`)}</Badge>
              </div>
              <dl className="mt-3 grid grid-cols-2 gap-2 text-xs text-slate-600">
                <div><dt className="text-slate-400">{t('plc.workshops.host')}</dt><dd className="truncate font-semibold">{w.host_school?.name ?? '—'}</dd></div>
                <div><dt className="text-slate-400">{t('plc.workshops.dates')}</dt><dd className="font-semibold">{w.start_date ? fmtDate(w.start_date, i18n.language) : t('plc.workshops.noDate')}</dd></div>
              </dl>
            </Link>
          ))}
        </div>
      )}
    </div>
  )
}

// Needs -----------------------------------------------------------------------------------------------------------

function NeedsTab({ catalog }: { catalog?: Catalog }) {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const [q, setQ] = useState('')
  const [source, setSource] = useState('')
  const [open, setOpen] = useState(false)
  const [converting, setConverting] = useState<Need | null>(null)
  const res = useGet<{ data: Need[]; meta: { total: number } }>('/admin/plc/needs', { q: q || undefined, source: source || undefined, per_page: 50 }, { staleTime: 0 })
  const canWrite = catalog?.can.manage || catalog?.can.internal
  const tone: Record<Need['review_status'], 'gray' | 'amber' | 'green' | 'red'> = { new: 'gray', under_review: 'amber', converted: 'green', declined: 'red' }
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2">
        <div className="relative min-w-52 flex-1">
          <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
          <input className="input ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('plc.needs.search'))} />
        </div>
        <select className="input w-auto" value={source} onChange={(e) => setSource(e.target.value)}>
          <option value="">{t('plc.needs.all')}</option>
          {(catalog?.sources ?? []).map((s) => <option key={s} value={s}>{t(`plc.source.${s}`)}</option>)}
        </select>
        {canWrite && <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t('plc.needs.create')}</Button>}
      </div>
      {!catalog?.rased_integration && <p className="text-xs text-slate-500">{t('plc.overview.rasedOff')}</p>}
      {res.isLoading ? <Spinner /> : res.error ? <ErrorState error={res.error} onRetry={() => res.refetch()} /> : !res.data?.data.length ? <Empty text={String(t('plc.needs.empty'))} icon={<FileSearch className="size-6" />} /> : (
        <div className="overflow-x-auto rounded-2xl border border-slate-200 bg-white">
          <table className="w-full text-sm">
            <thead className="bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr>
              <th className="px-3 py-2 text-start">{t('plc.needs.title')}</th><th className="px-3 py-2 text-start">{t('plc.wizard.source')}</th><th className="px-3 py-2 text-start">{t('plc.needs.reference')}</th>
              <th className="px-3 py-2 text-start">{t('plc.needs.received')}</th><th className="px-3 py-2 text-start">{t('status.pending', { defaultValue: 'Status' })}</th><th className="px-3 py-2 text-start">{t('plc.needs.workshops')}</th><th className="px-3 py-2" />
            </tr></thead>
            <tbody className="divide-y divide-slate-100">
              {res.data.data.map((n) => (
                <tr key={n.id} className="hover:bg-slate-50">
                  <td className="px-3 py-2 font-semibold text-navy-900">{n.title}<div className="text-xs font-normal text-slate-500">{n.domain}</div></td>
                  <td className="px-3 py-2">{t(`plc.source.${n.source}`)}{n.entity_name ? ` · ${n.entity_name}` : ''}</td>
                  <td className="px-3 py-2 font-mono text-xs">{n.reference_no ?? '—'}</td>
                  <td className="px-3 py-2">{n.received_at ? fmtDate(n.received_at, i18n.language) : '—'}</td>
                  <td className="px-3 py-2"><Badge color={tone[n.review_status]}>{t(`plc.needs.status.${n.review_status}`)}</Badge></td>
                  <td className="px-3 py-2 tabular-nums">{n.workshops}</td>
                  <td className="px-3 py-2 text-end">{canWrite && n.review_status !== 'declined' && <Button size="sm" variant="outline" onClick={() => setConverting(n)}>{t('plc.needs.convert')}</Button>}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <NeedForm open={open} onClose={() => setOpen(false)} catalog={catalog} onSaved={() => { setOpen(false); res.refetch() }} />
      <ConvertForm need={converting} onClose={() => setConverting(null)} catalog={catalog} onDone={(id) => { setConverting(null); navigate(`/admin/communities/workshops/${id}`) }} />
    </div>
  )
}

function SchoolPicker({ value, onChange, placeholder, single }: { value: SchoolRef[]; onChange: (next: SchoolRef[]) => void; placeholder: string; single?: boolean }) {
  const { i18n } = useTranslation()
  const [q, setQ] = useState('')
  const res = useGet<{ data: SchoolRef[] }>(q.length >= 2 ? '/admin/plc/lookup/schools' : null, { q })
  const options = (res.data?.data ?? []).filter((s) => !value.some((v) => v.id === s.id))
  return (
    <div className="space-y-2">
      <div className="flex flex-wrap gap-1.5">
        {value.map((s) => (
          <span key={s.id} className="inline-flex items-center gap-1 rounded-full bg-navy-100 px-2.5 py-1 text-xs font-semibold text-navy-900">
            {i18n.language === 'en' && s.name_en ? s.name_en : s.name}
            <button type="button" className="text-navy-500 hover:text-red-600" onClick={() => onChange(value.filter((v) => v.id !== s.id))} aria-label="remove">×</button>
          </span>
        ))}
      </div>
      {(!single || !value.length) && (
        <div className="relative">
          <input className="input" value={q} onChange={(e) => setQ(e.target.value)} placeholder={placeholder} />
          {q.length >= 2 && options.length > 0 && (
            <ul className="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
              {options.map((s) => (
                <li key={s.id}><button type="button" className="flex w-full items-center justify-between px-3 py-1.5 text-start text-sm hover:bg-slate-50" onClick={() => { onChange(single ? [s] : [...value, s]); setQ('') }}>
                  <span>{i18n.language === 'en' && s.name_en ? s.name_en : s.name}</span><span className="text-xs text-slate-400">{s.gender ?? ''} {s.region ?? ''}</span>
                </button></li>
              ))}
            </ul>
          )}
        </div>
      )}
    </div>
  )
}

function NeedForm({ open, onClose, catalog, onSaved }: { open: boolean; onClose: () => void; catalog?: Catalog; onSaved: () => void }) {
  const { t } = useTranslation()
  const [f, setF] = useState({ source: 'rased' as PlcSource, reference_no: '', title: '', description: '', domain: '', target_audience: '', received_at: '', source_url: '', notes: '', entity_name: '', proposer_name: '', justification: '', expected_outcomes: '' })
  const [schools, setSchools] = useState<SchoolRef[]>([])
  const set = (k: keyof typeof f, v: string) => setF((p) => ({ ...p, [k]: v }))
  const save = useSend<Record<string, unknown>, { data: Need }>('post', '/admin/plc/needs', ['/admin/plc'])
  const submit = async () => {
    try {
      const body: Record<string, unknown> = { ...f, school_ids: schools.map((s) => s.id) }
      Object.keys(body).forEach((k) => { if (body[k] === '') body[k] = null })
      await save.mutateAsync(body)
      toast(String(t('plc.needs.saved')))
      onSaved()
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <Modal open={open} onClose={onClose} title={t('plc.needs.create')} wide>
      <div className="grid gap-4 md:grid-cols-2">
        <Field label={t('plc.wizard.source')}>
          <select className="input" value={f.source} onChange={(e) => set('source', e.target.value)}>{(catalog?.sources ?? ['rased', 'centre', 'entity', 'school']).map((s) => <option key={s} value={s}>{t(`plc.source.${s}`)}</option>)}</select>
        </Field>
        <Field label={t('plc.needs.reference')} hint={f.source === 'rased' ? t('plc.needs.rasedHint') : undefined}><input className="input" dir="ltr" value={f.reference_no} onChange={(e) => set('reference_no', e.target.value)} maxLength={64} /></Field>
        <Field label={t('plc.needs.title')} className="md:col-span-2"><input className="input" value={f.title} onChange={(e) => set('title', e.target.value)} maxLength={300} /></Field>
        <Field label={t('plc.needs.description')} className="md:col-span-2"><textarea className="input min-h-20" value={f.description} onChange={(e) => set('description', e.target.value)} maxLength={5000} /></Field>
        <Field label={t('plc.needs.domain')}><input className="input" value={f.domain} onChange={(e) => set('domain', e.target.value)} maxLength={64} /></Field>
        <Field label={t('plc.needs.audience')}><input className="input" value={f.target_audience} onChange={(e) => set('target_audience', e.target.value)} maxLength={200} /></Field>
        <Field label={t('plc.needs.received')}><input type="date" className="input" value={f.received_at} onChange={(e) => set('received_at', e.target.value)} /></Field>
        <Field label={t('plc.needs.url')}><input className="input" dir="ltr" value={f.source_url} onChange={(e) => set('source_url', e.target.value)} maxLength={500} /></Field>
        {f.source === 'entity' && <>
          <Field label={t('plc.needs.entity')}><input className="input" value={f.entity_name} onChange={(e) => set('entity_name', e.target.value)} maxLength={200} /></Field>
          <Field label={t('plc.needs.proposer')}><input className="input" value={f.proposer_name} onChange={(e) => set('proposer_name', e.target.value)} maxLength={200} /></Field>
          <Field label={t('plc.needs.justification')} className="md:col-span-2"><textarea className="input min-h-16" value={f.justification} onChange={(e) => set('justification', e.target.value)} maxLength={2000} /></Field>
          <Field label={t('plc.needs.outcomes')} className="md:col-span-2"><textarea className="input min-h-16" value={f.expected_outcomes} onChange={(e) => set('expected_outcomes', e.target.value)} maxLength={2000} /></Field>
        </>}
        <Field label={t('plc.needs.schools')} className="md:col-span-2"><SchoolPicker value={schools} onChange={setSchools} placeholder={String(t('plc.needs.schoolSearch'))} /></Field>
        <Field label={t('plc.needs.notes')} className="md:col-span-2"><textarea className="input min-h-16" value={f.notes} onChange={(e) => set('notes', e.target.value)} maxLength={2000} /></Field>
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
        <Button variant="gold" loading={save.isPending} disabled={!f.title.trim()} onClick={submit}>{t('common.save')}</Button>
      </div>
    </Modal>
  )
}

function ConvertForm({ need, onClose, catalog, onDone }: { need: Need | null; onClose: () => void; catalog?: Catalog; onDone: (id: string) => void }) {
  const { t } = useTranslation()
  const [scenario, setScenario] = useState<'shared' | 'internal'>('shared')
  const [host, setHost] = useState<SchoolRef[]>([])
  const [start, setStart] = useState('')
  const convert = useSend<Record<string, unknown>, { data: { id: string } }>('post', (b) => `/admin/plc/needs/${b.id as string}/convert`, ['/admin/plc'])
  const submit = async () => {
    if (!need) return
    try {
      const { data } = await convert.mutateAsync({ id: need.id, scenario, host_school_id: host[0]?.id, start_date: start || undefined })
      toast(String(t('plc.needs.converted')))
      onDone(data.id)
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <Modal open={!!need} onClose={onClose} title={t('plc.needs.convertTitle')}>
      <p className="mb-4 text-sm text-slate-600">{t('plc.needs.convertHint')}</p>
      <div className="grid gap-4">
        <Field label={t('plc.wizard.scenario')}>
          <select className="input" value={scenario} onChange={(e) => setScenario(e.target.value as 'shared' | 'internal')} disabled={!catalog?.can.manage}>
            {(catalog?.scenarios ?? ['shared', 'internal']).map((s) => <option key={s} value={s}>{t(`plc.scenario.${s}`)}</option>)}
          </select>
        </Field>
        {catalog?.can.manage && <Field label={t('plc.wizard.hostSchool')}><SchoolPicker single value={host} onChange={setHost} placeholder={String(t('plc.wizard.hostSearch'))} /></Field>}
        <Field label={t('plc.wizard.start')}><input type="date" className="input" value={start} onChange={(e) => setStart(e.target.value)} /></Field>
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
        <Button variant="gold" loading={convert.isPending} onClick={submit}>{t('plc.needs.convert')}</Button>
      </div>
    </Modal>
  )
}

// Communities ----------------------------------------------------------------------------------------------------

function CommunitiesTab({ catalog }: { catalog?: Catalog }) {
  const { t, i18n } = useTranslation()
  const [open, setOpen] = useState(false)
  const res = useGet<{ data: Community[] }>('/admin/plc/communities', undefined, { staleTime: 0 })
  const archive = useSend<{ id: string }, unknown>('delete', (b) => `/admin/plc/communities/${b.id}`, ['/admin/plc'])
  const doArchive = async (c: Community) => {
    if (!window.confirm(String(t('plc.communities.confirmArchive')))) return
    try { await archive.mutateAsync({ id: c.id }); toast(String(t('plc.communities.archived'))) } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <div className="space-y-4">
      {catalog?.can.communities && <div className="flex justify-end"><Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t('plc.communities.create')}</Button></div>}
      {res.isLoading ? <Spinner /> : res.error ? <ErrorState error={res.error} onRetry={() => res.refetch()} /> : !res.data?.data.length ? <Empty text={String(t('plc.communities.empty'))} icon={<Users className="size-6" />} /> : (
        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
          {res.data.data.map((c) => (
            <Card key={c.id}>
              <div className="flex items-start justify-between gap-2">
                <div className="min-w-0">
                  <div className="font-display text-base font-bold text-navy-900">{i18n.language === 'en' && c.title_en ? c.title_en : c.title_ar}</div>
                  <div className="text-xs text-slate-500">{c.domain ?? ''} {c.host_school ? `· ${c.host_school.name}` : ''}</div>
                </div>
                <span className="font-mono text-[11px] text-slate-400">{c.code}</span>
              </div>
              {c.description_ar && <p className="mt-2 line-clamp-2 text-sm text-slate-600">{c.description_ar}</p>}
              <div className="mt-3 flex items-center justify-between text-xs text-slate-500">
                <span>{t('plc.communities.counts', { schools: c.schools, workshops: c.workshops })}</span>
                {catalog?.can.communities && <button type="button" className="inline-flex items-center gap-1 text-slate-400 hover:text-red-600" onClick={() => doArchive(c)}><Archive className="size-3.5" />{t('plc.communities.archive')}</button>}
              </div>
            </Card>
          ))}
        </div>
      )}
      <CommunityForm open={open} onClose={() => setOpen(false)} onSaved={() => { setOpen(false); res.refetch() }} />
    </div>
  )
}

function CommunityForm({ open, onClose, onSaved }: { open: boolean; onClose: () => void; onSaved: () => void }) {
  const { t } = useTranslation()
  const [f, setF] = useState({ title_ar: '', title_en: '', description_ar: '', domain: '' })
  const [host, setHost] = useState<SchoolRef[]>([])
  const [schools, setSchools] = useState<SchoolRef[]>([])
  const set = (k: keyof typeof f, v: string) => setF((p) => ({ ...p, [k]: v }))
  const save = useSend<Record<string, unknown>, unknown>('post', '/admin/plc/communities', ['/admin/plc'])
  const submit = async () => {
    try {
      await save.mutateAsync({ ...f, title_en: f.title_en || null, description_ar: f.description_ar || null, domain: f.domain || null, host_school_id: host[0]?.id ?? null, school_ids: schools.map((s) => s.id) })
      toast(String(t('plc.communities.created')))
      onSaved()
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  const schoolIds = useMemo(() => schools.map((s) => s.id), [schools])
  void schoolIds
  return (
    <Modal open={open} onClose={onClose} title={t('plc.communities.create')} wide>
      <div className="grid gap-4 md:grid-cols-2">
        <Field label={t('plc.communities.title')}><input className="input" dir="rtl" value={f.title_ar} onChange={(e) => set('title_ar', e.target.value)} maxLength={200} /></Field>
        <Field label={t('plc.communities.titleEn')}><input className="input" dir="ltr" value={f.title_en} onChange={(e) => set('title_en', e.target.value)} maxLength={200} /></Field>
        <Field label={t('plc.communities.domain')}><input className="input" value={f.domain} onChange={(e) => set('domain', e.target.value)} maxLength={64} /></Field>
        <Field label={t('plc.communities.host')}><SchoolPicker single value={host} onChange={setHost} placeholder={String(t('plc.wizard.hostSearch'))} /></Field>
        <Field label={t('plc.communities.description')} className="md:col-span-2"><textarea className="input min-h-20" value={f.description_ar} onChange={(e) => set('description_ar', e.target.value)} maxLength={2000} /></Field>
        <Field label={t('plc.communities.schools')} className="md:col-span-2"><SchoolPicker value={schools} onChange={setSchools} placeholder={String(t('plc.communities.schoolSearch'))} /></Field>
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button>
        <Button variant="gold" loading={save.isPending} disabled={!f.title_ar.trim()} onClick={submit}>{t('common.save')}</Button>
      </div>
    </Modal>
  )
}

export { SchoolPicker }
