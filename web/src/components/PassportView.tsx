import { Award, BookOpenCheck, Clock, Sparkles, TrendingUp } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { PolarAngleAxis, PolarGrid, Radar, RadarChart, ResponsiveContainer, Tooltip } from 'recharts'
import { ChartBox, SINGLE } from '@/components/admin/charts'
import { Badge, Card, CardTitle, Empty, StatCard } from '@/components/ui'
import { fmt } from '@/lib/format'

export type PassportData = {
  summary: { total_hours: number; attended_hours: number; completed_programs: number; certificates: number; skills: number; in_progress: number; avg_impact_score: number | null }
  hours_by_category: { category: string; hours: number }[]
  skills: { id: string; name: string; category: string; level: number; source: string }[]
  skills_by_category: { category: string; average_level: number; skills: number }[]
  certificates: { id: string; certificate_no: string; program: string; hours: number; issued_at: string; verification_url: string }[]
  growth_path: { date: string; program: string; level: string; hours: number; category?: string }[]
  in_progress: { registration_id: string; program: string; status: string; attendance_percent: number; start_date?: string }[]
}

/** Training Passport — shared by the employee portal and the admin employee profile. */
export default function PassportView({ data }: { data: PassportData }) {
  const { t } = useTranslation()
  const s = data.summary
  return (
    <div className="space-y-6">
      <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
        <StatCard accent label={t('portal.totalHours')} value={fmt.number(s.total_hours)} icon={<Clock className="size-5" />} />
        <StatCard label={t('portal.completed')} value={fmt.number(s.completed_programs)} icon={<BookOpenCheck className="size-5" />} />
        <StatCard label={t('portal.certificates')} value={fmt.number(s.certificates)} icon={<Award className="size-5" />} />
        <StatCard label={t('admin.impact.score')} value={fmt.number(s.avg_impact_score, 1)} icon={<TrendingUp className="size-5" />} />
      </div>
      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <CardTitle>{t('portal.skills')}</CardTitle>
          {data.skills_by_category.length >= 3 ? (
            <ChartBox height={260}>
              <ResponsiveContainer>
                <RadarChart data={data.skills_by_category.map((c) => ({ name: String(t(`hlp.skillCat.${c.category}`, { defaultValue: c.category })), level: c.average_level }))}>
                  <PolarGrid stroke="#e2e8f0" />
                  <PolarAngleAxis dataKey="name" tick={{ fontSize: 11, fill: '#475569' }} />
                  <Radar dataKey="level" stroke={SINGLE} fill="#a29475" fillOpacity={0.35} strokeWidth={2} />
                  <Tooltip />
                </RadarChart>
              </ResponsiveContainer>
            </ChartBox>
          ) : null}
          <div className="mt-2 space-y-2">
            {data.skills.map((k) => (
              <div key={k.id} className="flex items-center gap-3">
                <span className="w-44 truncate text-sm text-slate-700">{k.name}</span>
                <div className="flex gap-1">{[1, 2, 3, 4, 5].map((n) => <span key={n} className={`h-2 w-6 rounded-full ${n <= k.level ? 'bg-gold-500' : 'bg-navy-100'}`} />)}</div>
                {k.source === 'training' && <Badge color="green"><Sparkles className="size-3" /></Badge>}
              </div>
            ))}
          </div>
        </Card>
        <Card>
          <CardTitle>{t('portal.growthPath')}</CardTitle>
          {!data.growth_path.length ? <Empty /> : (
            <ol className="relative space-y-5 border-s-2 border-gold-300/60 ps-6">
              {data.growth_path.map((g, i) => (
                <li key={i} className="relative">
                  <span className="absolute -start-[33px] top-1 size-4 rounded-full bg-gold-500 ring-4 ring-gold-100" />
                  <div className="text-xs text-slate-400">{fmt.date(g.date)}</div>
                  <div className="font-bold text-navy-900">{g.program}</div>
                  <div className="text-xs text-slate-500">{t(`hlp.skillCat.${g.category}`, { defaultValue: g.category })} · {t(`levels.${g.level}`)} · {fmt.number(g.hours)} {t('common.hours')}</div>
                </li>
              ))}
              {data.in_progress.map((p) => (
                <li key={p.registration_id} className="relative opacity-70">
                  <span className="absolute -start-[33px] top-1 size-4 rounded-full border-2 border-gold-500 bg-white" />
                  <div className="text-xs text-slate-400">{fmt.date(p.start_date)}</div>
                  <div className="font-semibold text-navy-900">{p.program}</div>
                  <div className="text-xs text-slate-500">{t(`status.${p.status}`)} · {fmt.percent(p.attendance_percent)}</div>
                </li>
              ))}
            </ol>
          )}
        </Card>
      </div>
    </div>
  )
}
