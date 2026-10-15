import { AlertTriangle, Ban, Check, CheckCircle2, Pencil, Send, Undo2, XCircle } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, Link } from 'react-router-dom'
import { Badge, Button, Card, CardTitle, ErrorState, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet, useSend } from '@/hooks/useApi'
import { errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { STATE_TONE, fmtDate, type WorkshopDetail as Detail } from './plcApi'

type Action = 'submit' | 'approve' | 'request-changes' | 'reject' | 'cancel'

/** One workshop: its facts, the approval path, the actions the person may take, and the decision log. */
export default function WorkshopDetail() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const res = useGet<{ data: Detail }>(`/admin/plc/workshops/${id}`, undefined, { staleTime: 0 })
  const [action, setAction] = useState<Action | null>(null)
  const [text, setText] = useState('')
  const send = useSend<{ action: Action; body: Record<string, unknown> }, { data: Detail }>('post', (b) => `/admin/plc/workshops/${id}/${b.action}`, ['/admin/plc'])
  const en = i18n.language === 'en'

  if (res.isLoading) return <Spinner />
  if (res.error || !res.data) return <ErrorState error={res.error} onRetry={() => res.refetch()} home="/admin/communities?tab=workshops" />
  const w = res.data.data
  const needsText = action === 'request-changes' || action === 'reject' || action === 'cancel'
  const run = async () => {
    if (!action) return
    if (needsText && !text.trim()) { toast(String(t('plc.detail.reason')), 'error'); return }
    try {
      await send.mutateAsync({ action, body: needsText ? { reason: text } : text ? { note: text } : {} })
      toast(String(t({ submit: 'plc.detail.submitted', approve: 'plc.detail.approved', 'request-changes': 'plc.detail.changesSent', reject: 'plc.detail.rejected', cancel: 'plc.detail.cancelled' }[action])))
      setAction(null); setText(''); res.refetch()
    } catch (e: unknown) {
      const err = e as { response?: { data?: { code?: string; details?: { missing?: string[] } } } }
      if (err.response?.data?.code === 'plc_incomplete') toast(String(t('plc.detail.missing')) + (err.response.data.details?.missing ?? []).map((m) => t(`plc.detail.missingFields.${m}`)).join('، '), 'error')
      else toast(errorMessage(e), 'error')
    }
  }
  const title = en && w.title_en ? w.title_en : w.title_ar

  return (
    <>
      <PageHeader
        title={<span className="flex flex-wrap items-center gap-3">{title}<Badge color={STATE_TONE[w.state]}>{t(`plc.state.${w.state}`)}</Badge></span>}
        subtitle={<span className="font-mono text-xs">{w.code}</span>}
        actions={<div className="flex flex-wrap gap-2">
          <Button variant="outline" to="/admin/communities?tab=workshops">{t('plc.detail.back')}</Button>
          {w.can.edit && <Button variant="outline" to={`/admin/communities/workshops/${w.id}/edit`} icon={<Pencil className="size-4" />}>{t('plc.detail.actions.edit')}</Button>}
          {w.can.submit && <Button variant="gold" icon={<Send className="size-4" />} onClick={() => setAction('submit')}>{t('plc.detail.actions.submit')}</Button>}
          {w.can.review && <>
            <Button variant="primary" icon={<CheckCircle2 className="size-4" />} onClick={() => setAction('approve')}>{t('plc.detail.actions.approve')}</Button>
            <Button variant="outline" icon={<Undo2 className="size-4" />} onClick={() => setAction('request-changes')}>{t('plc.detail.actions.changes')}</Button>
            <Button variant="outline" icon={<XCircle className="size-4" />} onClick={() => setAction('reject')}>{t('plc.detail.actions.reject')}</Button>
          </>}
          {w.can.cancel && <Button variant="ghost" icon={<Ban className="size-4" />} onClick={() => setAction('cancel')}>{t('plc.detail.actions.cancel')}</Button>}
        </div>}
      />
      {w.state === 'needs_changes' && w.decision_note && <p className="mb-4 flex items-start gap-2 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900"><AlertTriangle className="mt-0.5 size-4 shrink-0" />{w.decision_note}</p>}
      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-5 lg:col-span-2">
          <Card>
            <div className="flex flex-wrap gap-1.5">
              <Badge color="navy">{t(`plc.scenario.${w.scenario}`)}</Badge>
              <Badge color="gray">{t(`plc.source.${w.source}`)}{w.source_ref ? ` · ${w.source_ref}` : ''}</Badge>
              <Badge color="gray">{t(`plc.mode.${w.delivery_mode}`)}</Badge>
              <Badge color={w.priority === 'urgent' ? 'red' : w.priority === 'high' ? 'amber' : 'gray'}>{t(`plc.priority.${w.priority}`)}</Badge>
            </div>
            <dl className="mt-4 grid gap-4 text-sm sm:grid-cols-2">
              <Item label={t('plc.workshops.host')}>{w.host_school ? (en && w.host_school.name_en ? w.host_school.name_en : w.host_school.name) : '—'}</Item>
              <Item label={t('plc.workshops.dates')}>{w.start_date ? `${fmtDate(w.start_date, i18n.language)}${w.end_date && w.end_date !== w.start_date ? ` – ${fmtDate(w.end_date, i18n.language)}` : ''}` : t('plc.workshops.noDate')}</Item>
              <Item label={t('plc.workshops.coordinator')}>{w.coordinator ?? '—'}</Item>
              <Item label={t('plc.wizard.expected')}>{w.expected_participants ?? '—'}{w.total_hours ? ` · ${w.total_hours} ${en ? 'h' : 'ساعة'}` : ''}</Item>
              {w.organiser && <Item label={t('plc.detail.organiser')}>{w.organiser === 'centre' ? t('plc.detail.organiserCentre') : w.organiser}</Item>}
              {w.need && <Item label={t('plc.wizard.need')}>{w.need.title}{w.need.reference_no ? ` · ${w.need.reference_no}` : ''}</Item>}
              {w.community && <Item label={t('plc.tabs.communities')}>{w.community.title}</Item>}
              <Item label={t('plc.schedule.sessions')}><Link to={`/admin/communities/workshops/${w.id}/schedule`} className="font-semibold text-navy-900 underline decoration-gold-400 hover:text-gold-700">{w.sessions?.count ? t('plc.schedule.sessionsN', { n: w.sessions.count }) : t('plc.schedule.planNow')}</Link>{w.sessions?.next && <span className="block text-xs text-slate-500">{t('plc.schedule.next')}: {fmtDate(w.sessions.next, i18n.language)}</span>}{['scheduled', 'ready', 'in_progress', 'completed', 'awaiting_evaluation', 'closed'].includes(w.state) && <Link to={`/admin/communities/workshops/${w.id}/attendance`} className="block text-xs font-semibold text-gold-700 underline">{t('plc.attendance.openReport')}</Link>}</Item>
              {w.domain && <Item label={t('plc.wizard.domain')}>{w.domain}</Item>}
              {w.target_audience && <Item label={t('plc.detail.audience')}>{w.target_audience}</Item>}
            </dl>
            {w.summary_ar && <p className="mt-4 text-sm text-slate-700">{w.summary_ar}</p>}
            {!!w.objectives?.length && <div className="mt-4"><h4 className="mb-1 text-xs font-bold uppercase tracking-wide text-slate-500">{t('plc.detail.objectives')}</h4><ul className="list-disc space-y-0.5 ps-5 text-sm text-slate-700">{w.objectives.map((o) => <li key={o}>{o}</li>)}</ul></div>}
            {w.outcomes && <div className="mt-4"><h4 className="mb-1 text-xs font-bold uppercase tracking-wide text-slate-500">{t('plc.detail.outcomes')}</h4><p className="text-sm text-slate-700">{w.outcomes}</p></div>}
            {w.prerequisites && <div className="mt-4"><h4 className="mb-1 text-xs font-bold uppercase tracking-wide text-slate-500">{t('plc.detail.prerequisites')}</h4><p className="text-sm text-slate-700">{w.prerequisites}</p></div>}
          </Card>
          <Card>
            <CardTitle subtitle={t('plc.detail.schoolsNext')} action={<Button size="sm" variant="outline" to={`/admin/communities/workshops/${w.id}/schools`}>{t('plc.detail.editSchools')}</Button>}>{t('plc.detail.schools')}</CardTitle>
            <ul className="divide-y divide-slate-100 text-sm">
              {w.schools.map((s) => <li key={s.id} className="flex items-center justify-between py-2"><span>{en && s.name_en ? s.name_en : s.name}</span><Badge color={s.role === 'host' ? 'gold' : 'gray'}>{t(`plc.detail.${s.role === 'host' ? 'host' : 'participant'}`)}</Badge></li>)}
            </ul>
          </Card>
          <Card>
            <CardTitle subtitle={t('plc.detail.peopleHint')} action={<div className="flex gap-1"><Button size="sm" variant="outline" to={`/admin/communities/workshops/${w.id}/trainees`}>{t('plc.detail.manageTrainees')}</Button><Button size="sm" variant="outline" to={`/admin/communities/workshops/${w.id}/trainers`}>{t('plc.detail.manageTrainers')}</Button></div>}>{t('plc.detail.people')}</CardTitle>
          </Card>
          <Card>
            <CardTitle>{t('plc.detail.decisions')}</CardTitle>
            {!w.decisions.length ? <p className="text-sm text-slate-500">{t('plc.detail.noDecisions')}</p> : (
              <ol className="relative space-y-3 border-s border-slate-200 ps-4 text-sm">
                {w.decisions.map((d, i) => (
                  <li key={i} className="relative">
                    <span className="absolute -start-[21px] top-1.5 size-2.5 rounded-full bg-gold-400 ring-4 ring-white" />
                    <div className="flex flex-wrap items-center gap-2">
                      <b className="text-navy-900">{t(`plc.detail.kinds.${d.kind}`, { defaultValue: d.kind })}</b>
                      <Badge color={(STATE_TONE as Record<string, 'gray'>)[d.decision] ?? 'gray'}>{(STATE_TONE as Record<string, string>)[d.decision] ? t(`plc.state.${d.decision}`) : t(`plc.detail.decisionLabels.${d.decision}`, { defaultValue: d.decision })}</Badge>
                      <span className="text-xs text-slate-400">{d.by ? `${t('plc.detail.by')} ${d.by} · ` : ''}{new Date(d.at).toLocaleString(en ? 'en-GB' : 'ar-QA')}</span>
                    </div>
                    {d.reason && <p className="mt-0.5 text-slate-600">{d.reason}</p>}
                  </li>
                ))}
              </ol>
            )}
          </Card>
        </div>
        <aside className="space-y-4">
          <Card>
            <h3 className="mb-3 font-display text-sm font-bold text-navy-900">{t('plc.detail.path')}</h3>
            {!w.approval_path?.length ? <p className="text-sm text-slate-500">{t(`plc.state.${w.state}`)}</p> : (
              <ol className="space-y-2 text-sm">
                {w.approval_path.map((s, i) => (
                  <li key={s.key} className="flex items-start gap-2">
                    <span className={`mt-0.5 grid size-5 shrink-0 place-items-center rounded-full text-[11px] font-bold ${s.status === 'approved' ? 'bg-emerald-100 text-emerald-700' : s.status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-slate-100 text-slate-500'}`}>{s.status === 'approved' ? <Check className="size-3" /> : i + 1}</span>
                    <div><div className="font-semibold text-navy-900">{t(`plc.step.${s.key}`, { defaultValue: s.key })}</div><div className="text-xs text-slate-500">{s.status === 'approved' ? `${t('plc.detail.approvedStep')}${s.at ? ` · ${fmtDate(s.at, i18n.language)}` : ''}` : t('plc.detail.pending')}{s.note ? ` — ${s.note}` : ''}</div></div>
                  </li>
                ))}
              </ol>
            )}
          </Card>
          <p className="text-xs text-slate-400">{t('plc.phase')}</p>
        </aside>
      </div>
      <Modal open={!!action} onClose={() => setAction(null)} title={action ? t(`plc.detail.actions.${{ submit: 'submit', approve: 'approve', 'request-changes': 'changes', reject: 'reject', cancel: 'cancel' }[action]}`) : ''}>
        {action === 'submit' ? <p className="text-sm text-slate-600">{t('plc.detail.actions.submit')}: {title}</p> : (
          <Field label={needsText ? t('plc.detail.reason') : t('plc.detail.note')} hint={needsText ? t('plc.detail.reasonHint') : undefined}>
            <textarea className="input min-h-24" value={text} onChange={(e) => setText(e.target.value)} maxLength={1000} />
          </Field>
        )}
        <div className="mt-5 flex justify-end gap-2">
          <Button variant="outline" onClick={() => setAction(null)}>{t('common.cancel')}</Button>
          <Button variant={action === 'reject' || action === 'cancel' ? 'danger' : 'gold'} loading={send.isPending} onClick={run}>{t('plc.detail.confirm')}</Button>
        </div>
      </Modal>
    </>
  )
}

function Item({ label, children }: { label: React.ReactNode; children: React.ReactNode }) {
  return <div><dt className="text-xs text-slate-500">{label}</dt><dd className="font-semibold text-navy-900">{children}</dd></div>
}
