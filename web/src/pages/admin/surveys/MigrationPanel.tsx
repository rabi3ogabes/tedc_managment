import { ArrowRightLeft, DatabaseZap, RotateCcw, ShieldAlert } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardTitle, Modal, Spinner } from '@/components/ui'
import { useGet, useSend } from '@/hooks/useApi'
import { errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Plan = {
  needs_surveys: { count: number; by_status: Record<string, number>; recipients: number; responses: number }
  evaluation_forms: { count: number; assignments: number; responses: number; system_skipped: number }
  migrated: { needs_surveys: number; evaluation_forms: number }
  warnings: { type: string; id: string; title: string; issue: string }[]
  batches: { batch: string; created_at: string; by?: string | null; needs_surveys: number; evaluation_forms: number; surveys: number; late_answers: number }[]
}
type RunResult = { batch: string; needs_surveys: number; evaluation_forms: number; participants: number; submissions: number; files: number; skipped: { type: string; id: string; title: string; reason: string }[] }

/**
 * The migration panel (Gate 5): what a run would do (the dry run), the run itself, and the batches with their rollback.
 * Nothing changes until the person presses migrate; the legacy records stay and become read-only.
 */
export default function MigrationPanel({ open, onClose }: { open: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const plan = useGet<{ data: Plan }>(open ? '/admin/surveys/migration' : null, undefined, { staleTime: 0 })
  const run = useSend<{ only: 'all' | 'needs' | 'evaluations' }, { data: RunResult }>('post', '/admin/surveys/migration', ['/admin/surveys'])
  const rollback = useSend<{ batch: string; force?: boolean }, { data: unknown }>('post', '/admin/surveys/migration/rollback', ['/admin/surveys'])
  const [last, setLast] = useState<RunResult | null>(null)
  const d = plan.data?.data
  const pending = (d?.needs_surveys.count ?? 0) + (d?.evaluation_forms.count ?? 0)

  const go = async (only: 'all' | 'needs' | 'evaluations') => {
    if (!window.confirm(String(t('ss.migration.confirm')))) return
    try {
      const { data } = await run.mutateAsync({ only })
      setLast(data)
      toast(String(t('ss.migration.done', { needs: data.needs_surveys, forms: data.evaluation_forms, participants: data.participants, submissions: data.submissions, files: data.files })))
      await plan.refetch()
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  const undo = async (b: Plan['batches'][number]) => {
    if (!window.confirm(String(t('ss.migration.rollbackConfirm')))) return
    let force = false
    if (b.late_answers > 0) {
      const typed = window.prompt(String(t('ss.migration.rollbackForce', { n: b.late_answers })))
      if (typed !== 'حذف' && typed?.toLowerCase() !== 'delete') return
      force = true
    }
    try { await rollback.mutateAsync({ batch: b.batch, force }); toast(String(t('ss.migration.rolledBack'))); setLast(null); await plan.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }

  return (
    <Modal open={open} onClose={onClose} title={t('ss.migration.title')} wide>
      {!d ? <Spinner /> : (
        <div className="space-y-5">
          <p className="rounded-2xl border border-navy-100 bg-ivory px-4 py-3 text-sm leading-7 text-slate-700"><DatabaseZap className="me-2 inline size-4 text-gold-700" />{t('ss.migration.dryNote')}</p>
          <div className="grid gap-4 md:grid-cols-2">
            <Card>
              <CardTitle subtitle={t('ss.migration.pending')}>{t('ss.migration.needs')}</CardTitle>
              <div className="flex items-end gap-3"><span className="font-display text-4xl font-bold text-navy-900">{d.needs_surveys.count}</span><span className="pb-1 text-xs text-slate-500">{Object.entries(d.needs_surveys.by_status).map(([k, v]) => `${t(`surveys.status.${k}`, { defaultValue: k })} ${v}`).join(' · ')}</span></div>
              <div className="mt-3 flex flex-wrap gap-1.5"><Badge color="gray">{d.needs_surveys.recipients} {t('ss.migration.recipients')}</Badge><Badge color="gray">{d.needs_surveys.responses} {t('ss.migration.responses')}</Badge><Badge color="green">{d.migrated.needs_surveys} {t('ss.migration.migrated')}</Badge></div>
              <Button className="mt-4" size="sm" variant="outline" icon={<ArrowRightLeft className="size-3.5" />} disabled={!d.needs_surveys.count} loading={run.isPending} onClick={() => go('needs')}>{t('ss.migration.runNeeds')}</Button>
            </Card>
            <Card>
              <CardTitle subtitle={t('ss.migration.pending')}>{t('ss.migration.forms')}</CardTitle>
              <div className="flex items-end gap-3"><span className="font-display text-4xl font-bold text-navy-900">{d.evaluation_forms.count}</span><span className="pb-1 text-xs text-slate-500">{t('ss.migration.systemSkipped', { n: d.evaluation_forms.system_skipped })}</span></div>
              <div className="mt-3 flex flex-wrap gap-1.5"><Badge color="gray">{d.evaluation_forms.assignments} {t('ss.migration.assignments')}</Badge><Badge color="gray">{d.evaluation_forms.responses} {t('ss.migration.responses')}</Badge><Badge color="green">{d.migrated.evaluation_forms} {t('ss.migration.migrated')}</Badge></div>
              <Button className="mt-4" size="sm" variant="outline" icon={<ArrowRightLeft className="size-3.5" />} disabled={!d.evaluation_forms.count} loading={run.isPending} onClick={() => go('evaluations')}>{t('ss.migration.runForms')}</Button>
            </Card>
          </div>
          {!!d.warnings.length && (
            <div className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
              <div className="mb-1 flex items-center gap-2 font-semibold"><ShieldAlert className="size-4" />{t('ss.migration.warnings')}</div>
              <ul className="list-disc space-y-0.5 ps-5 text-xs">{d.warnings.map((w, i) => <li key={i}>{w.title || t(`ss.migration.types.${w.type}`)} — {t(`ss.migration.issues.${w.issue.split(':')[0]}`, { defaultValue: w.issue })}{w.issue.includes(':') ? ` (${w.issue.split(':')[1]})` : ''}</li>)}</ul>
            </div>
          )}
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="gold" icon={<ArrowRightLeft className="size-4" />} disabled={!pending} loading={run.isPending} onClick={() => go('all')}>{t('ss.migration.runAll')}</Button>
            {!pending && <span className="text-sm text-slate-500">{t('ss.migration.nothing')}</span>}
          </div>
          {last && (
            <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
              <div className="font-semibold">{t('ss.migration.done', { needs: last.needs_surveys, forms: last.evaluation_forms, participants: last.participants, submissions: last.submissions, files: last.files })}</div>
              <div className="mt-1 font-mono text-xs" dir="ltr">{t('ss.migration.batch')}: {last.batch}</div>
              {!!last.skipped.length && <ul className="mt-2 list-disc ps-5 text-xs">{last.skipped.map((s, i) => <li key={i}>{t('ss.migration.skipped')}: {s.title} — {s.reason}</li>)}</ul>}
            </div>
          )}
          {!!d.batches.length && (
            <div>
              <h4 className="mb-2 font-semibold text-navy-900">{t('ss.migration.batches')}</h4>
              <div className="overflow-auto rounded-xl border border-slate-200">
                <table className="w-full text-xs"><thead className="bg-slate-50 text-slate-500"><tr><th className="px-2 py-1.5 text-start">{t('ss.migration.batch')}</th><th className="px-2 py-1.5 text-start">{t('ss.migration.by')}</th><th className="px-2 py-1.5 text-start">{t('ss.migration.needs')}</th><th className="px-2 py-1.5 text-start">{t('ss.migration.forms')}</th><th className="px-2 py-1.5 text-start">{t('ss.migration.late')}</th><th /></tr></thead>
                  <tbody className="divide-y divide-slate-100">{d.batches.map((b) => (
                    <tr key={b.batch}><td className="px-2 py-1.5"><span className="font-mono" dir="ltr">{b.batch}</span><div className="text-slate-400">{fmt.dateTime(b.created_at)}</div></td><td className="px-2 py-1.5">{b.by ?? '—'}</td><td className="px-2 py-1.5">{b.needs_surveys}</td><td className="px-2 py-1.5">{b.evaluation_forms}</td><td className="px-2 py-1.5">{b.late_answers ? <Badge color="amber">{b.late_answers}</Badge> : '0'}</td>
                      <td className="px-2 py-1.5 text-end"><Button size="sm" variant="ghost" icon={<RotateCcw className="size-3.5" />} loading={rollback.isPending} onClick={() => undo(b)}>{t('ss.migration.rollback')}</Button></td></tr>))}</tbody></table>
              </div>
            </div>
          )}
        </div>
      )}
    </Modal>
  )
}
