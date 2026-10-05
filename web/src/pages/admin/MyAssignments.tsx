import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Assignment = {
  id: string; role: string; status: string; hours: number; form: { availability_confirmed?: boolean; cv_updated?: boolean; notes?: string; materials_needed?: string } | null; form_submitted_at: string | null
  group: { code: string; title_ar: string; title_en: string; program_title_ar: string; program_title_en: string; start_date: string | null } | null
}

/** The trainer's side of a proposal: fill the assignment form, then wait for the centre's decision. */
export default function MyAssignments() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { data, isLoading, refetch } = useGet<{ data: Assignment[] }>('/me/assignments', undefined, { staleTime: 0 })
  if (isLoading || !data) return <Spinner />
  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('assignments.title')} subtitle={t('assignments.subtitle')} />
      {data.data.length === 0 ? <Card><Empty text={t('assignments.empty')} /></Card> : data.data.map((a) => <AssignmentCard key={a.id} a={a} ar={ar} onSaved={() => void refetch()} />)}
    </div>
  )
}

function AssignmentCard({ a, ar, onSaved }: { a: Assignment; ar: boolean; onSaved: () => void }) {
  const { t } = useTranslation()
  const [f, setF] = useState({ availability_confirmed: a.form?.availability_confirmed ?? false, cv_updated: a.form?.cv_updated ?? false, notes: a.form?.notes ?? '', materials_needed: a.form?.materials_needed ?? '' })
  const [busy, setBusy] = useState(false)
  const open = a.status === 'proposed'
  const save = async () => {
    setBusy(true)
    try { await api.put(`/me/assignments/${a.id}/form`, { form: f }); toast(t('assignments.submitted')); onSaved() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <Card className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <div className="min-w-0 flex-1"><h3 className="truncate font-bold text-navy-900">{ar ? a.group?.program_title_ar : a.group?.program_title_en}</h3><p className="text-sm text-slate-500">{ar ? a.group?.title_ar : a.group?.title_en} · {fmt.date(a.group?.start_date)}</p></div>
        <Badge color={a.status === 'approved' ? 'green' : a.status === 'rejected' ? 'red' : 'gold'}>{t(`groups.trainers.${a.status}`)}</Badge>
      </div>
      {open ? (
        <div className="space-y-3">
          <label className="flex items-center gap-2 text-sm font-semibold text-navy-900"><input type="checkbox" checked={f.availability_confirmed} onChange={(e) => setF({ ...f, availability_confirmed: e.target.checked })} />{t('assignments.availability')}</label>
          <label className="flex items-center gap-2 text-sm font-semibold text-navy-900"><input type="checkbox" checked={f.cv_updated} onChange={(e) => setF({ ...f, cv_updated: e.target.checked })} />{t('assignments.cv')}</label>
          <Field label={t('assignments.materials')}><textarea className="input min-h-16" value={f.materials_needed} onChange={(e) => setF({ ...f, materials_needed: e.target.value })} /></Field>
          <Field label={t('assignments.notes')}><textarea className="input min-h-16" value={f.notes} onChange={(e) => setF({ ...f, notes: e.target.value })} /></Field>
          <div className="flex items-center gap-3"><Button variant="gold" loading={busy} disabled={!f.availability_confirmed} onClick={() => void save()}>{t('assignments.submit')}</Button>{a.form_submitted_at && <span className="text-xs text-slate-500">{t('assignments.waiting')}</span>}</div>
        </div>
      ) : null}
    </Card>
  )
}
