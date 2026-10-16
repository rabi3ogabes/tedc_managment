import { AlertTriangle, BarChart3, Building2, ClipboardCheck, Download, FileSpreadsheet, FileText, RotateCcw, Users } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Badge, Button, Card, CardTitle, Empty, ErrorState, Progress, Spinner, StatCard } from '@/components/ui'
import { fmt } from '@/lib/format'
import { useGet } from '@/hooks/useApi'
import { downloadFile } from '@/lib/api'
import { STATE_TONE, type PlcSource, type PlcState, type WorkshopSummary } from './plcApi'

type Stat = { assigned: number; submitted: number; rate: number | null; score: number | null; score_hidden: boolean }
export type Report = {
  filters: Record<string, string>; generated_at: string
  workshops: { total: number; held: number; by_state: Record<string, number>; by_source: Record<string, number>; by_scenario: Record<string, number>; by_mode: Record<string, number>; closed_by_exception: number; overdue_close: number }
  sessions: { total: number; held: number; by_mode: Record<string, number>; hours: number }
  people: { capacity: number; seated: number; completed: number; unique: number; schools: number; communities: number }
  attendance: { people: number; rate: number | null; meeting: number; meeting_rate: number | null; workshops_with_records: number }
  evaluation: { all: Stat; workshop: Stat; trainer: Stat; session: Stat; community: Stat; min_responses: number; close_response_rate: number }
  impact: { trainee: Record<string, number> & { total: number }; manager: { assigned: number; submitted: number }; sources: string[] }
  needs: { total: number; by_status: Record<string, number>; by_source: Record<string, number>; converted_rate: number | null; median_wait_days: number | null }
  trend: { month: string; workshops: number; seated: number }[]
  by_school: { school_id: string; name?: string | null; name_en?: string | null; region?: string | null; gender?: string | null; hosted: number; participating: number; workshops: number; people: number; attendance: number | null; evaluation_rate: number | null }[]
  by_community: { community_id: string; title?: string | null; workshops: number; by_state: Record<string, number>; people: number; attendance: number | null; rate: number | null; score: number | null }[]
  overdue_close: WorkshopSummary[]; top_attendance: { id: string; title_ar?: string | null; title_en?: string | null; people: number; rate: number }[]
}
type Options = { schools: { id: string; name: string; name_en?: string | null; region?: string | null }[]; communities: { id: string; title: string }[]; regions: string[] }
type Filters = { from: string; to: string; source: string; scenario: string; state: string; mode: string; school_id: string; community_id: string; region: string; need_id: string }
const EMPTY: Filters = { from: '', to: '', source: '', scenario: '', state: '', mode: '', school_id: '', community_id: '', region: '', need_id: '' }
type Decision = { id: string; workshop_id: string; workshop: { title_ar?: string | null; title_en?: string | null }; kind: string; decision: string; reason?: string | null; by?: string | null; at?: string | null }
const pct = (v: number | null | undefined) => (v == null ? '—' : `${v}%`)

