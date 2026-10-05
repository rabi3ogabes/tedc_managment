/* eslint-disable @typescript-eslint/no-explicit-any */
import clsx from 'clsx'
import { Check, Flag, Maximize } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router-dom'
import QuestionInput, { type ExamQuestion } from '@/components/assessment/QuestionInput'
import { Badge, Button, Card, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Attempt = { id: string; status: string; remaining_seconds: number | null; answers: Record<string, any>; questions: ExamQuestion[]; proctoring: { enabled: boolean; fullscreen: boolean; snapshots: boolean } }

const answered = (v: any) => v !== undefined && v !== null && v !== '' && !(Array.isArray(v) && v.length === 0) && !(typeof v === 'object' && !Array.isArray(v) && Object.keys(v).length === 0)
const mmss = (s: number) => `${String(Math.floor(s / 60)).padStart(2, '0')}:${String(s % 60).padStart(2, '0')}`

/** Distraction-free exam: timer owned by the server, autosave, question navigator, integrity events, resume after a disconnect. */
export default function ExamRunner() {
  const { id } = useParams()
  const { t } = useTranslation()
  const nav = useNavigate()
  const [attempt, setAttempt] = useState<Attempt | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [answers, setAnswers] = useState<Record<string, any>>({})
  const [cur, setCur] = useState(0)
  const [flags, setFlags] = useState<string[]>([])
  const [left, setLeft] = useState<number | null>(null)
  const [saving, setSaving] = useState<'saved' | 'saving' | 'offline'>('saved')
  const [warnings, setWarnings] = useState(0)
  const [confirm, setConfirm] = useState(false)
  const dirty = useRef(false)
  const answersRef = useRef(answers)
  const finished = useRef(false)

  // Start or resume (the server returns the open attempt when there is one).
  useEffect(() => {
    api.post(`/me/assessments/${id}/start`).then(({ data }) => { setAttempt(data.data); setAnswers(data.data.answers ?? {}); answersRef.current = data.data.answers ?? {}; setLeft(data.data.remaining_seconds) }).catch((e) => setError(errorMessage(e)))
  }, [id])

  const save = useCallback(async () => {
    if (!attempt || !dirty.current || finished.current) return
    dirty.current = false; setSaving('saving')
    try { const { data } = await api.put(`/me/attempts/${attempt.id}/answers`, { answers: answersRef.current }); setSaving('saved'); if (data.data.remaining_seconds !== null) setLeft(data.data.remaining_seconds) } catch (e) {
      const code = (e as { response?: { data?: { code?: string } } }).response?.data?.code
      if (code === 'attempt_expired' || code === 'attempt_closed') { finished.current = true; toast(t('assess.runner.timeUp')); nav(`/portal/attempts/${attempt.id}`, { replace: true }); return }
      dirty.current = true; setSaving('offline')
    }
  }, [attempt, nav, t])

  useEffect(() => { const i = window.setInterval(() => void save(), 5000); return () => window.clearInterval(i) }, [save])
  useEffect(() => { if (left === null || finished.current) return; const i = window.setInterval(() => setLeft((s) => (s === null ? s : Math.max(0, s - 1))), 1000); return () => window.clearInterval(i) }, [left === null])
  useEffect(() => { if (left === 0 && attempt && !finished.current) void submit() }, [left]) // eslint-disable-line react-hooks/exhaustive-deps

  const submit = async () => {
    if (!attempt || finished.current) return
    finished.current = true
    try { dirty.current = true; await api.put(`/me/attempts/${attempt.id}/answers`, { answers: answersRef.current }).catch(() => undefined); await api.post(`/me/attempts/${attempt.id}/submit`); nav(`/portal/attempts/${attempt.id}`, { replace: true }) } catch (e) { finished.current = false; toast(errorMessage(e), 'error') }
  }

  // Integrity events: reported only when the assessment turns proctoring on; the server ignores them otherwise.
  const report = useCallback(async (type: string) => {
    if (!attempt?.proctoring.enabled || finished.current) return
    try { const { data } = await api.post(`/me/attempts/${attempt.id}/events`, { type }); setWarnings(data.data.warnings ?? 0); if (data.data.submitted) { finished.current = true; toast(t('assess.runner.timeUp')); nav(`/portal/attempts/${attempt.id}`, { replace: true }) } } catch { /* the next event will try again */ }
  }, [attempt, nav, t])
  useEffect(() => {
    if (!attempt?.proctoring.enabled) return
    const vis = () => { if (document.hidden) void report('visibility_hidden') }
    const blur = () => void report('blur')
    const fs = () => { if (!document.fullscreenElement && attempt.proctoring.fullscreen) void report('fullscreen_exit') }
    const paste = (e: Event) => { e.preventDefault(); void report('paste') }
    const copy = (e: Event) => { e.preventDefault(); void report('copy') }
    const ctx = (e: Event) => { e.preventDefault(); void report('contextmenu') }
    const bc = typeof BroadcastChannel !== 'undefined' ? new BroadcastChannel(`exam-${attempt.id}`) : null
    bc?.addEventListener('message', () => void report('multi_tab')); bc?.postMessage('open')
    document.addEventListener('visibilitychange', vis); window.addEventListener('blur', blur); document.addEventListener('fullscreenchange', fs); document.addEventListener('paste', paste); document.addEventListener('copy', copy); document.addEventListener('contextmenu', ctx)
    return () => { document.removeEventListener('visibilitychange', vis); window.removeEventListener('blur', blur); document.removeEventListener('fullscreenchange', fs); document.removeEventListener('paste', paste); document.removeEventListener('copy', copy); document.removeEventListener('contextmenu', ctx); bc?.close() }
  }, [attempt, report])

  // Optional camera snapshots, only with the trainee's consent (the browser asks).
  useEffect(() => {
    if (!attempt?.proctoring.snapshots || !navigator.mediaDevices?.getUserMedia) return
    let stream: MediaStream | null = null
    const video = document.createElement('video')
    const timer = window.setInterval(() => {
      if (!stream || !video.videoWidth) return
      const c = document.createElement('canvas'); c.width = 320; c.height = Math.round((320 * video.videoHeight) / video.videoWidth)
      c.getContext('2d')?.drawImage(video, 0, 0, c.width, c.height)
      void api.post(`/me/attempts/${attempt.id}/snapshot`, { image: c.toDataURL('image/jpeg', 0.6) }).catch(() => undefined)
    }, 60_000)
    navigator.mediaDevices.getUserMedia({ video: true }).then((s) => { stream = s; video.srcObject = s; void video.play() }).catch(() => undefined)
    return () => { window.clearInterval(timer); stream?.getTracks().forEach((x) => x.stop()) }
  }, [attempt])

  const change = (qid: string, v: any) => { answersRef.current = { ...answersRef.current, [qid]: v }; setAnswers(answersRef.current); dirty.current = true; setSaving('saving') }

  if (error) return <div className="mx-auto max-w-xl py-16"><Card className="space-y-4 text-center"><p className="text-danger">{error}</p><Link to="/portal/assessments" className="font-bold text-link">{t('assess.runner.back')}</Link></Card></div>
  if (!attempt) return <Spinner className="min-h-screen" />
  const qs = attempt.questions
  const q = qs[cur]
  const pending = qs.filter((x) => !answered(answers[x.id])).length

  return (
    <div className="min-h-screen bg-ivory">
      <header className="sticky top-0 z-10 flex flex-wrap items-center gap-3 border-b border-navy-100 bg-white px-4 py-3 shadow-sm">
        <span className="font-bold text-navy-900">{t('assess.runner.question', { n: cur + 1, total: qs.length })}</span>
        <span className={clsx('text-xs', saving === 'offline' ? 'text-danger' : 'text-slate-400')} aria-live="polite">{saving === 'saved' ? t('assess.runner.saved') : saving === 'saving' ? t('assess.runner.saving') : t('assess.runner.offline')}</span>
        {warnings > 0 && <Badge color="red">{t('assess.runner.warn', { n: warnings })}</Badge>}
        {attempt.proctoring.fullscreen && !document.fullscreenElement && <Button size="sm" variant="outline" icon={<Maximize className="size-4" />} onClick={() => void document.documentElement.requestFullscreen?.()}>{t('assess.runner.enterFullscreen')}</Button>}
        <span className="ms-auto text-lg font-extrabold tabular-nums text-navy-900" aria-label={t('assess.runner.timeLeft')}>{left !== null ? mmss(left) : '∞'}</span>
        <Button variant="gold" onClick={() => setConfirm(true)}>{t('assess.runner.submit')}</Button>
      </header>
      <div className="mx-auto grid max-w-6xl gap-6 px-4 py-6 lg:grid-cols-[1fr_16rem]">
        <Card className="space-y-6">
          {q && <QuestionInput key={q.id} q={q} value={answers[q.id]} onChange={(v) => change(q.id, v)} />}
          <div className="flex flex-wrap items-center gap-2 border-t border-navy-50 pt-4">
            <Button variant="outline" disabled={cur === 0} onClick={() => setCur(cur - 1)}>{t('assess.runner.prev')}</Button>
            <Button variant="outline" disabled={cur === qs.length - 1} onClick={() => setCur(cur + 1)}>{t('assess.runner.next')}</Button>
            <button type="button" aria-pressed={flags.includes(q?.id)} onClick={() => setFlags(flags.includes(q.id) ? flags.filter((x) => x !== q.id) : [...flags, q.id])} className={clsx('ms-auto flex items-center gap-1.5 rounded-xl px-3 py-2 text-sm font-bold', flags.includes(q?.id) ? 'bg-amber-100 text-amber-900' : 'text-slate-500 hover:bg-white')}><Flag className="size-4" />{flags.includes(q?.id) ? t('assess.runner.flagged') : t('assess.runner.flag')}</button>
          </div>
        </Card>
        <nav aria-label="questions" className="grid h-fit grid-cols-5 gap-2">{qs.map((x, i) => (
          <button key={x.id} type="button" onClick={() => setCur(i)} aria-current={i === cur} className={clsx('relative grid aspect-square place-items-center rounded-xl border text-sm font-bold', i === cur ? 'border-navy-900 bg-navy-900 text-white' : answered(answers[x.id]) ? 'border-emerald-400 bg-emerald-50 text-emerald-800' : 'border-navy-100 bg-white text-slate-500')}>{answered(answers[x.id]) && i !== cur ? <Check className="size-4" /> : i + 1}{flags.includes(x.id) && <span className="absolute -end-1 -top-1 size-3 rounded-full bg-amber-500" />}</button>))}</nav>
      </div>
      <Modal open={confirm} onClose={() => setConfirm(false)} title={t('assess.runner.submit')}>
        <div className="space-y-4"><p>{t('assess.runner.confirm')}</p>{pending > 0 && <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-900">{t('assess.runner.unanswered', { n: pending })}</p>}<div className="flex gap-2"><Button variant="gold" onClick={() => void submit()}>{t('assess.runner.submit')}</Button><Button variant="outline" onClick={() => setConfirm(false)}>{t('assess.runner.prev')}</Button></div></div>
      </Modal>
    </div>
  )
}

type Review = { id: string; stem_ar: string; stem_en: string | null; your_answer: any; correct: boolean | null; points_awarded: number | null; max_points: number; correct_answer: any; explanation_ar: string | null; explanation_en: string | null; comment: string | null }
type Result = { id: string; status: string; passed: boolean | null; score_percent: number | null; points: number | null; max_points: number | null; feedback: string | null; review: Review[]; submitted_at: string | null }

/** The result page, as far as the feedback rules allow. */
export function AttemptResult() {
  const { id } = useParams()
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const { data, isLoading } = useGet<{ data: Result }>(`/me/attempts/${id}/result`, undefined, { staleTime: 0 })
  if (isLoading || !data) return <Spinner className="min-h-[50vh]" />
  const r = data.data
  const show = (v: any) => (v === null || v === undefined ? '—' : typeof v === 'object' ? JSON.stringify(v) : String(v))
  return (
    <div className="mx-auto max-w-3xl space-y-5 px-4 py-8">
      <Card className="space-y-3 text-center">
        {r.status === 'grading' ? <><h1 className="text-2xl font-extrabold text-navy-900">{t('assess.runner.waiting')}</h1></>
          : r.score_percent !== null ? <><div className="text-5xl font-extrabold tabular-nums text-navy-900">{fmt.number(r.score_percent, 1)}%</div>{r.passed !== null && <Badge color={r.passed ? 'green' : 'red'}>{r.passed ? t('assess.runner.passed') : t('assess.runner.failed')}</Badge>}{r.points !== null && <p className="text-sm text-slate-500">{fmt.number(r.points, 1)} / {fmt.number(r.max_points, 1)}</p>}</>
            : <><h1 className="text-xl font-bold text-navy-900">{t('assess.runner.hidden')}</h1>{r.passed !== null && <Badge color={r.passed ? 'green' : 'red'}>{r.passed ? t('assess.runner.passed') : t('assess.runner.failed')}</Badge>}</>}
        {r.feedback && <p className="rounded-xl bg-ivory p-3 text-sm"><b>{t('assess.runner.feedback')}:</b> {r.feedback}</p>}
        <Link to="/portal/assessments" className="font-bold text-link">{t('assess.runner.back')}</Link>
      </Card>
      {r.review.length > 0 && (
        <Card className="space-y-4"><h2 className="font-bold text-navy-900">{t('assess.runner.review')}</h2>
          {r.review.map((x, i) => (
            <div key={x.id} className={clsx('space-y-1 rounded-xl border p-3', x.correct === true ? 'border-emerald-200 bg-emerald-50/50' : x.correct === false ? 'border-red-200 bg-red-50/50' : 'border-navy-100')}>
              <div className="font-semibold text-navy-900">{i + 1}. {ar ? x.stem_ar : x.stem_en || x.stem_ar}</div>
              <div className="text-sm text-slate-600">{t('assess.runner.yourAnswer')}: <span dir="auto">{show(x.your_answer)}</span></div>
              {x.correct !== true && x.correct_answer !== null && <div className="text-sm text-emerald-800">{t('assess.runner.correctAnswer')}: <span dir="auto">{show(x.correct_answer)}</span></div>}
              {(ar ? x.explanation_ar : x.explanation_en) && <div className="text-xs text-slate-500">{t('assess.runner.explanation')}: {ar ? x.explanation_ar : x.explanation_en}</div>}
              {x.comment && <div className="text-xs text-amber-800">{x.comment}</div>}
              {x.points_awarded !== null && <div className="text-xs text-slate-400">{x.points_awarded} / {x.max_points}</div>}
            </div>))}
        </Card>
      )}
    </div>
  )
}
