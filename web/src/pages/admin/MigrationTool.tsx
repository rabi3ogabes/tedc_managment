/* eslint-disable @typescript-eslint/no-explicit-any */
import { Download, FileUp } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLang } from '@/components/dashboard/Widget'
import { Badge, Button, Card, Field, PageHeader, Spinner, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { useStepUp } from './SecurityAdmin'

const cls = 'rounded-xl border border-navy-100 px-3 py-2 text-sm'
const stepIdx: Record<string, number> = { uploaded: 1, validated: 3, imported: 5, rolled_back: 5 }

/** The data-migration wizard: pick the kind, download the template, upload, map columns and convert values, validate, rehearse, import, reconcile, roll back. */
export default function MigrationTool() {
  const { t, i18n } = useTranslation()
  const L = useLang()
  const kinds = useGet<{ data: { kind: string; fields: { key: string; label: any; required: boolean }[] }[] }>('/admin/migration/kinds', undefined, { staleTime: 5 * 60_000 })
  const history = useGet<{ data: any[] }>('/admin/migration/batches', { per_page: 10 }, { staleTime: 0 })
  const [kind, setKind] = useState('employees')
  const [batch, setBatch] = useState<any | null>(null)
  const [stats, setStats] = useState<any | null>(null)
  const [dry, setDry] = useState<any | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [mapping, setMapping] = useState<Record<string, string>>({})
  const [valueMaps, setValueMaps] = useState<{ field: string; from: string; to: string }[]>([])
  const step = useStepUp()
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  const fields = kinds.data?.data.find((k) => k.kind === kind)?.fields ?? []

  useEffect(() => { if (batch) { setMapping(batch.mapping ?? {}); setValueMaps(Object.entries(batch.value_maps ?? {}).flatMap(([field, m]: any) => Object.entries(m).map(([from, to]) => ({ field, from, to: String(to) })))) } }, [batch?.id]) // eslint-disable-line react-hooks/exhaustive-deps

  const run = async (name: string, fn: () => Promise<void>) => { setBusy(name); setError(null); try { await fn() } catch (e: any) { if (e?.response?.data?.code === 'step_up_required') throw e; setError(errorMessage(e)) } finally { setBusy(null) } }
  const upload = (file: File) => run('upload', async () => { const f = new FormData(); f.append('kind', kind); f.append('file', file); const { data } = await api.post('/admin/migration/batches', f); setBatch(data.data); setStats(null); setDry(null); history.refetch() })
  const saveMapping = () => run('map', async () => {
    const vm: Record<string, Record<string, string>> = {}
    valueMaps.filter((v) => v.field && v.from).forEach((v) => { (vm[v.field] ??= {})[v.from] = v.to })
    const { data } = await api.put(`/admin/migration/batches/${batch.id}/mapping`, { mapping, value_maps: vm })
    setBatch({ ...batch, ...data.data }); setStats(null); setDry(null)
  })
  const validate = () => run('validate', async () => { const { data } = await api.post(`/admin/migration/batches/${batch.id}/validate`); setStats(data.data); setBatch(data.batch); history.refetch() })
  const rehearse = () => run('dry', async () => { const { data } = await api.post(`/admin/migration/batches/${batch.id}/dry-run`); setDry(data.data) })
  const doImport = () => step.guard(async () => { if (!window.confirm(String(t('idn.mig.confirmImport')))) return; await api.post(`/admin/migration/batches/${batch.id}/import`).then(({ data }) => { setBatch(data.batch); history.refetch(); toast(String(t('idn.mig.recon'))) }) })
  const rollback = () => step.guard(async () => { if (!window.confirm(String(t('idn.mig.confirmRollback')))) return; await api.post(`/admin/migration/batches/${batch.id}/rollback`).then(({ data }) => { setBatch(data.batch); history.refetch() }) })
  const recon = batch?.report?.reconciliation
  const v = stats ?? batch?.report?.validation
  const closed = ['imported', 'rolled_back'].includes(batch?.status)

  return (
    <>
      <PageHeader title={t('idn.mig.title')} subtitle={t('idn.mig.subtitle')} />
      <Card>
        <div className="flex flex-wrap items-end gap-3">
          <Field label={t('idn.mig.kind')}><select className={cls} value={kind} onChange={(e) => { setKind(e.target.value); setBatch(null) }}>{kinds.data?.data.map((k) => <option key={k.kind} value={k.kind}>{t(`idn.mig.kinds.${k.kind}`)}</option>)}</select></Field>
          <Button variant="outline" icon={<Download className="size-4" />} onClick={() => downloadFile(`/admin/migration/templates/${kind}?format=csv&lang=${lang}`, `template-${kind}.csv`)}>{t('idn.mig.template')}</Button>
          <label className="inline-flex cursor-pointer items-center gap-2 rounded-xl bg-gold-500 px-4 py-2 text-sm font-bold text-navy-950 hover:bg-gold-400"><FileUp className="size-4" />{busy === 'upload' ? '…' : t('idn.mig.upload')}<input type="file" className="hidden" accept=".csv,.txt,.xlsx,.xls" onChange={(e) => { const f = e.target.files?.[0]; if (f) void upload(f); e.target.value = '' }} /></label>
        </div>
        <p className="mt-3 text-xs text-slate-500">{t('idn.mig.order')} {t('idn.mig.retention')}</p>
        <ol className="mt-4 flex flex-wrap gap-2 text-xs font-semibold">{['upload', 'map', 'validate', 'dry', 'import'].map((s, i) => <li key={s} className={`rounded-full px-3 py-1 ${batch && (stepIdx[batch.status] ?? 0) >= i + 1 ? 'bg-navy-900 text-gold-300' : 'bg-navy-50 text-navy-700'}`}>{i + 1}. {t(`idn.mig.steps.${s}`)}</li>)}</ol>
        {error && <p className="mt-3 rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</p>}
      </Card>

      {batch && (
        <div className="mt-5 grid gap-5 xl:grid-cols-2">
          <Card>
            <div className="mb-3 flex items-center justify-between"><h3 className="font-bold text-navy-900">{t('idn.mig.mapping')}</h3><Badge color="navy">{batch.filename} · {batch.total_rows}</Badge></div>
            <table className="w-full text-sm"><thead><tr className="text-xs text-slate-500"><th className="p-1 text-start">{t('idn.mig.source')}</th><th className="p-1 text-start">{t('idn.mig.target')}</th></tr></thead>
              <tbody>{(batch.columns ?? []).map((c: string) => (
                <tr key={c}><td className="p-1" dir="auto">{c}</td><td className="p-1"><select className={`${cls} w-full`} disabled={closed} value={mapping[c] ?? ''} onChange={(e) => { const m = { ...mapping }; if (e.target.value) m[c] = e.target.value; else delete m[c]; setMapping(m) }}><option value="">— {t('idn.mig.ignore')} —</option>{fields.map((f) => <option key={f.key} value={f.key}>{L(f.label)}{f.required ? ' *' : ''}</option>)}</select></td></tr>
              ))}</tbody></table>
            <h4 className="mb-1 mt-4 text-sm font-bold text-navy-900">{t('idn.mig.valueMaps')}</h4>
            <p className="mb-2 text-xs text-slate-500">{t('idn.mig.valueMapHint')}</p>
            {valueMaps.map((m, i) => <div key={i} className="mb-1.5 flex gap-1.5"><select className={`${cls} w-40`} disabled={closed} value={m.field} onChange={(e) => setValueMaps(valueMaps.map((x, k) => (k === i ? { ...x, field: e.target.value } : x)))}><option value="" />{fields.map((f) => <option key={f.key} value={f.key}>{L(f.label)}</option>)}</select><input className={`${cls} flex-1`} disabled={closed} value={m.from} onChange={(e) => setValueMaps(valueMaps.map((x, k) => (k === i ? { ...x, from: e.target.value } : x)))} /><input className={`${cls} w-32`} dir="ltr" disabled={closed} value={m.to} onChange={(e) => setValueMaps(valueMaps.map((x, k) => (k === i ? { ...x, to: e.target.value } : x)))} /></div>)}
            {!closed && <div className="mt-3 flex gap-2"><Button size="sm" variant="outline" onClick={() => setValueMaps([...valueMaps, { field: '', from: '', to: '' }])}>+</Button><Button size="sm" variant="gold" loading={busy === 'map'} onClick={saveMapping}>{t('idn.mig.save')}</Button></div>}
          </Card>

          <div className="space-y-5">
            <Card>
              <div className="flex flex-wrap gap-2">
                <Button variant="outline" loading={busy === 'validate'} disabled={closed} onClick={validate}>{t('idn.mig.validateBtn')}</Button>
                <Button variant="outline" loading={busy === 'dry'} disabled={!v || closed} onClick={rehearse}>{t('idn.mig.dryBtn')}</Button>
                <Button variant="gold" disabled={!v || closed || !v.valid} onClick={doImport}>{t('idn.mig.importBtn')}</Button>
                {batch.status === 'imported' && <Button variant="outline" onClick={rollback}>{t('idn.mig.rollbackBtn')}</Button>}
                {v?.invalid + v?.duplicate > 0 && <Button variant="outline" icon={<Download className="size-4" />} onClick={() => downloadFile(`/admin/migration/batches/${batch.id}/errors`, 'migration-errors.csv')}>{t('idn.mig.errorsCsv')}</Button>}
              </div>
              {v && (
                <div className="mt-4 grid grid-cols-3 gap-2 text-center text-xs">
                  {[['total', v.total ?? batch.total_rows], ['valid', v.valid], ['invalid', v.invalid], ['duplicate', v.duplicate], ['willCreate', v.will_create], ['willUpdate', v.will_update]].map(([k, n]) => <div key={String(k)} className="rounded-xl bg-ivory p-2"><div className="font-display text-xl font-bold text-navy-900">{n ?? 0}</div><div className="text-slate-500">{t(`idn.mig.${k}`)}</div></div>)}
                </div>
              )}
              {!!v?.problems && Object.keys(v.problems).length > 0 && <div className="mt-3 text-xs"><div className="mb-1 font-bold text-navy-900">{t('idn.mig.problems')}</div><ul className="space-y-0.5">{Object.entries(v.problems as Record<string, number>).slice(0, 8).map(([k, n]) => <li key={k} className="flex justify-between"><code>{k}</code><span>{n}</span></li>)}</ul></div>}
              {dry && <p className="mt-3 rounded-xl bg-sky-50 p-2 text-xs text-sky-800">{t('idn.mig.dryBtn')}: +{dry.created} / ~{dry.updated} / ✕{dry.failed}</p>}
            </Card>
            {recon && (
              <Card>
                <h3 className="mb-2 font-bold text-navy-900">{t('idn.mig.recon')}</h3>
                <p className={`mb-2 text-sm font-semibold ${recon.matches && recon.accounted_for ? 'text-emerald-700' : 'text-red-600'}`}>{recon.matches && recon.accounted_for ? t('idn.mig.matches') : t('idn.mig.mismatch')}</p>
                <ul className="space-y-0.5 text-xs">{['source_rows', 'valid', 'invalid', 'duplicate', 'imported', 'imported_created', 'imported_updated', 'target_rows'].map((k) => <li key={k} className="flex justify-between"><span className="text-slate-500">{k}</span><b>{recon[k]}</b></li>)}</ul>
                <p className="mt-2 break-all font-mono text-[10px] text-slate-400" dir="ltr">sha256: {recon.checksum_imported}</p>
              </Card>
            )}
          </div>
        </div>
      )}

      <Card padded={false} className="mt-6">
        <h3 className="p-4 font-bold text-navy-900">{t('idn.mig.batches')}</h3>
        {!history.data ? <Spinner /> : (
          <Table head={[t('idn.mig.kind'), '', t('idn.mig.total'), t('idn.mig.status'), '']}>
            {history.data.data.map((b: any) => <tr key={b.id}><Td>{t(`idn.mig.kinds.${b.kind}`)}</Td><Td className="text-xs">{b.filename}</Td><Td>{b.total_rows}</Td><Td><Badge color={b.status === 'imported' ? 'green' : b.status === 'rolled_back' ? 'red' : 'gold'}>{b.status}</Badge></Td><Td><Button size="sm" variant="outline" onClick={async () => { const { data } = await api.get(`/admin/migration/batches/${b.id}`); setBatch(data.data); setKind(data.data.kind); setStats(null); setDry(null) }}>{t('idn.mig.rows')}</Button></Td></tr>)}
          </Table>
        )}
      </Card>
      {step.dialog}
    </>
  )
}
