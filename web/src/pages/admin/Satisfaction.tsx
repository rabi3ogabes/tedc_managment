/* eslint-disable @typescript-eslint/no-explicit-any */
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLang } from '@/components/help/helpApi'
import { Badge, Card, Empty, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'

function RankTable({ rows, title }: { rows: any[]; title: string }) {
  const { t } = useTranslation()
  const { lang } = useLang()
  return (
    <Card className="space-y-3">
      <h2 className="font-bold text-navy-900">{title}</h2>
      {rows.length === 0 ? <Empty text={String(t('hlp.sat.none'))} /> : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="text-start text-xs text-slate-500"><th className="p-2 text-start">{t('hlp.sat.group')}</th><th className="p-2 text-start">{t('hlp.sat.program')}</th><th className="p-2 text-start">{t('hlp.sat.average')}</th><th className="p-2 text-start">{t('hlp.sat.rate')}</th></tr></thead>
            <tbody>{rows.map((r) => (
              <tr key={r.group_id} className="border-t border-navy-100">
                <td className="p-2 font-semibold" dir="ltr">{r.group}</td>
                <td className="p-2">{lang === 'en' ? r.program_en : r.program}</td>
                <td className="p-2"><Badge color={r.average >= 80 ? 'green' : r.average >= 60 ? 'gold' : 'red'}>{fmt.number(r.average)}%</Badge></td>
                <td className="p-2 text-slate-600">{fmt.number(r.response_rate)}% · {r.responses}/{r.expected}</td>
              </tr>
            ))}</tbody>
          </table>
        </div>
      )}
    </Card>
  )
}

/** Satisfaction across groups: the best and the weakest, and the alerts raised for one program. */
export default function Satisfaction() {
  const { t } = useTranslation()
  const { lang } = useLang()
  const rank = useGet<{ data: { top: any[]; bottom: any[] } }>('/admin/evaluations/satisfaction-ranking', undefined, { staleTime: 30_000 })
  const programs = useGet<{ data: any[] }>('/admin/programs', { per_page: 100 }, { staleTime: 60_000 })
  const [program, setProgram] = useState('')
  const alerts = useGet<{ data: any[] }>(program ? `/admin/programs/${program}/satisfaction-alerts` : null, undefined, { staleTime: 0 })
  return (
    <>
      <PageHeader title={t('hlp.sat.title')} subtitle={t('hlp.sat.subtitle')} />
      {rank.isLoading ? <Spinner /> : (
        <div className="grid gap-5 xl:grid-cols-2">
          <RankTable rows={rank.data?.data.top ?? []} title={String(t('hlp.sat.top'))} />
          <RankTable rows={[...(rank.data?.data.bottom ?? [])]} title={String(t('hlp.sat.bottom'))} />
        </div>
      )}
      <Card className="mt-5 space-y-3">
        <h2 className="font-bold text-navy-900">{t('hlp.sat.alerts')}</h2>
        <p className="text-xs text-slate-500">{t('hlp.sat.alertsHint')}</p>
        <select className="input max-w-md" value={program} onChange={(e) => setProgram(e.target.value)} aria-label={String(t('hlp.sat.program'))}>
          <option value="">{t('hlp.sat.pick')}</option>
          {(programs.data?.data ?? []).map((p) => <option key={p.id} value={p.id}>{p.code} — {lang === 'en' ? (p.title_en ?? p.title) : (p.title ?? p.title_ar)}</option>)}
        </select>
        {program && (alerts.isLoading ? <Spinner /> : (alerts.data?.data.length ?? 0) === 0 ? <Empty text={String(t('hlp.sat.noAlerts'))} /> : (
          <ul className="space-y-2">{alerts.data!.data.map((a) => (
            <li key={a.id} className="flex flex-wrap items-center justify-between gap-2 rounded-xl bg-ivory p-3 text-sm">
              <span>{t('hlp.sat.alertLine', { avg: fmt.number(a.average), threshold: fmt.number(a.threshold), rate: fmt.number(a.response_rate) })}</span>
              <span className="text-xs text-slate-500">{a.notified_at ? fmt.date(a.notified_at) : '—'}</span>
            </li>
          ))}</ul>
        ))}
      </Card>
    </>
  )
}
