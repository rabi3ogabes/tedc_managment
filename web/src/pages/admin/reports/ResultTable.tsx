/* eslint-disable @typescript-eslint/no-explicit-any */
import { useTranslation } from 'react-i18next'
import { BarsChart, DonutChart, TrendChart } from '@/components/admin/charts'
import { useLang } from '@/components/dashboard/Widget'
import { fmt } from '@/lib/format'

/** The rows of a report result, with totals and the chart the definition asks for. */
export default function ResultTable({ result }: { result: any }) {
  const { t } = useTranslation()
  const L = useLang()
  const cell = (v: any, c: any) => {
    if (v === null || v === undefined || v === '') return ''
    if (c.type === 'bool') return v ? '✔' : '—'
    if (c.type === 'date') return String(v).slice(0, String(v).length > 10 ? 16 : 10).replace('T', ' ')
    if (c.type === 'number' && typeof v === 'number') return fmt.number(v)
    return String(v)
  }
  const chart = result.chart
  const points = chart?.points?.map((p: any) => ({ name: p.label, value: p.value, label: p.label }))
  return (
    <div className="space-y-4">
      {chart && points?.length > 0 && (
        <div className="print:hidden">
          {chart.type === 'donut' ? <DonutChart data={points} height={220} /> : chart.type === 'line' ? <TrendChart data={points} series={[{ key: 'value', label: '' }]} /> : <BarsChart data={points} dataKey="value" label="" height={240} />}
        </div>
      )}
      <div className="overflow-x-auto rounded-2xl border border-navy-100 bg-white">
        <table className="w-full text-sm">
          <thead className="bg-navy-50/60 text-xs text-slate-600">
            <tr>{result.columns.map((c: any) => <th key={c.key} className="whitespace-nowrap p-2.5 text-start font-bold">{L(c.label)}</th>)}</tr>
          </thead>
          <tbody className="divide-y divide-navy-50">
            {result.rows.map((r: any, i: number) => <tr key={i} className="hover:bg-ivory/60">{result.columns.map((c: any) => <td key={c.key} className="whitespace-nowrap p-2.5">{cell(r[c.key], c)}</td>)}</tr>)}
            {!result.rows.length && <tr><td colSpan={result.columns.length} className="p-6 text-center text-slate-400">{t('rep.noRows')}</td></tr>}
            {!!Object.keys(result.totals ?? {}).length && (
              <tr className="bg-gold-50 font-bold">{result.columns.map((c: any, i: number) => <td key={c.key} className="p-2.5">{i === 0 ? t('rep.totals') : result.totals[c.key] !== undefined ? fmt.number(Math.round(result.totals[c.key] * 10) / 10) : ''}</td>)}</tr>
            )}
          </tbody>
        </table>
      </div>
    </div>
  )
}
