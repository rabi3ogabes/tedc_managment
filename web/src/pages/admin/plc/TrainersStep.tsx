import { Check, GraduationCap, RefreshCw, Search, Trash2, UserCheck } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { Badge, Button, Card, CardTitle, Empty, ErrorState, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet, useSend } from '@/hooks/useApi'
import { errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { Stepper } from './SchoolsStep'
import { STATE_TONE, type Catalog, type WorkshopDetail } from './plcApi'

type TrainerRow = { trainer_id: string; name: string; name_en?: string | null; title?: string | null; specializations: string[]; external: boolean; organization?: string | null; rating?: number | null }
type Suggested = TrainerRow & { score: number; matched: string[]; available: boolean; assigned_role?: string | null }
type Assignment = TrainerRow & { id: string; role: 'lead' | 'co'; status: 'proposed' | 'approved' | 'rejected' | 'replaced'; hours: number; note?: string | null }

const TONE: Record<Assignment['status'], 'amber' | 'green' | 'red' | 'gray'> = { proposed: 'amber', approved: 'green', rejected: 'red', replaced: 'gray' }

/** Step 4: lead and co-trainers of the workshop, proposed, checked for double booking, confirmed, replaced with a recorded reason. */
export default function TrainersStep() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const en = i18n.language === 'en'
  const detail = useGet<{ data: WorkshopDetail }>(`/admin/plc/workshops/${id}`, undefined, { staleTime: 0 })
  const catalog = useGet<{ data: Catalog }>('/admin/plc/workshops/catalog')
  const current = useGet<{ data: Assignment[] }>(`/admin/plc/workshops/${id}/trainers`, undefined, { staleTime: 0 })
  const [q, setQ] = useState('')
  const suggest = useGet<{ data: Suggested[] }>(`/admin/plc/workshops/${id}/trainers/suggest`, { q: q || undefined }, { staleTime: 0 })
  const [role, setRole] = useState<'lead' | 'co'>('lead')
  const [busy, setBusy] = useState<{ trainer: Suggested; sessions: { title: string; program?: string | null; starts_at: string }[] } | null>(null)
  const [replacing, setReplacing] = useState<Assignment | null>(null)
  const [withdrawing, setWithdrawing] = useState<Assignment | null>(null)
  const [reason, setReason] = useState('')
  const [pick, setPick] = useState('')
  const inv = ['/admin/plc']
  const propose = useSend<Record<string, unknown>, unknown>('post', `/admin/plc/workshops/${id}/trainers`, inv)
  const confirm = useSend<{ a: string }, unknown>('post', (b) => `/admin/plc/workshops/${id}/trainers/${b.a}/confirm`, inv)
  const replace = useSend<{ a: string; trainer_id: string; reason: string }, unknown>('post', (b) => `/admin/plc/workshops/${id}/trainers/${b.a}/replace`, inv)
  const withdraw = useSend<{ a: string; reason?: string }, unknown>('delete', (b) => `/admin/plc/workshops/${id}/trainers/${b.a}`, inv)

  if (detail.isLoading || current.isLoading || catalog.isLoading) return <Spinner />
  if (detail.error || !detail.data) return <ErrorState error={detail.error} onRetry={() => detail.refetch()} home="/admin/communities?tab=workshops" />
  const w = detail.data.data
  const can = !!catalog.data?.data.can.manage
  const locked = ['rejected', 'cancelled', 'closed', 'archived'].includes(w.state)
  const refresh = () => Promise.all([current.refetch(), suggest.refetch()])
  const name = (r: TrainerRow) => (en && r.name_en ? r.name_en : r.name)
  const active = (current.data?.data ?? []).filter((a) => a.status === 'proposed' || a.status === 'approved')
  const history = (current.data?.data ?? []).filter((a) => a.status === 'rejected' || a.status === 'replaced')

  const doPropose = async (tr: Suggested, force = false, forceReason?: string) => {
    try {
      await propose.mutateAsync({ trainer_id: tr.trainer_id, role, force, force_reason: forceReason })
      toast(String(t('plc.trainers.proposed'))); setBusy(null); setReason(''); await refresh()
    } catch (e: unknown) {
      const err = e as { response?: { data?: { code?: string; details?: { sessions?: { title: string; program?: string | null; starts_at: string }[] } } } }
      if (err.response?.data?.code === 'plc_trainer_busy' && can) setBusy({ trainer: tr, sessions: err.response.data.details?.sessions ?? [] })
      else toast(errorMessage(e), 'error')
    }
  }
  const act = async (fn: () => Promise<unknown>, msg: string) => { try { await fn(); toast(msg); await refresh() } catch (e) { toast(errorMessage(e), 'error') } }

  return (
    <>
      <PageHeader title={<span className="flex flex-wrap items-center gap-3">{en && w.title_en ? w.title_en : w.title_ar}<Badge color={STATE_TONE[w.state]}>{t(`plc.state.${w.state}`)}</Badge></span>} subtitle={t('plc.trainers.subtitle')}
        actions={<Button variant="outline" to={`/admin/communities/workshops/${id}`}>{t('plc.schools.backToWorkshop')}</Button>} />
      <Stepper current={3} id={id!} />
      {locked && <p className="mb-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">{t('plc.schools.readOnly', { state: t(`plc.state.${w.state}`) })}</p>}
      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-5 lg:col-span-2">
          <Card>
            <CardTitle subtitle={t('plc.trainers.suggestHint')} action={can && !locked ? <div className="flex gap-1">{(['lead', 'co'] as const).map((r) => <button key={r} type="button" className={`rounded-full border px-3 py-1 text-xs font-semibold ${role === r ? 'border-navy-900 bg-navy-900 text-white' : 'border-slate-200 text-slate-600'}`} onClick={() => setRole(r)}>{t(`plc.trainers.role.${r}`)}</button>)}</div> : undefined}>{t('plc.trainers.suggestions')}</CardTitle>
            <div className="relative mb-3"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('plc.trainers.search'))} /></div>
            {suggest.isLoading ? <Spinner /> : !suggest.data?.data.length ? <Empty text={String(t('plc.trainers.none'))} icon={<GraduationCap className="size-6" />} /> : (
              <ul className="divide-y divide-slate-100">
                {suggest.data.data.map((tr) => (
                  <li key={tr.trainer_id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2"><span className="font-semibold text-navy-900">{name(tr)}</span>{tr.external && <Badge color="gray">{t('plc.trainers.external')}</Badge>}{!tr.available && <Badge color="amber">{t('plc.trainers.busy')}</Badge>}{tr.assigned_role && <Badge color="green">{t(`plc.trainers.role.${tr.assigned_role === 'assistant' ? 'co' : 'lead'}`)}</Badge>}</div>
                      <div className="mt-1 flex flex-wrap gap-1 text-[11px]">{tr.specializations.map((s) => <span key={s} className={`rounded-full px-2 py-0.5 font-semibold ${tr.matched.includes(s.toLowerCase()) ? 'bg-gold-100 text-gold-800' : 'bg-slate-100 text-slate-600'}`}>{s}</span>)}{tr.rating != null && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600">★ {tr.rating}</span>}</div>
                    </div>
                    {!tr.assigned_role && can && !locked && <Button size="sm" variant="outline" onClick={() => doPropose(tr)}>{t('plc.trainers.propose')}</Button>}
                  </li>
                ))}
              </ul>
            )}
          </Card>
          {!!history.length && (
            <Card><CardTitle>{t('plc.trainers.history')}</CardTitle>
              <ul className="divide-y divide-slate-100 text-sm">{history.map((a) => <li key={a.id} className="flex items-center justify-between py-2"><span>{name(a)} · {t(`plc.trainers.role.${a.role}`)}</span><span className="text-xs text-slate-500"><Badge color={TONE[a.status]}>{t(`plc.trainers.status.${a.status}`)}</Badge>{a.note ? ` ${a.note}` : ''}</span></li>)}</ul>
            </Card>
          )}
        </div>
        <aside className="space-y-4">
          <Card>
            <CardTitle subtitle={t('plc.trainers.currentHint')}>{t('plc.trainers.current')} <Badge color="navy">{active.length}</Badge></CardTitle>
            {!active.length ? <p className="text-sm text-slate-500">{t('plc.trainers.noCurrent')}</p> : (
              <ul className="space-y-2">
                {active.map((a) => (
                  <li key={a.id} className="rounded-xl border border-slate-200 px-3 py-2">
                    <div className="flex items-start justify-between gap-2">
                      <div className="min-w-0"><div className="truncate font-semibold text-navy-900">{name(a)}</div><div className="text-xs text-slate-500">{t(`plc.trainers.role.${a.role}`)} · {a.hours} {t('plc.trainers.hours')}</div></div>
                      <Badge color={TONE[a.status]}>{t(`plc.trainers.status.${a.status}`)}</Badge>
                    </div>
                    {can && !locked && (
                      <div className="mt-2 flex flex-wrap gap-1">
                        {a.status === 'proposed' && <Button size="sm" variant="primary" icon={<UserCheck className="size-3.5" />} onClick={() => act(() => confirm.mutateAsync({ a: a.id }), String(t('plc.trainers.confirmed')))}>{t('plc.trainers.confirm')}</Button>}
                        <Button size="sm" variant="outline" icon={<RefreshCw className="size-3.5" />} onClick={() => { setReplacing(a); setPick(''); setReason('') }}>{t('plc.trainers.replace')}</Button>
                        <Button size="sm" variant="ghost" icon={<Trash2 className="size-3.5" />} onClick={() => { if (a.status === 'proposed') act(() => withdraw.mutateAsync({ a: a.id }), String(t('plc.trainers.withdrawn'))); else { setWithdrawing(a); setReason('') } }}>{t('plc.trainers.withdraw')}</Button>
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>
          <div className="flex flex-col gap-2"><Button variant="gold" to={`/admin/communities/workshops/${id}`}>{t('plc.schools.done')}</Button></div>
          <p className="text-xs text-slate-400">{t('plc.trainers.notConfirmed')}</p>
        </aside>
      </div>

      <Modal open={!!busy} onClose={() => setBusy(null)} title={t('plc.trainers.busyTitle')}>
        <p className="mb-2 text-sm text-slate-600">{t('plc.trainers.busyHint', { name: busy ? name(busy.trainer) : '' })}</p>
        <ul className="mb-3 list-disc ps-5 text-sm text-slate-700">{busy?.sessions.map((s, i) => <li key={i}>{s.program ? `${s.program} — ` : ''}{s.title} · {new Date(s.starts_at).toLocaleString(en ? 'en-GB' : 'ar-QA')}</li>)}</ul>
        <Field label={t('plc.detail.reason')}><textarea className="input min-h-20" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} /></Field>
        <div className="mt-4 flex justify-end gap-2"><Button variant="outline" onClick={() => setBusy(null)}>{t('common.cancel')}</Button><Button variant="gold" disabled={!reason.trim()} loading={propose.isPending} onClick={() => busy && doPropose(busy.trainer, true, reason)}>{t('plc.detail.confirm')}</Button></div>
      </Modal>
      <Modal open={!!replacing} onClose={() => setReplacing(null)} title={replacing ? `${t('plc.trainers.replace')} — ${name(replacing)}` : ''}>
        <Field label={t('plc.trainers.pickReplacement')}>
          <select className="input" value={pick} onChange={(e) => setPick(e.target.value)}><option value="">—</option>{(suggest.data?.data ?? []).filter((x) => !x.assigned_role).map((x) => <option key={x.trainer_id} value={x.trainer_id}>{name(x)}{x.available ? '' : ` (${t('plc.trainers.busy')})`}</option>)}</select>
        </Field>
        <Field label={t('plc.detail.reason')} hint={t('plc.trainers.replaceHint')} className="mt-3"><textarea className="input min-h-20" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} /></Field>
        <div className="mt-4 flex justify-end gap-2"><Button variant="outline" onClick={() => setReplacing(null)}>{t('common.cancel')}</Button><Button variant="gold" disabled={!pick || !reason.trim()} loading={replace.isPending} icon={<Check className="size-4" />} onClick={() => replacing && act(() => replace.mutateAsync({ a: replacing.id, trainer_id: pick, reason }), String(t('plc.trainers.replaced'))).then(() => setReplacing(null))}>{t('plc.detail.confirm')}</Button></div>
      </Modal>
      <Modal open={!!withdrawing} onClose={() => setWithdrawing(null)} title={withdrawing ? `${t('plc.trainers.withdraw')} — ${name(withdrawing)}` : ''}>
        <Field label={t('plc.detail.reason')} hint={t('plc.detail.reasonHint')}><textarea className="input min-h-20" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} /></Field>
        <div className="mt-4 flex justify-end gap-2"><Button variant="outline" onClick={() => setWithdrawing(null)}>{t('common.cancel')}</Button><Button variant="danger" disabled={!reason.trim()} loading={withdraw.isPending} onClick={() => withdrawing && act(() => withdraw.mutateAsync({ a: withdrawing.id, reason }), String(t('plc.trainers.withdrawn'))).then(() => setWithdrawing(null))}>{t('plc.detail.confirm')}</Button></div>
      </Modal>
    </>
  )
}
