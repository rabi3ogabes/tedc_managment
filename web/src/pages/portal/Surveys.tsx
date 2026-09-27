import { CheckCircle2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, PageHeader, Spinner, StatusBadge } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'

type Survey = { id: string; program: string; stage_days: number; status: string; scheduled_for: string; answers: Record<string, unknown> | null }

export default function Surveys() {
  const { t } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: Survey[] }>('/me/surveys')
  return (
    <>
      <PageHeader title={t('portal.surveys')} />
      {isLoading ? <Spinner /> : !data?.data.length ? <Card><Empty /></Card> : (
        <div className="grid gap-4 lg:grid-cols-2">{data.data.map((s) => <SurveyCard key={s.id} survey={s} onDone={refetch} />)}</div>
      )}
    </>
  )
}

function SurveyCard({ survey, onDone }: { survey: Survey; onDone: () => void }) {
  const { t } = useTranslation()
  const [form, setForm] = useState({ applied_learning: 'yes', changes_observed: '', skills: '', needs_support: false, support_details: '' })
  const [error, setError] = useState<string | null>(null)
  const done = survey.status === 'completed'

  const submit = async () => {
    try {
      await api.post(`/me/surveys/${survey.id}`, { ...form, skills_improved: form.skills.split(/[,،]/).map((s) => s.trim()).filter(Boolean) })
      onDone()
    } catch (e) { setError(errorMessage(e)) }
  }

  return (
    <Card>
      <div className="flex items-start justify-between">
        <div><h3 className="font-bold text-navy-900">{survey.program}</h3><div className="text-xs text-slate-400">{fmt.date(survey.scheduled_for)}</div></div>
        <div className="flex gap-2"><Badge color="gold">{survey.stage_days} {t('admin.impact.days')}</Badge><StatusBadge status={survey.status} /></div>
      </div>
      {done ? (
        <p className="mt-4 flex items-center gap-2 text-sm text-emerald-700"><CheckCircle2 className="size-5" />{t('common.saved')}</p>
      ) : (
        <div className="mt-4 space-y-3">
          <div>
            <span className="label">{t('portal.survey.applied')}</span>
            <div className="flex gap-2">{(['yes', 'partially', 'no'] as const).map((v) => (
              <button key={v} onClick={() => setForm({ ...form, applied_learning: v })} className={`flex-1 rounded-xl border px-3 py-2 text-sm font-semibold ${form.applied_learning === v ? 'border-gold-500 bg-gold-100 text-navy-900' : 'border-navy-100 text-slate-500'}`}>{t(`portal.survey.${v}`)}</button>
            ))}</div>
          </div>
          <Field label={t('portal.survey.changes')}><textarea className="input" value={form.changes_observed} onChange={(e) => setForm({ ...form, changes_observed: e.target.value })} /></Field>
          <Field label={t('portal.survey.skills')}><input className="input" value={form.skills} onChange={(e) => setForm({ ...form, skills: e.target.value })} /></Field>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={form.needs_support} onChange={(e) => setForm({ ...form, needs_support: e.target.checked })} />{t('portal.survey.support')}</label>
          {form.needs_support && <textarea className="input" placeholder={t('portal.survey.supportDetails')} value={form.support_details} onChange={(e) => setForm({ ...form, support_details: e.target.value })} />}
          {error && <p className="text-sm text-danger">{error}</p>}
          <Button variant="gold" onClick={submit}>{t('common.submit')}</Button>
        </div>
      )}
    </Card>
  )
}
