import clsx from 'clsx'
import { CheckCircle2, Clock, RotateCcw, XCircle } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Progress } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { clock, type LessonDetail, type ProgressResult } from './types'

type Result = {
  score_percent: number; points: number; total_points: number; passed: boolean; pass_percent: number; attempts: number; attempts_left: number | null; status: string
  review: Record<string, { correct: boolean; correct_options?: string[]; chosen?: string[]; explanation?: string | null }>
}

/** A quiz: all questions on one page, graded by the server, with the right answers and explanations shown as the lesson allows. */
export default function QuizRunner({ lesson, onProgress }: { lesson: LessonDetail; onProgress: (r: ProgressResult) => void }) {
  const { t } = useTranslation()
  const quiz = lesson.quiz!
  const [started, setStarted] = useState(false)
  const [answers, setAnswers] = useState<Record<string, string[]>>({})
  const [result, setResult] = useState<Result | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [left, setLeft] = useState<number | null>(null)
  const startedAt = useRef(0)
  const used = result?.attempts ?? quiz.attempts
  const exhausted = quiz.max_attempts != null && used >= quiz.max_attempts
  const answered = useMemo(() => quiz.questions.filter((q) => (answers[q.id] ?? []).length > 0).length, [answers, quiz.questions])

  const submit = async (auto = false) => {
    if (!auto && answered < quiz.questions.length) { setError(t('learn.quiz.answerAll')); return }
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: Result }>(`/me/lessons/${lesson.id}/quiz`, { answers, seconds: Math.round((Date.now() - startedAt.current) / 1000) })
      setResult(res.data.data)
      setStarted(false)
      setLeft(null)
      onProgress({ percent: res.data.data.passed ? 100 : res.data.data.score_percent, status: res.data.data.status, completed: res.data.data.status === 'completed' })
      if (auto) setError(t('learn.quiz.timeUp'))
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  const begin = () => {
    setAnswers({})
    setResult(null)
    setError(null)
    setStarted(true)
    startedAt.current = Date.now()
    if (quiz.time_limit_minutes) setLeft(quiz.time_limit_minutes * 60)
  }

  useEffect(() => {
    if (!started || left == null) return
    if (left <= 0) { void submit(true); return }
    const id = window.setTimeout(() => setLeft((x) => (x == null ? x : x - 1)), 1000)
    return () => window.clearTimeout(id)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [started, left])

  const pick = (qid: string, oid: string, multiple: boolean) => setAnswers((a) => {
    const cur = a[qid] ?? []
    return { ...a, [qid]: multiple ? (cur.includes(oid) ? cur.filter((x) => x !== oid) : [...cur, oid]) : [oid] }
  })

  if (!started && !result) {
    return (
      <Card className="mx-auto max-w-xl space-y-4 text-center">
        <div className="mx-auto grid size-16 place-items-center rounded-full bg-gold-100 text-3xl">📝</div>
        <h3 className="text-xl font-bold text-navy-900">{lesson.title}</h3>
        <div className="flex flex-wrap justify-center gap-2 text-sm text-slate-600">
          <span className="rounded-full bg-navy-100/70 px-3 py-1">{quiz.questions.length} ?</span>
          <span className="rounded-full bg-navy-100/70 px-3 py-1">{t('learn.quiz.passAt', { percent: quiz.pass_percent })}</span>
          <span className="rounded-full bg-navy-100/70 px-3 py-1">{quiz.max_attempts ? t('learn.quiz.attempts', { used, max: quiz.max_attempts }) : t('learn.quiz.attemptsFree')}</span>
          {quiz.time_limit_minutes && <span className="rounded-full bg-navy-100/70 px-3 py-1">{quiz.time_limit_minutes} {t('course.minutes')}</span>}
        </div>
        {lesson.progress.best_score != null && <p className="text-sm text-slate-600">{t('learn.quiz.result')}: <b className="text-navy-900">{fmt.number(lesson.progress.best_score, 0)}%</b></p>}
        <Button variant="gold" size="lg" disabled={exhausted || quiz.questions.length === 0} onClick={begin}>{exhausted ? t('learn.quiz.noAttempts') : t('learn.quiz.start')}</Button>
      </Card>
    )
  }

  if (result && !started) {
    return (
      <div className="space-y-5">
        <Card className={clsx('text-center', result.passed ? 'border-emerald-300 bg-emerald-50/50' : 'border-amber-300 bg-amber-50/50')}>
          <div className="text-5xl font-extrabold text-navy-900">{fmt.number(result.score_percent, 0)}%</div>
          <div className={clsx('mt-1 text-lg font-bold', result.passed ? 'text-emerald-700' : 'text-amber-800')}>{result.passed ? t('learn.quiz.passed') : t('learn.quiz.failed')}</div>
          <p className="text-sm text-slate-600">{fmt.number(result.points, 1)} / {fmt.number(result.total_points, 1)} · {t('learn.quiz.passAt', { percent: result.pass_percent })}</p>
          {error && <p className="mt-2 text-sm text-amber-800">{error}</p>}
          {!result.passed && <Button className="mt-4" variant="gold" icon={<RotateCcw className="size-4" />} disabled={result.attempts_left === 0} onClick={begin}>{result.attempts_left === 0 ? t('learn.quiz.noAttempts') : t('learn.quiz.retry')}</Button>}
        </Card>
        {quiz.questions.map((q, i) => {
          const r = result.review[q.id]
          return (
            <Card key={q.id} className="space-y-2">
              <div className="flex items-start gap-2">{r?.correct ? <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-emerald-600" /> : <XCircle className="mt-0.5 size-5 shrink-0 text-danger" />}<div className="font-semibold text-navy-900">{i + 1}. {q.text}</div></div>
              {r?.correct_options && q.options.map((o) => {
                const right = r.correct_options!.includes(o.id)
                const chosen = r.chosen?.includes(o.id)
                return <div key={o.id} className={clsx('rounded-lg border px-3 py-2 text-sm', right ? 'border-emerald-300 bg-emerald-50 text-emerald-900' : chosen ? 'border-red-300 bg-red-50 text-red-900' : 'border-navy-100 text-slate-600')}>{o.text}{right && ' ✓'}{chosen && !right && ' ✗'}</div>
              })}
              {r?.explanation && <p className="rounded-lg bg-ivory p-3 text-sm text-slate-700">{r.explanation}</p>}
              {r && !r.correct_options && <p className="text-sm text-slate-500">{r.correct ? t('learn.quiz.correct') : t('learn.quiz.wrong')}</p>}
            </Card>
          )
        })}
      </div>
    )
  }

  return (
    <div className="space-y-5">
      <div className="sticky top-2 z-10 flex items-center justify-between gap-3 rounded-2xl border border-navy-100 bg-white/95 p-3 shadow backdrop-blur">
        <div className="min-w-0 flex-1"><Progress value={(answered / quiz.questions.length) * 100} /><div className="mt-1 text-xs text-slate-500">{answered} / {quiz.questions.length}</div></div>
        {left != null && <div className={clsx('inline-flex items-center gap-1.5 font-mono text-sm font-bold', left < 60 ? 'text-danger' : 'text-navy-900')}><Clock className="size-4" />{clock(left)}</div>}
      </div>
      {quiz.questions.map((q, i) => (
        <Card key={q.id} className="space-y-3">
          <div className="text-xs font-semibold text-gold-700">{t('learn.quiz.question', { n: i + 1, total: quiz.questions.length })} · {q.points} {t('course.q.pts')} · {t(q.type === 'multiple' ? 'learn.quiz.pickMany' : 'learn.quiz.pickOne')}</div>
          <div className="text-lg font-bold text-navy-900">{q.text}</div>
          <div className="grid gap-2">
            {q.options.map((o) => {
              const on = (answers[q.id] ?? []).includes(o.id)
              return (
                <button key={o.id} type="button" aria-pressed={on} onClick={() => pick(q.id, o.id, q.type === 'multiple')} className={clsx('flex items-center gap-3 rounded-xl border px-4 py-3 text-start text-sm transition', on ? 'border-navy-900 bg-navy-900/5 ring-2 ring-navy-900/10' : 'border-navy-100 bg-white hover:border-gold-400')}>
                  <span className={clsx('grid size-5 shrink-0 place-items-center border-2', q.type === 'multiple' ? 'rounded-md' : 'rounded-full', on ? 'border-navy-900 bg-navy-900' : 'border-navy-200')}>{on && <span className="size-2 rounded-full bg-gold-300" />}</span>{o.text}
                </button>
              )
            })}
          </div>
        </Card>
      ))}
      {error && <p className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</p>}
      <Button variant="gold" size="lg" className="w-full" loading={busy} onClick={() => void submit()}>{t('learn.quiz.submit')}</Button>
    </div>
  )
}
