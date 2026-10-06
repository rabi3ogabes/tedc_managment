/* eslint-disable @typescript-eslint/no-explicit-any */
import { ArrowUpLeft } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link, useLocation } from 'react-router-dom'
import { BarsChart, DonutChart, ScoreRing } from '@/components/admin/charts'
import { Skeleton } from '@/components/ui/Skeleton'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'

/** The text of a {ar, en} pair in the current language. */
export function useLang() {
  const { i18n } = useTranslation()
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  return (v: any): string => (v && typeof v === 'object' ? v[lang] || v.ar || v.en || '' : String(v ?? ''))
}

/** Draws one widget by its type. Used on the role dashboards (web) and wherever a widget is embedded. */
export function WidgetBody({ w }: { w: any }) {
  const { t } = useTranslation()
  const L = useLang()
  const lab = (s: string) => String(t(`rep.dash.label.${s}`, { defaultValue: s }))
  switch (w.type) {
    case 'kpis':
      return (
        <div className="grid grid-cols-2 gap-3 sm:grid-cols-3">
          {(w.items ?? []).map((i: any) => (
            <div key={i.key} className="rounded-2xl bg-ivory p-3">
              <div className="font-display text-2xl font-bold text-navy-900">{typeof i.value === 'number' ? fmt.number(i.value) : i.value}{i.unit ? <span className="ms-1 text-sm font-semibold text-slate-400">{i.unit}</span> : null}</div>
              <div className="mt-0.5 text-xs text-slate-500">{L(i.label)}</div>
            </div>
          ))}
          {w.note === 'no_plan' && <div className="col-span-full text-xs text-slate-400">{t('rep.dash.noData')}</div>}
        </div>
      )
    case 'gauge':
      return (
        <div className="flex flex-col items-center gap-2">
          {w.unit === '%' ? <ScoreRing value={w.value} size={140} /> : <div className="font-display text-4xl font-bold text-navy-900">{fmt.number(w.value)}<span className="ms-1 text-base text-slate-400">{w.unit}</span></div>}
          {w.target != null && <div className="text-xs text-slate-500">{t('rep.dash.target')}: {w.target}{w.unit}</div>}
        </div>
      )
    case 'bar':
      return (w.points ?? []).length ? <BarsChart data={w.points.map((p: any) => ({ name: lab(p.label), value: p.value }))} dataKey="value" label="" height={220} /> : <p className="text-sm text-slate-400">{t('rep.dash.noData')}</p>
    case 'donut':
      return (w.points ?? []).some((p: any) => p.value) ? <DonutChart data={w.points.map((p: any) => ({ name: lab(p.label), value: p.value }))} height={180} /> : <p className="text-sm text-slate-400">{t('rep.dash.noData')}</p>
    case 'list':
      return (w.items ?? []).length ? (
        <ul className="divide-y divide-navy-50 text-sm">
          {w.items.map((i: any, k: number) => (
            <li key={k} className="py-2">
              <div className="font-semibold text-navy-900">{L(i.title)}</div>
              <div className="text-xs text-slate-500">{i.subtitle}</div>
              {typeof i.percent === 'number' && <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-navy-50"><div className="h-full rounded-full bg-gold-500" style={{ width: `${Math.min(100, i.percent)}%` }} /></div>}
            </li>
          ))}
        </ul>
      ) : <p className="text-sm text-slate-400">{t('rep.dash.noData')}</p>
    case 'table':
      if (w.rows && w.columns) {
        return w.rows.length ? (
          <div className="overflow-x-auto"><table className="w-full text-sm"><thead><tr className="text-xs text-slate-500">{w.columns.map((c: any) => <th key={c.key} className="p-1.5 text-start">{L(c.label)}</th>)}</tr></thead>
            <tbody className="divide-y divide-navy-50">{w.rows.map((r: any, i: number) => <tr key={i}>{w.columns.map((c: any) => <td key={c.key} className="p-1.5">{lab(String(r[c.key] ?? ''))}</td>)}</tr>)}</tbody></table></div>
        ) : <p className="text-sm text-slate-400">{t('rep.dash.noData')}</p>
      }
      return (
        <ul className="divide-y divide-navy-50 text-sm">
          {(w.rows ?? []).map((r: any, i: number) => (
            <li key={i} className="flex items-center justify-between gap-3 py-2">
              <span className="min-w-0 truncate"><span className={`me-2 rounded-full px-2 py-0.5 text-[10px] font-bold ${r.kind === 'highest' ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-700'}`}>{t(`rep.dash.kind.${r.kind}`)}</span>{L(r.title)}</span>
              <span className="shrink-0 font-bold text-navy-900">{r.score} <span className="text-xs font-normal text-slate-400">({r.responses})</span></span>
            </li>
          ))}
          {!(w.rows ?? []).length && <li className="py-2 text-slate-400">{t('rep.dash.noData')}</li>}
        </ul>
      )
    case 'heatmap': {
      const cells: { x: string; y: string; value: number }[] = w.cells ?? []
      const xs = [...new Set(cells.map((c) => c.x))]
      const ys = [...new Set(cells.map((c) => c.y))]
      const max = Math.max(1, ...cells.map((c) => c.value))
      return cells.length ? (
        <div className="overflow-x-auto"><table className="text-xs"><thead><tr><th />{xs.map((x) => <th key={x} className="px-2 py-1 font-semibold text-slate-500">{x}</th>)}</tr></thead>
          <tbody>{ys.map((y) => <tr key={y}><th className="pe-2 text-start font-semibold text-slate-500">{lab(y)}</th>{xs.map((x) => { const v = cells.find((c) => c.x === x && c.y === y)?.value ?? 0; return <td key={x} className="size-10 text-center font-bold" style={{ background: `rgba(162,148,117,${0.1 + (v / max) * 0.8})`, color: v / max > 0.6 ? '#fff' : '#1f2937' }}>{v || ''}</td> })}</tr>)}</tbody></table></div>
      ) : <p className="text-sm text-slate-400">{t('rep.dash.noData')}</p>
    }
    default:
      return null
  }
}

/** A widget that loads its own data for a period, with skeleton while waiting and a link to the report behind it. */
export default function WidgetCard({ meta, from, to, onHide, controls }: { meta: any; from: string; to: string; onHide?: () => void; controls?: React.ReactNode }) {
  const { t } = useTranslation()
  const L = useLang()
  const base = useLocation().pathname.startsWith('/portal') ? '/portal' : '/admin'
  const { data, isLoading, isError } = useGet<{ data: any }>(`/dashboard/widgets/${meta.key}`, { from, to }, { staleTime: 60_000 })
  return (
    <section className="card relative flex flex-col p-5 transition hover:shadow-glass" aria-label={L(meta.title)}>
      <header className="mb-3 flex items-start justify-between gap-2">
        <h3 className="text-sm font-bold text-navy-900">{L(meta.title)}</h3>
        <div className="flex shrink-0 items-center gap-1">
          {controls}
          {meta.drill && <Link to={`${base}/reports?report=${meta.drill}`} className="inline-flex items-center gap-0.5 text-xs font-semibold text-link hover:opacity-80" title={String(t('rep.dash.drill'))}>{t('rep.dash.drill')}<ArrowUpLeft className="size-3 rtl:-scale-x-100" /></Link>}
          {onHide && <button type="button" onClick={onHide} className="text-xs text-slate-400 hover:text-navy-900">{t('rep.dash.hide')}</button>}
        </div>
      </header>
      {isLoading ? <div className="space-y-2"><Skeleton className="h-6 w-2/3" /><Skeleton className="h-16 w-full" /></div> : isError || !data ? <p className="text-sm text-slate-400">{t('rep.dash.noData')}</p> : <WidgetBody w={data.data} />}
    </section>
  )
}
