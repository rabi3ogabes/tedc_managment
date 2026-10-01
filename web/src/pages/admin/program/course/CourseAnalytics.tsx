import clsx from 'clsx'
import { Clock, GraduationCap, Hourglass, Users } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Card, CardTitle, Progress, Spinner, StatCard, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import { fmtDuration } from './api'

type Analytics = {
  summary: { learners: number; started: number; completed: number; avg_percent: number; watch_hours: number; stalled: number }
  lessons: {
    id: string; type: string; title: string; is_required: boolean; duration_seconds: number; started: number; completed: number; completion_rate: number; avg_percent: number; avg_minutes: number
    retention?: number[]
    quiz?: { attempts: number; avg_score: number | null; pass_rate: number | null; questions: { id: string; text: string; correct_rate: number | null }[] }
    survey?: { responses: number; questions: { id: string; type: string; text: string; answered: number; average?: number | null; distribution?: Record<string, number>; options?: { id: string; text: string; count: number }[]; texts?: string[] }[] }
  }[]
  learners: { registration_id: string; name: string; percent: number; completed: boolean; last_activity_at: string | null; watch_minutes: number }[]
}

/** Where viewers drop off: the share of learners who watched each twentieth of the video. */
function Retention({ values }: { values: number[] }) {
  return (
    <div className="flex h-16 items-end gap-0.5" dir="ltr" role="img" aria-label="retention">
      {values.map((v, i) => <div key={i} title={`${v}%`} className={clsx('flex-1 rounded-t', v >= 70 ? 'bg-emerald-500' : v >= 40 ? 'bg-gold-500' : 'bg-red-400')} style={{ height: `${Math.max(4, v)}%` }} />)}
    </div>
  )
}

