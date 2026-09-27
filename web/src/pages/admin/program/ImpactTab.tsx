import { useTranslation } from 'react-i18next'
import { BarsChart, ScoreRing } from '@/components/admin/charts'
import { Badge, Card, CardTitle, Spinner, StatCard } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import type { Program } from '@/lib/types'

type Impact = { participants: number; impact_score: number | null; applied_rate: number | null; needs_support: number; by_stage: Record<string, { responses: number; avg_application: number }>; skills_improved: Record<string, number> }

export default function ImpactTab({ program }: { program: Program }) {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: Impact }>(`/admin/programs/${program.id}/impact`)
  if (isLoading || !data) return <Spinner />
  const d = data.data
  return (
    <div className="grid gap-6 lg:grid-cols-3">
      <div className="flex flex-col items-center justify-center rounded-3xl bg-navy-900 p-8 shadow-glass"><ScoreRing value={d.impact_score} size={180} /></div>
      <div className="grid gap-4 sm:grid-cols-3 lg:col-span-2 lg:grid-cols-3">
        <StatCard label={t('admin.kpis.participants')} value={fmt.number(d.participants)} />
        <StatCard label={t('admin.impact.applied')} value={fmt.percent(d.applied_rate)} />
        <StatCard label={t('admin.impact.needsSupport')} value={fmt.number(d.needs_support)} />
        <Card className="sm:col-span-3">
          <CardTitle>{t('admin.impact.byStage')}</CardTitle>
          <BarsChart data={Object.entries(d.by_stage).map(([k, v]) => ({ name: `${k} ${t('admin.impact.days')}`, value: v.avg_application }))} dataKey="value" label={t('admin.impact.applied')} height={200} formatter={(v) => fmt.percent(v)} />
        </Card>
      </div>
      <Card className="lg:col-span-3">
        <CardTitle>{t('admin.impact.skillsImproved')}</CardTitle>
        <div className="flex flex-wrap gap-2">{Object.entries(d.skills_improved).map(([k, v]) => <Badge key={k} color="navy">{k} · {v}</Badge>)}</div>
      </Card>
    </div>
  )
}
