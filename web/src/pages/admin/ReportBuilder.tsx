/* eslint-disable @typescript-eslint/no-explicit-any */
import { Plus, Trash2 } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { useLang } from '@/components/dashboard/Widget'
import { Badge, Button, Card, Field, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { useLookups } from './communication/shared'
import ResultTable from './reports/ResultTable'

const cls = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'

/** The builder for non-technical people: pick a data source, drag in columns, add filters in plain words, group, sort, chart, preview, save and share. */
export default function ReportBuilder() {
  const { t } = useTranslation()
  const L = useLang()
  const { id } = useParams()
  const navigate = useNavigate()
  const lookups = useLookups()
  const datasets = useGet<{ data: any[]; meta: { aggregates: string[] } }>('/admin/report-datasets', undefined, { staleTime: 5 * 60_000 })
  const existing = useGet<{ data: any }>(id ? `/admin/report-definitions/${id}` : null, undefined, { staleTime: 0 })
  const [f, setF] = useState<any>({ title_ar: '', title_en: '', dataset: '', columns: [], filters: [], group_by: [], sort: [], chart: null, visibility: 'private', roles: [] })
  const [preview, setPreview] = useState<any | null>(null)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  useEffect(() => {
    if (existing.data) { const d = existing.data.data; setF({ title_ar: d.title.ar, title_en: d.title.en, dataset: d.dataset, columns: d.columns, filters: d.filters ?? [], group_by: d.group_by ?? [], sort: d.sort ?? [], chart: d.chart, visibility: d.visibility, roles: d.roles ?? [] }) }
  }, [existing.data])

  const ds = datasets.data?.data.find((d) => d.key === f.dataset)
  const fieldOf = (key: string) => ds?.fields.find((x: any) => x.key === key)
  const grouped = f.columns.some((c: any) => c.aggregate)
  const outKeys = useMemo(() => f.columns.map((c: any) => (c.aggregate ? `${c.field}__${c.aggregate}` : c.field)), [f.columns])

  if (datasets.isLoading || (id && existing.isLoading)) return <Spinner />
  const set = (patch: any) => { setF({ ...f, ...patch }); setPreview(null) }
  const setCol = (i: number, patch: any) => set({ columns: f.columns.map((c: any, k: number) => (k === i ? { ...c, ...patch } : c)) })
  const setFlt = (i: number, patch: any) => set({ filters: f.filters.map((c: any, k: number) => (k === i ? { ...c, ...patch } : c)) })
  const payload = () => ({ ...f, filters: f.filters.map((x: any) => ({ ...x, value: x.operator === 'in' && typeof x.value === 'string' ? x.value.split(',').map((v: string) => v.trim()).filter(Boolean) : x.operator === 'between' && typeof x.value === 'string' ? x.value.split(',').map((v: string) => v.trim()) : x.value })), group_by: grouped ? f.columns.filter((c: any) => !c.aggregate).map((c: any) => c.field) : [], chart: f.chart?.type ? f.chart : null })

  const run = async () => {
    setBusy(true); setError(null)
    try { const { data } = await api.post('/admin/report-definitions/preview', payload()); setPreview(data.data) } catch (e) { setError(errorMessage(e)); setPreview(null) } finally { setBusy(false) }
  }
  const save = async () => {
    if (!f.title_ar.trim() || !f.title_en.trim() || !f.columns.length) { setError(String(t('rep.builder.needColumn'))); return }
    setBusy(true); setError(null)
    try { const { data } = id ? await api.put(`/admin/report-definitions/${id}`, payload()) : await api.post('/admin/report-definitions', payload()); toast(String(t('rep.builder.saved'))); navigate(`/admin/reports?report=${data.data.id}`) } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  const usable = (fd: any, c: any) => c.aggregate || !fd.computed_only

  return (
    <>
      <PageHeader title={t('rep.builder.title')} actions={<div className="flex gap-2"><Button variant="outline" loading={busy} disabled={!f.dataset || !f.columns.length} onClick={run}>{t('rep.preview')}</Button><Button variant="gold" loading={busy} onClick={save}>{t('rep.builder.save')}</Button></div>} />
      <div className="grid gap-5 xl:grid-cols-2">
        <div className="space-y-4">
          <Card>
            <div className="grid gap-3 md:grid-cols-2">
              <Field label={t('rep.builder.titleAr')}><input className={cls} value={f.title_ar} onChange={(e) => set({ title_ar: e.target.value })} /></Field>
              <Field label={t('rep.builder.titleEn')}><input className={cls} dir="ltr" value={f.title_en} onChange={(e) => set({ title_en: e.target.value })} /></Field>
              <Field label={t('rep.builder.dataset')}><select className={cls} value={f.dataset} onChange={(e) => set({ dataset: e.target.value, columns: (datasets.data?.data.find((d) => d.key === e.target.value)?.default ?? []).map((k: string) => ({ field: k })), filters: [], sort: [], chart: null })}><option value="" disabled>—</option>{datasets.data?.data.map((d) => <option key={d.key} value={d.key}>{L(d.label)}</option>)}</select></Field>
              <Field label={t('rep.builder.visibility')}><select className={cls} value={f.visibility} onChange={(e) => set({ visibility: e.target.value })}>{['private', 'role', 'everyone'].map((v) => <option key={v} value={v}>{t(`rep.builder.${v}`)}</option>)}</select></Field>
              {f.visibility === 'role' && <Field label={t('rep.schedule.roles')} className="md:col-span-2"><div className="flex flex-wrap gap-1.5">{lookups?.roles.map((r) => { const on = f.roles.includes(r.slug); return <button key={r.slug} type="button" onClick={() => set({ roles: on ? f.roles.filter((x: string) => x !== r.slug) : [...f.roles, r.slug] })} className={`rounded-full px-3 py-1 text-xs font-semibold ${on ? 'bg-navy-900 text-gold-300' : 'bg-navy-50 text-navy-700'}`}>{L({ ar: r.name_ar, en: r.name_en })}</button> })}</div></Field>}
            </div>
          </Card>

          {!ds ? <Card><p className="text-sm text-slate-500">{t('rep.builder.pickDataset')}</p></Card> : (
            <>
              <Card>
                <h3 className="mb-3 font-bold text-navy-900">{t('rep.builder.columns')}</h3>
                <div className="space-y-2">
                  {f.columns.map((c: any, i: number) => {
                    const fd = fieldOf(c.field)
                    return (
                      <div key={i} className="flex flex-wrap items-center gap-2">
                        <select className={`${cls} w-52`} value={c.field} onChange={(e) => setCol(i, { field: e.target.value, aggregate: undefined })}>{ds.fields.map((x: any) => <option key={x.key} value={x.key}>{L(x.label)}{x.personal ? ' 🔒' : ''}</option>)}</select>
                        {!!fd?.aggregates.length && <select className={`${cls} w-36`} value={c.aggregate ?? ''} onChange={(e) => setCol(i, { aggregate: e.target.value || undefined })}><option value="">{fd.computed_only ? '—' : t('rep.builder.none')}</option>{fd.aggregates.map((a: string) => <option key={a} value={a}>{t(`rep.builder.agg.${a}`)}</option>)}</select>}
                        {fd?.personal && <Badge color="gold">{t('rep.builder.personal')}</Badge>}
                        {fd && !usable(fd, c) && <span className="text-xs text-danger">{t('rep.builder.aggregate')}</span>}
                        <button className="ms-auto text-slate-400 hover:text-danger" onClick={() => set({ columns: f.columns.filter((_: any, k: number) => k !== i) })} aria-label="remove"><Trash2 className="size-4" /></button>
                      </div>
                    )
                  })}
                  <Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => set({ columns: [...f.columns, { field: ds.fields[0].key }] })}>{t('rep.builder.addColumn')}</Button>
                </div>
              </Card>

              <Card>
                <h3 className="mb-3 font-bold text-navy-900">{t('rep.builder.filters')}</h3>
                <div className="space-y-2">
                  {f.filters.map((x: any, i: number) => {
                    const fd = fieldOf(x.field)
                    const ops: string[] = fd?.operators ?? []
                    const noValue = ['empty', 'not_empty', 'is_true', 'is_false'].includes(x.operator)
                    return (
                      <div key={i} className="flex flex-wrap items-center gap-2">
                        <select className={`${cls} w-44`} value={x.field} onChange={(e) => { const nf = fieldOf(e.target.value); setFlt(i, { field: e.target.value, operator: nf.operators[0], value: '' }) }}>{ds.fields.filter((y: any) => !y.computed_only).map((y: any) => <option key={y.key} value={y.key}>{L(y.label)}</option>)}</select>
                        <select className={`${cls} w-36`} value={x.operator} onChange={(e) => setFlt(i, { operator: e.target.value })}>{ops.map((o) => <option key={o} value={o}>{t(`rep.builder.op.${o}`)}</option>)}</select>
                        {!noValue && <input className={`${cls} w-44`} type={fd?.type === 'date' && x.operator !== 'between' ? 'date' : fd?.type === 'number' && x.operator !== 'between' ? 'number' : 'text'} placeholder={x.operator === 'between' || x.operator === 'in' ? 'a, b' : String(t('rep.builder.value'))} value={Array.isArray(x.value) ? x.value.join(', ') : x.value ?? ''} onChange={(e) => setFlt(i, { value: e.target.value })} />}
                        <label className="flex items-center gap-1.5 text-xs"><input type="checkbox" className="accent-gold-600" checked={!!x.adjustable} onChange={(e) => setFlt(i, { adjustable: e.target.checked })} />{t('rep.builder.adjustable')}</label>
                        <button className="ms-auto text-slate-400 hover:text-danger" onClick={() => set({ filters: f.filters.filter((_: any, k: number) => k !== i) })} aria-label="remove"><Trash2 className="size-4" /></button>
                      </div>
                    )
                  })}
                  <Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => { const first = ds.fields.find((y: any) => !y.computed_only); set({ filters: [...f.filters, { field: first.key, operator: first.operators[0], value: '', adjustable: false }] }) }}>{t('rep.builder.addFilter')}</Button>
                </div>
              </Card>

              <Card>
                <div className="grid gap-3 md:grid-cols-2">
                  <Field label={t('rep.builder.sort')}>
                    <div className="flex gap-2"><select className={cls} value={f.sort[0]?.field ?? ''} onChange={(e) => set({ sort: e.target.value ? [{ field: e.target.value, dir: f.sort[0]?.dir ?? 'asc' }] : [] })}><option value="">—</option>{outKeys.map((k: string) => <option key={k} value={k}>{k}</option>)}</select>
                      <select className={`${cls} w-28`} value={f.sort[0]?.dir ?? 'asc'} onChange={(e) => f.sort[0] && set({ sort: [{ field: f.sort[0].field, dir: e.target.value }] })}><option value="asc">{t('rep.builder.asc')}</option><option value="desc">{t('rep.builder.desc')}</option></select></div>
                  </Field>
                  <Field label={t('rep.builder.chart')}>
                    <select className={cls} value={f.chart?.type ?? ''} onChange={(e) => set({ chart: e.target.value ? { type: e.target.value, x: f.chart?.x ?? outKeys[0], y: f.chart?.y ?? outKeys[1] ?? outKeys[0] } : null })}><option value="">{t('rep.builder.chartNone')}</option><option value="bar">{t('rep.builder.chartBar')}</option><option value="line">{t('rep.builder.chartLine')}</option><option value="donut">{t('rep.builder.chartDonut')}</option></select>
                  </Field>
                  {f.chart?.type && <>
                    <Field label={t('rep.builder.x')}><select className={cls} value={f.chart.x} onChange={(e) => set({ chart: { ...f.chart, x: e.target.value } })}>{outKeys.map((k: string) => <option key={k} value={k}>{k}</option>)}</select></Field>
                    <Field label={t('rep.builder.y')}><select className={cls} value={f.chart.y} onChange={(e) => set({ chart: { ...f.chart, y: e.target.value } })}>{outKeys.map((k: string) => <option key={k} value={k}>{k}</option>)}</select></Field>
                  </>}
                </div>
              </Card>
            </>
          )}
          {error && <p className="text-sm text-danger">{error}</p>}
        </div>
        <div>
          <Card>
            <h3 className="mb-3 font-bold text-navy-900">{t('rep.builder.previewTitle')}</h3>
            {preview ? <ResultTable result={preview} /> : <p className="text-sm text-slate-400">{ds ? t('rep.preview') : t('rep.builder.pickDataset')}</p>}
          </Card>
        </div>
      </div>
    </>
  )
}
