import { Camera } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { BarsChart, ChartBox, ScoreRing, SERIES, TrendChart } from '@/components/admin/charts'
import { Button, Card, CardTitle, PageHeader, Progress, Spinner, StatCard } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api } from '@/lib/api'
import { fmt } from '@/lib/format'
import { Line, LineChart, ResponsiveContainer, Tooltip, XAxis, YAxis, CartesianGrid, Legend } from 'recharts'

type Exec = {
  coverage: { training_coverage: number; school_participation: number; avg_hours_per_employee: number; certificates: number; impact_score: number; application_rate: number | null }
  employee_development: { trained_employees: number; untrained_employees: number; skills_acquired: number }
  skill_trends: { skill: string; total: number; series: { month: string; total: number }[] }[]
  category_performance: { category: string; programs: number; completed: number; impact: number }[]
  trend: { registrations: { month: string; total: number }[]; completions: { month: string; total: number }[] }
}

export default function Executive() {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: Exec }>('/admin/analytics/executive')
  const [saved, setSaved] = useState(false)
  if (isLoading || !data) return <Spinner />
  const d = data.data
  const trend = d.trend.registrations.map((r, i) => ({ label: fmt.month(r.month), registrations: r.total, completions: d.trend.completions[i]?.total ?? 0 }))
  const topSkills = d.skill_trends.slice(0, 4)
  const skillSeries = (topSkills[0]?.series ?? []).map((pt, i) => ({ label: fmt.month(pt.month), ...Object.fromEntries(topSkills.map((s) => [s.skill, s.series[i]?.total ?? 0])) }))

  return (
    <>
      <PageHeader title={t('admin.analytics.title')} actions={<Button variant="outline" icon={<Camera className="size-4" />} onClick={async () => { await api.post('/admin/reports/executive-snapshot'); setSaved(true) }}>{saved ? t('common.saved') : t('admin.analytics.snapshot')}</Button>} />
      <div className="grid gap-4 lg:grid-cols-4">
        <div className="flex items-center justify-center rounded-3xl bg-navy-900 p-6 shadow-glass lg:row-span-2"><ScoreRing value={d.coverage.impact_score} size={180} /></div>
        <CoverageCard label={t('admin.analytics.coverage')} value={d.coverage.training_coverage} />
        <CoverageCard label={t('admin.analytics.participation')} value={d.coverage.school_participation} />
        <CoverageCard label={t('admin.analytics.applicationRate')} value={d.coverage.application_rate ?? 0} />
        <StatCard label={t('admin.analytics.avgHours')} value={fmt.number(d.coverage.avg_hours_per_employee, 1)} />
        <StatCard label={t('admin.kpis.certificates_issued')} value={fmt.number(d.coverage.certificates)} />
        <StatCard label={t('admin.analytics.skillsAcquired')} value={fmt.number(d.employee_development.skills_acquired)} />
      </div>
      <div className="mt-6 grid gap-6 xl:grid-cols-2">
        <Card><CardTitle>{t('admin.dashboard.trend')}</CardTitle><TrendChart data={trend} series={[{ key: 'registrations', label: t('admin.dashboard.registrations') }, { key: 'completions', label: t('admin.dashboard.completions') }]} /></Card>
        <Card>
          <CardTitle>{t('admin.analytics.skillTrends')}</CardTitle>
          <ChartBox>
            <ResponsiveContainer>
              <LineChart data={skillSeries} margin={{ top: 10, right: 12, left: -12, bottom: 0 }}>
                <CartesianGrid stroke="#e2e8f0" strokeDasharray="3 3" vertical={false} />
                <XAxis dataKey="label" stroke="#94a3b8" fontSize={12} tickLine={false} axisLine={false} />
                <YAxis stroke="#94a3b8" fontSize={12} tickLine={false} axisLine={false} allowDecimals={false} />
                <Tooltip />
                <Legend iconType="circle" wrapperStyle={{ fontSize: 12 }} />
                {topSkills.map((s, i) => <Line key={s.skill} dataKey={s.skill} stroke={SERIES[i]} strokeWidth={2} dot={{ r: 4 }} type="monotone" />)}
              </LineChart>
            </ResponsiveContainer>
          </ChartBox>
        </Card>
      </div>
      <div className="mt-6 grid gap-6 xl:grid-cols-3">
        <Card className="xl:col-span-2">
          <CardTitle>{t('admin.analytics.categoryPerformance')}</CardTitle>
          <BarsChart data={d.category_performance.map((c) => ({ name: c.category, value: c.impact }))} dataKey="value" label={t('admin.impact.score')} height={260} />
        </Card>
        <Card>
          <CardTitle>{t('admin.analytics.development')}</CardTitle>
          <div className="space-y-5">
            <Bar2 label={t('admin.analytics.trained')} value={d.employee_development.trained_employees} total={d.employee_development.trained_employees + d.employee_development.untrained_employees} />
            <Bar2 label={t('admin.analytics.untrained')} value={d.employee_development.untrained_employees} total={d.employee_development.trained_employees + d.employee_development.untrained_employees} />
          </div>
        </Card>
      </div>
    </>
  )
}

function CoverageCard({ label, value }: { label: string; value: number }) {
  return (
    <div className="card p-5">
      <div className="text-sm text-slate-500">{label}</div>
      <div className="mt-2 font-display text-3xl font-bold text-navy-900">{fmt.percent(value, 1)}</div>
      <Progress value={value} className="mt-3" />
    </div>
  )
}

function Bar2({ label, value, total }: { label: string; value: number; total: number }) {
  return (
    <div>
      <div className="mb-1 flex justify-between text-sm"><span className="text-slate-600">{label}</span><span className="font-bold text-navy-900">{fmt.number(value)}</span></div>
      <Progress value={total ? (value / total) * 100 : 0} tone="navy" />
    </div>
  )
}
