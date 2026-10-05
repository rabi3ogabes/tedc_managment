/* eslint-disable @typescript-eslint/no-explicit-any */
import { CheckCircle2, ClipboardList } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router-dom'
import EvidenceInput, { noEvidence, type Evidence } from '@/components/EvidenceInput'
import { QuestionInput } from '@/components/surveys/SurveyForm'
import { Badge, Button, Card, Empty, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { isVisible, missingRequired, type Answers, type Question } from '@/lib/surveys'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Row = { id: string; kind: string; title_ar: string; title_en: string; program: string | null; status: string; due_at: string | null }

/** The evaluation forms waiting for the signed-in person: trainer reflection, planning evaluation, supervisor and specialist feedback. */
export default function MyEvaluations() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const list = useGet<{ data: Row[] }>('/me/evaluations', undefined, { staleTime: 0 })
  return (
    <>
      <PageHeader title={t('evalc.my.title')} />
      {list.isLoading ? <Spinner /> : !list.data?.data.length ? <Card><Empty text={t('evalc.my.empty')} icon={<ClipboardList className="size-6" />} /></Card> : (
        <div className="grid gap-4 lg:grid-cols-2">{list.data.data.map((r) => (
          <Card key={r.id} className="flex items-center gap-4">
            <div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{ar ? r.title_ar : r.title_en}</div><div className="truncate text-xs text-slate-500">{r.program}</div>{r.due_at && r.status === 'pending' && <div className="text-xs text-amber-700">{t('evalc.due')}: {fmt.date(r.due_at)}</div>}</div>
            {r.status === 'pending' ? <Link to={`/portal/evaluations/${r.id}`} className="rounded-xl bg-gold-500 px-4 py-2 text-sm font-bold text-navy-950">{t('evalc.my.fill')}</Link> : <Badge color="green"><CheckCircle2 className="me-1 inline size-4" />{t('evalc.my.done')}</Badge>}
          </Card>))}</div>
      )}
    </>
  )
}

export function MyEvaluationForm() {
  const { id } = useParams()
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const nav = useNavigate()
  const res = useGet<{ data: Row & { questions: (Question & { title_en?: string })[]; evidence_allowed: boolean; max_files: number } }>(`/me/evaluations/${id}`, undefined, { staleTime: 0 })
  const [answers, setAnswers] = useState<Answers>({})
  const [evidence, setEvidence] = useState<Record<string, Evidence>>({})
  const [busy, setBusy] = useState(false)
  if (res.isLoading || !res.data) return <Spinner />
  const f = res.data.data
  const qs = f.questions.map((q) => ({ ...q, title: !ar && q.title_en ? q.title_en : q.title }))

  const submit = async () => {
    if (missingRequired(qs, answers).length) { toast(t('evalc.my.required'), 'error'); return }
    const hasEvidence = Object.values(evidence).some((e) => e.files.length + e.links.length > 0)
    const fd = new FormData()
    Object.entries(answers).forEach(([k, v]) => { if (v !== undefined) (Array.isArray(v) ? v.forEach((x, i) => fd.append(`answers[${k}][${i}]`, String(x))) : typeof v === 'object' ? Object.entries(v).forEach(([a, b]) => fd.append(`answers[${k}][${a}]`, String(b))) : fd.append(`answers[${k}]`, String(v))) })
    Object.entries(evidence).forEach(([k, e]) => { e.files.forEach((file, i) => fd.append(`evidence[${k}][${i}]`, file)); e.links.forEach((l, i) => fd.append(`evidence[${k}][${e.files.length + i}]`, l)) })
    setBusy(true)
    try { await api.post(`/me/evaluations/${id}`, hasEvidence ? fd : { answers }); toast(t('evalc.my.submitted')); nav('/portal/evaluations') } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <div className="mx-auto max-w-3xl space-y-5">
      <PageHeader title={ar ? f.title_ar : f.title_en} subtitle={f.program ?? undefined} />
      {qs.filter((q) => isVisible(q, answers)).map((q) => q.type === 'section' ? <h3 key={q.id} className="pt-2 text-lg font-bold text-navy-900">{q.title}</h3> : (
        <Card key={q.id} className="space-y-3">
          <div className="font-semibold text-navy-900">{q.title}{q.required && <span className="text-danger"> *</span>}</div>
          <QuestionInput q={q} value={answers[q.id]} onChange={(v) => setAnswers({ ...answers, [q.id]: v })} accent="#8A1538" />
          {q.evidence && f.evidence_allowed && <EvidenceInput max={f.max_files} value={evidence[q.id] ?? noEvidence()} onChange={(v) => setEvidence({ ...evidence, [q.id]: v })} />}
        </Card>))}
      <div className="flex gap-2"><Button variant="gold" loading={busy} onClick={() => void submit()}>{t('evalc.my.submit')}</Button><Button variant="outline" to="/portal/evaluations">{t('evalc.my.back')}</Button></div>
    </div>
  )
}
