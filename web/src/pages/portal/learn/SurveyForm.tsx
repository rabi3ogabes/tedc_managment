import clsx from 'clsx'
import { CheckCircle2, Star } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import type { LessonDetail, ProgressResult } from './types'

/** In-course survey: star ratings, 0–10 recommendation score, choices and free text. */
export default function SurveyForm({ lesson, onProgress }: { lesson: LessonDetail; onProgress: (r: ProgressResult) => void }) {
  const { t } = useTranslation()
  const survey = lesson.survey!
  const [answers, setAnswers] = useState<Record<string, string | number | string[]>>({})
  const [done, setDone] = useState(survey.submitted)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const set = (id: string, v: string | number | string[]) => setAnswers((a) => ({ ...a, [id]: v }))

  const submit = async () => {
    const missing = survey.questions.find((q) => q.required && (answers[q.id] == null || answers[q.id] === '' || (Array.isArray(answers[q.id]) && (answers[q.id] as string[]).length === 0)))
    if (missing) { setError(t('learn.survey.required')); return }
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: ProgressResult }>(`/me/lessons/${lesson.id}/survey`, { answers })
      onProgress(res.data.data)
      setDone(true)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  if (done) return <Card className="mx-auto max-w-xl py-10 text-center"><CheckCircle2 className="mx-auto size-14 text-emerald-500" /><p className="mt-3 text-xl font-bold text-navy-900">{t('learn.survey.thanks')}</p></Card>

  return (
    <div className="space-y-4">
      {survey.questions.map((q, i) => (
        <Card key={q.id} className="space-y-3">
          <div className="font-bold text-navy-900">{i + 1}. {q.text}{q.required && <span className="text-danger"> *</span>}</div>
          {q.type === 'rating' && (
            <div className="flex gap-1.5" dir="ltr">{[1, 2, 3, 4, 5].map((n) => <button key={n} type="button" aria-label={`${n}`} onClick={() => set(q.id, n)}><Star className={clsx('size-9 transition', Number(answers[q.id] ?? 0) >= n ? 'fill-gold-400 text-gold-500' : 'text-slate-300 hover:text-gold-300')} /></button>)}</div>
          )}
          {q.type === 'nps' && (
            <div><div className="flex flex-wrap gap-1.5" dir="ltr">{Array.from({ length: 11 }, (_, n) => <button key={n} type="button" onClick={() => set(q.id, n)} className={clsx('size-10 rounded-lg border text-sm font-bold transition', answers[q.id] === n ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white hover:border-gold-400')}>{n}</button>)}</div>
              <div className="mt-1 flex justify-between text-xs text-slate-500" dir="ltr"><span>{t('learn.survey.low')}</span><span>{t('learn.survey.high')}</span></div></div>
          )}
          {(q.type === 'choice' || q.type === 'multiple') && (
            <div className="grid gap-2">{q.options.map((o) => {
              const multiple = q.type === 'multiple'
              const cur = (answers[q.id] as string[] | string | undefined) ?? (multiple ? [] : '')
              const on = multiple ? (cur as string[]).includes(o.id) : cur === o.id
              return <button key={o.id} type="button" aria-pressed={on} onClick={() => set(q.id, multiple ? (on ? (cur as string[]).filter((x) => x !== o.id) : [...(cur as string[]), o.id]) : o.id)} className={clsx('rounded-xl border px-4 py-3 text-start text-sm transition', on ? 'border-navy-900 bg-navy-900/5 ring-2 ring-navy-900/10' : 'border-navy-100 hover:border-gold-400')}>{o.text}</button>
            })}</div>
          )}
          {q.type === 'text' && <textarea rows={3} className="input" placeholder={t('learn.survey.typeHere')} value={(answers[q.id] as string) ?? ''} onChange={(e) => set(q.id, e.target.value)} />}
        </Card>
      ))}
      {error && <p className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</p>}
      <Button variant="gold" size="lg" className="w-full" loading={busy} onClick={submit}>{t('learn.survey.submit')}</Button>
    </div>
  )
}
