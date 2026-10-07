/* eslint-disable @typescript-eslint/no-explicit-any */
import { Check, Plus, X } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import EvidenceInput, { noEvidence, type Evidence } from '@/components/EvidenceInput'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Progress, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { dialogs } from '@/lib/dialogs'

const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
type Tab = 'paths' | 'pd' | 'kt' | 'team'
const tone = (s: string) => (s === 'eligible' || s === 'achieved' || s === 'approved' ? 'green' : s === 'expired' || s === 'rejected' ? 'red' : 'gold')

function toForm(e: Evidence, fields: [string, string][]) {
  const fd = new FormData()
  fields.forEach(([k, v]) => fd.append(k, v))
  e.files.forEach((f, i) => fd.append(`evidence[${i}]`, f))
  e.links.forEach((l, i) => fd.append(`evidence[${e.files.length + i}]`, l))
  return fd
}

/** Career paths and licences with next steps, professional-development logging with the yearly ring, and knowledge transfer. */
export default function MyGrowth() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('paths')
  return (
    <>
      <PageHeader title={t('career.myNav')} />
      <Tabs<Tab> value={tab} onChange={setTab} tabs={[{ id: 'paths', label: t('career.my.paths') }, { id: 'pd', label: t('career.pd.title') }, { id: 'kt', label: t('career.kt.title') }, ...(can('impact.supervise') ? [{ id: 'team' as const, label: t('career.pd.team') }] : [])]} />
      <div className="mt-4">{tab === 'paths' ? <Paths /> : tab === 'pd' ? <Pd /> : tab === 'kt' ? <Transfers /> : <Team />}</div>
    </>
  )
}

function Paths() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const paths = useGet<{ data: any[] }>('/me/paths', undefined, { staleTime: 0 })
  const lic = useGet<{ data: any[] }>('/me/licences')
  return (
    <div className="space-y-5">
      {paths.isLoading ? <Spinner /> : !paths.data?.data.length ? <Card><Empty text={t('career.my.noPaths')} /></Card> : paths.data.data.map((p) => (
        <Card key={p.path_id} className="space-y-3">
          <div className="flex flex-wrap items-center gap-2"><b className="text-navy-900">{ar ? p.title_ar : p.title_en}</b><Badge>{t(`career.types.${p.type}`)}</Badge><Badge color={tone(p.status)}>{t(`career.status.${p.status}`)}</Badge></div>
          <div className="flex flex-wrap gap-2">{p.levels.map((l: any) => <span key={l.level_no} className={`rounded-full px-3 py-1 text-xs font-bold ${l.level_no <= p.current_level_no ? 'bg-emerald-100 text-emerald-800' : l.level_no === p.target_level_no ? 'bg-gold-100 text-gold-900' : 'bg-ivory text-slate-500'}`}>{ar ? l.title_ar : l.title_en}</span>)}</div>
          {p.explanation.length > 0 && <div className="space-y-1"><div className="text-xs font-semibold text-gold-700">{t('career.my.next')}</div>{p.explanation.map((c: any) => <div key={c.key} className="flex items-center gap-2 text-sm">{c.met ? <Check className="size-4 text-emerald-600" /> : <X className="size-4 text-red-500" />}<span>{c.label}</span>{c.expected != null && <span className="text-xs text-slate-400">({c.actual ?? '—'} / {Array.isArray(c.expected) ? c.expected.join(', ') : String(c.expected)})</span>}</div>)}</div>}
        </Card>))}
      <h3 className="font-bold text-navy-900">{t('career.my.licences')}</h3>
      {!lic.data?.data.length ? <Card><Empty text={t('career.licence.empty')} /></Card> : <div className="grid gap-3 md:grid-cols-2">{lic.data.data.map((l) => <Card key={l.id} className="flex items-center gap-3"><div className="flex-1"><div className="font-bold">{l.path ? (ar ? l.path.title_ar : l.path.title_en) : ''} · {t('career.licence.level')} {l.level_no}</div><div className="font-mono text-xs text-slate-500" dir="ltr">{l.licence_no}</div></div><div className="text-end text-xs"><Badge color={l.status === 'active' ? 'green' : 'red'}>{t(`career.licence.status.${l.status}`)}</Badge><div className="mt-1 text-slate-500">{fmt.date(l.expires_at)}</div></div></Card>)}</div>}
    </div>
  )
}

