import { ArrowLeftRight, Award, BookOpen, CalendarClock, ClipboardList, Clock, GraduationCap, MapPin, Sparkles, Target } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate } from 'react-router-dom'
import RoleCharter from '@/components/dashboard/RoleCharter'
import RoleDashboard from '@/components/dashboard/RoleDashboard'
import { ProgramCover } from '@/components/public/ProgramCard'
import { PendingSurveysBanner } from '@/components/surveys/NeedsSurveys'
import { Badge, Button, Card, CardTitle, Empty, PageHeader, Progress, Spinner, StatCard } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { showsOwnTraining, TRAINEE_ROLE } from '@/lib/portalHome'
import { toast } from '@/lib/toast'
import type { Program } from '@/lib/types'

export type Recommendation = { program: Program; score: number; reasons: string[]; variant?: string }
type HomeData = {
  greeting_name: string
  stats: Record<string, number>
  next_session: { id: string; title: string; program: string; starts_at: string; ends_at: string; location?: string } | null
  recommended: Recommendation[]
}

/**
 * The home page of the portal. It belongs to the role the person is working in: the role's own card and dashboard come first, and
 * the person's own training (hours, programmes, next session, recommendations) is shown in the trainee role. Somebody who is both a
 * supervisor and a trainee therefore sees supervision as «مشرف» and their own training as «موظف» (see showsOwnTraining).
 */
export default function PortalHome() {
  const { t } = useTranslation()
  const { user, activeRole } = useAuth()
  const own = showsOwnTraining(activeRole?.slug, (user?.roles ?? []).map((r) => r.slug))

  return (
    <>
      <PageHeader title={<>{t('portal.welcome')} {user?.name}</>} />
      <PendingSurveysBanner />
      <RoleCharter />
      <RoleDashboard />
      {own ? <OwnTraining /> : <OwnTrainingElsewhere />}
    </>
  )
}

/** For a role that is not the trainee's: where the person's own training is, one press away. */
function OwnTrainingElsewhere() {
  const { t } = useTranslation()
  const { user, switchRole } = useAuth()
  const navigate = useNavigate()
  const [switching, setSwitching] = useState(false)
  const trainee = user?.roles.find((r) => r.slug === TRAINEE_ROLE)
  if (!trainee) return null
  const go = async () => {
    setSwitching(true)
    try {
      const next = await switchRole(trainee.id)
      navigate(next?.landing_route ?? '/portal')
      toast(t('roles.switched', { role: next?.name ?? '' }))
    } catch (e) { toast(errorMessage(e), 'error') } finally { setSwitching(false) }
  }
  return (
    <div className="flex flex-wrap items-center gap-4 rounded-3xl border border-navy-100 bg-white px-5 py-4">
      <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-navy-50 text-navy-900"><GraduationCap className="size-5" /></span>
      <div className="min-w-0 flex-1">
        <h2 className="font-bold text-navy-900">{t('portal.ownElsewhere.title')}</h2>
        <p className="mt-0.5 text-sm leading-6 text-slate-600">{t('portal.ownElsewhere.text', { role: trainee.name })}</p>
      </div>
      <Button variant="outline" size="sm" loading={switching} icon={<ArrowLeftRight className="size-4" />} onClick={() => void go()}>{t('portal.ownElsewhere.go', { role: trainee.name })}</Button>
    </div>
  )
}

/** The person's own training: hours and programmes, the next session, what is waiting for them, and what is recommended to them. */
function OwnTraining() {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: HomeData }>('/me/home')
  if (isLoading) return <Spinner />
  if (!data) return <Empty />
  const d = data.data

  return (
    <>
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
  const [hidden, setHidden] = useState<string[]>([])
  const send = (id: string, event: 'clicked' | 'dismissed' | 'liked') => { api.post(`/me/recommendations/${id}/feedback`, { event }).catch(() => undefined) }
  return (
    <div className="grid gap-5 md:grid-cols-2 xl:grid-cols-4">
      {items.filter((r) => !hidden.includes(r.program.id)).map((r) => (
        <div key={r.program.id} className="group relative overflow-hidden rounded-2xl border border-navy-100 bg-white transition hover:-translate-y-1 hover:shadow-glass">
          <Link to={`/programs/${r.program.code}`} onClick={() => send(r.program.id, 'clicked')} className="block">
            <ProgramCover program={r.program} className="h-28" />
            <div className="p-4 pb-2">
              <div className="flex items-center justify-between"><Badge color="gold">{fmt.number(r.score)}%</Badge><span className="text-xs text-slate-400">{t(`modes.${r.program.delivery_mode}`)}</span></div>
              <h4 className="mt-2 font-bold leading-snug text-navy-900 group-hover:text-link">{r.program.title}</h4>
              <Progress value={r.score} className="mt-3" />
              <div className="mt-3 text-xs font-bold text-slate-500">{t('portal.why')}</div>
              <ul className="mt-1 space-y-1">{r.reasons.slice(0, 3).map((x) => <li key={x} className="text-xs text-slate-500">• {x}</li>)}</ul>
            </div>
          </Link>
          {r.variant && (
            <div className="flex justify-end gap-2 px-4 pb-3 text-xs">
              <button type="button" className="font-semibold text-slate-400 hover:text-emerald-700" onClick={() => { send(r.program.id, 'liked'); toast(String(t('aix.rec.helpful'))) }}>{t('aix.rec.helpful')}</button>
              <button type="button" className="font-semibold text-slate-400 hover:text-danger" onClick={() => { send(r.program.id, 'dismissed'); setHidden((h) => [...h, r.program.id]); toast(String(t('aix.rec.dismissed'))) }}>{t('aix.rec.dismiss')}</button>
            </div>
          )}
        </div>
      ))}
    </div>
  )
}
