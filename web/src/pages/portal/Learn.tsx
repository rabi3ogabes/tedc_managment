import clsx from 'clsx'
import { ArrowLeft, ArrowRight, Award, CheckCircle2, ClipboardList, ExternalLink, FileText, Link2, ListChecks, Lock, Package, PartyPopper, Play, Presentation, Video } from 'lucide-react'
import { useCallback, useEffect, useRef, useState, type ComponentType } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams } from 'react-router-dom'
import { Badge, Button, Card, ErrorState, Progress, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import AdaptivePathCard from '@/components/ai/AdaptivePathCard'
import LessonSocial from '@/components/social/LessonSocial'
import PackagePlayer from './learn/PackagePlayer'
import Article from './learn/Article'
import QuizRunner from './learn/QuizRunner'
import SlidesViewer from './learn/SlidesViewer'
import SurveyForm from './learn/SurveyForm'
import { clock, type LessonDetail, type Outline, type OutlineLesson, type ProgressResult } from './learn/types'
import VideoPlayer from './learn/VideoPlayer'

const ICONS: Record<OutlineLesson['type'], ComponentType<{ className?: string }>> = { video: Video, presentation: Presentation, quiz: ListChecks, survey: ClipboardList, article: FileText, package: Package, lti: Link2, external: ExternalLink }

function Ring({ value }: { value: number }) {
  const r = 26
  const c = 2 * Math.PI * r
  return (
    <svg viewBox="0 0 64 64" className="size-16 -rotate-90" aria-label={`${Math.round(value)}%`}>
      <circle cx="32" cy="32" r={r} fill="none" strokeWidth="6" className="stroke-white/20" />
      <circle cx="32" cy="32" r={r} fill="none" strokeWidth="6" strokeLinecap="round" className="stroke-gold-400 transition-all duration-700" strokeDasharray={c} strokeDashoffset={c - (c * Math.min(100, value)) / 100} />
    </svg>
  )
}

/** The learner's online course: outline with progress and locks on one side, the lesson player on the other. */
export default function Learn() {
  const { registrationId, lessonId } = useParams()
  const { t } = useTranslation()
  const navigate = useNavigate()
  const outline = useGet<{ data: Outline }>(`/me/registrations/${registrationId}/course`, undefined, { staleTime: 0 })
  const o = outline.data?.data

  const [lesson, setLesson] = useState<LessonDetail | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [justDone, setJustDone] = useState(false)
  const topRef = useRef<HTMLDivElement>(null)

  // Load the lesson from the URL.
  useEffect(() => {
    if (!lessonId) { setLesson(null); return }
    let cancelled = false
    setLoading(true)
    setError(null)
    setJustDone(false)
    api.get<{ data: LessonDetail }>(`/me/lessons/${lessonId}`)
      .then((r) => { if (!cancelled) { setLesson(r.data.data); topRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' }) } })
      .catch((e) => { if (!cancelled) { setLesson(null); setError(errorMessage(e)) } })
      .finally(() => !cancelled && setLoading(false))
    return () => { cancelled = true }
  }, [lessonId])

  const flat = o?.modules.flatMap((m) => m.lessons) ?? []
  const index = flat.findIndex((l) => l.id === lessonId)
  const next = index >= 0 ? flat.slice(index + 1).find((l) => !l.locked) ?? null : null

  const onProgress = useCallback((r: ProgressResult) => {
    if (r.completed) setJustDone(true)
    // Keep the outline's numbers current without waiting for the next visit.
    outline.refetch()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const complete = async () => {
    if (!lesson) return
    try { const r = await api.post<{ data: ProgressResult }>(`/me/lessons/${lesson.id}/complete`); onProgress(r.data.data); setLesson({ ...lesson, progress: { ...lesson.progress, status: 'completed', percent: 100 } }) } catch (e) { setError(errorMessage(e)) }
  }

  if (outline.isLoading) return <Spinner />
  if (outline.error || !o) return <ErrorState onRetry={() => outline.refetch()} />
  const s = o.summary

  return (
    <div ref={topRef} className="space-y-5">
      <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-navy-900 via-navy-800 to-navy-700 p-6 text-white shadow-glass">
        <div className="pattern-bg absolute inset-0 opacity-20" />
        <div className="relative flex flex-wrap items-center gap-5">
          <div className="relative grid place-items-center"><Ring value={s.percent} /><span className="absolute text-sm font-bold">{Math.round(s.percent)}%</span></div>
          <div className="min-w-0 flex-1">
            <Link to="/portal/training" className="text-xs font-semibold text-gold-300 hover:underline">← {t('portal.myTraining')}</Link>
            <h1 className="truncate text-2xl font-extrabold">{o.program.title}</h1>
            <p className="text-sm text-white/75">{t('learn.lessonsDone', { done: s.completed, total: s.required })}{s.duration_seconds > 0 && ` · ${clock(s.duration_seconds)}`}</p>
          </div>
          {!lessonId && (s.resume_lesson_id || s.next_lesson_id) && <Button variant="gold" size="lg" icon={<Play className="size-5" />} onClick={() => navigate(`/portal/learn/${registrationId}/${s.resume_lesson_id ?? s.next_lesson_id}`)}>{s.resume_lesson_id ? t('learn.resume') : t('learn.start')}</Button>}
        </div>
      </div>

      {s.certificate && (s.completed_course || s.certificate.issued) && (
        <Card className={clsx('flex flex-wrap items-center gap-4', s.certificate.issued ? 'border-gold-400 bg-gold-100/40' : 'border-navy-100')}>
          <Award className="size-10 shrink-0 text-gold-600" />
          <div className="min-w-0 flex-1">
            <div className="font-bold text-navy-900">{s.certificate.issued ? t('learn.cert.issued') : t('learn.cert.pending')}</div>
            <div className="text-sm text-slate-600">{s.certificate.issued ? (s.certificate.downloadable ? t('learn.cert.ready') : t('learn.cert.needSurvey')) : s.certificate.missing.join(' · ')}</div>
          </div>
          {s.certificate.issued && <Button variant="gold" to="/portal/certificates">{t('learn.cert.open')}</Button>}
        </Card>
      )}

      {s.completed_course && !lessonId && (
        <Card className="flex items-center gap-4 border-emerald-300 bg-emerald-50/60"><PartyPopper className="size-10 shrink-0 text-emerald-600" /><div><div className="font-bold text-emerald-900">{t('learn.allDone')}</div><div className="text-sm text-emerald-800">{t('learn.allDoneHint')}</div></div></Card>
      )}

      <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_21rem]">
        <div className="min-w-0 space-y-4">
          {loading && <Spinner />}
          {error && <Card className="flex items-center gap-3 border-amber-300 bg-amber-50 text-amber-900"><Lock className="size-6 shrink-0" />{error}</Card>}
          {lesson && !loading && (
            <>
              <div>
                <div className="mb-1 flex flex-wrap items-center gap-2"><Badge color="gold">{t(`course.types.${lesson.type}`)}</Badge>{!lesson.is_required && <Badge color="gray">{t('learn.optional')}</Badge>}{lesson.progress.status === 'completed' && <Badge color="green">{t('learn.done')}</Badge>}</div>
                <h2 className="text-2xl font-extrabold text-navy-900">{lesson.title}</h2>
                {lesson.description && <p className="mt-1 text-slate-600">{lesson.description}</p>}
              </div>
              {lesson.type === 'video' && <VideoPlayer key={lesson.id} lesson={lesson} onProgress={onProgress} />}
              {lesson.type === 'presentation' && <SlidesViewer key={lesson.id} lesson={lesson} onProgress={onProgress} onConfirm={complete} />}
              {lesson.type === 'quiz' && <QuizRunner key={lesson.id} lesson={lesson} onProgress={onProgress} />}
              {lesson.type === 'survey' && <SurveyForm key={lesson.id} lesson={lesson} onProgress={onProgress} />}
              {(lesson.type === 'package' || lesson.type === 'lti') && <PackagePlayer key={lesson.id} lesson={lesson} onProgress={onProgress} />}
              {lesson.type === 'article' && <Article key={lesson.id} lesson={lesson} onDone={complete} />}
              {lesson.type === 'video' && lesson.progress.status !== 'completed' && <p className="text-xs text-slate-500">{t('learn.needWatch', { percent: lesson.rules.min_watch_percent })}</p>}

              {(justDone || lesson.progress.status === 'completed') && (
                <Card className="flex flex-wrap items-center justify-between gap-3 border-emerald-300 bg-emerald-50/50">
                  <div className="flex items-center gap-2 font-semibold text-emerald-800"><CheckCircle2 className="size-5" />{t('learn.lessonDone')}</div>
                  {next ? <Button variant="gold" onClick={() => navigate(`/portal/learn/${registrationId}/${next.id}`)}>{t('learn.next')}: {next.title}<ArrowRight className="size-4 rtl:rotate-180" /></Button>
                    : <Button variant="outline" icon={<ArrowLeft className="size-4 rtl:rotate-180" />} onClick={() => navigate(`/portal/learn/${registrationId}`)}>{t('learn.back')}</Button>}
                </Card>
              )}
              {o && <LessonSocial key={lesson.id} lessonId={lesson.id} programId={o.program.id} />}
            </>
          )}
          {!lessonId && !loading && (
            <Card className="py-10 text-center text-slate-600">{s.lessons === 0 ? t('course.empty') : t('learn.resume')}</Card>
          )}
        </div>

        <aside className="space-y-3 lg:sticky lg:top-4 lg:self-start">
          <AdaptivePathCard registrationId={registrationId!} />
          <h3 className="font-bold text-navy-900">{t('learn.outline')}</h3>
          {o.modules.map((m, mi) => (
            <div key={m.id} className="overflow-hidden rounded-2xl border border-navy-100 bg-white">
              <div className="flex items-center gap-2 bg-navy-900 px-3 py-2 text-sm font-bold text-white"><span className="grid size-6 place-items-center rounded-full bg-gold-500 text-xs text-navy-950">{fmt.number(mi + 1)}</span><span className="truncate">{m.title}</span></div>
              <ul className="divide-y divide-navy-100/70">
                {m.lessons.map((l) => {
                  const Icon = ICONS[l.type]
                  const active = l.id === lessonId
                  return (
                    <li key={l.id}>
                      <button type="button" disabled={l.locked} onClick={() => navigate(`/portal/learn/${registrationId}/${l.id}`)} className={clsx('flex w-full items-center gap-3 px-3 py-2.5 text-start transition', active ? 'bg-gold-100/70' : 'hover:bg-ivory', l.locked && 'cursor-not-allowed opacity-55')} title={l.locked ? t('learn.lockedHint') : undefined}>
                        <span className={clsx('grid size-8 shrink-0 place-items-center rounded-full', l.status === 'completed' ? 'bg-emerald-500 text-white' : 'bg-navy-100/70 text-navy-800')}>{l.status === 'completed' ? <CheckCircle2 className="size-4" /> : l.locked ? <Lock className="size-4" /> : <Icon className="size-4" />}</span>
                        <span className="min-w-0 flex-1">
                          <span className="block truncate text-sm font-semibold text-navy-900">{l.title}</span>
                          <span className="flex items-center gap-2 text-[11px] text-slate-500">{t(`course.types.${l.type}`)}{l.duration_seconds > 0 && ` · ${clock(l.duration_seconds)}`}{!l.is_required && ` · ${t('learn.optional')}`}{l.status === 'in_progress' && l.percent > 0 && ` · ${Math.round(l.percent)}%`}{l.score != null && ` · ${Math.round(l.score)}%`}</span>
                          {l.status === 'in_progress' && l.percent > 0 && <span className="mt-1 block"><Progress value={l.percent} /></span>}
                        </span>
                      </button>
                    </li>
                  )
                })}
              </ul>
            </div>
          ))}
        </aside>
      </div>
    </div>
  )
}
