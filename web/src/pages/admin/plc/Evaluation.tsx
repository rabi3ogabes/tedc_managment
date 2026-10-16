import { AlertTriangle, BellRing, CheckCircle2, ClipboardCheck, Download, Lock, Send, ShieldAlert, Sparkles, UserRound, WandSparkles } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { Badge, Button, Card, CardTitle, Empty, ErrorState, Field, Modal, PageHeader, Progress, Spinner, StatCard } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { STATE_TONE, type PlcState } from './plcApi'
import type { Assistant } from './AssistantPanel'

type Themes = { summary: string; themes: { title: string; count: number; quotes: string[] }[]; sentiment: { positive: number; neutral: number; negative: number }; recommendations: string[]; answers_count: number; model?: string | null; redactions: number; generated_at: string; generated_by?: string | null; advisory: boolean }

type Instrument = 'workshop' | 'trainer' | 'session' | 'community'
type Status = 'off' | 'not_applicable' | 'waiting' | 'due' | 'sent' | 'complete'
type Text = { ar: string; en: string }
type Stats = { assigned: number; submitted: number; pending: number; expired: number; overdue: number; rate: number | null; notified: number; unsent: number; failed: number; score: number | null; score_hidden: boolean }
type Job = Stats & { instrument: Instrument; key: string; label: Text; reason: Text; enabled: boolean; mandatory: boolean; due_days: number; applies: boolean; due_at?: string | null; session_at?: string | null; targets: number; status: Status }
type Row = { id: string; instrument: string; key: string; name?: string | null; school?: string | null; respondent_type: string; target?: Text | null; status: 'pending' | 'submitted' | 'expired'; overdue: boolean; sent_at?: string | null; due_at?: string | null; reminded_at?: string | null; submitted_at?: string | null; delivery: { in_app: boolean; email?: string | null; sms?: string | null; failed: boolean } }
type Rules = { workshop: Rule; trainer: Rule; session: Rule; community: Rule; impact_sources: string[]; auto_send: boolean; close_response_rate: number }
type Rule = { enabled: boolean; mandatory: boolean; due_days: number }
type Tracking = {
  workshop: { id: string; title_ar: string; title_en?: string | null; state: PlcState; source: string; community?: string | null; state_changed_at?: string | null }
  rules: Rules; min_responses: number; instruments: Job[]; summary: Stats
  completion: { complete: boolean; assigned: number; blocking: { instrument: Instrument; key: string; label: Text; assigned: number; submitted: number; rate: number | null; required: number }[] }
  impact: { applies: boolean; sources: string[]; completed_registrations: number; reason: Text; trainee: { scheduled: number; sent: number; completed: number; expired: number; next_at?: string | null; stages: number[] }; manager: { assigned: number; submitted: number; days: number } }
  assignments: Row[]
  can: { send: boolean; remind: boolean; close: boolean; exception: boolean; settings: boolean }
}
const STATUS_TONE: Record<Status, 'gray' | 'green' | 'amber' | 'blue' | 'gold' | 'navy'> = { off: 'gray', not_applicable: 'gray', waiting: 'blue', due: 'amber', sent: 'gold', complete: 'green' }
const ROW_TONE = { pending: 'amber', submitted: 'green', expired: 'gray' } as const

