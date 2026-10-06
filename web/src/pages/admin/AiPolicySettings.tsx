/* eslint-disable @typescript-eslint/no-explicit-any */
import { ShieldCheck } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field, Spinner, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

const FEATURES = ['recommendations', 'feedback', 'adaptive', 'forecasts', 'assistant'] as const
const BADGE: Record<string, 'green' | 'blue' | 'red'> = { qatar: 'green', approved: 'blue', external: 'red' }

/** Settings → AI & privacy: residency, redaction, retention, the model of each feature, recommendation weights, call statistics and the log. */
export default function AiPolicySettings() {
  const { t } = useTranslation()
  const res = useGet<{ data: any }>('/admin/settings/ai', undefined, { staleTime: 0 })
  const stats = useGet<{ data: any }>('/admin/ai/recommendation-stats', undefined, { staleTime: 0 })
  const logs = useGet<{ data: any[] }>('/admin/ai/logs', { per_page: 15 }, { staleTime: 0 })
  const [p, setP] = useState<any>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (res.data) setP(res.data.data.policy) }, [res.data])
  if (!p || !res.data) return <Spinner />
  const d = res.data.data
  const resid = (modelId: string | null) => {
    const m = d.models.find((x: any) => x.id === modelId)
    return m?.residency ?? d.default_residency ?? null
  }
  const setF = (f: string, k: string, v: any) => setP({ ...p, features: { ...p.features, [f]: { ...p.features[f], [k]: v } } })
  const save = async () => {
    setBusy(true)
    try { await api.put('/admin/settings/ai', p); toast(String(t('aix.set.saved'))); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const reindex = async () => { try { const { data } = await api.post('/admin/assistant/reindex'); toast(String(t('aix.set.reindexed', { n: data.data.chunks }))); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const w = p.recommendations.weights

  return (
    <div className="space-y-6">
      <Card className="space-y-4">
        <h3 className="flex items-center gap-2 text-lg font-bold text-navy-900"><ShieldCheck className="size-5 text-gold-600" />{t('aix.set.title')}</h3>
        <p className="text-sm text-slate-500">{t('aix.set.subtitle')}</p>
        <label className="flex items-start gap-3 text-sm"><input type="checkbox" className="mt-1 accent-gold-600" checked={p.residency_enforced} onChange={(e) => setP({ ...p, residency_enforced: e.target.checked })} /><span><b>{t('aix.set.residency')}</b><span className="block text-xs text-slate-500">{t('aix.set.residencyHint')}</span></span></label>
        <label className="flex items-center gap-3 text-sm"><input type="checkbox" className="accent-gold-600" checked={p.redaction} onChange={(e) => setP({ ...p, redaction: e.target.checked })} /><b>{t('aix.set.redaction')}</b></label>
        <Field label={t('aix.set.retention')} className="max-w-48"><input type="number" min={1} max={365} className="input" value={p.retention_days} onChange={(e) => setP({ ...p, retention_days: Number(e.target.value) })} /></Field>
      </Card>

      <Card padded={false}>
        <Table head={['', t('aix.set.enabled'), t('aix.set.model'), t('aix.set.allowExternal'), '']}>
          {FEATURES.map((f) => {
            const row = p.features[f]
            const residency = resid(row.model_id ?? null)
            return (
              <tr key={f}>
                <Td><span className="font-semibold text-navy-900">{t(`aix.set.features.${f}`)}</span></Td>
                <Td><input type="checkbox" className="size-4 accent-gold-600" checked={row.enabled} onChange={(e) => setF(f, 'enabled', e.target.checked)} aria-label={f} /></Td>
                <Td>
                  <div className="flex flex-wrap items-center gap-2">
                    <select className="input w-auto min-w-40" value={row.model_id ?? ''} onChange={(e) => setF(f, 'model_id', e.target.value || null)}>
                      <option value="">{t('aix.set.defaultModel')}</option>
                      {d.models.map((m: any) => <option key={m.id} value={m.id}>{m.label}</option>)}
                    </select>
                    {residency && <Badge color={BADGE[residency]}>{t(`aix.set.residencyBadge.${residency}`)}</Badge>}
                  </div>
                </Td>
                <Td><input type="checkbox" className="size-4 accent-gold-600" checked={row.allow_external} onChange={(e) => setF(f, 'allow_external', e.target.checked)} aria-label={`${f} external`} /></Td>
                <Td><Badge color={d.policy.features[f].usable ? 'green' : 'gray'}>{t(d.policy.features[f].usable ? 'aix.set.usable' : 'aix.set.notUsable')}</Badge></Td>
              </tr>
            )
          })}
        </Table>
      </Card>

      <Card className="space-y-3">
        <h3 className="font-bold text-navy-900">{t('aix.set.weights')}</h3>
        <div className="grid gap-3 sm:grid-cols-5">{Object.keys(w).map((k) => <Field key={k} label={t(`aix.set.w.${k}`)}><input type="number" min={0} max={100} className="input" value={w[k]} onChange={(e) => setP({ ...p, recommendations: { ...p.recommendations, weights: { ...w, [k]: Number(e.target.value) } } })} /></Field>)}</div>
        <div className="flex flex-wrap items-center gap-4">
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={p.recommendations.ab_test} onChange={(e) => setP({ ...p, recommendations: { ...p.recommendations, ab_test: e.target.checked } })} />{t('aix.set.ab')}</label>
          {p.recommendations.ab_test && <Field label={t('aix.set.abShare')} className="w-40"><input type="number" min={0} max={100} className="input" value={p.recommendations.ab_share} onChange={(e) => setP({ ...p, recommendations: { ...p.recommendations, ab_share: Number(e.target.value) } })} /></Field>}
        </div>
        {stats.data && (
          <Table head={[t('aix.set.conv'), t('aix.set.shown'), t('aix.set.clicked'), t('aix.set.enrolled')]}>
            {(['hybrid', 'rules'] as const).map((v) => <tr key={v}><Td><b>{t(`aix.set.${v}`)}</b></Td><Td>{stats.data.data[v].shown}</Td><Td>{stats.data.data[v].clicked} ({stats.data.data[v].click_rate}%)</Td><Td>{stats.data.data[v].enrolled} ({stats.data.data[v].enrol_rate}%)</Td></tr>)}
          </Table>
        )}
      </Card>
      <div className="flex justify-end"><Button variant="gold" loading={busy} onClick={save}>{t('aix.set.save')}</Button></div>

      <Card className="space-y-3">
        <div className="flex flex-wrap items-center justify-between gap-3">
          <div><h3 className="font-bold text-navy-900">{t('aix.set.index')}</h3><p className="text-xs text-slate-500">{fmt.number(d.index.chunks)} {t('aix.set.chunks')}</p></div>
          <Button variant="outline" onClick={reindex}>{t('aix.set.reindex')}</Button>
        </div>
        <div className="grid gap-3 sm:grid-cols-3 text-center">
          {[[t('aix.set.calls'), d.stats.calls_30d], [t('aix.set.blocked'), d.stats.blocked_30d], [t('aix.set.redactions'), d.stats.redactions_30d]].map(([l, v]) => <div key={String(l)} className="rounded-xl bg-ivory p-3"><div className="font-display text-2xl font-bold text-navy-900">{fmt.number(Number(v))}</div><div className="text-xs text-slate-500">{l} · {t('aix.set.stats')}</div></div>)}
        </div>
      </Card>

      <Card padded={false}>
        <h3 className="px-5 pt-4 font-bold text-navy-900">{t('aix.set.logs')}</h3>
        <p className="px-5 pb-2 text-xs text-slate-500">{t('aix.set.logsHint')}</p>
        <Table head={[t('aix.set.when'), t('aix.set.feature'), t('aix.set.status'), t('aix.set.reason'), t('aix.set.ms')]}>
          {(logs.data?.data ?? []).map((l: any) => <tr key={l.id}><Td>{fmt.dateTime(l.created_at)}</Td><Td>{l.feature}</Td><Td><Badge color={l.status === 'ok' ? 'green' : l.status === 'blocked' ? 'red' : 'amber'}>{l.status}</Badge></Td><Td>{l.reason ?? '—'}</Td><Td>{l.latency_ms}</Td></tr>)}
        </Table>
      </Card>
    </div>
  )
}
