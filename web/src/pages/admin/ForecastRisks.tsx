/* eslint-disable @typescript-eslint/no-explicit-any */
import { AlertTriangle, RefreshCw } from 'lucide-react'
import { Fragment, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, PageHeader, Progress, Spinner, Table, Tabs, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Tab = 'forecasts' | 'risks'

/** Expected professional-development needs for next year with their explanation, and the early-warning lists. */
export default function ForecastRisks() {
  const { t } = useTranslation()
  const [tab, setTab] = useState<Tab>('forecasts')
  const [busy, setBusy] = useState(false)
  const run = async () => {
    setBusy(true)
    try { await api.post('/admin/forecasts/run'); toast(String(t('aix.fc.ran'))); window.location.reload() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <>
      <PageHeader title={t('aix.fc.title')} subtitle={t('aix.fc.subtitle')} actions={<Button variant="outline" loading={busy} icon={<RefreshCw className="size-4" />} onClick={run}>{t('aix.fc.run')}</Button>} />
      <Tabs<Tab> value={tab} onChange={setTab} tabs={[{ id: 'forecasts', label: t('aix.fc.tabs.forecasts') }, { id: 'risks', label: t('aix.fc.tabs.risks') }]} />
      {tab === 'forecasts' ? <Forecasts /> : <Risks />}
    </>
  )
}

function Forecasts() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [dimension, setDimension] = useState('competency')
  const [open, setOpen] = useState<string | null>(null)
  const [plan, setPlan] = useState('')
  const res = useGet<{ data: any[]; year: number }>('/admin/forecasts', { dimension }, { staleTime: 0 })
  const plans = useGet<{ data: any[] }>(can('plans.manage') ? '/admin/plans' : null, undefined, { staleTime: 30_000, retry: false })
  const draftPlans = (plans.data?.data ?? []).filter((p) => p.status === 'draft')
  const add = async (ids: string[]) => {
    try { const { data } = await api.post('/admin/forecasts/to-plan', { plan_id: plan, forecast_ids: ids }); toast(String(t('aix.fc.added', { n: data.data.added.length }))) } catch (e) { toast(errorMessage(e), 'error') }
  }
  const rows = res.data?.data ?? []
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3">
        <select className="input w-auto" value={dimension} onChange={(e) => setDimension(e.target.value)}>{['competency', 'job', 'school'].map((d) => <option key={d} value={d}>{t(`aix.fc.dimension.${d}`)}</option>)}</select>
        {res.data && <Badge color="navy">{t('aix.fc.year')} {res.data.year}</Badge>}
        {can('plans.manage') && draftPlans.length > 0 && <select className="input w-auto" value={plan} onChange={(e) => setPlan(e.target.value)}><option value="">{t('aix.fc.pickPlan')}</option>{draftPlans.map((p) => <option key={p.id} value={p.id}>{p.title_en || p.title_ar}</option>)}</select>}
      </div>
      {res.isLoading ? <Spinner /> : rows.length === 0 ? <Empty text={String(t('aix.fc.none'))} /> : (
        <Card padded={false}>
          <Table head={[t('aix.fc.label'), t('aix.fc.value'), t('aix.fc.range'), t('aix.fc.confidence'), '']}>
            {rows.map((r) => (
              <Fragment key={r.id}>
                <tr className="cursor-pointer hover:bg-ivory" onClick={() => setOpen(open === r.id ? null : r.id)}>
                  <Td><span className="font-semibold text-navy-900">{r.label}</span></Td>
                  <Td><b>{fmt.number(r.value, 1)}</b></Td><Td>{fmt.number(r.low, 1)} – {fmt.number(r.high, 1)}</Td>
                  <Td><div className="flex items-center gap-2"><Progress value={r.confidence * 100} className="w-20" tone={r.confidence >= 0.6 ? 'green' : 'gold'} /><span className="text-xs">{Math.round(r.confidence * 100)}%</span></div></Td>
                  <Td>{plan && <Button size="sm" variant="outline" onClick={(e) => { e.stopPropagation(); void add([r.id]) }}>{t('aix.fc.toPlan')}</Button>}</Td>
                </tr>
                {open === r.id && <tr><td colSpan={5} className="bg-ivory px-5 py-3 text-xs text-slate-600"><b>{t('aix.fc.why')}:</b> {r.explanation}<div className="mt-1">{t('aix.fc.history')}: {(r.history?.years ?? []).map((y: number, i: number) => `${y}: ${r.history.values[i]}`).join(' · ')}</div></td></tr>}
              </Fragment>
            ))}
          </Table>
        </Card>
      )}
    </div>
  )
}

function Risks() {
  const { t } = useTranslation()
  const [type, setType] = useState('')
  const res = useGet<{ data: any[]; counts: Record<string, number> }>('/admin/risks', { type: type || undefined }, { staleTime: 0 })
  const resolve = async (id: string) => { try { await api.post(`/admin/risks/${id}/resolve`); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-4">
      <div className="flex flex-wrap gap-2">
        <button type="button" onClick={() => setType('')} className={`rounded-full border px-3 py-1 text-xs font-semibold ${type === '' ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-slate-600'}`}>{t('soc.hub.all')}</button>
        {['hours_shortfall', 'licence_gap', 'group_underfill', 'low_satisfaction'].map((x) => <button key={x} type="button" onClick={() => setType(x)} className={`rounded-full border px-3 py-1 text-xs font-semibold ${type === x ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-slate-600'}`}>{t(`aix.fc.risk.${x}`)} {res.data?.counts?.[x] ? `(${res.data.counts[x]})` : ''}</button>)}
      </div>
      {res.isLoading ? <Spinner /> : (res.data?.data.length ?? 0) === 0 ? <Empty text={String(t('aix.fc.noRisks'))} icon={<AlertTriangle className="size-8" />} /> : (
        <div className="grid gap-3 md:grid-cols-2">
          {res.data!.data.map((r) => (
            <Card key={r.id}>
              <div className="flex items-start justify-between gap-2"><div><Badge color={r.score >= 0.7 ? 'red' : 'amber'}>{t(`aix.fc.risk.${r.type}`)}</Badge><h3 className="mt-1 font-bold text-navy-900">{r.label}</h3></div><div className="text-end text-xs text-slate-500">{t('aix.fc.score')}<div className="font-display text-xl font-bold text-navy-900">{Math.round(r.score * 100)}%</div></div></div>
              <ul className="mt-2 list-disc ps-5 text-sm text-slate-600">{r.reasons.map((x: string) => <li key={x}>{x}</li>)}</ul>
              <div className="mt-3 flex justify-end"><Button size="sm" variant="ghost" onClick={() => resolve(r.id)}>{t('aix.fc.resolve')}</Button></div>
            </Card>
          ))}
        </div>
      )}
    </div>
  )
}
