import { AlertTriangle, Check, Search, UserPlus, Users } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { Badge, Button, Card, CardTitle, Empty, ErrorState, Field, Modal, PageHeader, Progress, Spinner } from '@/components/ui'
import { useGet, useSend } from '@/hooks/useApi'
import { errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { Stepper } from './SchoolsStep'
import { STATE_TONE, type Catalog, type WorkshopDetail } from './plcApi'

type Clash = { program_id: string; program: string; program_en?: string | null; start_date?: string | null; end_date?: string | null; status: string }
type Person = { employee_id: string; employee_no?: string | null; name?: string | null; name_en?: string | null; email?: string | null; job_title?: string | null; job_title_en?: string | null; specialization?: string | null; school_id: string; school?: string | null; school_en?: string | null; nomination?: string | null; clashes: Clash[] }
type NominationStatus = 'nominated' | 'approved' | 'waitlisted' | 'rejected' | 'declined' | 'withdrawn' | 'completed'
type Nomination = Person & { id: string; status: NominationStatus; source: string; note?: string | null; reason?: string | null; registration?: { id: string; status: string } | null; transitions: NominationStatus[] }
type Nominations = { capacity: number; seated: number; waitlisted: number; by_status: Record<string, number>; schools: { school_id: string; school?: string | null; school_en?: string | null; seats: number | null; seated: number; waitlisted: number }[]; rows: Nomination[]; reason_required: NominationStatus[] }

const TONE: Record<NominationStatus, 'gray' | 'green' | 'blue' | 'red' | 'amber' | 'navy'> = { nominated: 'amber', approved: 'green', waitlisted: 'blue', rejected: 'red', declined: 'gray', withdrawn: 'gray', completed: 'navy' }

/** Step 3: nominate trainees from the participating schools, with seats, the waiting list, clashes and explicit states. */
export default function TraineesStep() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const en = i18n.language === 'en'
  const detail = useGet<{ data: WorkshopDetail }>(`/admin/plc/workshops/${id}`, undefined, { staleTime: 0 })
  const catalog = useGet<{ data: Catalog }>('/admin/plc/workshops/catalog')
  const noms = useGet<{ data: Nominations }>(`/admin/plc/workshops/${id}/nominations`, undefined, { staleTime: 0 })
  const [school, setSchool] = useState('')
  const [q, setQ] = useState('')
  const eligible = useGet<{ data: Person[] }>(`/admin/plc/workshops/${id}/people/eligible`, { school_id: school || undefined, q: q || undefined }, { staleTime: 0 })
  const [picked, setPicked] = useState<Set<string>>(new Set())
  const [override, setOverride] = useState<{ ids: string[]; names: string[] } | null>(null)
  const [reason, setReason] = useState('')
  const [decision, setDecision] = useState<{ n: Nomination; to: NominationStatus } | null>(null)
  const inv = ['/admin/plc']
  const nominate = useSend<Record<string, unknown>, { data: { added: Nomination[]; skipped: { employee_id: string; name?: string | null; code: string; message: string }[] } }>('post', `/admin/plc/workshops/${id}/nominations`, inv)
  const decide = useSend<{ nomination: string; to: NominationStatus; reason?: string; force?: boolean }, unknown>('post', (b) => `/admin/plc/workshops/${id}/nominations/${b.nomination}`, inv)

  const refresh = () => Promise.all([noms.refetch(), eligible.refetch()])
  const name = (p: Person) => (en && p.name_en ? p.name_en : p.name) ?? p.employee_no ?? ''
  const rows = useMemo(() => (eligible.data?.data ?? []).filter((p) => !p.nomination || ['rejected', 'declined', 'withdrawn'].includes(p.nomination)), [eligible.data])

  if (detail.isLoading || noms.isLoading || catalog.isLoading) return <Spinner />
  if (detail.error || !detail.data) return <ErrorState error={detail.error} onRetry={() => detail.refetch()} home="/admin/communities?tab=workshops" />
  const w = detail.data.data
  const data = noms.data?.data
  const canNominate = !!catalog.data?.data.can.manage || !!catalog.data?.data.can.internal
  const locked = ['rejected', 'cancelled', 'closed', 'archived', 'completed', 'awaiting_evaluation'].includes(w.state)

  const submit = async (ids: string[], force = false, forceReason?: string) => {
    try {
      const { data: res } = await nominate.mutateAsync({ employee_ids: ids, force, force_reason: forceReason })
      if (res.added.length) toast(String(t('plc.people.nominatedCount', { count: res.added.length })))
      const clashes = res.skipped.filter((s) => s.code === 'plc_trainee_clash')
      const others = res.skipped.filter((s) => s.code !== 'plc_trainee_clash')
      others.forEach((s) => toast(`${s.name ?? ''}: ${s.message}`, 'error'))
      setPicked(new Set())
      await refresh()
      if (clashes.length && !force && catalog.data?.data.can.manage) setOverride({ ids: clashes.map((s) => s.employee_id), names: clashes.map((s) => s.name ?? '') })
      else if (clashes.length) clashes.forEach((s) => toast(`${s.name ?? ''}: ${s.message}`, 'error'))
      setOverride(force ? null : (prev) => prev)
      if (force) { setOverride(null); setReason('') }
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  const runDecision = async () => {
    if (!decision) return
    const needs = data?.reason_required.includes(decision.to)
    if (needs && !reason.trim()) { toast(String(t('plc.detail.reason')), 'error'); return }
    try {
      await decide.mutateAsync({ nomination: decision.n.id, to: decision.to, reason: reason || undefined })
      toast(String(t(`plc.people.decided.${decision.to}`)))
      setDecision(null); setReason(''); await refresh()
    } catch (e) { toast(errorMessage(e), 'error') }
  }

  return (
    <>
      <PageHeader title={<span className="flex flex-wrap items-center gap-3">{en && w.title_en ? w.title_en : w.title_ar}<Badge color={STATE_TONE[w.state]}>{t(`plc.state.${w.state}`)}</Badge></span>} subtitle={t('plc.people.traineesSubtitle')}
        actions={<Button variant="outline" to={`/admin/communities/workshops/${id}`}>{t('plc.schools.backToWorkshop')}</Button>} />
      <Stepper current={2} id={id!} />
      {locked && <p className="mb-4 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-600">{t('plc.schools.readOnly', { state: t(`plc.state.${w.state}`) })}</p>}
      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-5 lg:col-span-2">
          <Card>
            <CardTitle subtitle={t('plc.people.eligibleHint')}>{t('plc.people.eligible')}</CardTitle>
            <div className="mb-3 flex flex-wrap gap-2">
              <div className="relative min-w-52 flex-1"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('plc.people.search'))} /></div>
              <select className="input w-auto" value={school} onChange={(e) => setSchool(e.target.value)}>
                <option value="">{t('plc.people.allSchools')}</option>
                {w.schools.filter((s) => s.status !== 'excluded').map((s) => <option key={s.id} value={s.id}>{en && s.name_en ? s.name_en : s.name}</option>)}
              </select>
              {canNominate && !locked && <Button variant="gold" icon={<UserPlus className="size-4" />} disabled={!picked.size} loading={nominate.isPending} onClick={() => submit([...picked])}>{t('plc.people.nominateSelected', { count: picked.size })}</Button>}
            </div>
            {eligible.isLoading ? <Spinner /> : !rows.length ? <Empty text={String(t('plc.people.noEligible'))} icon={<Users className="size-6" />} /> : (
              <div className="max-h-[28rem] overflow-auto rounded-xl border border-slate-200">
                <table className="w-full text-sm">
                  <thead className="sticky top-0 bg-slate-50 text-xs uppercase tracking-wide text-slate-500"><tr>
                    <th className="w-8 px-2 py-2"><input type="checkbox" aria-label="all" checked={picked.size > 0 && picked.size === rows.length} onChange={(e) => setPicked(e.target.checked ? new Set(rows.map((p) => p.employee_id)) : new Set())} /></th>
                    <th className="px-3 py-2 text-start">{t('plc.people.person')}</th><th className="px-3 py-2 text-start">{t('plc.people.jobTitle')}</th><th className="px-3 py-2 text-start">{t('admin.menu.schools')}</th><th className="px-3 py-2 text-start">{t('plc.people.clash')}</th>
                  </tr></thead>
                  <tbody className="divide-y divide-slate-100">
                    {rows.map((p) => (
                      <tr key={p.employee_id} className={`hover:bg-slate-50 ${picked.has(p.employee_id) ? 'bg-gold-50/60' : ''}`} onClick={() => setPicked((s) => { const n = new Set(s); if (n.has(p.employee_id)) n.delete(p.employee_id); else n.add(p.employee_id); return n })}>
                        <td className="px-2 py-2"><input type="checkbox" checked={picked.has(p.employee_id)} readOnly aria-label={name(p)} /></td>
                        <td className="px-3 py-2"><div className="font-semibold text-navy-900">{name(p)}</div><div className="text-xs text-slate-400" dir="ltr">{p.employee_no}</div></td>
                        <td className="px-3 py-2 text-slate-600">{(en ? p.job_title_en : p.job_title) ?? '—'}{p.specialization ? <span className="text-xs text-slate-400"> · {p.specialization}</span> : null}</td>
                        <td className="px-3 py-2 text-slate-600">{(en ? p.school_en : p.school) ?? ''}</td>
                        <td className="px-3 py-2">{p.clashes.length ? <Badge color="amber"><AlertTriangle className="size-3" /> {t('plc.people.clashWith', { program: en && p.clashes[0].program_en ? p.clashes[0].program_en : p.clashes[0].program })}</Badge> : <span className="text-xs text-slate-400">—</span>}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
          </Card>

          <Card>
            <CardTitle>{t('plc.people.nominations')} <Badge color="navy">{data?.rows.length ?? 0}</Badge></CardTitle>
            {!data?.rows.length ? <p className="text-sm text-slate-500">{t('plc.people.noNominations')}</p> : (
              <ul className="divide-y divide-slate-100">
                {data.rows.map((n) => (
                  <li key={n.id} className="flex flex-wrap items-center justify-between gap-3 py-2.5">
                    <div className="min-w-0">
                      <div className="flex flex-wrap items-center gap-2"><span className="font-semibold text-navy-900">{name(n)}</span><Badge color={TONE[n.status]}>{t(`plc.people.status.${n.status}`)}</Badge>{n.registration && <Badge color="gray">{t('plc.people.registered')}</Badge>}</div>
                      <div className="text-xs text-slate-500">{(en ? n.school_en : n.school) ?? ''}{n.job_title ? ` · ${en ? n.job_title_en : n.job_title}` : ''}{n.reason ? ` · ${n.reason}` : ''}</div>
                    </div>
                    {canNominate && !locked && (
                      <div className="flex flex-wrap gap-1">
                        {n.transitions.filter((x) => x !== 'completed').map((x) => <Button key={x} size="sm" variant={x === 'approved' ? 'primary' : 'outline'} onClick={() => { setDecision({ n, to: x }); setReason('') }}>{t(`plc.people.actions.${x}`)}</Button>)}
                      </div>
                    )}
                  </li>
                ))}
              </ul>
            )}
          </Card>
        </div>
        <aside className="space-y-4">
          <Card>
            <CardTitle subtitle={t('plc.people.seatsHint')}>{t('plc.people.seats')}</CardTitle>
            {data && <>
              <div className="flex items-baseline justify-between text-sm"><span>{t('plc.people.capacity')}</span><b className="tabular-nums">{data.seated} / {data.capacity}</b></div>
              <Progress value={data.capacity ? Math.min(100, (data.seated / data.capacity) * 100) : 0} tone="gold" className="mt-1" />
              <div className="mt-1 text-xs text-slate-500">{t('plc.people.waiting', { count: data.waitlisted })}</div>
              <ul className="mt-3 space-y-2 text-sm">
                {data.schools.map((s) => (
                  <li key={s.school_id}><div className="flex items-baseline justify-between"><span className="truncate">{en && s.school_en ? s.school_en : s.school}</span><b className="tabular-nums text-xs">{s.seated}{s.seats != null ? ` / ${s.seats}` : ''}{s.waitlisted ? ` (+${s.waitlisted})` : ''}</b></div>{s.seats != null && <Progress value={Math.min(100, (s.seated / s.seats) * 100)} tone="navy" className="mt-0.5" />}</li>
                ))}
              </ul>
            </>}
          </Card>
          <div className="flex flex-col gap-2"><Button variant="gold" to={`/admin/communities/workshops/${id}/trainers`}>{t('plc.people.nextTrainers')}</Button><Button variant="outline" to={`/admin/communities/workshops/${id}`}>{t('plc.schools.done')}</Button></div>
          <p className="text-xs text-slate-400">{t('plc.people.explicit')}</p>
        </aside>
      </div>

      <Modal open={!!override} onClose={() => setOverride(null)} title={t('plc.people.overrideTitle')}>
        <p className="mb-3 text-sm text-slate-600">{t('plc.people.overrideHint')}: <b>{override?.names.join('، ')}</b></p>
        <Field label={t('plc.detail.reason')}><textarea className="input min-h-20" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} /></Field>
        <div className="mt-4 flex justify-end gap-2"><Button variant="outline" onClick={() => setOverride(null)}>{t('common.cancel')}</Button><Button variant="gold" disabled={!reason.trim()} loading={nominate.isPending} onClick={() => override && submit(override.ids, true, reason)}>{t('plc.detail.confirm')}</Button></div>
      </Modal>
      <Modal open={!!decision} onClose={() => setDecision(null)} title={decision ? `${t(`plc.people.actions.${decision.to}`)} — ${name(decision.n)}` : ''}>
        {decision && data?.reason_required.includes(decision.to) ? <Field label={t('plc.detail.reason')} hint={t('plc.detail.reasonHint')}><textarea className="input min-h-20" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={1000} /></Field> : <p className="text-sm text-slate-600">{t('plc.people.confirmDecision')}</p>}
        <div className="mt-4 flex justify-end gap-2"><Button variant="outline" onClick={() => setDecision(null)}>{t('common.cancel')}</Button><Button variant={decision?.to === 'rejected' ? 'danger' : 'gold'} loading={decide.isPending} onClick={runDecision} icon={<Check className="size-4" />}>{t('plc.detail.confirm')}</Button></div>
      </Modal>
    </>
  )
}
