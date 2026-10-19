import { useQuery } from '@tanstack/react-query'
import { Clock3 } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { api } from '@/lib/api'

/** Hours each person trained last year, for any list of employees: one request for the page, shown beside every trainee. */
export function useLastYearHours(ids: (string | undefined | null)[]) {
  const list = [...new Set(ids.filter(Boolean) as string[])].sort()
  const q = useQuery({
    queryKey: ['hours-last-year', list.join(',')],
    queryFn: async () => (await api.post('/admin/employees/hours-last-year', { ids: list.slice(0, 300) })).data as { year: number; data: Record<string, number> },
    enabled: list.length > 0,
    staleTime: 60_000,
  })
  return { hours: q.data?.data ?? {}, year: q.data?.year }
}

/** «12 ساعة · 2025» — how long this trainee trained last year. */
export function HoursChip({ hours, year, className }: { hours: number | undefined; year?: number; className?: string }) {
  const { t } = useTranslation()
  if (hours === undefined) return <span className="text-xs text-slate-300">…</span>
  return (
    <span className={'inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2 py-0.5 text-xs font-semibold ring-1 ring-inset ' + (hours > 0 ? 'bg-emerald-50 text-emerald-800 ring-emerald-600/20' : 'bg-slate-100 text-slate-500 ring-slate-500/15') + (className ? ` ${className}` : '')} title={t('admin.programs.traineesAdd.hoursTitle', { year: year ?? '' })}>
      <Clock3 className="size-3" />{hours % 1 === 0 ? hours : hours.toFixed(1)} {t('common.hours')}{year ? <span className="font-normal opacity-60">· {year}</span> : null}
    </span>
  )
}
