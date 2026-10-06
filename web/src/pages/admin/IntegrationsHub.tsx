/* eslint-disable @typescript-eslint/no-explicit-any */
import clsx from 'clsx'
import { Activity, Copy, RefreshCw } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLang } from '@/components/dashboard/Widget'
import { Badge, Button, Card, Field, Modal, PageHeader, Spinner, Table, Tabs, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { useStepUp } from './SecurityAdmin'

const cls = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
const dot: Record<string, string> = { ok: 'bg-emerald-500', degraded: 'bg-amber-500', down: 'bg-red-500', unknown: 'bg-slate-300' }

function SettingsDialog({ item, onClose, onSaved }: { item: any; onClose: () => void; onSaved: () => void }) {
  const { t } = useTranslation()
  const L = useLang()
  const step = useStepUp()
  const [driver, setDriver] = useState(item.driver)
  const [enabled, setEnabled] = useState(item.enabled)
  const [vals, setVals] = useState<Record<string, any>>(() => Object.fromEntries(item.fields.map((f: any) => [f.k, f.secret ? '' : (item.settings[f.k] ?? f.default ?? (f.type === 'bool' ? false : ''))])))
  const save = () => step.guard(async () => {
    const settings = Object.fromEntries(Object.entries(vals).map(([k, v]) => { const f = item.fields.find((x: any) => x.k === k); return [k, f.type === 'json' && typeof v !== 'string' ? JSON.stringify(v) : v] }))
    await api.put(`/admin/integrations/${item.key}`, { driver, enabled, settings })
    toast(String(t('idn.hub.saved'))); onSaved(); onClose()
  })
  return (
    <Modal open onClose={onClose} title={L(item.name)} wide>
      {item.managed_elsewhere ? <p className="text-sm text-slate-500">{t('idn.hub.elsewhere', { where: item.managed_elsewhere })}</p> : (
        <div className="grid gap-3 md:grid-cols-2">
          <Field label={t('idn.hub.driver')}><select className={cls} value={driver} onChange={(e) => setDriver(e.target.value)}>{item.drivers.map((d: string) => <option key={d} value={d}>{t(`idn.hub.${d}`)}</option>)}</select></Field>
          <label className="flex items-center gap-2 pt-7 text-sm"><input type="checkbox" className="accent-gold-600" checked={enabled} onChange={(e) => setEnabled(e.target.checked)} />{t('idn.hub.enabled')}</label>
          {item.fields.map((f: any) => (
            <Field key={f.k} label={f.k} hint={f.secret && item.secrets_set[f.k] ? t('idn.hub.secretSet') : f.hint} className={f.type === 'json' ? 'md:col-span-2' : ''}>
              {f.type === 'bool' ? <input type="checkbox" className="accent-gold-600" checked={!!vals[f.k]} onChange={(e) => setVals({ ...vals, [f.k]: e.target.checked })} />
                : f.type === 'json' ? <textarea className={`${cls} min-h-20 font-mono text-xs`} dir="ltr" value={typeof vals[f.k] === 'string' ? vals[f.k] : JSON.stringify(vals[f.k] ?? '')} onChange={(e) => setVals({ ...vals, [f.k]: e.target.value })} />
                : <input className={cls} dir="ltr" type={f.secret ? 'password' : f.type === 'number' ? 'number' : 'text'} autoComplete="off" value={vals[f.k] ?? ''} onChange={(e) => setVals({ ...vals, [f.k]: e.target.value })} />}
            </Field>
          ))}
        </div>
      )}
      <div className="mt-5 flex justify-end gap-2"><Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button>{!item.managed_elsewhere && <Button variant="gold" onClick={save}>{t('idn.hub.save')}</Button>}</div>
      {step.dialog}
    </Modal>
  )
}

function LogsDialog({ item, onClose }: { item: any; onClose: () => void }) {
  const { t } = useTranslation()
  const L = useLang()
  const logs = useGet<{ data: any[] }>(`/admin/integrations/${item.key}/logs`, { per_page: 40 }, { staleTime: 0 })
  return (
    <Modal open onClose={onClose} title={`${t('idn.hub.logs')} — ${L(item.name)}`} wide>
      {!logs.data ? <Spinner /> : (
        <Table head={[t('idn.hub.time'), '', t('idn.hub.operation'), t('idn.hub.status'), t('idn.hub.duration'), t('idn.hub.error')]}>
          {logs.data.data.map((l: any) => <tr key={l.id}><Td className="text-xs">{fmt.date(l.created_at, { dateStyle: 'short', timeStyle: 'medium' } as any)}</Td><Td>{t(`idn.hub.direction.${l.direction}`)}</Td><Td>{l.operation}</Td><Td><Badge color={l.status === 'ok' ? 'green' : 'red'}>{l.status}</Badge></Td><Td>{l.duration_ms} ms</Td><Td className="max-w-64 truncate text-xs text-slate-500">{l.error}</Td></tr>)}
        </Table>
      )}
    </Modal>
  )
}

function Webhooks() {
  const { t } = useTranslation()
  const subs = useGet<{ data: any[]; meta: { events: string[] } }>('/admin/webhooks', undefined, { staleTime: 0 })
  const deliveries = useGet<{ data: any[] }>('/admin/webhook-deliveries', { per_page: 20 }, { staleTime: 0 })
  const [form, setForm] = useState<any | null>(null)
  const [secret, setSecret] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const create = async () => { setError(null); try { const { data } = await api.post('/admin/webhooks', form); setSecret(data.data.secret); setForm(null); subs.refetch() } catch (e) { setError(errorMessage(e)) } }
  const replay = async (id: string) => { await api.post(`/admin/webhook-deliveries/${id}/replay`); deliveries.refetch() }
  const test = async (id: string) => { await api.post(`/admin/webhooks/${id}/test`); deliveries.refetch(); subs.refetch() }
  return (
    <div className="space-y-5">
      <Card>
        <div className="mb-3 flex items-center justify-between"><h3 className="font-bold text-navy-900">{t('idn.hub.tabs.webhooks')}</h3><Button variant="gold" onClick={() => setForm({ name: '', url: '', events: ['*'] })}>{t('idn.hub.wh.new')}</Button></div>
        {!subs.data?.data.length ? <p className="text-sm text-slate-400">{t('idn.hub.wh.empty')}</p> : (
          <ul className="divide-y divide-navy-50 text-sm">{subs.data.data.map((s) => <li key={s.id} className="flex flex-wrap items-center justify-between gap-2 py-2"><div><div className="font-semibold">{s.name}</div><div className="text-xs text-slate-500" dir="ltr">{s.url} · {s.events.join(', ')}</div></div><div className="flex items-center gap-2"><Badge color="gold">{t('idn.hub.wh.pending')}: {s.pending}</Badge><Badge color="red">{t('idn.hub.wh.dead')}: {s.dead}</Badge><Button size="sm" variant="outline" onClick={() => test(s.id)}>{t('idn.hub.wh.test')}</Button></div></li>)}</ul>
        )}
      </Card>
      <Card padded={false}>
        <h3 className="p-4 font-bold text-navy-900">{t('idn.hub.wh.deliveries')}</h3>
        <Table head={[t('idn.hub.time'), t('idn.hub.wh.name'), t('idn.hub.operation'), t('idn.hub.status'), '']}>
          {deliveries.data?.data.map((d: any) => <tr key={d.id}><Td className="text-xs">{fmt.date(d.created_at, { dateStyle: 'short', timeStyle: 'short' } as any)}</Td><Td>{d.subscription?.name}</Td><Td className="text-xs">{d.event?.type}</Td><Td><Badge color={d.status === 'delivered' ? 'green' : d.status === 'dead' ? 'red' : 'gold'}>{t(`idn.hub.wh.${d.status}`)}</Badge></Td><Td>{d.status !== 'delivered' && <Button size="sm" variant="outline" onClick={() => replay(d.id)}>{t('idn.hub.wh.replay')}</Button>}</Td></tr>)}
        </Table>
      </Card>
      <Modal open={!!form} onClose={() => setForm(null)} title={t('idn.hub.wh.new')}>
        {form && <div className="space-y-3">
          <Field label={t('idn.hub.wh.name')}><input className={cls} value={form.name} onChange={(e) => setForm({ ...form, name: e.target.value })} /></Field>
          <Field label={t('idn.hub.wh.url')}><input className={cls} dir="ltr" placeholder="https://" value={form.url} onChange={(e) => setForm({ ...form, url: e.target.value })} /></Field>
          <Field label={t('idn.hub.wh.events')}><div className="flex flex-wrap gap-1.5">{['*', ...(subs.data?.meta.events ?? [])].map((ev) => { const on = form.events.includes(ev); return <button key={ev} type="button" onClick={() => setForm({ ...form, events: on ? form.events.filter((x: string) => x !== ev) : [...form.events, ev] })} className={`rounded-full px-3 py-1 text-xs font-semibold ${on ? 'bg-navy-900 text-gold-300' : 'bg-navy-50 text-navy-700'}`}>{ev}</button> })}</div></Field>
          {error && <p className="text-sm text-danger">{error}</p>}
          <div className="flex justify-end"><Button variant="gold" onClick={create}>{t('common.save')}</Button></div>
        </div>}
      </Modal>
      <Modal open={!!secret} onClose={() => setSecret(null)} title={t('idn.hub.wh.secret')}>
        <p className="mb-2 text-sm text-slate-500">{t('idn.hub.wh.copy')}</p>
        <div className="flex items-center gap-2 rounded-xl bg-ivory p-3"><code className="min-w-0 flex-1 break-all font-mono text-sm" dir="ltr">{secret}</code><button onClick={() => { void navigator.clipboard?.writeText(secret ?? ''); toast('✓') }} aria-label="copy"><Copy className="size-4" /></button></div>
      </Modal>
    </div>
  )
}

/** The integration hub: a card per connected system with its health, settings, test, sync and logs; plus webhooks and the event log. */
export default function IntegrationsHub() {
  const { t } = useTranslation()
  const L = useLang()
  const { can } = useAuth()
  const list = useGet<{ data: any[] }>('/admin/integrations', undefined, { staleTime: 0, refetchInterval: 60_000 })
  const [tab, setTab] = useState<'systems' | 'webhooks'>('systems')
  const [edit, setEdit] = useState<any | null>(null)
  const [logs, setLogs] = useState<any | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const act = async (key: string, kind: 'check' | 'sync') => {
    setBusy(key + kind)
    try { const { data } = await api.post(`/admin/integrations/${key}/${kind}`); toast(String(kind === 'sync' ? `${t('idn.hub.synced')} ${JSON.stringify(data.data)}` : t(`idn.hub.health.${data.data.health}`)), kind === 'check' && data.data.health !== 'ok' ? 'error' : 'success'); list.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }
  const groups = ['identity', 'collaboration', 'ministry']
  const syncable = ['hr', 'mawared', 'licences', 'nsis', 'qneds', 'sijil']
  return (
    <>
      <PageHeader title={t('idn.hub.title')} subtitle={t('idn.hub.subtitle')} />
      {can('webhooks.manage') && <div className="mb-4"><Tabs<'systems' | 'webhooks'> value={tab} onChange={setTab} tabs={[{ id: 'systems', label: t('idn.hub.tabs.systems') }, { id: 'webhooks', label: t('idn.hub.tabs.webhooks') }]} /></div>}
      {tab === 'webhooks' ? <Webhooks /> : !list.data ? <Spinner /> : groups.map((g) => (
        <section key={g} className="mb-8">
          <h2 className="mb-3 text-sm font-bold text-slate-500">{t(`idn.hub.groups.${g}`)}</h2>
          <div className="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
            {list.data!.data.filter((i) => i.group === g).map((i) => (
              <Card key={i.key}>
                <div className="flex items-start gap-3"><span className={clsx('mt-1.5 size-3 shrink-0 rounded-full', dot[i.health])} aria-label={i.health} /><div className="min-w-0 flex-1"><h3 className="font-bold leading-snug text-navy-900">{L(i.name)}</h3><div className="mt-0.5 flex flex-wrap items-center gap-1.5 text-xs text-slate-500"><Badge color={i.enabled ? 'green' : 'gray'}>{i.enabled ? t('idn.hub.enabled') : '—'}</Badge><span>{t(`idn.hub.health.${i.health}`)}</span>{i.latency_ms != null && <span>{i.latency_ms} ms</span>}</div></div></div>
                {i.circuit_open && <p className="mt-2 rounded-lg bg-red-50 p-2 text-xs text-red-700">{t('idn.hub.paused')}</p>}
                {i.last_error && <p className="mt-2 truncate text-xs text-danger" title={i.last_error}>{i.last_error}</p>}
                <div className="mt-2 text-xs text-slate-400">{t('idn.hub.calls')}: {i.stats.ok} · {t('idn.hub.errors')}: {i.stats.error}{i.last_sync_at ? ` · ${t('idn.hub.last')}: ${fmt.date(i.last_sync_at, { dateStyle: 'short', timeStyle: 'short' } as any)}` : ''}</div>
                <div className="mt-3 flex flex-wrap gap-1.5">
                  {can('integrations.manage') || (can('sso.manage') && ['entra', 'ldap'].includes(i.key)) ? <Button size="sm" variant="outline" onClick={() => setEdit(i)}>{t('idn.hub.settings')}</Button> : null}
                  {can('integrations.manage') && !i.managed_elsewhere && <Button size="sm" variant="outline" icon={<Activity className="size-3.5" />} loading={busy === i.key + 'check'} onClick={() => act(i.key, 'check')}>{t('idn.hub.check')}</Button>}
                  {can('integrations.manage') && syncable.includes(i.key) && <Button size="sm" variant="outline" icon={<RefreshCw className="size-3.5" />} loading={busy === i.key + 'sync'} onClick={() => act(i.key, 'sync')}>{t('idn.hub.sync')}</Button>}
                  <Button size="sm" variant="outline" onClick={() => setLogs(i)}>{t('idn.hub.logs')}</Button>
                </div>
              </Card>
            ))}
          </div>
        </section>
      ))}
      {edit && <SettingsDialog item={edit} onClose={() => setEdit(null)} onSaved={() => list.refetch()} />}
      {logs && <LogsDialog item={logs} onClose={() => setLogs(null)} />}
    </>
  )
}
