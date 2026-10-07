/* eslint-disable @typescript-eslint/no-explicit-any */
import { Plus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, Table, Tabs, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { dialogs } from '@/lib/dialogs'

const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
type Tab = 'activities' | 'recognitions' | 'types' | 'targets' | 'report' | 'transfers'

/** The centre's professional-development desk: approvals, recognition, hour rules, annual minimums, reports and knowledge-transfer review. */
export default function PdCentre() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('activities')
  const tabs: { id: Tab; label: string; show: boolean }[] = [
    { id: 'activities', label: t('career.tabs.activities'), show: true }, { id: 'recognitions', label: t('career.tabs.recognitions'), show: can('pd.recognise') }, { id: 'types', label: t('career.tabs.types'), show: can('pd.types.manage') },
    { id: 'targets', label: t('career.tabs.targets'), show: can('pd.targets.manage') }, { id: 'report', label: t('career.tabs.report'), show: true }, { id: 'transfers', label: t('career.tabs.transfers'), show: can('knowledge_transfer.review') },
  ]
  return (
    <>
      <PageHeader title={t('career.pdNav')} />
      <Tabs<Tab> value={tab} onChange={setTab} tabs={tabs.filter((x) => x.show).map(({ id, label }) => ({ id, label }))} />
      <div className="mt-4">{tab === 'activities' ? <Activities /> : tab === 'recognitions' ? <Recognitions /> : tab === 'types' ? <Types /> : tab === 'targets' ? <Targets /> : tab === 'report' ? <Report /> : <Transfers />}</div>
    </>
  )
}