/** The evaluation plan of a workshop: every instrument with its reason and timing, what was sent and to whom with the delivery evidence, the response rates, the impact follow-ups, reminders, and the close (complete, or by a documented exception). */
export default function PlcEvaluation() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const en = i18n.language === 'en'
  const res = useGet<{ data: Tracking }>(`/admin/plc/workshops/${id}/evaluation`, undefined, { staleTime: 0 })
  const [busy, setBusy] = useState(false)
  const [closing, setClosing] = useState<null | 'plain' | 'exception'>(null)
  const [reason, setReason] = useState('')
  const [rules, setRules] = useState<Rules | null>(null)
  const [filter, setFilter] = useState<'all' | Instrument>('all')
  const assistant = useGet<{ data: Assistant }>(`/admin/plc/workshops/${id}/assistant`, undefined, { staleTime: 15_000 })
  const [themes, setThemes] = useState<Themes | null>(null)
  const [themesBusy, setThemesBusy] = useState(false)
  if (res.isLoading && !res.data) return <Spinner />
  if (res.error || !res.data) return <ErrorState error={res.error} onRetry={() => res.refetch()} home={`/admin/communities/workshops/${id}`} />
  const d = res.data.data
  const tx = (x: Text) => (en ? x.en : x.ar)
  const act = async (path: string, body: Record<string, unknown>, done: (r: Record<string, number>) => string) => {
    setBusy(true)
    try { const r = await api.post<{ data: Record<string, number> }>(`/admin/plc/workshops/${id}/evaluation/${path}`, body); toast(done(r.data.data)); await res.refetch(); return true } catch (e) { toast(errorMessage(e), 'error'); return false } finally { setBusy(false) }
  }
  const saveRules = async () => {
    if (!rules) return
    setBusy(true)
    try { await api.put('/admin/plc/settings', { evaluation_rules: rules }); toast(String(t('plc.evaluation.rulesSaved'))); setRules(null); await res.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const rows = filter === 'all' ? d.assignments : d.assignments.filter((r) => r.instrument === filter)
  const grouped = (['workshop', 'trainer', 'session', 'community'] as Instrument[]).map((k) => ({ k, jobs: d.instruments.filter((j) => j.instrument === k) }))

  return (
    <>
      <PageHeader title={t('plc.evaluation.title')} subtitle={`${en && d.workshop.title_en ? d.workshop.title_en : d.workshop.title_ar}`}
        actions={<div className="flex flex-wrap gap-2">
          <Button variant="outline" to={`/admin/communities/workshops/${id}`}>{t('plc.detail.back')}</Button>
          <Button variant="outline" icon={<Download className="size-4" />} onClick={() => downloadFile(`/admin/plc/workshops/${id}/evaluation/export`, 'plc-evaluation.csv')}>{t('common.export')}</Button>
          {d.can.remind && <Button variant="outline" icon={<BellRing className="size-4" />} loading={busy} onClick={() => act('remind', {}, (r) => String(t('plc.evaluation.reminded', { n: r.reminded })))}>{t('plc.evaluation.remindAll')}</Button>}
          {d.can.close && (d.completion.complete
            ? <Button variant="gold" icon={<Lock className="size-4" />} onClick={() => { setReason(''); setClosing('plain') }}>{t('plc.evaluation.close')}</Button>
            : d.can.exception && <Button variant="gold" icon={<ShieldAlert className="size-4" />} onClick={() => { setReason(''); setClosing('exception') }}>{t('plc.evaluation.closeException')}</Button>)}
        </div>} />

      <div className="mb-4 flex flex-wrap items-center gap-2">
        <Badge color={STATE_TONE[d.workshop.state]}>{t(`plc.state.${d.workshop.state}`)}</Badge>
        {d.workshop.community && <Badge color="navy">{d.workshop.community}</Badge>}
        <Badge color="gray">{t(`plc.source.${d.workshop.source}`)}</Badge>
        {!d.rules.auto_send && <Badge color="amber">{t('plc.evaluation.manualMode')}</Badge>}
      </div>

      <div className="mb-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard label={t('plc.evaluation.assigned')} value={d.summary.assigned} icon={<Send className="size-4" />} accent />
        <StatCard label={t('plc.evaluation.submitted')} value={d.summary.submitted} icon={<CheckCircle2 className="size-4" />} hint={d.summary.rate != null ? t('plc.evaluation.rateOf', { n: d.summary.rate }) : undefined} />
        <StatCard label={t('plc.evaluation.overdue')} value={d.summary.overdue} icon={<AlertTriangle className="size-4" />} />
        <StatCard label={t('plc.evaluation.unsentLabel')} value={d.summary.unsent + d.summary.failed} icon={<ShieldAlert className="size-4" />} hint={d.summary.failed ? t('plc.evaluation.failedN', { n: d.summary.failed }) : t('plc.evaluation.unsentHint')} />
      </div>

      {!d.completion.complete && d.completion.blocking.length > 0 && ['completed', 'awaiting_evaluation'].includes(d.workshop.state) && (
        <Card className="mb-5 border-amber-200 bg-amber-50/60">
          <div className="flex items-start gap-3"><AlertTriangle className="mt-0.5 size-5 shrink-0 text-amber-600" /><div className="text-sm">
            <div className="font-bold text-navy-900">{t('plc.evaluation.blockingTitle')}</div>
            <ul className="mt-1 space-y-0.5 text-slate-700">{d.completion.blocking.map((b) => <li key={b.key}>{t(`plc.evaluation.instrument.${b.instrument}`)}{b.instrument === 'trainer' || b.instrument === 'session' ? ` · ${tx(b.label)}` : ''}: {b.assigned ? t('plc.evaluation.blockingRate', { rate: b.rate ?? 0, required: b.required, submitted: b.submitted, assigned: b.assigned }) : t('plc.evaluation.blockingUnsent')}</li>)}</ul>
          </div></div>
        </Card>
      )}

      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-4 lg:col-span-2">
          {grouped.map(({ k, jobs }) => jobs.length > 0 && (
            <Card key={k}>
              <CardTitle subtitle={t(`plc.evaluation.hint.${k}`)} action={<div className="flex items-center gap-1.5">
                {jobs[0].mandatory && <Badge color="navy">{t('plc.evaluation.mandatory')}</Badge>}
                {d.can.send && jobs.some((j) => j.enabled && j.applies && j.assigned === 0) && <Button size="sm" variant="outline" icon={<Send className="size-3.5" />} loading={busy} onClick={() => act('send', { instruments: [k] }, (r) => String(t('plc.evaluation.sentN', { n: r.sent })))}>{t('plc.evaluation.sendNow')}</Button>}
              </div>}>{t(`plc.evaluation.instrument.${k}`)}</CardTitle>
              <ul className="divide-y divide-slate-100">
                {jobs.map((j) => (
                  <li key={j.key} className="py-3 first:pt-0 last:pb-0">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <div className="min-w-0">
                        <div className="flex flex-wrap items-center gap-1.5">
                          {(k === 'trainer' || k === 'session') && <span className="font-semibold text-navy-900">{tx(j.label)}</span>}
                          <Badge color={STATUS_TONE[j.status]}>{t(`plc.evaluation.status.${j.status}`)}</Badge>
                          {j.applies && j.enabled && <span className="text-xs text-slate-500">{t('plc.evaluation.targetsN', { n: j.targets })}{j.due_at ? ` · ${j.status === 'waiting' ? t('plc.evaluation.dueOn') : t('plc.evaluation.wasDue')} ${fmt.date(j.due_at, { day: 'numeric', month: 'short', hour: '2-digit', minute: '2-digit' })}` : ''} · {t('plc.evaluation.dueDays', { n: j.due_days })}</span>}
                        </div>
                        <p className="mt-1 text-xs text-slate-500"><Sparkles className="me-1 inline size-3 text-gold-600" />{tx(j.reason)}</p>
                      </div>
                      {j.assigned > 0 && (
                        <div className="w-44 shrink-0 text-xs">
                          <div className="flex items-center justify-between"><span>{j.submitted}/{j.assigned}</span><span className="font-semibold tabular-nums">{j.rate ?? 0}%</span></div>
                          <Progress value={j.rate ?? 0} tone={j.status === 'complete' ? 'green' : 'gold'} className="mt-1" />
                          <div className="mt-1 flex flex-wrap gap-x-2 text-[11px] text-slate-500">
                            <span>{t('plc.evaluation.notifiedN', { n: j.notified })}</span>
                            {j.unsent > 0 && <span className="text-amber-700">{t('plc.evaluation.unsentN', { n: j.unsent })}</span>}
                            {j.failed > 0 && <span className="text-red-700">{t('plc.evaluation.failedN', { n: j.failed })}</span>}
                            {j.overdue > 0 && <span className="text-amber-700">{t('plc.evaluation.overdueN', { n: j.overdue })}</span>}
                          </div>
                          <div className="mt-1 text-[11px]">{j.score != null ? <span className="font-semibold text-navy-900">{t('plc.evaluation.score', { n: j.score })}</span> : j.score_hidden ? <span className="text-slate-400">{t('plc.evaluation.scoreHidden', { n: d.min_responses })}</span> : null}</div>
                        </div>
                      )}
                    </div>
                  </li>
                ))}
              </ul>
            </Card>
          ))}

          <Card padded={false}>
            <div className="flex flex-wrap items-center justify-between gap-2 border-b border-slate-100 px-4 py-3">
              <div className="font-bold text-navy-900">{t('plc.evaluation.tracking')}</div>
              <div className="flex flex-wrap gap-1">{(['all', 'workshop', 'trainer', 'session', 'community'] as const).map((f) => <button key={f} type="button" onClick={() => setFilter(f)} className={`rounded-full px-2.5 py-1 text-xs font-semibold ${filter === f ? 'bg-navy-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200'}`}>{f === 'all' ? t('common.all') : t(`plc.evaluation.instrument.${f}`)}</button>)}</div>
            </div>
            {!rows.length ? <Empty text={String(t('plc.evaluation.noRows'))} icon={<ClipboardCheck className="size-6" />} /> : (
              <div className="overflow-auto"><table className="w-full text-xs">
                <thead className="bg-slate-50 text-slate-500"><tr><th className="px-3 py-2 text-start">{t('plc.evaluation.respondent')}</th><th className="px-3 py-2 text-start">{t('plc.evaluation.form')}</th><th className="px-3 py-2 text-start">{t('plc.evaluation.delivery')}</th><th className="px-3 py-2 text-start">{t('plc.evaluation.due')}</th><th className="px-3 py-2 text-start">{t('plc.evaluation.state')}</th></tr></thead>
                <tbody className="divide-y divide-slate-100">{rows.map((r) => (
                  <tr key={r.id}>
                    <td className="px-3 py-1.5"><div className="font-semibold text-navy-900">{r.name ?? '—'}</div><div className="text-slate-500">{r.school ?? (r.respondent_type === 'supervisor' ? t('plc.evaluation.coordinator') : '')}</div></td>
                    <td className="px-3 py-1.5">{t(`plc.evaluation.instrument.${r.instrument}`)}{r.target ? <span className="text-slate-500"> · {tx(r.target)}</span> : null}</td>
                    <td className="px-3 py-1.5"><Delivery d={r.delivery} /></td>
                    <td className="px-3 py-1.5 tabular-nums text-slate-600">{fmt.date(r.due_at, { day: 'numeric', month: 'short' })}{r.reminded_at ? <span className="block text-[10px] text-slate-400">{t('plc.evaluation.remindedAt')} {fmt.date(r.reminded_at, { day: 'numeric', month: 'short' })}</span> : null}</td>
                    <td className="px-3 py-1.5"><Badge color={r.overdue ? 'red' : ROW_TONE[r.status]}>{r.overdue ? t('plc.evaluation.rowOverdue') : t(`plc.evaluation.row.${r.status}`)}</Badge>{r.submitted_at && <span className="block text-[10px] text-slate-400">{fmt.date(r.submitted_at, { day: 'numeric', month: 'short' })}</span>}</td>
                  </tr>))}
                </tbody></table></div>
            )}
          </Card>
        </div>

        <div className="space-y-4">
          <ThemesCard id={id!} status={assistant.data?.data.themes} canSettings={d.can.settings} result={themes ?? (assistant.data?.data.themes.result as Themes | null)} busy={themesBusy}
            onGenerate={async (fresh) => { setThemesBusy(true); try { const r = await api.post<{ data: Themes }>(`/admin/plc/workshops/${id}/assistant/themes`, { fresh, lang: en ? 'en' : 'ar' }); setThemes(r.data.data) } catch (e) { toast(errorMessage(e), 'error') } finally { setThemesBusy(false) } }}
            onOptIn={async () => { try { await api.put('/admin/plc/settings', { ai_feedback_opt_in: true }); toast(String(t('plc.assistant.optInSaved'))); await assistant.refetch() } catch (e) { toast(errorMessage(e), 'error') } }} />
          <Card>
            <CardTitle subtitle={tx(d.impact.reason)}>{t('plc.evaluation.impact')}</CardTitle>
            {d.impact.applies ? (
              <dl className="grid grid-cols-2 gap-3 text-sm">
                <Item label={t('plc.evaluation.impactCompleted')}>{d.impact.completed_registrations}</Item>
                <Item label={t('plc.evaluation.impactStages')}>{d.impact.trainee.stages.length ? d.impact.trainee.stages.map((s) => `+${s}`).join(' / ') : '—'}</Item>
                <Item label={t('plc.evaluation.impactTrainee')}>{t('plc.evaluation.impactCounts', { s: d.impact.trainee.scheduled, sent: d.impact.trainee.sent, c: d.impact.trainee.completed })}</Item>
                <Item label={t('plc.evaluation.impactNext')}>{d.impact.trainee.next_at ? fmt.date(d.impact.trainee.next_at, { day: 'numeric', month: 'short', year: 'numeric' }) : '—'}</Item>
                <Item label={t('plc.evaluation.impactManager', { n: d.impact.manager.days })}>{d.impact.manager.assigned ? `${d.impact.manager.submitted}/${d.impact.manager.assigned}` : t('plc.evaluation.impactManagerLater')}</Item>
              </dl>
            ) : <p className="text-sm text-slate-500">{t('plc.evaluation.impactOff')}</p>}
          </Card>

          <Card>
            <CardTitle subtitle={t('plc.evaluation.rulesHint')} action={d.can.settings && !rules && <Button size="sm" variant="outline" onClick={() => setRules(d.rules)}>{t('common.edit')}</Button>}>{t('plc.evaluation.rules')}</CardTitle>
            {rules ? (
              <div className="space-y-3 text-sm">
                {(['workshop', 'trainer', 'session', 'community'] as Instrument[]).map((k) => (
                  <div key={k} className="rounded-xl border border-slate-200 p-2.5">
                    <div className="flex items-center justify-between gap-2"><span className="font-semibold text-navy-900">{t(`plc.evaluation.instrument.${k}`)}</span>
                      <label className="flex items-center gap-1.5 text-xs"><input type="checkbox" checked={rules[k].enabled} onChange={(e) => setRules({ ...rules, [k]: { ...rules[k], enabled: e.target.checked } })} />{t('plc.evaluation.enabled')}</label></div>
                    <div className="mt-2 flex flex-wrap items-center gap-3 text-xs">
                      <label className="flex items-center gap-1.5"><input type="checkbox" disabled={!rules[k].enabled} checked={rules[k].mandatory} onChange={(e) => setRules({ ...rules, [k]: { ...rules[k], mandatory: e.target.checked } })} />{t('plc.evaluation.mandatory')}</label>
                      <label className="flex items-center gap-1.5">{t('plc.evaluation.dueDaysLabel')}<input type="number" min={1} max={90} className="input w-16 py-1" value={rules[k].due_days} onChange={(e) => setRules({ ...rules, [k]: { ...rules[k], due_days: Number(e.target.value) } })} /></label>
                    </div>
                  </div>
                ))}
                <label className="flex items-center gap-1.5 text-xs"><input type="checkbox" checked={rules.auto_send} onChange={(e) => setRules({ ...rules, auto_send: e.target.checked })} />{t('plc.evaluation.autoSend')}</label>
                <label className="flex items-center gap-1.5 text-xs">{t('plc.evaluation.closeRate')}<input type="number" min={0} max={100} className="input w-16 py-1" value={rules.close_response_rate} onChange={(e) => setRules({ ...rules, close_response_rate: Number(e.target.value) })} />%</label>
                <Field label={t('plc.evaluation.impactSources')}><div className="flex flex-wrap gap-2 text-xs">{['rased', 'centre', 'entity', 'school'].map((s) => <label key={s} className="flex items-center gap-1"><input type="checkbox" checked={rules.impact_sources.includes(s)} onChange={(e) => setRules({ ...rules, impact_sources: e.target.checked ? [...rules.impact_sources, s] : rules.impact_sources.filter((x) => x !== s) })} />{t(`plc.source.${s}`)}</label>)}</div></Field>
                <div className="flex justify-end gap-2"><Button size="sm" variant="outline" onClick={() => setRules(null)}>{t('common.cancel')}</Button><Button size="sm" variant="gold" loading={busy} onClick={saveRules}>{t('common.save')}</Button></div>
              </div>
            ) : (
              <ul className="space-y-1.5 text-sm">
                {(['workshop', 'trainer', 'session', 'community'] as Instrument[]).map((k) => <li key={k} className="flex items-center justify-between gap-2"><span>{t(`plc.evaluation.instrument.${k}`)}</span><span className="text-xs text-slate-500">{d.rules[k].enabled ? `${d.rules[k].mandatory ? t('plc.evaluation.mandatory') + ' · ' : ''}${t('plc.evaluation.dueDays', { n: d.rules[k].due_days })}` : t('plc.evaluation.status.off')}</span></li>)}
                <li className="flex items-center justify-between gap-2 border-t border-slate-100 pt-1.5"><span>{t('plc.evaluation.autoSend')}</span><span className="text-xs text-slate-500">{d.rules.auto_send ? t('common.yes') : t('common.no')}</span></li>
                <li className="flex items-center justify-between gap-2"><span>{t('plc.evaluation.closeRate')}</span><span className="text-xs text-slate-500">{d.rules.close_response_rate}%</span></li>
                <li className="flex items-center justify-between gap-2"><span>{t('plc.evaluation.impactSources')}</span><span className="text-xs text-slate-500">{d.rules.impact_sources.map((s) => t(`plc.source.${s}`)).join('، ') || '—'}</span></li>
              </ul>
            )}
          </Card>
        </div>
      </div>

      <Modal open={!!closing} onClose={() => setClosing(null)} title={closing === 'exception' ? t('plc.evaluation.closeException') : t('plc.evaluation.close')}>
        <p className="text-sm text-slate-600">{closing === 'exception' ? t('plc.evaluation.closeExceptionHint') : t('plc.evaluation.closeHint')}</p>
        <Field label={closing === 'exception' ? t('plc.evaluation.exceptionReason') : t('plc.detail.note')} className="mt-3"><textarea className="input" rows={3} value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
        <div className="mt-4 flex justify-end gap-2"><Button variant="outline" onClick={() => setClosing(null)}>{t('common.cancel')}</Button><Button variant="gold" loading={busy} disabled={closing === 'exception' && !reason.trim()} onClick={async () => { if (await act('close', { reason: reason || undefined, exception: closing === 'exception' }, () => String(t('plc.evaluation.closed')))) setClosing(null) }}>{t('plc.detail.confirm')}</Button></div>
      </Modal>
    </>
  )
}

function ThemesCard({ status, canSettings, result, busy, onGenerate, onOptIn }: { id: string; status?: Assistant['themes']; canSettings: boolean; result: Themes | null; busy: boolean; onGenerate: (fresh: boolean) => void; onOptIn: () => void }) {
  const { t } = useTranslation()
  if (!status) return null
  const total = result ? Math.max(1, result.sentiment.positive + result.sentiment.neutral + result.sentiment.negative) : 1
  return (
    <Card>
      <CardTitle subtitle={t('plc.assistant.themesHint')} action={status.can_generate && <Button size="sm" variant={result ? 'outline' : 'gold'} icon={<WandSparkles className="size-3.5" />} loading={busy} onClick={() => onGenerate(!!result)}>{result ? t('plc.assistant.regenerate') : t('plc.assistant.generate')}</Button>}>{t('plc.assistant.themes')}</CardTitle>
      {!status.opt_in ? (
        <div className="text-sm text-slate-600"><p>{t('plc.assistant.optOut')}</p>{canSettings && <Button size="sm" variant="outline" className="mt-2" onClick={onOptIn}>{t('plc.assistant.enableOptIn')}</Button>}</div>
      ) : !status.available ? <p className="text-sm text-amber-700">{t('plc.assistant.unavailable')}</p>
        : status.answers < status.min_answers ? <p className="text-sm text-slate-500">{t('plc.assistant.tooFew', { n: status.min_answers, have: status.answers })}</p>
          : !result ? <p className="text-sm text-slate-500">{t('plc.assistant.answersN', { n: status.answers })}</p> : (
            <div className="space-y-3 text-sm">
              <p className="text-slate-700">{result.summary}</p>
              <div>
                <div className="mb-1 text-xs font-semibold text-navy-900">{t('plc.assistant.sentiment')}</div>
                <div className="flex h-2 overflow-hidden rounded-full bg-slate-100"><div className="bg-emerald-500" style={{ width: `${(result.sentiment.positive / total) * 100}%` }} /><div className="bg-slate-300" style={{ width: `${(result.sentiment.neutral / total) * 100}%` }} /><div className="bg-red-400" style={{ width: `${(result.sentiment.negative / total) * 100}%` }} /></div>
                <div className="mt-1 flex gap-3 text-[11px] text-slate-500"><span>{t('plc.assistant.positive')} {result.sentiment.positive}</span><span>{t('plc.assistant.neutral')} {result.sentiment.neutral}</span><span>{t('plc.assistant.negative')} {result.sentiment.negative}</span></div>
              </div>
              <ul className="space-y-2">{result.themes.map((th, i) => <li key={i}><div className="flex items-center justify-between gap-2"><span className="font-semibold text-navy-900">{th.title}</span><Badge color="gray">{th.count}</Badge></div>{th.quotes.length > 0 && <ul className="mt-0.5 space-y-0.5 text-xs text-slate-500">{th.quotes.map((q, j) => <li key={j}>« {q} »</li>)}</ul>}</li>)}</ul>
              {result.recommendations.length > 0 && <div><div className="mb-1 text-xs font-semibold text-navy-900">{t('plc.assistant.recommendations')}</div><ul className="list-disc space-y-0.5 ps-5 text-xs text-slate-700">{result.recommendations.map((r, i) => <li key={i}>{r}</li>)}</ul></div>}
              <p className="text-[11px] text-slate-400">{t('plc.assistant.generatedBy', { by: result.generated_by ?? '—', model: result.model ?? '—', n: result.redactions })} · {t('plc.assistant.advisory')}</p>
            </div>
          )}
    </Card>
  )
}

function Delivery({ d }: { d: Row['delivery'] }) {
  const { t } = useTranslation()
  if (!d.in_app) return <span className="inline-flex items-center gap-1 text-amber-700"><AlertTriangle className="size-3" />{t('plc.evaluation.notNotified')}</span>
  const ch = (k: 'email' | 'sms') => d[k] ? <span className={`rounded px-1 ${d[k] === 'sent' ? 'bg-emerald-50 text-emerald-700' : d[k] === 'failed' ? 'bg-red-50 text-red-700' : 'bg-slate-100 text-slate-500'}`}>{k} · {t(`plc.evaluation.channel.${d[k]}`)}</span> : null
  return <span className="inline-flex flex-wrap items-center gap-1"><span className="inline-flex items-center gap-1 text-emerald-700"><UserRound className="size-3" />{t('plc.evaluation.inApp')}</span>{ch('email')}{ch('sms')}</span>
}

function Item({ label, children }: { label: React.ReactNode; children: React.ReactNode }) {
  return <div><dt className="text-xs text-slate-500">{label}</dt><dd className="font-semibold text-navy-900">{children}</dd></div>
}
