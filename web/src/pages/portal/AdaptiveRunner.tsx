/* eslint-disable @typescript-eslint/no-explicit-any */
import clsx from 'clsx'
import { CheckCircle2, Compass, Info, XCircle } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import QuestionInput, { type ExamQuestion } from '@/components/assessment/QuestionInput'
import { Button, Card, Modal } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

type Level = 'easy' | 'medium' | 'hard'
type Text = { ar: string; en: string }
export type AdaptiveState = { total: number; answered: number; reveal: boolean; current_id: string | null; level: Level | null; skill: Text | null; why: Text | null; steps: { level: Level; answered: boolean; correct: boolean | null }[] }
export type AdaptiveAttempt = { id: string; status: string; remaining_seconds: number | null; answers: Record<string, any>; questions: ExamQuestion[]; adaptive: AdaptiveState }
type Answered = { correct: boolean | null; graded: boolean; explanation: Text | null; next: ExamQuestion | null; level: Level | null; skill: Text | null; why: Text | null; done: boolean; answered: number; total: number; remaining_seconds: number | null }

const LEVELS: Level[] = ['easy', 'medium', 'hard']
const answeredValue = (v: any) => v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && v.length === 0) && !(typeof v === 'object' && !Array.isArray(v) && Object.keys(v).length === 0)
const mmss = (s: number) => `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`

/** The level as three bars, like a signal: one for easy, two for medium, three for hard. */
function LevelChip({ level }: { level: Level }) {
  const { t } = useTranslation()
  const n = LEVELS.indexOf(level) + 1
  return (
    <span className="inline-flex items-center gap-2 rounded-full bg-navy-50 px-3 py-1 text-xs font-bold text-navy-900 ring-1 ring-inset ring-navy-100">
      <span aria-hidden className="flex items-end gap-0.5">{[1, 2, 3].map((i) => <span key={i} className={clsx('w-1 rounded-sm transition-colors duration-200', i <= n ? 'bg-gold-600' : 'bg-navy-200')} style={{ height: 4 + i * 3 }} />)}</span>
      {t('assess.adaptive.level', { level: t(`assess.difficulty.${level}`) })}
    </span>
  )
}

/**
 * Adaptive practice: one question at a time, each chosen from the learner's mastery and their answers so far, with the
 * reason it was chosen. Where the assessment allows, the learner hears right or wrong straight away.
 */
