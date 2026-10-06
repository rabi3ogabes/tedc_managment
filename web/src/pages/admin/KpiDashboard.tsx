/* eslint-disable @typescript-eslint/no-explicit-any */
import clsx from 'clsx'
import { FileText, RefreshCw } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, ErrorState, Modal, PageHeader, Spinner } from '@/components/ui'
import { useLang } from '@/components/dashboard/Widget'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

/** A tiny trend line drawn as SVG. */
function Spark({ points, ok }: { points: { date: string; value: number }[]; ok: boolean }) {
  if (points.length < 2) return <div className="h-10 text-xs text-slate-300">—</div>
  const vals = points.map((p) => p.value)
  const min = Math.min(...vals)
  const max = Math.max(...vals)
  const span = max - min || 1
  const d = points.map((p, i) => `${(i / (points.length - 1)) * 100},${36 - ((p.value - min) / span) * 32}`).join(' ')
  return <svg viewBox="0 0 100 40" className="h-10 w-full" preserveAspectRatio="none" aria-hidden><polyline points={d} fill="none" strokeWidth="2" vectorEffect="non-scaling-stroke" stroke={ok ? '#059669' : '#dc2626'} /></svg>
}

/** A horizontal gauge: the value against the target, filled and coloured by whether it is met. */
function Gauge({ m }: { m: any }) {
  const gte = m.comparator === 'gte'
  const ratio = m.target ? (gte ? m.value / m.target : m.target / Math.max(m.value, 0.0001)) : m.value === 0 ? 1 : 0
  const pct = Math.max(0, Math.min(100, ratio * 100))
  return <div className="h-2 overflow-hidden rounded-full bg-navy-50"><div className={clsx('h-full rounded-full transition-all', m.status === 'ok' ? 'bg-emerald-500' : 'bg-red-500')} style={{ width: `${pct}%` }} /></div>
}

