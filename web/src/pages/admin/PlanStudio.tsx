import clsx from 'clsx'
import { CheckCheck, FileSpreadsheet, FileText, Plus, Sparkles, Undo2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Progress, Spinner, StatCard, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Rules = { weights: { severity: number; headcount: number; breadth: number }; max_seats_per_group: number; default_hours_per_group: number; min_fill_percent: number; carry_over: boolean }
type Item = { id: string; title_ar: string; title_en: string; priority: string; priority_score: number; planned_groups: number; planned_seats: number; planned_hours: number; window_start: string | null; window_end: string | null; source: string; status: string; rationale_ar: string | null; rationale_en: string | null }
type Plan = { id: string; year: number; version: number; title_ar: string; title_en: string; status: string; items_count: number; rules: Rules; items?: Item[] }
type Exec = { totals: { execution_percent: number; changed_percent: number; emergency_percent: number; planned_groups: number; executed_groups: number; created_groups: number; deviations: number }; items: { id: string; title_ar: string; title_en: string; planned_groups: number; created_groups: number; executed_groups: number; percent: number }[]; deviations: { type: string; item_id: string | null; title_ar: string; title_en: string; message: { ar: string; en: string } }[] }
type Change = { id: string; change_type: string; reason: string | null; changed_by: string | null; created_at: string; item_id: string | null }
type Tab = 'items' | 'rules' | 'execution' | 'changes'
const TONE: Record<string, 'slate' | 'gold' | 'green' | 'navy'> = { draft: 'slate', in_review: 'gold', approved: 'green', active: 'navy', closed: 'slate' }
const PRIORITY_TONE: Record<string, 'red' | 'gold' | 'navy' | 'slate'> = { critical: 'red', high: 'gold', medium: 'navy', low: 'slate' }

/** The annual training plan: generate from needs, review, approve, execute and monitor. */
export default function PlanStudio() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { can } = useAuth()
  const plans = useGet<{ data: Plan[] }>('/admin/plans', undefined, { staleTime: 0 })
  const [selected, setSelected] = useState<string | null>(null)
  const [creating, setCreating] = useState(false)
  const [form, setForm] = useState({ year: new Date().getFullYear() + 1, title_ar: '', title_en: '' })
  const [busy, setBusy] = useState(false)
  const id = selected ?? plans.data?.data[0]?.id ?? null

  const create = async () => {
    setBusy(true)
    try { const { data } = await api.post('/admin/plans', form); toast(t('groups.saved')); setCreating(false); setSelected(data.data.id); await plans.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  if (plans.isLoading || !plans.data) return <Spinner />
  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('plans.title')} subtitle={t('plans.subtitle')} actions={can('plans.manage') && <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setCreating(true)}>{t('plans.new')}</Button>} />
      {plans.data.data.length === 0 ? <Card><Empty text={t('plans.empty')} /></Card> : (
        <>
          <div className="flex flex-wrap gap-2">{plans.data.data.map((p) => <button key={p.id} type="button" aria-pressed={p.id === id} onClick={() => setSelected(p.id)} className={clsx('flex items-center gap-2 rounded-xl border px-4 py-2 text-sm font-bold', p.id === id ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-navy-900')}>{p.year}{p.version > 1 && <span className="text-xs opacity-70">v{p.version}</span>}<Badge color={TONE[p.status] ?? 'navy'}>{t(`plans.status.${p.status}`)}</Badge></button>)}</div>
          {id && <PlanView key={id} id={id} ar={ar} onChanged={() => void plans.refetch()} />}
        </>
      )}
      <Modal open={creating} onClose={() => setCreating(false)} title={t('plans.new')}>
        <div className="space-y-4">
          <Field label={t('plans.year')}><input type="number" className="input" value={form.year} onChange={(e) => setForm({ ...form, year: Number(e.target.value) })} /></Field>
          <Field label={t('plans.titleAr')}><input className="input" value={form.title_ar} onChange={(e) => setForm({ ...form, title_ar: e.target.value })} /></Field>
          <Field label={t('plans.titleEn')}><input className="input" dir="ltr" value={form.title_en} onChange={(e) => setForm({ ...form, title_en: e.target.value })} /></Field>
          <Button variant="gold" loading={busy} disabled={!form.title_ar.trim() || !form.title_en.trim()} onClick={() => void create()}>{t('plans.create')}</Button>
        </div>
      </Modal>
    </div>
  )
}

function PlanView({ id, ar, onChanged }: { id: string; ar: boolean; onChanged: () => void }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const plan = useGet<{ data: Plan }>(`/admin/plans/${id}`, undefined, { staleTime: 0 })
  const exec = useGet<{ data: Exec }>(`/admin/plans/${id}/execution`, undefined, { staleTime: 0 })
  const changes = useGet<{ data: Change[] }>(`/admin/plans/${id}/changes`, undefined, { staleTime: 0 })
  const [tab, setTab] = useState<Tab>('items')
  const [comment, setComment] = useState<{ open: boolean; text: string }>({ open: false, text: '' })
  const [reasonFor, setReasonFor] = useState<{ item: Item; patch: Partial<Item> } | null>(null)
  const [reason, setReason] = useState('')

  const refresh = async () => { await Promise.all([plan.refetch(), exec.refetch(), changes.refetch()]); onChanged() }
  const run = async (fn: () => Promise<unknown>, ok?: string) => { try { await fn(); if (ok) toast(ok); await refresh() } catch (e) { toast(errorMessage(e), 'error') } }
  if (plan.isLoading || !plan.data) return <Spinner />
  const p = plan.data.data
  const baselined = ['approved', 'active', 'closed'].includes(p.status)
  const base = `/admin/plans/${id}`

  const patchItem = (item: Item, patch: Partial<Item>) => {
    if (baselined) { setReasonFor({ item, patch }); setReason(''); return }
    void run(() => api.put(`${base}/items/${item.id}`, patch))
  }
  const confirmReason = async () => { if (!reasonFor) return; await run(() => api.put(`${base}/items/${reasonFor.item.id}`, { ...reasonFor.patch, reason })); setReasonFor(null) }

  return (
    <div className="space-y-5">
      <Card className="flex flex-wrap items-center gap-3">
        <div className="min-w-0 flex-1"><h2 className="truncate text-lg font-bold text-navy-900">{ar ? p.title_ar : p.title_en}</h2><div className="mt-1 flex items-center gap-2 text-sm text-slate-500"><Badge color={TONE[p.status] ?? 'navy'}>{t(`plans.status.${p.status}`)}</Badge><span>{p.year} · {t('plans.version')} {p.version}</span></div></div>
        <div className="flex flex-wrap gap-2">
          {p.status === 'draft' && can('plans.manage') && <><Button variant="outline" icon={<Sparkles className="size-4" />} onClick={() => void run(async () => { const { data } = await api.post(`${base}/generate`); toast(t('plans.generated', { added: data.data.added, carried: data.data.carried_over })) })}>{t('plans.generate')}</Button><Button variant="gold" onClick={() => void run(() => api.post(`${base}/submit`), t('plans.done'))}>{t('plans.submit')}</Button></>}
          {p.status === 'in_review' && can('plans.approve') && <><Button variant="outline" icon={<Undo2 className="size-4" />} onClick={() => setComment({ open: true, text: '' })}>{t('plans.return')}</Button><Button variant="gold" icon={<CheckCheck className="size-4" />} onClick={() => void run(() => api.post(`${base}/approve`), t('plans.done'))}>{t('plans.approve')}</Button></>}
          {p.status === 'approved' && can('plans.approve') && <Button variant="gold" onClick={() => void run(() => api.post(`${base}/activate`), t('plans.done'))}>{t('plans.activate')}</Button>}
          {(p.status === 'active' || p.status === 'approved') && can('plans.approve') && <Button variant="outline" onClick={() => void run(() => api.post(`${base}/close`), t('plans.done'))}>{t('plans.close')}</Button>}
          <Button variant="outline" icon={<FileSpreadsheet className="size-4" />} onClick={() => downloadFile(`${base}/export?format=xlsx`, `plan-${p.year}.xlsx`)}>{t('plans.exportXlsx')}</Button>
          <Button variant="outline" icon={<FileText className="size-4" />} onClick={() => downloadFile(`${base}/export?format=pdf`, `plan-${p.year}.pdf`)}>{t('plans.exportPdf')}</Button>
        </div>
      </Card>

      {exec.data && baselined && (
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
          <StatCard label={t('plans.exec.execution')} value={fmt.percent(exec.data.data.totals.execution_percent, 1)} hint={`${exec.data.data.totals.executed_groups}/${exec.data.data.totals.planned_groups}`} accent />
          <StatCard label={t('plans.exec.changed')} value={fmt.percent(exec.data.data.totals.changed_percent, 1)} />
          <StatCard label={t('plans.exec.emergency')} value={fmt.percent(exec.data.data.totals.emergency_percent, 1)} />
          <StatCard label={t('plans.exec.deviations')} value={fmt.number(exec.data.data.totals.deviations)} />
        </div>
      )}

      <Tabs<Tab> value={tab} onChange={setTab} tabs={[{ id: 'items', label: t('plans.tabs.items') }, { id: 'rules', label: t('plans.tabs.rules') }, ...(baselined ? [{ id: 'execution' as const, label: t('plans.tabs.execution') }, { id: 'changes' as const, label: t('plans.tabs.changes') }] : [])]} />

      {tab === 'items' && (
        <Card padded={false}>
          {(p.items ?? []).length === 0 ? <Empty text={t('plans.empty')} /> : (
            <div className="overflow-x-auto"><table className="w-full text-sm"><thead className="bg-ivory text-xs text-slate-500"><tr>{['', t('plans.priority.high'), t('plans.score'), t('plans.groups'), t('plans.seats'), t('plans.hours'), t('plans.window')].map((h, i) => <th key={i} className="px-4 py-3 text-start font-bold">{i === 1 ? '' : h}</th>)}</tr></thead>
              <tbody>{p.items!.map((i) => (
                <tr key={i.id} className="border-t border-navy-50 align-top">
                  <td className="px-4 py-3"><div className="font-bold text-navy-900">{ar ? i.title_ar : i.title_en}</div><div className="mt-0.5 max-w-md text-xs text-slate-500" title={t('plans.why')}>{ar ? i.rationale_ar : i.rationale_en}</div><Badge color="slate" className="mt-1">{t(`plans.source.${i.source}`)}</Badge></td>
                  <td className="px-4 py-3"><Badge color={PRIORITY_TONE[i.priority] ?? 'navy'}>{t(`plans.priority.${i.priority}`)}</Badge></td>
                  <td className="px-4 py-3 tabular-nums font-bold">{fmt.number(i.priority_score, 1)}</td>
                  <td className="px-4 py-3"><input type="number" min={1} className="input w-20" defaultValue={i.planned_groups} disabled={p.status === 'in_review' || p.status === 'closed' || !can('plans.manage')} onBlur={(e) => { const v = Number(e.target.value); if (v && v !== i.planned_groups) patchItem(i, { planned_groups: v }) }} /></td>
                  <td className="px-4 py-3 tabular-nums">{fmt.number(i.planned_seats)}</td><td className="px-4 py-3 tabular-nums">{fmt.number(i.planned_hours, 1)}</td>
                  <td className="px-4 py-3 text-xs text-slate-500">{fmt.date(i.window_start, { month: 'short', day: 'numeric' })} – {fmt.date(i.window_end, { month: 'short', day: 'numeric' })}</td>
                </tr>))}</tbody></table></div>
          )}
        </Card>
      )}

      {tab === 'rules' && <RulesPanel plan={p} editable={can('plans.manage') && p.status === 'draft'} onSaved={refresh} />}

      {tab === 'execution' && exec.data && (
        <div className="grid gap-4 lg:grid-cols-2">
          <Card className="space-y-3">{exec.data.data.items.map((r) => <div key={r.id} className="space-y-1"><div className="flex justify-between text-sm"><span className="font-semibold text-navy-900">{ar ? r.title_ar : r.title_en}</span><span className="tabular-nums text-slate-500">{r.executed_groups}/{r.planned_groups}</span></div><Progress value={r.percent} tone={r.percent >= 100 ? 'green' : 'gold'} /></div>)}</Card>
          <Card className="space-y-2"><h3 className="font-bold text-navy-900">{t('plans.exec.deviations')}</h3>{exec.data.data.deviations.length === 0 ? <p className="text-sm text-slate-500">{t('plans.exec.none')}</p> : exec.data.data.deviations.map((d, idx) => <div key={idx} className="rounded-xl bg-amber-50 p-3 text-sm"><Badge color="gold">{t(`plans.exec.${d.type}`)}</Badge> <span className="font-semibold">{ar ? d.title_ar : d.title_en}</span><div className="text-xs text-slate-500">{ar ? d.message.ar : d.message.en}</div></div>)}</Card>
        </div>
      )}

      {tab === 'changes' && (
        <Card>{!changes.data || changes.data.data.length === 0 ? <p className="text-sm text-slate-500">{t('plans.noChanges')}</p> : <ul className="space-y-3">{changes.data.data.map((c) => <li key={c.id} className="flex flex-wrap items-center gap-3 border-b border-navy-50 pb-3 text-sm last:border-0"><Badge color="navy">{t(`plans.changeTypes.${c.change_type}`)}</Badge><span className="flex-1 text-slate-700">{c.reason}</span><span className="text-xs text-slate-400">{c.changed_by} · {fmt.dateTime(c.created_at)}</span></li>)}</ul>}<p className="mt-3 text-xs text-slate-400">{t('plans.baselineNote')}</p></Card>
      )}

      <Modal open={comment.open} onClose={() => setComment({ open: false, text: '' })} title={t('plans.return')}>
        <div className="space-y-4"><Field label={t('plans.comment')}><textarea className="input min-h-24" value={comment.text} onChange={(e) => setComment({ ...comment, text: e.target.value })} /></Field><Button variant="gold" onClick={() => void run(async () => { await api.post(`${base}/return`, { comment: comment.text }); setComment({ open: false, text: '' }) }, t('plans.done'))}>{t('plans.return')}</Button></div>
      </Modal>
      <Modal open={!!reasonFor} onClose={() => setReasonFor(null)} title={t('plans.reasonPrompt')}>
        <div className="space-y-4"><textarea className="input min-h-24" value={reason} onChange={(e) => setReason(e.target.value)} /><Button variant="gold" disabled={!reason.trim()} onClick={() => void confirmReason()}>{t('groups.save')}</Button></div>
      </Modal>
    </div>
  )
}

function RulesPanel({ plan, editable, onSaved }: { plan: Plan; editable: boolean; onSaved: () => Promise<void> }) {
  const { t } = useTranslation()
  const [r, setR] = useState<Rules>(plan.rules)
  const [busy, setBusy] = useState(false)
  const save = async () => {
    setBusy(true)
    try { await api.put(`/admin/plans/${plan.id}/rules`, { rules: r }); toast(t('plans.rules.saved')); await onSaved() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const num = (v: string) => Number(v) || 0
  return (
    <Card className="space-y-5">
      <div><h3 className="font-bold text-navy-900">{t('plans.rules.weights')}</h3>
        <div className="mt-3 grid gap-4 sm:grid-cols-3">{(['severity', 'headcount', 'breadth'] as const).map((k) => <Field key={k} label={`${t(`plans.rules.${k}`)} (${r.weights[k]})`}><input type="range" min={0} max={100} disabled={!editable} value={r.weights[k]} onChange={(e) => setR({ ...r, weights: { ...r.weights, [k]: num(e.target.value) } })} className="w-full accent-gold-500" /></Field>)}</div></div>
      <div className="grid gap-4 sm:grid-cols-3">
        <Field label={t('plans.rules.maxSeats')}><input type="number" min={1} className="input" disabled={!editable} value={r.max_seats_per_group} onChange={(e) => setR({ ...r, max_seats_per_group: num(e.target.value) })} /></Field>
        <Field label={t('plans.rules.defaultHours')}><input type="number" min={1} className="input" disabled={!editable} value={r.default_hours_per_group} onChange={(e) => setR({ ...r, default_hours_per_group: num(e.target.value) })} /></Field>
        <Field label={t('plans.rules.minFill')}><input type="number" min={0} max={100} className="input" disabled={!editable} value={r.min_fill_percent} onChange={(e) => setR({ ...r, min_fill_percent: num(e.target.value) })} /></Field>
      </div>
      <label className="flex items-center gap-2 text-sm font-semibold text-navy-900"><input type="checkbox" disabled={!editable} checked={r.carry_over} onChange={(e) => setR({ ...r, carry_over: e.target.checked })} />{t('plans.rules.carryOver')}</label>
      {editable && <Button variant="gold" loading={busy} onClick={() => void save()}>{t('groups.save')}</Button>}
    </Card>
  )
}