export default function CourseAnalytics({ programId }: { programId: string }) {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: Analytics }>(`/admin/programs/${programId}/course/analytics`, undefined, { staleTime: 0 })
  if (isLoading || !data) return <Spinner />
  const d = data.data

  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label={t('course.an.learners')} value={`${fmt.number(d.summary.started)} / ${fmt.number(d.summary.learners)}`} icon={<Users className="size-5" />} hint={t('course.an.startedHint')} />
        <StatCard label={t('course.an.completed')} value={fmt.number(d.summary.completed)} icon={<GraduationCap className="size-5" />} accent />
        <StatCard label={t('course.an.avg')} value={`${fmt.number(d.summary.avg_percent, 1)}%`} icon={<Clock className="size-5" />} hint={t('course.an.watchHours', { hours: fmt.number(d.summary.watch_hours, 1) })} />
        <StatCard label={t('course.an.stalled')} value={fmt.number(d.summary.stalled)} icon={<Hourglass className="size-5" />} hint={t('course.an.stalledHint')} />
      </div>

      <div className="grid gap-5 lg:grid-cols-2">
        {d.lessons.map((l) => (
          <Card key={l.id} className="space-y-3">
            <div className="flex items-start justify-between gap-2"><div className="min-w-0"><div className="truncate font-bold text-navy-900">{l.title}</div><div className="mt-1 flex flex-wrap items-center gap-2 text-xs text-slate-500"><Badge color="gold">{t(`course.types.${l.type}`)}</Badge>{l.duration_seconds > 0 && <span>{fmtDuration(l.duration_seconds)}</span>}</div></div>
              <div className="text-end"><div className="text-2xl font-extrabold text-navy-900">{fmt.number(l.completion_rate, 0)}%</div><div className="text-[11px] text-slate-500">{t('course.an.completion')}</div></div></div>
            <Progress value={l.completion_rate} tone="green" />
            <div className="flex flex-wrap gap-x-5 gap-y-1 text-xs text-slate-600"><span>{t('course.an.started', { count: l.started })}</span><span>{t('course.an.avgWatched', { percent: fmt.number(l.avg_percent, 0) })}</span>{l.avg_minutes > 0 && <span>{t('course.an.avgMinutes', { minutes: fmt.number(l.avg_minutes, 1) })}</span>}</div>

            {l.retention && <div><div className="mb-1 text-xs font-semibold text-navy-800">{t('course.an.retention')}</div><Retention values={l.retention} /><div className="mt-1 flex justify-between text-[10px] text-slate-400" dir="ltr"><span>0:00</span><span>{fmtDuration(l.duration_seconds)}</span></div></div>}

            {l.quiz && (
              <div className="space-y-2 rounded-xl bg-ivory p-3">
                <div className="flex flex-wrap gap-x-5 text-xs text-slate-600"><span>{t('course.an.attempts', { count: l.quiz.attempts })}</span>{l.quiz.avg_score != null && <span>{t('course.an.avgScore', { score: fmt.number(l.quiz.avg_score, 0) })}</span>}{l.quiz.pass_rate != null && <span>{t('course.an.passRate', { rate: fmt.number(l.quiz.pass_rate, 0) })}</span>}</div>
                {l.quiz.questions.map((q, i) => (
                  <div key={q.id}><div className="mb-0.5 flex justify-between gap-2 text-xs"><span className="truncate text-navy-900">{i + 1}. {q.text}</span><span className={clsx('shrink-0 font-semibold', (q.correct_rate ?? 100) < 50 ? 'text-danger' : 'text-emerald-700')}>{q.correct_rate == null ? '—' : `${fmt.number(q.correct_rate, 0)}%`}</span></div><Progress value={q.correct_rate ?? 0} tone={(q.correct_rate ?? 100) < 50 ? 'red' : 'green'} /></div>
                ))}
                <p className="text-[11px] text-slate-400">{t('course.an.hardHint')}</p>
              </div>
            )}

            {l.survey && (
              <div className="space-y-3 rounded-xl bg-ivory p-3">
                <div className="text-xs text-slate-600">{t('course.an.responses', { count: l.survey.responses })}</div>
                {l.survey.questions.map((q) => (
                  <div key={q.id} className="text-xs"><div className="mb-1 font-semibold text-navy-900">{q.text}</div>
                    {q.average != null && <div className="text-lg font-extrabold text-navy-900">{fmt.number(q.average, 1)}<span className="text-xs font-normal text-slate-400"> / {q.type === 'nps' ? 10 : 5}</span></div>}
                    {q.options?.map((o) => <div key={o.id} className="mb-1"><div className="flex justify-between"><span>{o.text}</span><span className="font-semibold">{o.count}</span></div><Progress value={q.answered ? (o.count / q.answered) * 100 : 0} /></div>)}
                    {q.texts?.slice(0, 5).map((x, i) => <p key={i} className="mb-1 rounded-lg bg-white p-2 text-slate-600">“{x}”</p>)}
                  </div>
                ))}
              </div>
            )}
          </Card>
        ))}
      </div>

      <Card padded={false}>
        <div className="p-5 pb-0"><CardTitle subtitle={t('course.an.learnersHint')}>{t('course.an.learnersTitle')}</CardTitle></div>
        <Table head={[t('course.an.name'), t('course.an.progress'), t('course.an.watched'), t('course.an.last')]}>
          {d.learners.map((p) => (
            <tr key={p.registration_id}>
              <Td><div className="flex items-center gap-2 font-semibold text-navy-900">{p.name}{p.completed && <Badge color="green">{t('course.an.done')}</Badge>}</div></Td>
              <Td><div className="min-w-[9rem]"><div className="mb-1 text-xs font-semibold">{fmt.number(p.percent, 0)}%</div><Progress value={p.percent} tone={p.completed ? 'green' : 'gold'} /></div></Td>
              <Td>{fmt.number(p.watch_minutes, 1)} {t('course.minutes')}</Td>
              <Td>{p.last_activity_at ? fmt.dateTime(p.last_activity_at) : '—'}</Td>
            </tr>
          ))}
        </Table>
      </Card>
    </div>
  )
}