/** The live KPI dashboard: every RFP indicator against its target, with a 30-day trend, breach banner, editable targets and the monthly report. */
export default function KpiDashboard() {
  const { t } = useTranslation()
  const L = useLang()
  const { can } = useAuth()
  const { data, isLoading, isError, refetch } = useGet<{ data: any[] }>('/admin/kpis', undefined, { refetchInterval: 30_000, staleTime: 0 })
  const integrity = useGet<{ data: any }>('/admin/kpis/integrity', undefined, { staleTime: 60_000 })
  const [editing, setEditing] = useState(false)
  const [targets, setTargets] = useState<Record<string, string>>({})
  const [month, setMonth] = useState(() => new Date().toISOString().slice(0, 7))
  const [busy, setBusy] = useState(false)
  const lang = useTranslation().i18n.language

  if (isLoading) return <Spinner />
  if (isError || !data) return <ErrorState onRetry={refetch} />
  const rows = data.data
  const breaches = rows.filter((m) => m.status === 'breach')

  const measure = async () => { setBusy(true); try { await api.post('/admin/kpis/refresh'); await refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) } }
  const saveTargets = async () => {
    setBusy(true)
    try { await api.put('/admin/kpi-targets', { targets: Object.fromEntries(Object.entries(targets).filter(([, v]) => v !== '').map(([k, v]) => [k, Number(v)])) }); toast(String(t('common.saved'))); setEditing(false); await refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const val = (m: any) => (m.unit === 'ms' ? `${fmt.number(Math.round(m.value))} ms` : m.unit === '/5' ? `${m.value} / 5` : m.unit === '%' ? `${m.value}%` : fmt.number(m.value))

  return (
    <>
      <PageHeader title={t('rep.kpi.title')} subtitle={t('rep.kpi.subtitle')} actions={(
        <div className="flex flex-wrap items-center gap-2">
          <input type="month" className="rounded-xl border border-navy-100 px-2 py-1.5 text-sm" value={month} onChange={(e) => setMonth(e.target.value)} aria-label={String(t('rep.kpi.month'))} />
          <Button variant="outline" size="sm" icon={<FileText className="size-4" />} onClick={() => downloadFile(`/admin/kpis/report?format=pdf&month=${month}&lang=${lang}`, `kpi-${month}.pdf`)}>PDF</Button>
          <Button variant="outline" size="sm" icon={<FileText className="size-4" />} onClick={() => downloadFile(`/admin/kpis/report?format=docx&month=${month}&lang=${lang}`, `kpi-${month}.docx`)}>Word</Button>
          {can('dashboards.manage') && <Button variant="outline" size="sm" onClick={() => { setTargets(Object.fromEntries(rows.map((m) => [m.metric, String(m.target)]))); setEditing(true) }}>{t('rep.kpi.editTargets')}</Button>}
          <Button variant="gold" size="sm" icon={<RefreshCw className={clsx('size-4', busy && 'animate-spin')} />} onClick={measure}>{t('rep.kpi.refresh')}</Button>
        </div>
      )} />
      <div className={clsx('mb-5 rounded-2xl p-3 text-sm font-semibold', breaches.length ? 'bg-red-50 text-red-700' : 'bg-emerald-50 text-emerald-800')} role="status">
        {breaches.length ? t('rep.kpi.breaches', { n: breaches.length }) : t('rep.kpi.allOk')}
      </div>
      <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
        {rows.map((m) => (
          <Card key={m.metric} className={clsx(m.status === 'breach' && 'ring-1 ring-red-200')}>
            <div className="flex items-start justify-between gap-2">
              <h3 className="text-sm font-bold text-navy-900">{L(m.label)}</h3>
              <Badge color={m.status === 'ok' ? 'green' : 'red'}>{t(`rep.kpi.status.${m.status}`)}</Badge>
            </div>
            <div className="mt-2 font-display text-3xl font-bold text-navy-900">{val(m)}</div>
            <div className="mt-1 text-xs text-slate-500">{t('rep.kpi.target')}: {m.comparator === 'gte' ? '≥' : '≤'} {m.target}{m.unit === '%' ? '%' : m.unit === 'ms' ? ' ms' : ''}{m.metric === 'concurrent_users' && m.meta?.peak_24h != null ? ` · ${t('rep.kpi.peak')}: ${fmt.number(m.meta.peak_24h)}` : ''}</div>
            <div className="mt-3"><Gauge m={m} /></div>
            <div className="mt-3"><Spark points={m.trend} ok={m.status === 'ok'} /></div>
            {m.metric === 'unauthorized_attempts' && m.meta?.blocked_30d != null && <div className="mt-2 text-xs text-slate-500">{t('rep.kpi.blocked')}: {fmt.number(m.meta.blocked_30d)}</div>}
            <div className="mt-2 text-[11px] text-slate-400">{t('rep.kpi.updated')}: {fmt.date(m.measured_at, { dateStyle: 'short', timeStyle: 'short' } as any)}</div>
          </Card>
        ))}
      </div>

      {integrity.data && (
        <Card className="mt-6">
          <h3 className="mb-3 font-bold text-navy-900">{t('rep.kpi.integrity')} — {integrity.data.data.value}%</h3>
          <ul className="grid gap-2 text-sm md:grid-cols-2">
            {Object.entries(integrity.data.data.meta.checks as Record<string, { records: number; violations: number }>).map(([k, c]) => (
              <li key={k} className="flex items-center justify-between rounded-xl bg-ivory px-3 py-2"><span>{t(`rep.kpi.check.${k}`, { defaultValue: k })}</span><span className={clsx('font-bold', c.violations ? 'text-red-600' : 'text-emerald-700')}>{c.violations} / {fmt.number(c.records)}</span></li>
            ))}
          </ul>
        </Card>
      )}

      <Modal open={editing} onClose={() => setEditing(false)} title={t('rep.kpi.editTargets')}>
        <div className="space-y-2">
          {rows.map((m) => (
            <label key={m.metric} className="flex items-center justify-between gap-3 text-sm"><span className="min-w-0 flex-1">{L(m.label)} <span className="text-xs text-slate-400">({m.comparator === 'gte' ? '≥' : '≤'})</span></span>
              <input type="number" step="any" className="w-28 rounded-xl border border-navy-100 px-2 py-1.5 text-end" value={targets[m.metric] ?? ''} onChange={(e) => setTargets({ ...targets, [m.metric]: e.target.value })} /></label>
          ))}
        </div>
        <div className="mt-5 flex justify-end gap-2"><Button variant="outline" onClick={() => setEditing(false)}>{t('common.cancel')}</Button><Button variant="gold" loading={busy} onClick={saveTargets}>{t('rep.kpi.saveTargets')}</Button></div>
      </Modal>
    </>
  )
}