export default function AdaptiveRunner({ start }: { start: AdaptiveAttempt }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const nav = useNavigate()
  const tx = (v: Text | null | undefined) => (v ? (ar ? v.ar : v.en) || v.ar || v.en : '')
  const current = start.questions.find((q) => q.id === start.adaptive.current_id) ?? null
  const [question, setQuestion] = useState<ExamQuestion | null>(current)
  const [value, setValue] = useState<any>(current ? start.answers[current.id] : undefined)
  const [now, setNow] = useState({ level: start.adaptive.level, skill: start.adaptive.skill, why: start.adaptive.why })
  const [steps, setSteps] = useState(start.adaptive.steps)
  const [result, setResult] = useState<Answered | null>(null)
  const [busy, setBusy] = useState(false)
  const [confirm, setConfirm] = useState(false)
  const [left, setLeft] = useState<number | null>(start.remaining_seconds)
  const finished = useRef(false)
  const nextRef = useRef<HTMLButtonElement>(null)
  const total = start.adaptive.total
  const reveal = start.adaptive.reveal
  const position = Math.min(total, steps.length)

  const submit = useCallback(async () => {
    if (finished.current) return
    finished.current = true
    try { await api.post(`/me/attempts/${start.id}/submit`); nav(`/portal/attempts/${start.id}`, { replace: true }) } catch (e) { finished.current = false; toast(errorMessage(e), 'error') }
  }, [nav, start.id])

  useEffect(() => { if (left === null || finished.current) return; const i = window.setInterval(() => setLeft((s) => (s === null ? s : Math.max(0, s - 1))), 1000); return () => window.clearInterval(i) }, [left === null]) // eslint-disable-line react-hooks/exhaustive-deps
  useEffect(() => { if (left === 0) void submit() }, [left, submit])
  useEffect(() => { if (result) nextRef.current?.focus() }, [result])
  // A draft of the answer on screen survives a dropped connection (the server keeps only the question on screen).
  useEffect(() => {
    if (!question || result || !answeredValue(value)) return
    const id = window.setTimeout(() => void api.put(`/me/attempts/${start.id}/answers`, { answers: { [question.id]: value } }).catch(() => undefined), 1200)
    return () => window.clearTimeout(id)
  }, [question, value, result, start.id])

  const check = async () => {
    if (!question || busy) return
    setBusy(true)
    try {
      const { data } = await api.post(`/me/attempts/${start.id}/adaptive-answer`, { question_id: question.id, answer: value ?? null })
      const r: Answered = data.data
      setResult(r)
      setSteps((all) => all.map((s, i) => (i === all.length - 1 ? { ...s, answered: true, correct: reveal ? r.correct : null } : s)))
      if (r.remaining_seconds !== null) setLeft(r.remaining_seconds)
    } catch (e) {
      const code = (e as { response?: { data?: { code?: string } } }).response?.data?.code
      if (code === 'attempt_expired' || code === 'attempt_closed') { finished.current = true; nav(`/portal/attempts/${start.id}`, { replace: true }); return }
      toast(errorMessage(e), 'error')
    } finally { setBusy(false) }
  }

  const next = () => {
    if (!result) return
    if (result.done || !result.next) { void submit(); return }
    setQuestion(result.next)
    setValue(undefined)
    setNow({ level: result.level, skill: result.skill, why: result.why })
    setSteps((all) => [...all, { level: result.level ?? 'medium', answered: false, correct: null }])
    setResult(null)
  }

  return (
    <div className="min-h-screen bg-ivory">
      <header className="sticky top-0 z-10 border-b border-navy-100 bg-white/95 px-4 py-3 shadow-sm backdrop-blur">
        <div className="mx-auto flex max-w-3xl flex-wrap items-center gap-3">
          <div className="min-w-0">
            <div className="text-xs font-semibold text-gold-700">{t('assess.adaptive.title')}</div>
            <div className="font-bold text-navy-900">{t('assess.runner.question', { n: position, total })}</div>
          </div>
          {left !== null && <span className="ms-auto font-extrabold tabular-nums text-navy-900" aria-label={String(t('assess.runner.timeLeft'))}>{mmss(left)}</span>}
          <Button variant="outline" className={left === null ? 'ms-auto' : undefined} onClick={() => setConfirm(true)}>{t('assess.adaptive.finish')}</Button>
        </div>
        {/* The path so far: one segment per question, coloured by the result where it is shown. */}
        <ol className="mx-auto mt-3 flex max-w-3xl gap-1" aria-label={String(t('assess.adaptive.path'))}>
          {Array.from({ length: total }, (_, i) => {
            const s = steps[i]
            const label = s ? `${t(`assess.difficulty.${s.level}`)}${s.correct === true ? ` · ${t('assess.adaptive.correct')}` : s.correct === false ? ` · ${t('assess.adaptive.notQuite')}` : ''}` : String(t('assess.adaptive.notYet'))
            return <li key={i} title={label} aria-label={label} className={clsx('h-1.5 flex-1 rounded-full transition-colors duration-200',
              !s ? 'bg-navy-100' : !s.answered ? 'bg-gold-400' : s.correct === true ? 'bg-emerald-500' : s.correct === false ? 'bg-rose-500' : 'bg-navy-400')} />
          })}
        </ol>
      </header>

      <main className="mx-auto max-w-3xl px-4 py-6">
        {question ? (
          <Card className="space-y-5">
            <div className="flex flex-wrap items-center gap-2">
              {now.level && <LevelChip level={now.level} />}
              {now.skill && <span className="rounded-full bg-gold-50 px-3 py-1 text-xs font-semibold text-gold-800 ring-1 ring-inset ring-gold-200">{tx(now.skill)}</span>}
            </div>
            {now.why && (
              <p className="flex items-start gap-2 rounded-xl bg-navy-50/60 p-3 text-sm text-navy-800">
                <Compass className="mt-0.5 size-4 shrink-0 text-gold-600" aria-hidden />
                <span><b className="font-semibold">{t('assess.adaptive.why')} </b>{tx(now.why)}</span>
              </p>
            )}
            <QuestionInput key={question.id} q={question} value={value} onChange={setValue} disabled={!!result || busy} />

            {result && (
              <div role="status" className={clsx('space-y-1 rounded-xl p-4 text-sm transition-[opacity,transform] duration-200 ease-[cubic-bezier(0.23,1,0.32,1)] starting:translate-y-1 starting:opacity-0 motion-reduce:transition-opacity motion-reduce:starting:translate-y-0',
                !reveal || result.correct === null ? 'bg-navy-50 text-navy-900' : result.correct ? 'bg-emerald-50 text-emerald-900' : 'bg-rose-50 text-rose-900')}>
                <div className="flex items-center gap-2 font-bold">
                  {!reveal || result.correct === null ? <Info className="size-5" /> : result.correct ? <CheckCircle2 className="size-5" /> : <XCircle className="size-5" />}
                  {!reveal ? t('assess.adaptive.saved') : result.correct === null ? t('assess.adaptive.pending') : result.correct ? t('assess.adaptive.correct') : t('assess.adaptive.notQuite')}
                </div>
                {result.explanation && tx(result.explanation) && <p className="leading-relaxed" dir="auto">{tx(result.explanation)}</p>}
                {result.done && <p className="font-semibold">{t('assess.adaptive.done')}</p>}
              </div>
            )}

            <div className="flex flex-wrap items-center gap-2 border-t border-navy-50 pt-4">
              {!result
                ? <Button variant="gold" loading={busy} disabled={!answeredValue(value)} onClick={() => void check()}>{t('assess.adaptive.check')}</Button>
                : <Button ref={nextRef} variant="gold" onClick={next}>{result.done || !result.next ? t('assess.adaptive.seeResult') : t('assess.adaptive.next')}</Button>}
              <span className="ms-auto text-xs text-slate-500">{t('assess.adaptive.answered', { n: steps.filter((s) => s.answered).length, total })}</span>
            </div>
          </Card>
        ) : (
          <Card className="space-y-4 text-center"><p>{t('assess.adaptive.done')}</p><Button variant="gold" onClick={() => void submit()}>{t('assess.adaptive.seeResult')}</Button></Card>
        )}
      </main>

      <Modal open={confirm} onClose={() => setConfirm(false)} title={t('assess.adaptive.finish')}>
        <div className="space-y-4"><p>{t('assess.adaptive.finishConfirm')}</p><div className="flex gap-2"><Button variant="gold" onClick={() => void submit()}>{t('assess.adaptive.finish')}</Button><Button variant="outline" onClick={() => setConfirm(false)}>{t('assess.adaptive.keepGoing')}</Button></div></div>
      </Modal>
    </div>
  )
}
