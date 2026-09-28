import { Award, BookOpen, CalendarClock, ClipboardList, Clock, MapPin, Sparkles, Target } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { ProgramCover } from '@/components/public/ProgramCard'
import { PendingSurveysBanner } from '@/components/surveys/NeedsSurveys'
import { Badge, Card, CardTitle, Empty, PageHeader, Progress, Spinner, StatCard } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import type { Program } from '@/lib/types'

export type Recommendation = { program: Program; score: number; reasons: string[] }
type HomeData = {
  greeting_name: string
  stats: Record<string, number>
  next_session: { id: string; title: string; program: string; starts_at: string; ends_at: string; location?: string } | null
  recommended: Recommendation[]
}

export default function PortalHome() {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: HomeData }>('/me/home')
  if (isLoading) return <Spinner />
  if (!data) return <Empty />
  const d = data.data

  return (
    <>
      <PageHeader title={<>{t('portal.welcome')} {d.greeting_name}</>} />
      <PendingSurveysBanner />
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard accent label={t('portal.totalHours')} value={fmt.number(d.stats.training_hours)} icon={<Clock className="size-5" />} />
        <StatCard label={t('portal.activePrograms')} value={fmt.number(d.stats.active_programs)} icon={<BookOpen className="size-5" />} />
        <StatCard label={t('portal.completed')} value={fmt.number(d.stats.completed_programs)} icon={<Award className="size-5" />} />
        <StatCard label={t('portal.surveys')} value={fmt.number(d.stats.pending_surveys)} icon={<Target className="size-5" />} />
      </div>

      {d.next_session && (
        <div className="relative mt-6 overflow-hidden rounded-3xl bg-gradient-to-l from-navy-900 to-navy-700 p-6 text-white shadow-glass">
          <div className="pattern-bg absolute inset-0 opacity-20" />
          <div className="relative flex flex-wrap items-center gap-5">
            <div className="grid size-14 place-items-center rounded-2xl bg-gold-500 text-navy-950"><CalendarClock className="size-7" /></div>
            <div className="flex-1">
              <div className="text-sm text-gold-300">{t('portal.nextSession')}</div>
              <div className="text-xl font-bold">{d.next_session.program} — {d.next_session.title}</div>
              <div className="mt-1 flex flex-wrap gap-4 text-sm text-white/70">
                <span>{fmt.dateTime(d.next_session.starts_at)}</span>
                {d.next_session.location && <span className="flex items-center gap-1"><MapPin className="size-4" />{d.next_session.location}</span>}
              </div>
            </div>
          </div>
        </div>
      )}

      {(d.stats.tasks_needing_changes > 0 || d.stats.pending_surveys > 0) && (
        <div className="mt-6 grid gap-4 sm:grid-cols-2">
          {d.stats.tasks_needing_changes > 0 && <Link to="/portal/tasks" className="card flex items-center gap-3 p-4 hover:shadow-glass"><ClipboardList className="size-6 text-amber-600" /><span className="font-semibold">{t('portal.tasks')}: {d.stats.tasks_needing_changes} {t('status.changes_requested')}</span></Link>}
          {d.stats.pending_surveys > 0 && <Link to="/portal/surveys" className="card flex items-center gap-3 p-4 hover:shadow-glass"><Target className="size-6 text-gold-600" /><span className="font-semibold">{t('portal.surveys')}: {d.stats.pending_surveys}</span></Link>}
        </div>
      )}

      <Card className="mt-6">
        <CardTitle><span className="flex items-center gap-2"><Sparkles className="size-5 text-gold-600" />{t('portal.recommended')}</span></CardTitle>
        {!d.recommended.length ? <Empty /> : <RecommendationGrid items={d.recommended} />}
      </Card>
    </>
  )
}

export function RecommendationGrid({ items }: { items: Recommendation[] }) {
  const { t } = useTranslation()
  return (
    <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
      {items.map((r) => (
        <Link key={r.program.id} to={`/programs/${r.program.code}`} className="group overflow-hidden rounded-2xl border border-navy-100 bg-white transition hover:-translate-y-1 hover:shadow-glass">
          <ProgramCover program={r.program} className="h-28" />
          <div className="p-4">
            <div className="flex items-center justify-between"><Badge color="gold">{fmt.number(r.score)}%</Badge><span className="text-xs text-slate-400">{t(`modes.${r.program.delivery_mode}`)}</span></div>
            <h4 className="mt-2 font-bold leading-snug text-navy-900 group-hover:text-link">{r.program.title}</h4>
            <Progress value={r.score} className="mt-3" />
            <div className="mt-3 text-xs font-bold text-slate-500">{t('portal.why')}</div>
            <ul className="mt-1 space-y-1">{r.reasons.slice(0, 3).map((x) => <li key={x} className="text-xs text-slate-500">• {x}</li>)}</ul>
          </div>
        </Link>
      ))}
    </div>
  )
}