function Activities() {
  const { t } = useTranslation()
  const [status, setStatus] = useState('pending_manager')
  const res = useGet<{ data: any[] }>('/admin/pd-activities', { status }, { staleTime: 0 })
  const decide = async (id: string, decision: string) => { const note = decision === 'approve' ? undefined : await dialogs.prompt(t('career.pd.note')) ?? ''; if (decision !== 'approve' && !note) return; try { await api.post(`/admin/pd-activities/${id}/decision`, { decision, note }); toast(t('career.pd.decided')); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-3">
      <select className="rounded-xl border border-navy-100 px-3 py-2 text-sm" value={status} onChange={(e) => setStatus(e.target.value)}>{['pending_manager', 'approved', 'rejected', 'returned'].map((s) => <option key={s} value={s}>{t(`career.pd.status.${s}`)}</option>)}</select>
      {res.isLoading ? <Spinner /> : !res.data?.data.length ? <Card><Empty text={t('career.pd.empty')} /></Card> : res.data.data.map((a) => (
        <Card key={a.id} className="flex flex-wrap items-center gap-3"><div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{a.employee_name}</div><div className="text-sm">{a.title} · {a.computed_hours} h · {fmt.date(a.starts_on)}</div></div>
          {a.status === 'pending_manager' && <><Button size="sm" variant="gold" onClick={() => void decide(a.id, 'approve')}>{t('career.pd.approve')}</Button><Button size="sm" variant="outline" onClick={() => void decide(a.id, 'return')}>{t('career.pd.returnIt')}</Button><Button size="sm" variant="ghost" onClick={() => void decide(a.id, 'reject')}>{t('career.pd.reject')}</Button></>}</Card>))}
    </div>
  )
}

function Recognitions() {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>('/admin/pd-recognitions', undefined, { staleTime: 0 })
  const programs = useGet<{ data: any[] }>('/admin/programs', { per_page: 200 })
  const [cur, setCur] = useState<any | null>(null)
  const [f, setF] = useState<any>({ hours: '', ids: [], note: '' })
  const send = async (decision: string) => { try { await api.post(`/admin/pd-recognitions/${cur.id}/decision`, { decision, recognised_hours: decision === 'approved' ? Number(f.hours) : null, equivalent_program_ids: f.ids, note: f.note || null }); toast(t('career.pd.decided')); setCur(null); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-3">
      {res.isLoading ? <Spinner /> : !res.data?.data.length ? <Card><Empty /></Card> : res.data.data.map((r) => (
        <Card key={r.id} className="flex flex-wrap items-center gap-3"><div className="flex-1"><div className="font-bold">{r.employee_name}</div><div className="text-sm">{r.activity.title} · {r.activity.computed_hours} h</div></div>{r.center_decision ? <Badge color={r.center_decision === 'approved' ? 'green' : 'red'}>{r.center_decision}{r.recognised_hours != null && ` · ${r.recognised_hours} h`}</Badge> : <Button size="sm" variant="gold" onClick={() => { setCur(r); setF({ hours: r.activity.computed_hours, ids: [], note: '' }) }}>{t('career.centre.decide')}</Button>}</Card>))}
      <Modal open={!!cur} onClose={() => setCur(null)} title={t('career.centre.recognise')}>
        <div className="space-y-3"><Field label={t('career.centre.recognisedHours')}><input className={input} type="number" step="0.5" value={f.hours} onChange={(e) => setF({ ...f, hours: e.target.value })} /></Field>
          <Field label={t('career.centre.equivalents')}><select multiple className={`${input} h-28`} value={f.ids} onChange={(e) => setF({ ...f, ids: Array.from(e.target.selectedOptions).map((o) => o.value) })}>{(programs.data?.data ?? []).map((p: any) => <option key={p.id} value={p.id}>{p.code} — {p.title}</option>)}</select></Field>
          <Field label={t('career.kt.note')}><input className={input} value={f.note} onChange={(e) => setF({ ...f, note: e.target.value })} /></Field>
          <div className="flex gap-2"><Button variant="gold" disabled={!f.hours} onClick={() => void send('approved')}>{t('career.pd.approve')}</Button><Button variant="ghost" onClick={() => void send('rejected')}>{t('career.pd.reject')}</Button></div></div>
      </Modal>
    </div>
  )
}

function Types() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const res = useGet<{ data: any[] }>('/admin/pd-activity-types', undefined, { staleTime: 0 })
  const [cur, setCur] = useState<any | null>(null)
  const open = (x: any | null) => setCur(x ? JSON.parse(JSON.stringify(x)) : { code: '', name_ar: '', name_en: '', hour_rules: { attendee: { factor: 1 }, presenter: { factor: 1.5 }, organiser: { factor: 1.5 }, author: { factor: 2 } }, evidence_required: true, is_active: true })
  const save = async () => { try { cur.id ? await api.put(`/admin/pd-activity-types/${cur.id}`, cur) : await api.post('/admin/pd-activity-types', cur); toast(t('career.path.saved')); setCur(null); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const rule = (lvl: string, k: string, v: string) => setCur({ ...cur, hour_rules: { ...cur.hour_rules, [lvl]: { ...cur.hour_rules[lvl], [k]: v === '' ? null : Number(v) } } })
  return (
    <div className="space-y-3">
      <div className="flex justify-end"><Button size="sm" icon={<Plus className="size-4" />} onClick={() => open(null)}>{t('career.centre.addType')}</Button></div>
      {res.isLoading ? <Spinner /> : res.data?.data.map((x) => <Card key={x.id} className="flex items-center gap-3"><b>{ar ? x.name_ar : x.name_en}</b><span className="text-xs text-slate-400" dir="ltr">{x.code}</span>{!x.is_active && <Badge color="gray">off</Badge>}<Button className="ms-auto" size="sm" variant="outline" onClick={() => open(x)}>{t('common.edit')}</Button></Card>)}
      <Modal wide open={!!cur} onClose={() => setCur(null)} title={t('career.tabs.types')}>
        {cur && <div className="space-y-3"><div className="grid gap-3 sm:grid-cols-3"><Field label={t('career.centre.typeCode')}><input dir="ltr" className={input} value={cur.code} onChange={(e) => setCur({ ...cur, code: e.target.value })} /></Field><Field label={t('career.path.titleAr')}><input className={input} value={cur.name_ar} onChange={(e) => setCur({ ...cur, name_ar: e.target.value })} /></Field><Field label={t('career.path.titleEn')}><input dir="ltr" className={input} value={cur.name_en} onChange={(e) => setCur({ ...cur, name_en: e.target.value })} /></Field></div>
          {['attendee', 'presenter', 'organiser', 'author'].map((l) => <div key={l} className="grid items-center gap-2 sm:grid-cols-3"><b className="text-sm">{t(`career.pd.levels.${l}`)}</b><input className={input} type="number" step="0.25" placeholder={t('career.centre.factor')} value={cur.hour_rules[l]?.factor ?? ''} onChange={(e) => rule(l, 'factor', e.target.value)} /><input className={input} type="number" placeholder={t('career.centre.capActivity')} value={cur.hour_rules[l]?.cap_activity ?? ''} onChange={(e) => rule(l, 'cap_activity', e.target.value)} /></div>)}
          <Field label={t('career.centre.capYear')}><input className={input} type="number" value={cur.hour_rules.cap_year ?? ''} onChange={(e) => setCur({ ...cur, hour_rules: { ...cur.hour_rules, cap_year: e.target.value === '' ? null : Number(e.target.value) } })} /></Field>
          <div className="flex gap-5 text-sm"><label className="flex items-center gap-2"><input type="checkbox" checked={cur.evidence_required} onChange={(e) => setCur({ ...cur, evidence_required: e.target.checked })} />{t('career.centre.evidenceRequired')}</label><label className="flex items-center gap-2"><input type="checkbox" checked={cur.is_active} onChange={(e) => setCur({ ...cur, is_active: e.target.checked })} />{t('career.centre.active')}</label></div>
          <Button variant="gold" onClick={() => void save()}>{t('common.save')}</Button></div>}
      </Modal>
    </div>
  )
}

function Targets() {
  const { t } = useTranslation()
  const res = useGet<{ data: any }>('/admin/pd-targets', undefined, { staleTime: 0 })
  const [rows, setRows] = useState<any[] | null>(null)
  const [month, setMonth] = useState<number | null>(null)
  if (!res.data) return <Spinner />
  const d = res.data.data
  const list = rows ?? d.targets
  const set = (i: number, patch: any) => setRows(list.map((x: any, j: number) => (j === i ? { ...x, ...patch } : x)))
  const save = async () => { try { await api.put('/admin/pd-targets', { year_start_month: month ?? d.year_start_month, targets: list.map((x: any) => ({ id: x.id, year: Number(x.year), min_hours: Number(x.min_hours), audience: x.audience, counts: x.counts ?? { center: true, internal: true, external: true, knowledge_transfer: true } })) }); toast(t('career.path.saved')); setRows(null); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-3">
      <Field label={t('career.centre.yearStart')}><input className={`${input} max-w-28`} type="number" min="1" max="12" value={month ?? d.year_start_month} onChange={(e) => setMonth(Number(e.target.value))} /></Field>
      {list.map((x: any, i: number) => <Card key={x.id ?? i} className="space-y-2"><div className="grid gap-2 sm:grid-cols-2"><Field label={t('career.centre.year')}><input className={input} type="number" value={x.year} onChange={(e) => set(i, { year: e.target.value })} /></Field><Field label={t('career.centre.minHours')}><input className={input} type="number" value={x.min_hours} onChange={(e) => set(i, { min_hours: e.target.value })} /></Field></div>
        <div className="flex flex-wrap gap-4 text-sm"><b>{t('career.centre.counts')}</b>{['center', 'internal', 'external', 'knowledge_transfer'].map((s) => <label key={s} className="flex items-center gap-2"><input type="checkbox" checked={(x.counts ?? {})[s] ?? true} onChange={(e) => set(i, { counts: { ...(x.counts ?? {}), [s]: e.target.checked } })} />{t(`career.pd.sources.${s}`)}</label>)}</div></Card>)}
      <div className="flex gap-2"><Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => setRows([...list, { year: d.current_year, min_hours: 40 }])}>{t('career.centre.addTarget')}</Button><Button variant="gold" onClick={() => void save()}>{t('common.save')}</Button></div>
    </div>
  )
}

function Report() {
  const { t, i18n } = useTranslation()
  const res = useGet<{ data: any }>('/admin/pd-reports', undefined, { staleTime: 0 })
  if (!res.data) return <Spinner />
  const d = res.data.data
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3"><Badge color="gold">{t('career.centre.below', { n: d.below_target })}</Badge><span className="text-xs text-slate-500">{t('career.centre.approval')}: {d.approval.approved}/{d.approval.submitted}</span><div className="ms-auto flex gap-2">{['xlsx', 'pdf', 'docx'].map((f) => <Button key={f} size="sm" variant="outline" onClick={() => void downloadFile(`/admin/pd-reports?format=${f}&lang=${i18n.language}&year=${d.year}`, `pd-report.${f}`)}>{f.toUpperCase()}</Button>)}</div></div>
      <Table head={[t('career.path.employees'), t('career.pd.sources.center'), t('career.pd.sources.external'), t('career.pd.sources.knowledge_transfer'), '∑', t('career.centre.minHours')]}>{d.rows.map((r: any) => <tr key={r.employee_no}><Td>{r.employee}</Td><Td>{r.counted.center + r.counted.internal}</Td><Td>{r.counted.external}</Td><Td>{r.counted.knowledge_transfer}</Td><Td><b>{r.total}</b></Td><Td>{r.target ?? '—'}</Td></tr>)}</Table>
      <Card><div className="mb-2 font-semibold">{t('career.centre.byDomain')}</div>{d.by_domain.map((x: any) => <div key={x.domain} className="flex justify-between text-sm"><span>{x.domain}</span><span>{x.hours} h · {x.count}</span></div>)}</Card>
    </div>
  )
}

function Transfers() {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>('/admin/knowledge-transfers', { status: 'pending_review' }, { staleTime: 0 })
  const decide = async (id: string, decision: string) => { const note = decision === 'approve' ? undefined : await dialogs.prompt(t('career.kt.note')) ?? ''; if (decision !== 'approve' && !note) return; try { await api.post(`/admin/knowledge-transfers/${id}/decision`, { decision, note }); toast(t('career.pd.decided')); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return !res.data ? <Spinner /> : !res.data.data.length ? <Card><Empty text={t('career.kt.empty')} /></Card> : (
    <div className="space-y-3">{res.data.data.map((k) => <Card key={k.id} className="flex flex-wrap items-center gap-3"><div className="flex-1"><div className="font-bold">{k.employee_name}</div><div className="text-sm">{k.program} · {k.hours} h · {k.beneficiary_count} · {fmt.date(k.delivered_on)}</div></div><Button size="sm" variant="gold" onClick={() => void decide(k.id, 'approve')}>{t('career.kt.approve')}</Button><Button size="sm" variant="ghost" onClick={() => void decide(k.id, 'reject')}>{t('career.kt.reject')}</Button></Card>)}</div>
  )
}
