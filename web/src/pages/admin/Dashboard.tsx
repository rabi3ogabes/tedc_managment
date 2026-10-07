import { CalendarClock } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { BarsChart, DonutChart, TrendChart } from '@/components/admin/charts'
import { Card, CardTitle, ErrorState, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import RoleDashboard from '@/components/dashboard/RoleDashboard'
import JourneyTracker from './dashboard/JourneyTracker'

type DashboardData = {
  kpis: Record<string, number>
  trend: { registrations: { month: string; total: number }[]; completions: { month: string; total: number }[] }
  status_distribution: Record<string, number>
  by_category: Record<string, number>
  upcoming_sessions: { id: string; title: string; program: string; starts_at: string; trainer?: string }[]
  top_programs: { id: string; code: string; title: string; participants: number; impact: number | null }[]
}

export default function Dashboard() {
  const { t } = useTranslation()
  const { user } = useAuth()
  const { data, isLoading, error, refetch } = useGet<{ data: DashboardData }>('/admin/dashboard')
  if (isLoading) return <Spinner />
  if (error || !data) return <ErrorState onRetry={refetch} />
  const d = data.data

  const trend = d.trend.registrations.map((r, i) => ({ label: fmt.month(r.month), registrations: r.total, completions: d.trend.completions[i]?.total ?? 0 }))

  return (
    <>
      <PageHeader title={<>{t('admin.dashboard.welcome')} {user?.name}</>} subtitle={t('admin.dashboard.subtitle')} />

      <RoleDashboard />

      <JourneyTracker />

      <div className="grid gap-6 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardTitle>{t('admin.dashboard.trend')}</CardTitle>
          <TrendChart data={trend} series={[{ key: 'registrations', label: t('admin.dashboard.registrations') }, { key: 'completions', label: t('admin.dashboard.completions') }]} />
        </Card>
        <Card>
          <CardTitle>{t('admin.dashboard.statusDist')}</CardTitle>
          <DonutChart data={Object.entries(d.status_distribution).map(([k, v]) => ({ name: t(`status.${k}`), value: v }))} height={200} />
        </Card>
      </div>

      <div className="mt-6 grid gap-6 xl:grid-cols-3">
        <Card>
          <CardTitle>{t('admin.dashboard.byCategory')}</CardTitle>
          <BarsChart data={Object.entries(d.by_category).map(([name, value]) => ({ name, value }))} dataKey="value" label={t('admin.menu.programs')} horizontal height={260} />
        </Card>
        <Card>
          <CardTitle>{t('admin.dashboard.topPrograms')}</CardTitle>
          <ul className="space-y-3">
            {d.top_programs.map((p, i) => (
              <li key={p.id}>
                <Link to={`/admin/programs/${p.id}`} className="flex items-center gap-3 rounded-xl p-2 hover:bg-ivory">
                  <span className="grid size-8 place-items-center rounded-lg bg-gold-100 font-display font-bold text-gold-700">{i + 1}</span>
                  <div className="min-w-0 flex-1"><div className="truncate text-sm font-bold text-navy-900">{p.title}</div><div className="text-xs text-slate-400" dir="ltr">{p.code}</div></div>
                  <div className="text-end"><div className="text-sm font-bold text-navy-900">{fmt.number(p.participants)}</div><div className="text-xs text-slate-400">{t('admin.dashboard.impact')} {fmt.number(p.impact, 1)}</div></div>
                </Link>
              </li>
            ))}
          </ul>
        </Card>
        <Card>
          <CardTitle>{t('admin.dashboard.upcoming')}</CardTitle>
          <ul className="space-y-3">
            {d.upcoming_sessions.map((s) => (
              <li key={s.id} className="flex items-start gap-3">
                <div className="grid size-10 shrink-0 place-items-center rounded-xl bg-navy-900 text-gold-300"><CalendarClock className="size-5" /></div>
                <div className="min-w-0"><div className="truncate text-sm font-bold text-navy-900">{s.program}</div><div className="text-xs text-slate-500">{s.title} · {fmt.dateTime(s.starts_at)}</div></div>
              </li>
            ))}
          </ul>
        </Card>
      </div>
    </>
  )
}
