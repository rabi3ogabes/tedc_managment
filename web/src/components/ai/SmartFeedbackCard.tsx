/* eslint-disable @typescript-eslint/no-explicit-any */
import { Lightbulb } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Badge, Card } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useFeature } from '@/hooks/useFeature'

/** After an assessment: what the person chose, what was right and why, how common the mistake is, which competency, and what to review. */
export default function SmartFeedbackCard({ attemptId, review }: { attemptId: string; review: any[] }) {
  const { t, i18n } = useTranslation()
  const on = useFeature('ai')
  const res = useGet<{ data: any }>(on ? `/me/attempts/${attemptId}/smart-feedback` : null, undefined, { staleTime: 0, retry: false })
  const en = i18n.language === 'en'
  const d = res.data?.data
  if (!on || !d?.available) return null
  const wrong = d.questions.filter((q: any) => !q.correct)
  const stems = Object.fromEntries(review.map((r) => [r.id, en ? r.stem_en || r.stem_ar : r.stem_ar]))
  if (!wrong.length && !d.review.length) return null
  return (
    <Card className="space-y-4 border-gold-200 bg-gold-50/30">
      <h2 className="flex items-center gap-2 font-bold text-navy-900"><Lightbulb className="size-5 text-gold-600" />{t('aix.feedback.title')}</h2>
      {wrong.map((q: any) => (
        <div key={q.question_id} className="space-y-1 rounded-xl bg-white p-3 text-sm">
          <div className="font-semibold text-navy-900" dir="auto">{stems[q.question_id]}</div>
          {q.your_choice && <div className="text-slate-600">{t('aix.feedback.mistake')}: <span dir="auto">{en ? q.your_choice.en || q.your_choice.ar : q.your_choice.ar}</span></div>}
          {q.right_choice && <div className="text-emerald-800">{t('aix.feedback.right')}: <span dir="auto">{en ? q.right_choice.en || q.right_choice.ar : q.right_choice.ar}</span></div>}
          {(en ? q.explanation_en : q.explanation_ar) && <div className="text-xs text-slate-500">{en ? q.explanation_en : q.explanation_ar}</div>}
          <div className="flex flex-wrap items-center gap-2 pt-1">
            {q.same_mistake_percent != null && <Badge color="amber">{t('aix.feedback.sameMistake', { n: q.same_mistake_percent })}</Badge>}
            {q.skills.map((s: any, i: number) => <Badge key={i} color="blue">{en ? s.en || s.ar : s.ar}</Badge>)}
          </div>
        </div>
      ))}
      {d.review.length > 0 && (
        <div>
          <h3 className="mb-1 text-sm font-bold text-navy-900">{t('aix.feedback.review')}</h3>
          <ul className="space-y-1 text-sm">{d.review.map((r: any) => <li key={r.lesson_id}><Link to={r.route} className="font-semibold text-link hover:underline">{r.title}</Link></li>)}</ul>
        </div>
      )}
    </Card>
  )
}
