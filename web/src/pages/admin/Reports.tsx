/* eslint-disable @typescript-eslint/no-explicit-any */
import { CalendarClock, Copy, Download, FileSpreadsheet, FileText, Pencil, Plus, Printer, Search, Star, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useSearchParams } from 'react-router-dom'
import { useLang } from '@/components/dashboard/Widget'
import { Badge, Button, Card, Empty, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { toast } from '@/lib/toast'
import ResultTable from './reports/ResultTable'
import ScheduleDialog from './reports/ScheduleDialog'
import { dialogs } from '@/lib/dialogs'

const cls = 'rounded-xl border border-navy-100 px-3 py-2 text-sm'
const looksLikeDate = (f: string, op: string) => ['gte', 'lte'].includes(op) && /(date|_at|starts|ends)/.test(f)

/** The reports hub: reports for your role by category, a viewer with adjustable filters, exports in three formats, schedules and a link to the builder. */
export default function Reports() {
  const { t } = useTranslation()
  const L = useLang()
  const { can } = useAuth()
  const navigate = useNavigate()
  const [params, setParams] = useSearchParams()
  const [cat, setCat] = useState('all')
  const [q, setQ] = useState('')
  const list = useGet<{ data: any[] }>('/admin/report-definitions', { q: q || undefined }, { staleTime: 0 })
  const [selected, setSelected] = useState<any | null>(null)
  const [filters, setFilters] = useState<Record<string, string>>({})
  const [range, setRange] = useState({ from: '', to: '' })
  const [page, setPage] = useState(1)
  const [result, setResult] = useState<any | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [schedule, setSchedule] = useState(false)
  const [exporting, setExporting] = useState<string | null>(null)
  const lang = useTranslation().i18n.language === 'en' ? 'en' : 'ar'

  const rows = list.data?.data ?? []
  const cats = ['all', 'favorites', ...Array.from(new Set(rows.map((r) => r.category)))]
  const shown = rows.filter((r) => (cat === 'all' ? true : cat === 'favorites' ? r.favorite : r.category === cat))

  const open = (def: any) => { setSelected(def); setFilters({}); setRange({ from: '', to: '' }); setPage(1); setResult(null); setError(null) }
  useEffect(() => {
    const key = params.get('report')
    if (key && rows.length && selected?.key !== key) { const d = rows.find((r) => r.key === key); if (d) open(d) }
  }, [params, rows, selected?.key])

  const body = (p = page) => ({
    page: p,
    params: {
      date_from: range.from || undefined, date_to: range.to || undefined,
      filters: (selected?.adjustable ?? []).filter((a: any) => filters[`${a.field}|${a.operator}`]).map((a: any) => ({ field: a.field, operator: a.operator, value: filters[`${a.field}|${a.operator}`] })),
    },
  })
  const load = async (p = 1) => {
    if (!selected) return
    setLoading(true); setError(null); setPage(p)
    try { const { data } = await api.post(`/admin/report-definitions/${selected.id}/preview`, body(p)); setResult(data.data) } catch (e) { setError(errorMessage(e)); setResult(null) } finally { setLoading(false) }
  }
  useEffect(() => { if (selected) void load(1) /* eslint-disable-next-line react-hooks/exhaustive-deps */ }, [selected?.id])

  const exportAs = async (format: string) => {
    setExporting(format)
    try {
      const { data } = await api.post(`/admin/report-definitions/${selected.id}/run`, { ...body(), formats: [format], lang })
      if (data.data.status === 'ready') await downloadFile(`/admin/report-runs/${data.data.id}/download?format=${format}`, `${selected.key ?? 'report'}.${format}`)
      else toast(String(t('rep.queued')), 'info')
    } catch (e) { toast(errorMessage(e), 'error') } finally { setExporting(null) }
  }
  const favorite = async (d: any) => { await api.post(`/admin/report-definitions/${d.id}/favorite`); list.refetch() }
  const copy = async (d: any) => { const { data } = await api.post(`/admin/report-definitions/${d.id}/copy`); navigate(`/admin/reports/${data.data.id}/edit`) }
  const remove = async (d: any) => { if (!await dialogs.confirm(String(t('rep.deleteAsk')))) return; await api.delete(`/admin/report-definitions/${d.id}`); setSelected(null); setParams({}); list.refetch() }
  const lastPage = result ? Math.max(1, Math.ceil(result.total / 50)) : 1

  return (
    <>
      <PageHeader title={t('rep.title')} subtitle={t('rep.subtitle')} actions={(
        <div className="flex gap-2 print:hidden">
          {can('reports.schedule') && <Button variant="outline" icon={<CalendarClock className="size-4" />} onClick={() => { setSelected(null); setSchedule(true) }}>{t('rep.schedule.list')}</Button>}
          {can('reports.builder') && <Button variant="gold" icon={<Plus className="size-4" />} to="/admin/reports/new">{t('rep.newReport')}</Button>}
        </div>
      )} />
      <div className="grid gap-5 xl:grid-cols-[22rem_1fr]">
        <div className="space-y-3 print:hidden">
          <div className="relative"><Search className="pointer-events-none absolute start-3 top-2.5 size-4 text-slate-400" /><input className={`${cls} w-full ps-9`} placeholder={String(t('rep.search'))} value={q} onChange={(e) => setQ(e.target.value)} /></div>
          <div className="flex flex-wrap gap-1.5">{cats.map((c) => <button key={c} type="button" onClick={() => setCat(c)} className={`rounded-full px-3 py-1 text-xs font-semibold transition ${cat === c ? 'bg-navy-900 text-gold-300' : 'bg-navy-50 text-navy-700 hover:bg-navy-100'}`}>{t(`rep.cat.${c}`, { defaultValue: c })}</button>)}</div>
          {list.isLoading ? <Spinner /> : !shown.length ? <Card><Empty text={String(t('rep.empty'))} /></Card> : (
            <ul className="max-h-[70vh] space-y-1.5 overflow-y-auto pe-1">
              {shown.map((d) => (
                <li key={d.id}>
                  <div className={`flex items-center gap-2 rounded-2xl border p-3 transition ${selected?.id === d.id ? 'border-gold-400 bg-gold-50' : 'border-navy-100 bg-white hover:border-gold-300'}`}>
                    <button type="button" className="min-w-0 flex-1 text-start" onClick={() => { open(d); setParams({ report: d.key ?? d.id }) }}>
                      <div className="truncate text-sm font-semibold text-navy-900">{L(d.title)}</div>
                      <div className="mt-0.5 flex items-center gap-1.5 text-[11px] text-slate-500">{d.is_system ? <Badge>{t('rep.system')}</Badge> : <Badge color="gold">{t('rep.mine')}</Badge>}{d.kind !== 'table' && <span>{t(`rep.kind.${d.kind}`)}</span>}</div>
                    </button>
                    <button type="button" onClick={() => favorite(d)} aria-label={String(t('rep.favorite'))} className={d.favorite ? 'text-gold-500' : 'text-slate-300 hover:text-gold-500'}><Star className={`size-4 ${d.favorite ? 'fill-current' : ''}`} /></button>
                  </div>
                </li>
              ))}
            </ul>
          )}
        </div>

        <div>
          {!selected ? <Card><Empty text={String(t('rep.subtitle'))} /></Card> : (
            <Card>
              <div className="flex flex-wrap items-center gap-2">
                <h2 className="text-lg font-bold text-navy-900">{L(selected.title)}</h2>
                <div className="ms-auto flex flex-wrap gap-1.5 print:hidden">
                  <Button size="sm" variant="outline" icon={<Printer className="size-4" />} onClick={() => window.print()}>{t('rep.print')}</Button>
                  <Button size="sm" variant="outline" icon={<FileSpreadsheet className="size-4" />} loading={exporting === 'xlsx'} onClick={() => exportAs('xlsx')}>{t('rep.xlsx')}</Button>
                  <Button size="sm" variant="outline" icon={<FileText className="size-4" />} loading={exporting === 'pdf'} onClick={() => exportAs('pdf')}>{t('rep.pdf')}</Button>
                  <Button size="sm" variant="outline" icon={<Download className="size-4" />} loading={exporting === 'docx'} onClick={() => exportAs('docx')}>{t('rep.docx')}</Button>
                  {can('reports.schedule') && <Button size="sm" variant="outline" icon={<CalendarClock className="size-4" />} onClick={() => setSchedule(true)}>{t('rep.schedule.button')}</Button>}
                  {can('reports.builder') && (selected.is_system || selected.owner_id) && <Button size="sm" variant="outline" icon={<Copy className="size-4" />} onClick={() => copy(selected)}>{t('rep.copy')}</Button>}
                  {!selected.is_system && can('reports.builder') && <><Link to={`/admin/reports/${selected.id}/edit`} className="inline-flex items-center gap-1 rounded-xl border border-navy-100 px-3 text-sm font-semibold text-navy-800 hover:bg-navy-50"><Pencil className="size-4" />{t('rep.edit')}</Link><Button size="sm" variant="outline" icon={<Trash2 className="size-4" />} onClick={() => remove(selected)}>{t('rep.delete')}</Button></>}
                </div>
              </div>
              <div className="mt-4 flex flex-wrap items-end gap-3 print:hidden">
                <label className="text-xs font-semibold text-slate-500">{t('rep.from')}<input type="date" className={`${cls} mt-1 block`} value={range.from} onChange={(e) => setRange({ ...range, from: e.target.value })} /></label>
                <label className="text-xs font-semibold text-slate-500">{t('rep.to')}<input type="date" className={`${cls} mt-1 block`} value={range.to} onChange={(e) => setRange({ ...range, to: e.target.value })} /></label>
                {(selected.adjustable ?? []).filter((a: any) => !['gte', 'lte'].includes(a.operator) || !/(date|_at|starts|ends)/.test(a.field)).map((a: any) => (
                  <label key={`${a.field}|${a.operator}`} className="text-xs font-semibold text-slate-500">{a.field}
                    <input className={`${cls} mt-1 block`} type={looksLikeDate(a.field, a.operator) ? 'date' : 'text'} value={filters[`${a.field}|${a.operator}`] ?? ''} onChange={(e) => setFilters({ ...filters, [`${a.field}|${a.operator}`]: e.target.value })} />
                  </label>
                ))}
                <Button variant="gold" size="sm" loading={loading} onClick={() => load(1)}>{t('rep.apply')}</Button>
              </div>
              {error && <p className="mt-3 text-sm text-danger">{error}</p>}
              <div className="mt-4">
                {loading && !result ? <Spinner /> : result && <ResultTable result={result} />}
                {result && (
                  <div className="mt-3 flex items-center justify-between text-sm text-slate-500 print:hidden">
                    <span>{t('rep.rows', { n: result.total })}</span>
                    <div className="flex items-center gap-2"><Button size="sm" variant="outline" disabled={page <= 1} onClick={() => load(page - 1)}>‹</Button><span>{t('rep.page', { p: page, n: lastPage })}</span><Button size="sm" variant="outline" disabled={page >= lastPage} onClick={() => load(page + 1)}>›</Button></div>
                  </div>
                )}
              </div>
            </Card>
          )}
        </div>
      </div>
      {schedule && <ScheduleDialog definition={selected ? { id: selected.id, title: selected.title } : undefined} onClose={() => setSchedule(false)} />}
    </>
  )
}