/** The reports tab: the same figures as the dashboard, filtered by period, source, scenario, state, mode, school, community and region, with the school and community breakdowns and the exports. */
export default function PlcReports({ states, sources, initial }: { states?: PlcState[]; sources?: PlcSource[]; initial?: Partial<Filters> }) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const [f, setF] = useState<Filters>({ ...EMPTY, ...initial })
  const params = Object.fromEntries(Object.entries(f).filter(([, v]) => v !== ''))
  const res = useGet<{ data: Report }>('/admin/plc/reports', params, { staleTime: 30_000 })
  const opts = useGet<{ data: Options }>('/admin/plc/reports/options', undefined, { staleTime: 300_000 })
  const [decisionKind, setDecisionKind] = useState<string>('')
  const decisions = useGet<{ data: { by_kind: Record<string, number>; rows: Decision[] } }>('/admin/plc/reports/decisions', { ...params, kind: decisionKind || undefined, limit: 50 }, { staleTime: 30_000 })
  const set = (k: keyof Filters, v: string) => setF((p) => ({ ...p, [k]: v }))
  const active = Object.keys(params).length
  const qs = new URLSearchParams({ ...params, lang: en ? 'en' : 'ar' })
  const exportAs = (format: 'xlsx' | 'pdf' | 'csv') => downloadFile(`/admin/plc/reports/export?${qs.toString()}&format=${format}`, `plc-report.${format}`)
  const name = (x: { name?: string | null; name_en?: string | null }) => (en && x.name_en ? x.name_en : x.name) ?? '—'
  const o = opts.data?.data
  const d = res.data?.data
  const maxBar = (m: Record<string, number>) => Math.max(1, ...Object.values(m))

  return (
    <div className="space-y-5">
      <Card>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <label className="text-xs text-slate-500">{t('plc.reports.from')}<input type="date" className="input mt-1" value={f.from} onChange={(e) => set('from', e.target.value)} /></label>
          <label className="text-xs text-slate-500">{t('plc.reports.to')}<input type="date" className="input mt-1" value={f.to} onChange={(e) => set('to', e.target.value)} /></label>
          <label className="text-xs text-slate-500">{t('plc.reports.source')}<select className="input mt-1" value={f.source} onChange={(e) => set('source', e.target.value)}><option value="">{t('common.all')}</option>{(sources ?? ['rased', 'centre', 'entity', 'school']).map((s) => <option key={s} value={s}>{t(`plc.source.${s}`)}</option>)}</select></label>
          <label className="text-xs text-slate-500">{t('plc.wizard.scenario')}<select className="input mt-1" value={f.scenario} onChange={(e) => set('scenario', e.target.value)}><option value="">{t('common.all')}</option>{(['shared', 'internal'] as const).map((s) => <option key={s} value={s}>{t(`plc.scenario.${s}`)}</option>)}</select></label>
          <label className="text-xs text-slate-500">{t('plc.reports.state')}<select className="input mt-1" value={f.state} onChange={(e) => set('state', e.target.value)}><option value="">{t('common.all')}</option>{(states ?? []).map((s) => <option key={s} value={s}>{t(`plc.state.${s}`)}</option>)}</select></label>
          <label className="text-xs text-slate-500">{t('plc.wizard.mode')}<select className="input mt-1" value={f.mode} onChange={(e) => set('mode', e.target.value)}><option value="">{t('common.all')}</option>{(['in_person', 'online', 'hybrid'] as const).map((s) => <option key={s} value={s}>{t(`plc.mode.${s}`)}</option>)}</select></label>
          <label className="text-xs text-slate-500">{t('plc.reports.school')}<select className="input mt-1" value={f.school_id} onChange={(e) => set('school_id', e.target.value)}><option value="">{t('common.all')}</option>{(o?.schools ?? []).map((s) => <option key={s.id} value={s.id}>{name(s)}</option>)}</select></label>
          <label className="text-xs text-slate-500">{t('plc.reports.community')}<select className="input mt-1" value={f.community_id} onChange={(e) => set('community_id', e.target.value)}><option value="">{t('common.all')}</option>{(o?.communities ?? []).map((c) => <option key={c.id} value={c.id}>{c.title}</option>)}</select></label>
          <label className="text-xs text-slate-500">{t('plc.reports.region')}<select className="input mt-1" value={f.region} onChange={(e) => set('region', e.target.value)}><option value="">{t('common.all')}</option>{(o?.regions ?? []).map((r) => <option key={r} value={r}>{r}</option>)}</select></label>
          <div className="flex items-end gap-2">
            {active > 0 && <Button variant="outline" size="sm" icon={<RotateCcw className="size-3.5" />} onClick={() => setF(EMPTY)}>{t('plc.reports.reset')}</Button>}
            <Button variant="gold" size="sm" icon={<FileSpreadsheet className="size-3.5" />} onClick={() => exportAs('xlsx')}>Excel</Button>
            <Button variant="outline" size="sm" icon={<FileText className="size-3.5" />} onClick={() => exportAs('pdf')}>PDF</Button>
            <Button variant="outline" size="sm" icon={<Download className="size-3.5" />} onClick={() => exportAs('csv')}>CSV</Button>
          </div>
        </div>
      </Card>

      {res.isLoading && !d ? <Spinner /> : res.error || !d ? <ErrorState error={res.error} onRetry={() => res.refetch()} /> : (
        <>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label={t('plc.reports.workshops')} value={d.workshops.total} icon={<BarChart3 className="size-5" />} hint={t('plc.reports.heldN', { n: d.workshops.held })} accent />
            <StatCard label={t('plc.reports.people')} value={d.people.seated} icon={<Users className="size-5" />} hint={t('plc.reports.peopleHint', { cap: d.people.capacity, done: d.people.completed, u: d.people.unique })} />
            <StatCard label={t('plc.reports.attendance')} value={pct(d.attendance.rate)} icon={<ClipboardCheck className="size-5" />} hint={t('plc.reports.attendanceHint', { n: d.attendance.meeting, of: d.attendance.people })} />
            <StatCard label={t('plc.reports.evaluation')} value={pct(d.evaluation.workshop.rate)} icon={<ClipboardCheck className="size-5" />} hint={d.evaluation.workshop.score != null ? t('plc.reports.scoreHint', { n: d.evaluation.workshop.score }) : d.evaluation.workshop.score_hidden ? t('plc.evaluation.scoreHidden', { n: d.evaluation.min_responses }) : t('plc.reports.answersHint', { n: d.evaluation.workshop.submitted, of: d.evaluation.workshop.assigned })} />
          </div>
          <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
            <StatCard label={t('plc.reports.sessions')} value={d.sessions.total} hint={t('plc.reports.sessionsHint', { held: d.sessions.held, h: d.sessions.hours })} />
            <StatCard label={t('plc.reports.schools')} value={d.people.schools} hint={t('plc.reports.communitiesN', { n: d.people.communities })} icon={<Building2 className="size-5" />} />
            <StatCard label={t('plc.reports.needs')} value={d.needs.total} hint={d.needs.converted_rate != null ? t('plc.reports.needsHint', { n: d.needs.converted_rate, days: d.needs.median_wait_days ?? '—' }) : t('plc.reports.noNeeds')} />
            <StatCard label={t('plc.reports.impact')} value={d.impact.trainee.total} hint={t('plc.reports.impactHint', { c: d.impact.trainee.completed ?? 0, m: d.impact.manager.submitted, ma: d.impact.manager.assigned })} />
          </div>

          {(d.workshops.overdue_close > 0 || d.workshops.closed_by_exception > 0) && (
            <Card className="border-amber-200 bg-amber-50/60">
              <div className="flex items-start gap-3 text-sm"><AlertTriangle className="mt-0.5 size-5 shrink-0 text-amber-600" /><div>
                {d.workshops.overdue_close > 0 && <div className="font-semibold text-navy-900">{t('plc.reports.overdueClose', { n: d.workshops.overdue_close })}</div>}
                {d.workshops.closed_by_exception > 0 && <div className="text-slate-700">{t('plc.reports.closedByException', { n: d.workshops.closed_by_exception })}</div>}
                {d.overdue_close.length > 0 && <ul className="mt-1 flex flex-wrap gap-x-3 gap-y-1 text-xs">{d.overdue_close.map((w) => <li key={w.id}><Link className="underline" to={`/admin/communities/workshops/${w.id}/evaluation`}>{en && w.title_en ? w.title_en : w.title_ar}</Link></li>)}</ul>}
              </div></div>
            </Card>
          )}

          <div className="grid gap-4 lg:grid-cols-3">
            <Bars title={t('plc.overview.byState')} map={d.workshops.by_state} label={(k) => t(`plc.state.${k}`)} max={maxBar(d.workshops.by_state)} tone={(k) => STATE_TONE[k as PlcState] ?? 'gray'} />
            <Bars title={t('plc.overview.bySource')} map={d.workshops.by_source} label={(k) => t(`plc.source.${k}`)} max={maxBar(d.workshops.by_source)} />
            <Bars title={t('plc.reports.byMode')} map={d.workshops.by_mode} label={(k) => t(`plc.mode.${k}`)} max={maxBar(d.workshops.by_mode)} extra={<div className="mt-3 text-xs text-slate-500">{t('plc.overview.byScenario')}: {Object.entries(d.workshops.by_scenario).map(([k, v]) => `${t(`plc.scenario.${k}`)} ${v}`).join(' · ') || '—'}</div>} />
          </div>

          <div className="grid gap-4 lg:grid-cols-3">
            <Card className="lg:col-span-2">
              <CardTitle subtitle={t('plc.reports.trendHint')}>{t('plc.reports.trend')}</CardTitle>
              {!d.trend.length ? <Empty text={String(t('plc.reports.noData'))} /> : (
                <div className="flex h-40 items-end gap-2 overflow-x-auto pb-1">
                  {d.trend.map((m) => { const max = Math.max(1, ...d.trend.map((x) => x.workshops)); return (
                    <div key={m.month} className="flex min-w-12 flex-1 flex-col items-center justify-end gap-1" title={`${m.workshops} · ${m.seated}`}>
                      <span className="text-[11px] font-semibold tabular-nums text-navy-900">{m.workshops}</span>
                      <div className="w-full rounded-t-lg bg-gradient-to-t from-gold-600 to-gold-400" style={{ height: `${Math.max(6, (m.workshops / max) * 110)}px` }} />
                      <span className="text-[10px] text-slate-500">{m.month}</span>
                    </div>) })}
                </div>
              )}
            </Card>
            <Card>
              <CardTitle subtitle={t('plc.reports.evalHint', { n: d.evaluation.min_responses })}>{t('plc.reports.evalByInstrument')}</CardTitle>
              <ul className="space-y-2.5 text-sm">
                {(['workshop', 'trainer', 'session', 'community'] as const).map((k) => (
                  <li key={k}><div className="flex items-center justify-between"><span>{t(`plc.evaluation.instrument.${k}`)}</span><span className="text-xs tabular-nums text-slate-500">{d.evaluation[k].submitted}/{d.evaluation[k].assigned} · {pct(d.evaluation[k].rate)}{d.evaluation[k].score != null ? ` · ${t('plc.reports.avg')} ${d.evaluation[k].score}%` : ''}</span></div><Progress value={d.evaluation[k].rate ?? 0} className="mt-1" tone={(d.evaluation[k].rate ?? 0) >= d.evaluation.close_response_rate ? 'green' : 'gold'} /></li>
                ))}
              </ul>
              {d.top_attendance.length > 0 && <div className="mt-4 border-t border-slate-100 pt-3 text-xs"><div className="mb-1 font-semibold text-navy-900">{t('plc.reports.topAttendance')}</div><ul className="space-y-1">{d.top_attendance.map((w) => <li key={w.id} className="flex items-center justify-between gap-2"><Link to={`/admin/communities/workshops/${w.id}/attendance`} className="truncate underline decoration-gold-400">{en && w.title_en ? w.title_en : w.title_ar}</Link><span className="tabular-nums text-slate-500">{w.rate}% · {w.people}</span></li>)}</ul></div>}
            </Card>
          </div>

          <Card padded={false}>
            <div className="border-b border-slate-100 px-4 py-3 font-bold text-navy-900">{t('plc.reports.bySchool')}</div>
            {!d.by_school.length ? <Empty text={String(t('plc.reports.noData'))} /> : (
              <div className="overflow-auto"><table className="w-full text-xs">
                <thead className="bg-slate-50 text-slate-500"><tr><th className="px-3 py-2 text-start">{t('plc.reports.school')}</th><th className="px-3 py-2 text-start">{t('plc.reports.region')}</th><th className="px-3 py-2">{t('plc.detail.host')}</th><th className="px-3 py-2">{t('plc.detail.participant')}</th><th className="px-3 py-2">{t('plc.reports.people')}</th><th className="px-3 py-2">{t('plc.reports.attendance')}</th><th className="px-3 py-2">{t('plc.reports.evaluation')}</th></tr></thead>
                <tbody className="divide-y divide-slate-100">{d.by_school.map((s) => (
                  <tr key={s.school_id} className="hover:bg-slate-50">
                    <td className="px-3 py-1.5 font-semibold text-navy-900"><button type="button" className="underline decoration-gold-400 hover:text-gold-700" onClick={() => set('school_id', s.school_id)}>{name(s)}</button>{s.gender && <Badge color="gray" className="ms-1">{s.gender}</Badge>}</td>
                    <td className="px-3 py-1.5 text-slate-500">{s.region ?? '—'}</td><td className="px-3 py-1.5 text-center tabular-nums">{s.hosted}</td><td className="px-3 py-1.5 text-center tabular-nums">{s.participating}</td><td className="px-3 py-1.5 text-center tabular-nums">{s.people}</td>
                    <td className="px-3 py-1.5 text-center tabular-nums">{pct(s.attendance)}</td><td className="px-3 py-1.5 text-center tabular-nums">{pct(s.evaluation_rate)}</td>
                  </tr>))}
                </tbody></table></div>
            )}
          </Card>

          {d.by_community.length > 0 && (
            <Card padded={false}>
              <div className="border-b border-slate-100 px-4 py-3 font-bold text-navy-900">{t('plc.reports.byCommunity')}</div>
              <div className="overflow-auto"><table className="w-full text-xs">
                <thead className="bg-slate-50 text-slate-500"><tr><th className="px-3 py-2 text-start">{t('plc.reports.community')}</th><th className="px-3 py-2 text-start">{t('plc.reports.workshops')}</th><th className="px-3 py-2">{t('plc.reports.people')}</th><th className="px-3 py-2">{t('plc.reports.attendance')}</th><th className="px-3 py-2">{t('plc.reports.evaluation')}</th><th className="px-3 py-2">{t('plc.reports.avg')}</th></tr></thead>
                <tbody className="divide-y divide-slate-100">{d.by_community.map((c) => (
                  <tr key={c.community_id} className="hover:bg-slate-50">
                    <td className="px-3 py-1.5 font-semibold text-navy-900"><button type="button" className="underline decoration-gold-400 hover:text-gold-700" onClick={() => set('community_id', c.community_id)}>{c.title}</button></td>
                    <td className="px-3 py-1.5"><span className="tabular-nums font-semibold">{c.workshops}</span> <span className="text-slate-500">{Object.entries(c.by_state).map(([k, v]) => `${t(`plc.state.${k}`)} ${v}`).join(' · ')}</span></td>
                    <td className="px-3 py-1.5 text-center tabular-nums">{c.people}</td><td className="px-3 py-1.5 text-center tabular-nums">{pct(c.attendance)}</td><td className="px-3 py-1.5 text-center tabular-nums">{pct(c.rate)}</td><td className="px-3 py-1.5 text-center tabular-nums">{pct(c.score)}</td>
                  </tr>))}
                </tbody></table></div>
            </Card>
          )}
          <Card padded={false}>
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
              <div><div className="font-bold text-navy-900">{t('plc.assistant.register')}</div><div className="text-xs text-slate-500">{t('plc.assistant.registerHint')}</div></div>
              <div className="flex flex-wrap gap-1">
                <button type="button" onClick={() => setDecisionKind('')} className={`rounded-full px-2.5 py-1 text-xs font-semibold ${!decisionKind ? 'bg-navy-900 text-white' : 'bg-slate-100 text-slate-600'}`}>{t('common.all')}</button>
                {Object.entries(decisions.data?.data.by_kind ?? {}).map(([k, n]) => <button key={k} type="button" onClick={() => setDecisionKind(k)} className={`rounded-full px-2.5 py-1 text-xs font-semibold ${decisionKind === k ? 'bg-navy-900 text-white' : k === 'exception' ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-600'}`}>{t(`plc.detail.kinds.${k}`, { defaultValue: k })} {n}</button>)}
              </div>
            </div>
            {!decisions.data?.data.rows.length ? <Empty text={String(t('plc.assistant.noDecisions'))} /> : (
              <ul className="divide-y divide-slate-100 text-xs">{decisions.data.data.rows.map((r) => (
                <li key={r.id} className="flex flex-wrap items-start gap-2 px-4 py-2">
                  <Badge color={r.kind === 'exception' ? 'amber' : 'gray'}>{t(`plc.detail.kinds.${r.kind}`, { defaultValue: r.kind })}</Badge>
                  <span className="font-semibold text-navy-900">{(STATE_TONE as Record<string, string>)[r.decision] ? t(`plc.state.${r.decision}`) : t(`plc.detail.decisionLabels.${r.decision}`, { defaultValue: r.decision })}</span>
                  <Link to={`/admin/communities/workshops/${r.workshop_id}`} className="underline decoration-gold-300">{en && r.workshop.title_en ? r.workshop.title_en : r.workshop.title_ar}</Link>
                  {r.reason && <span className="text-slate-600">— {r.reason}</span>}
                  <span className="ms-auto text-slate-400">{r.by ? `${r.by} · ` : ''}{fmt.date(r.at, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}</span>
                </li>))}
              </ul>
            )}
          </Card>
          <p className="text-xs text-slate-400">{t('plc.reports.footer')}</p>
        </>
      )}
    </div>
  )
}

function Bars({ title, map, label, max, tone, extra }: { title: string; map: Record<string, number>; label: (k: string) => string; max: number; tone?: (k: string) => 'green' | 'red' | 'amber' | 'blue' | 'navy' | 'gold' | 'gray'; extra?: React.ReactNode }) {
  const { t } = useTranslation()
  const entries = Object.entries(map)
  return (
    <Card>
      <CardTitle>{title}</CardTitle>
      {!entries.length ? <p className="text-sm text-slate-400">{t('plc.reports.noData')}</p> : (
        <ul className="space-y-2 text-sm">{entries.map(([k, v]) => (
          <li key={k}><div className="flex items-center justify-between gap-2">{tone ? <Badge color={tone(k)}>{label(k)}</Badge> : <span>{label(k)}</span>}<b className="tabular-nums">{v}</b></div><Progress value={(v / max) * 100} className="mt-1" tone="navy" /></li>
        ))}</ul>
      )}
      {extra}
    </Card>
  )
}