function Pd() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const sum = useGet<{ data: any }>('/me/pd-hours', undefined, { staleTime: 0 })
  const list = useGet<{ data: any[] }>('/me/pd-activities', undefined, { staleTime: 0 })
  const [open, setOpen] = useState(false)
  const s = sum.data?.data
  const submit = async (id: string) => { try { await api.post(`/me/pd-activities/${id}/submit`); toast(t('career.pd.submitted')); void list.refetch(); void sum.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-4">
      {s && <Card className="space-y-2"><div className="flex items-end justify-between"><div><div className="text-3xl font-extrabold text-navy-900">{s.total}{s.target != null && <span className="text-base font-semibold text-slate-400"> {t('career.pd.of', { n: s.target })}</span>}</div><div className="text-xs text-slate-500">{t('career.pd.year')} · {s.year}</div></div>{s.shortfall > 0 && <Badge color="gold">{t('career.pd.shortfall', { n: s.shortfall })}</Badge>}</div>
        {s.percent != null && <Progress value={s.percent} tone={s.percent >= 100 ? 'green' : 'gold'} />}
        <div className="flex flex-wrap gap-3 text-xs text-slate-600">{Object.entries<number>(s.counted).map(([k, v]) => <span key={k}>{t(`career.pd.sources.${k}`)}: <b>{v}</b></span>)}</div></Card>}
      <div className="flex justify-end"><Button icon={<Plus className="size-4" />} onClick={() => setOpen(true)}>{t('career.pd.log')}</Button></div>
      {list.isLoading ? <Spinner /> : !list.data?.data.length ? <Card><Empty text={t('career.pd.empty')} /></Card> : list.data.data.map((a) => (
        <Card key={a.id} className="flex flex-wrap items-center gap-3"><div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{a.title}</div><div className="text-xs text-slate-500">{ar ? a.type?.name_ar : a.type?.name_en} · {fmt.date(a.starts_on)} · {a.computed_hours} h{a.approved_hours != null && ` · ${t('career.pd.approvedHours', { n: a.approved_hours })}`}</div>{a.manager_note && <div className="text-xs text-amber-700">{a.manager_note}</div>}</div>
          <Badge color={tone(a.status)}>{t(`career.pd.status.${a.status}`)}</Badge>{['draft', 'returned'].includes(a.status) && <Button size="sm" variant="gold" onClick={() => void submit(a.id)}>{t('career.pd.submit')}</Button>}</Card>))}
      {open && <Wizard onClose={(c) => { setOpen(false); if (c) { void list.refetch() } }} />}
    </div>
  )
}

function Wizard({ onClose }: { onClose: (changed?: boolean) => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const types = useGet<{ data: any[] }>('/me/pd-activity-types')
  const [f, setF] = useState<any>({ type_id: '', title: '', provider: '', domain: '', starts_on: new Date().toISOString().slice(0, 10), duration_hours: 4, participation_level: 'attendee', location: '', recognition_request: false })
  const [ev, setEv] = useState<Evidence>(noEvidence())
  const [hours, setHours] = useState<number | null>(null)
  const typeId = f.type_id || types.data?.data[0]?.id
  const preview = async (next: any) => { if (!(next.type_id || typeId)) return; try { const { data } = await api.post('/me/pd-activities/preview', { type_id: next.type_id || typeId, participation_level: next.participation_level, duration_hours: Number(next.duration_hours) || 0 }); setHours(data.data.hours) } catch { setHours(null) } }
  const set = (patch: any) => { const n = { ...f, ...patch }; setF(n); void preview(n) }
  const save = async () => {
    try {
      const fields: [string, string][] = [['type_id', typeId], ['title', f.title], ['starts_on', f.starts_on], ['duration_hours', String(f.duration_hours)], ['participation_level', f.participation_level], ['recognition_request', f.recognition_request ? '1' : '0']]
      ;(['provider', 'domain', 'location'] as const).forEach((k) => f[k] && fields.push([k, f[k]]))
      await api.post('/me/pd-activities', toForm(ev, fields)); toast(t('career.pd.saved')); onClose(true)
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <Modal wide open onClose={() => onClose()} title={t('career.pd.log')}>
      <div className="grid gap-3 sm:grid-cols-2">
        <Field label={t('career.pd.type')}><select className={input} value={typeId ?? ''} onChange={(e) => set({ type_id: e.target.value })}>{(types.data?.data ?? []).map((x) => <option key={x.id} value={x.id}>{ar ? x.name_ar : x.name_en}</option>)}</select></Field>
        <Field label={t('career.pd.level')}><select className={input} value={f.participation_level} onChange={(e) => set({ participation_level: e.target.value })}>{['attendee', 'presenter', 'organiser', 'author'].map((l) => <option key={l} value={l}>{t(`career.pd.levels.${l}`)}</option>)}</select></Field>
        <Field label={t('career.pd.activityTitle')} className="sm:col-span-2"><input className={input} value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} /></Field>
        <Field label={t('career.pd.provider')}><input className={input} value={f.provider} onChange={(e) => setF({ ...f, provider: e.target.value })} /></Field>
        <Field label={t('career.pd.domain')}><input className={input} value={f.domain} onChange={(e) => setF({ ...f, domain: e.target.value })} /></Field>
        <Field label={t('career.pd.start')}><input className={input} type="date" value={f.starts_on} onChange={(e) => setF({ ...f, starts_on: e.target.value })} /></Field>
        <Field label={t('career.pd.hours')}><input className={input} type="number" min="0.5" step="0.5" value={f.duration_hours} onChange={(e) => set({ duration_hours: e.target.value })} /></Field>
        <Field label={t('career.pd.location')} className="sm:col-span-2"><input className={input} value={f.location} onChange={(e) => setF({ ...f, location: e.target.value })} /></Field>
      </div>
      {hours !== null && <p className="mt-3 rounded-xl bg-gold-50 p-3 text-sm font-bold text-gold-900">{t('career.pd.preview', { n: hours })}</p>}
      <div className="mt-3"><span className="label">{t('career.pd.evidence')}</span><EvidenceInput max={5} value={ev} onChange={setEv} /></div>
      <label className="mt-3 flex items-center gap-2 text-sm"><input type="checkbox" checked={f.recognition_request} onChange={(e) => setF({ ...f, recognition_request: e.target.checked })} />{t('career.pd.recognition')}</label>
      <div className="mt-4"><Button variant="gold" disabled={!f.title || !typeId} onClick={() => void save()}>{t('career.pd.save')}</Button></div>
    </Modal>
  )
}

function Transfers() {
  const { t } = useTranslation()
  const list = useGet<{ data: any[] }>('/me/knowledge-transfers', undefined, { staleTime: 0 })
  const [cur, setCur] = useState<any | null>(null)
  return (
    <div className="space-y-3">
      {list.isLoading ? <Spinner /> : !list.data?.data.length ? <Card><Empty text={t('career.kt.empty')} /></Card> : list.data.data.map((k) => (
        <Card key={k.id} className="flex flex-wrap items-center gap-3"><div className="flex-1"><div className="font-bold text-navy-900">{k.program}</div><div className="text-xs text-slate-500">{t('career.kt.due')}: {fmt.date(k.due_on)}{k.note && ` · ${k.note}`}</div></div><Badge color={tone(k.status)}>{t(`career.kt.status.${k.status}`)}</Badge>{['pending', 'rejected'].includes(k.status) && <Button size="sm" variant="gold" onClick={() => setCur(k)}>{t('career.kt.send')}</Button>}</Card>))}
      {cur && <TransferForm kt={cur} onClose={(c) => { setCur(null); if (c) void list.refetch() }} />}
    </div>
  )
}

function TransferForm({ kt, onClose }: { kt: any; onClose: (changed?: boolean) => void }) {
  const { t } = useTranslation()
  const [f, setF] = useState<any>({ delivered_on: new Date().toISOString().slice(0, 10), hours: 1, method: 'workshop' })
  const [people, setPeople] = useState<{ employee_id?: string; name?: string }[]>([])
  const [q, setQ] = useState('')
  const [ev, setEv] = useState<Evidence>(noEvidence())
  const found = useGet<{ data: any[] }>('/me/colleagues', { q }, { enabled: q.length >= 2 })
  const send = async () => {
    const fd = toForm(ev, [['delivered_on', f.delivered_on], ['hours', String(f.hours)], ['method', f.method]])
    people.forEach((p, i) => { p.employee_id ? fd.append(`beneficiaries[${i}][employee_id]`, p.employee_id) : fd.append(`beneficiaries[${i}][name]`, p.name ?? ''); if (p.employee_id && p.name) fd.append(`beneficiaries[${i}][name]`, p.name) })
    try { await api.post(`/me/knowledge-transfers/${kt.id}`, fd); toast(t('career.kt.sent')); onClose(true) } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <Modal wide open onClose={() => onClose()} title={`${t('career.kt.title')} — ${kt.program}`}>
      <div className="grid gap-3 sm:grid-cols-3">
        <Field label={t('career.kt.delivered')}><input className={input} type="date" value={f.delivered_on} onChange={(e) => setF({ ...f, delivered_on: e.target.value })} /></Field>
        <Field label={t('career.kt.hours')}><input className={input} type="number" min="0.5" step="0.5" value={f.hours} onChange={(e) => setF({ ...f, hours: e.target.value })} /></Field>
        <Field label={t('career.kt.method')}><select className={input} value={f.method} onChange={(e) => setF({ ...f, method: e.target.value })}>{['workshop', 'meeting', 'coaching', 'online'].map((m) => <option key={m} value={m}>{t(`career.kt.methods.${m}`)}</option>)}</select></Field>
      </div>
      <div className="mt-3 space-y-2"><span className="label">{t('career.kt.beneficiaries')} ({people.length})</span>
        <div className="flex flex-wrap gap-2">{people.map((p, i) => <span key={i} className="inline-flex items-center gap-1 rounded-full bg-ivory px-3 py-1 text-sm">{p.name}<button type="button" aria-label="remove" onClick={() => setPeople(people.filter((_, j) => j !== i))}><X className="size-3.5" /></button></span>)}</div>
        <div className="flex gap-2"><input className={input} placeholder={t('career.kt.search')} value={q} onChange={(e) => setQ(e.target.value)} /><Button size="sm" variant="outline" disabled={q.trim().length < 2} onClick={() => { setPeople([...people, { name: q.trim() }]); setQ('') }}>{t('career.kt.addName')}</Button></div>
        {(found.data?.data ?? []).map((c) => <button key={c.id} type="button" className="block w-full rounded-lg px-3 py-1.5 text-start text-sm hover:bg-ivory" onClick={() => { setPeople([...people, { employee_id: c.id, name: c.name }]); setQ('') }}>{c.name} <span className="text-xs text-slate-400">{c.school}</span></button>)}</div>
      <div className="mt-3"><span className="label">{t('career.kt.evidence')}</span><EvidenceInput max={8} value={ev} onChange={setEv} /></div>
      <div className="mt-4"><Button variant="gold" onClick={() => void send()}>{t('career.kt.send')}</Button></div>
    </Modal>
  )
}

function Team() {
  const { t } = useTranslation()
  const list = useGet<{ data: any[] }>('/me/team/pd-activities', undefined, { staleTime: 0 })
  const decide = async (id: string, decision: string) => {
    const note = decision === 'approve' ? undefined : await dialogs.prompt(t('career.pd.note')) ?? ''
    if (decision !== 'approve' && !note) return
    try { await api.post(`/me/team/pd-activities/${id}/decision`, { decision, note }); toast(t('career.pd.decided')); void list.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  return !list.data ? <Spinner /> : !list.data.data.length ? <Card><Empty text={t('career.pd.empty')} /></Card> : (
    <div className="space-y-3">{list.data.data.map((a) => <Card key={a.id} className="flex flex-wrap items-center gap-3"><div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{a.employee_name}</div><div className="text-sm">{a.title} · {a.computed_hours} h</div></div>
      <Button size="sm" variant="gold" onClick={() => void decide(a.id, 'approve')}>{t('career.pd.approve')}</Button><Button size="sm" variant="outline" onClick={() => void decide(a.id, 'return')}>{t('career.pd.returnIt')}</Button><Button size="sm" variant="ghost" onClick={() => void decide(a.id, 'reject')}>{t('career.pd.reject')}</Button></Card>)}</div>
  )
}
