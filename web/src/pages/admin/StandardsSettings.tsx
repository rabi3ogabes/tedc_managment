/* eslint-disable @typescript-eslint/no-explicit-any */
import { Copy, Plus, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { toast } from '@/lib/toast'
import { dialogs } from '@/lib/dialogs'

const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
type Tab = 'lrs' | 'lti' | 'providers' | 'libraries'
const copy = (v: string) => { void navigator.clipboard?.writeText(v); toast('✓') }

/** Settings → Standards and integrations: LRS and Caliper, LTI tools and platform details, content providers, external libraries, offline. */
export default function StandardsSettings() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [tab, setTab] = useState<Tab>('lrs')
  const tabs = [can('standards.manage') && { id: 'lrs' as Tab, label: t('content.standards.tabs.lrs') }, can('lti.manage') && { id: 'lti' as Tab, label: t('content.standards.tabs.lti') }, can('providers.manage') && { id: 'providers' as Tab, label: t('content.standards.tabs.providers') }, can('library.manage') && { id: 'libraries' as Tab, label: t('content.standards.tabs.libraries') }].filter(Boolean) as { id: Tab; label: string }[]
  const cur = tabs.find((x) => x.id === tab) ? tab : tabs[0]?.id
  return (
    <>
      <PageHeader title={t('content.standards.title')} />
      <Tabs<Tab> value={cur ?? 'lrs'} onChange={setTab} tabs={tabs} />
      <div className="mt-4">{cur === 'lrs' ? <Lrs /> : cur === 'lti' ? <Lti /> : cur === 'providers' ? <Providers /> : <Libraries />}</div>
    </>
  )
}

function Lrs() {
  const { t } = useTranslation()
  const res = useGet<{ data: any }>('/admin/settings/standards', undefined, { staleTime: 0 })
  const [s, setS] = useState<any>(null)
  const [secret, setSecret] = useState<string | null>(null)
  const [cred, setCred] = useState({ label: '', scope: 'readwrite' })
  useEffect(() => { if (res.data) setS(res.data.data) }, [res.data])
  if (!s) return <Spinner />
  const save = async () => { try { const { data } = await api.put('/admin/settings/standards', { lrs_forward: s.lrs_forward, caliper: s.caliper, offline: s.offline }); setS(data.data); toast(t('content.standards.saved')) } catch (e) { toast(errorMessage(e), 'error') } }
  const add = async () => { try { const { data } = await api.post('/admin/settings/standards/lrs-credentials', cred); setSecret(String(t('content.standards.secretOnce', data.data))); setCred({ label: '', scope: 'readwrite' }); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const del = async (key: string) => { try { await api.delete(`/admin/settings/standards/lrs-credentials/${key}`); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const set = (k: string, f: string, v: any) => setS({ ...s, [k]: { ...s[k], [f]: v } })
  return (
    <div className="space-y-5">
      <Card className="space-y-3"><h3 className="font-bold text-navy-900">{t('content.standards.lrs')}</h3>
        <div className="flex items-center gap-2 text-sm"><span className="text-slate-500">{t('content.standards.endpoint')}:</span><code dir="ltr" className="rounded bg-ivory px-2 py-1 text-xs">{s.lrs_endpoint}</code><button type="button" aria-label="copy" onClick={() => copy(s.lrs_endpoint)}><Copy className="size-4" /></button></div>
        <div className="text-sm font-semibold">{t('content.standards.creds')}</div>
        {s.lrs_credentials.length === 0 ? <Empty /> : s.lrs_credentials.map((c: any) => <div key={c.key} className="flex items-center gap-2 text-sm"><code dir="ltr">{c.key}</code><span className="flex-1">{c.label}</span><Badge>{t(`content.standards.scopes.${c.scope}`)}</Badge><button type="button" aria-label="remove" className="text-danger" onClick={() => void del(c.key)}><Trash2 className="size-4" /></button></div>)}
        <div className="flex flex-wrap gap-2"><input className={`${input} max-w-60`} placeholder={t('content.standards.label')} value={cred.label} onChange={(e) => setCred({ ...cred, label: e.target.value })} /><select className={`${input} max-w-44`} value={cred.scope} onChange={(e) => setCred({ ...cred, scope: e.target.value })}>{['read', 'write', 'readwrite'].map((x) => <option key={x} value={x}>{t(`content.standards.scopes.${x}`)}</option>)}</select><Button size="sm" icon={<Plus className="size-4" />} disabled={!cred.label} onClick={() => void add()}>{t('content.standards.add')}</Button></div>
        {secret && <div className="rounded-xl bg-gold-50 p-3 text-xs font-bold" dir="ltr">{secret}</div>}
      </Card>
      <Card className="space-y-3"><h3 className="font-bold text-navy-900">{t('content.standards.forward')}</h3>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={s.lrs_forward.enabled} onChange={(e) => set('lrs_forward', 'enabled', e.target.checked)} />{t('content.standards.enabled')}</label>
        <div className="grid gap-3 sm:grid-cols-3"><Field label={t('content.standards.url')}><input dir="ltr" className={input} value={s.lrs_forward.endpoint} onChange={(e) => set('lrs_forward', 'endpoint', e.target.value)} /></Field><Field label={t('content.standards.key')}><input dir="ltr" className={input} value={s.lrs_forward.key} onChange={(e) => set('lrs_forward', 'key', e.target.value)} /></Field><Field label={t('content.standards.secret')}><input dir="ltr" type="password" className={input} value={s.lrs_forward.secret} onChange={(e) => set('lrs_forward', 'secret', e.target.value)} /></Field></div></Card>
      <Card className="space-y-3"><h3 className="font-bold text-navy-900">{t('content.standards.caliper')}</h3>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={s.caliper.enabled} onChange={(e) => set('caliper', 'enabled', e.target.checked)} />{t('content.standards.enabled')}</label>
        <div className="grid gap-3 sm:grid-cols-3"><Field label={t('content.standards.url')}><input dir="ltr" className={input} value={s.caliper.endpoint} onChange={(e) => set('caliper', 'endpoint', e.target.value)} /></Field><Field label={t('content.standards.apiKey')}><input dir="ltr" type="password" className={input} value={s.caliper.api_key} onChange={(e) => set('caliper', 'api_key', e.target.value)} /></Field><Field label={t('content.standards.sensor')}><input dir="ltr" className={input} value={s.caliper.sensor_id} onChange={(e) => set('caliper', 'sensor_id', e.target.value)} /></Field></div>
        <div className="flex items-center gap-3 text-xs text-slate-500">{t('content.standards.pending', { n: s.caliper_pending, f: s.caliper_failed })}<Button size="sm" variant="outline" onClick={async () => { try { await api.post('/admin/caliper/flush'); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }}>{t('content.standards.flush')}</Button></div></Card>
      <Card className="space-y-3"><h3 className="font-bold text-navy-900">{t('content.standards.offline')}</h3>
        <label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={s.offline.enabled} onChange={(e) => set('offline', 'enabled', e.target.checked)} />{t('content.standards.enabled')}</label>
        <Field label={t('content.standards.expiry')}><input className={`${input} max-w-28`} type="number" min="1" value={s.offline.expiry_days} onChange={(e) => set('offline', 'expiry_days', Number(e.target.value))} /></Field></Card>
      <Button variant="gold" onClick={() => void save()}>{t('content.standards.save')}</Button>
    </div>
  )
}

function Lti() {
  const { t } = useTranslation()
  const res = useGet<{ data: any[]; platform: any }>('/admin/lti-tools', undefined, { staleTime: 0 })
  const [cur, setCur] = useState<any | null>(null)
  const blank = { name: '', version: '1.3', client_id: '', deployment_id: '1', login_url: '', launch_url: '', jwks_url: '', public_key: '', deep_link_url: '', consumer_key: '', consumer_secret: '', privacy: { share_name: true, share_email: false }, supports_ags: true, is_active: true }
  const save = async () => { try { const body = { ...cur, custom: cur.custom ?? null }; cur.id ? await api.put(`/admin/lti-tools/${cur.id}`, body) : await api.post('/admin/lti-tools', body); toast(t('content.lti.saved')); setCur(null); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const rotate = async () => { try { await api.post('/admin/lti-tools/rotate-keys'); void res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  if (!res.data) return <Spinner />
  const p = res.data.platform
  const f = (k: string, label: string, dir = true) => <Field label={label}><input dir={dir ? 'ltr' : undefined} className={input} value={cur[k] ?? ''} onChange={(e) => setCur({ ...cur, [k]: e.target.value })} /></Field>
  return (
    <div className="space-y-4">
      <Card className="space-y-2"><div className="flex items-center justify-between"><h3 className="font-bold text-navy-900">{t('content.lti.platform')}</h3><Button size="sm" variant="outline" onClick={() => void rotate()}>{t('content.lti.rotate')}</Button></div>
        {([['issuer', 'issuer'], ['jwks', 'jwks_url'], ['auth', 'auth_url'], ['tokenUrl', 'token_url'], ['dlReturn', 'deep_link_return_url'], ['outcomes', 'outcomes_url']] as const).map(([l, k]) => <div key={k} className="flex items-center gap-2 text-xs"><span className="w-36 text-slate-500">{t(`content.lti.${l}`)}</span><code dir="ltr" className="flex-1 truncate rounded bg-ivory px-2 py-1">{p[k]}</code><button type="button" aria-label="copy" onClick={() => copy(p[k])}><Copy className="size-4" /></button></div>)}</Card>
      <div className="flex justify-end"><Button size="sm" icon={<Plus className="size-4" />} onClick={() => setCur(blank)}>{t('content.lti.new')}</Button></div>
      {res.data.data.map((x) => <Card key={x.id} className="flex items-center gap-3"><b>{x.name}</b><Badge>{x.version}</Badge>{!x.is_active && <Badge color="gray">off</Badge>}<span className="flex-1 truncate text-xs text-slate-400" dir="ltr">{x.launch_url}</span><Button size="sm" variant="outline" onClick={() => setCur({ ...x, consumer_secret: '' })}>{t('common.edit')}</Button></Card>)}
      <Modal wide open={!!cur} onClose={() => setCur(null)} title={t('content.lti.title')}>
        {cur && <div className="grid gap-3 sm:grid-cols-2">{f('name', t('content.lti.name'), false)}
          <Field label={t('content.lti.version')}><select className={input} disabled={!!cur.id} value={cur.version} onChange={(e) => setCur({ ...cur, version: e.target.value })}><option value="1.3">1.3</option><option value="1.1">1.1</option></select></Field>
          {f('launch_url', t('content.lti.launchUrl'))}
          {cur.version === '1.3' ? <>{f('client_id', t('content.lti.clientId'))}{f('deployment_id', t('content.lti.deployment'))}{f('login_url', t('content.lti.loginUrl'))}{f('jwks_url', t('content.lti.jwksUrl'))}{f('deep_link_url', t('content.lti.deepLinkUrl'))}
            <Field label={t('content.lti.publicKey')} className="sm:col-span-2"><textarea dir="ltr" className={input} rows={3} value={cur.public_key ?? ''} onChange={(e) => setCur({ ...cur, public_key: e.target.value })} /></Field></> : <>{f('consumer_key', t('content.lti.consumerKey'))}{f('consumer_secret', t('content.lti.consumerSecret'))}</>}
          <div className="flex flex-wrap gap-4 text-sm sm:col-span-2"><label className="flex items-center gap-2"><input type="checkbox" checked={!!cur.privacy?.share_name} onChange={(e) => setCur({ ...cur, privacy: { ...cur.privacy, share_name: e.target.checked } })} />{t('content.lti.shareName')}</label><label className="flex items-center gap-2"><input type="checkbox" checked={!!cur.privacy?.share_email} onChange={(e) => setCur({ ...cur, privacy: { ...cur.privacy, share_email: e.target.checked } })} />{t('content.lti.shareEmail')}</label>{cur.version === '1.3' && <label className="flex items-center gap-2"><input type="checkbox" checked={!!cur.supports_ags} onChange={(e) => setCur({ ...cur, supports_ags: e.target.checked })} />{t('content.lti.ags')}</label>}<label className="flex items-center gap-2"><input type="checkbox" checked={!!cur.is_active} onChange={(e) => setCur({ ...cur, is_active: e.target.checked })} />{t('content.lti.active')}</label></div>
          <div className="sm:col-span-2"><Button variant="gold" onClick={() => void save()}>{t('common.save')}</Button></div></div>}
      </Modal>
    </div>
  )
}

function Providers() {
  const { t } = useTranslation()
  const res = useGet<{ data: any }>('/admin/settings/content-providers', undefined, { staleTime: 0 })
  const cat = useGet<{ data: any[] }>('/admin/external-courses', undefined, { staleTime: 0 })
  const rev = useGet<{ data: any[] }>('/admin/external-completions', { status: 'pending' }, { staleTime: 0 })
  const [s, setS] = useState<any>(null)
  useEffect(() => { if (res.data) setS(res.data.data) }, [res.data])
  if (!s) return <Spinner />
  const set = (p: string, f: string, v: any) => setS({ ...s, [p]: { ...s[p], [f]: v } })
  const save = async () => { try { await api.put('/admin/settings/content-providers', s); toast(t('content.standards.saved')) } catch (e) { toast(errorMessage(e), 'error') } }
  const sync = async () => { try { const { data } = await api.post('/admin/content-providers/sync'); toast(String(t('content.providers.synced', data.data))); void cat.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const make = async (id: string) => { try { await api.post(`/admin/external-courses/${id}/program`); toast(t('content.providers.programMade')); void cat.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const decide = async (id: string, decision: string) => { const note = decision === 'approve' ? undefined : await dialogs.prompt(t('content.providers.note')) ?? ''; if (decision !== 'approve' && !note) return; try { await api.post(`/admin/external-completions/${id}/decision`, { decision, note }); void rev.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-4">
      {Object.keys(s).map((p) => (
        <Card key={p} className="space-y-2"><div className="flex items-center gap-3"><b className="capitalize">{p.replace('_', ' ')}</b><label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={s[p].enabled} onChange={(e) => set(p, 'enabled', e.target.checked)} />{t('content.standards.enabled')}</label></div>
          {s[p].enabled && <div className="grid gap-3 sm:grid-cols-4"><Field label={t('content.providers.driver')}><select className={input} value={s[p].driver} onChange={(e) => set(p, 'driver', e.target.value)}><option value="rest">REST</option><option value="fake">Demo</option></select></Field><Field label={t('content.providers.base')}><input dir="ltr" className={input} value={s[p].base_url} onChange={(e) => set(p, 'base_url', e.target.value)} /></Field><Field label={t('content.standards.apiKey')}><input dir="ltr" type="password" className={input} value={s[p].api_key} onChange={(e) => set(p, 'api_key', e.target.value)} /></Field><Field label={t('content.providers.catalogue')}><input dir="ltr" className={input} value={s[p].catalogue_path} onChange={(e) => set(p, 'catalogue_path', e.target.value)} /></Field></div>}</Card>))}
      <div className="flex gap-2"><Button variant="gold" onClick={() => void save()}>{t('content.standards.save')}</Button><Button variant="outline" onClick={() => void sync()}>{t('content.providers.sync')}</Button></div>
      <h3 className="font-bold text-navy-900">{t('content.providers.catalogueTitle')}</h3>
      {!cat.data?.data.length ? <Empty /> : cat.data.data.map((c) => <Card key={c.id} className="flex items-center gap-3"><Badge>{c.provider}</Badge><span className="flex-1">{c.title}</span><span className="text-xs text-slate-400">{c.hours} h</span>{c.program_id ? <Badge color="green">✓</Badge> : <Button size="sm" variant="outline" onClick={() => void make(c.id)}>{t('content.providers.makeProgram')}</Button>}</Card>)}
      <h3 className="font-bold text-navy-900">{t('content.providers.reviews')}</h3>
      {!rev.data?.data.length ? <Empty /> : rev.data.data.map((c) => <Card key={c.id} className="flex flex-wrap items-center gap-3"><div className="flex-1"><b>{c.employee_name}</b><div className="text-sm">{c.program}</div>{c.note && <div className="text-xs text-slate-500">{c.note}</div>}</div><Button size="sm" variant="gold" onClick={() => void decide(c.id, 'approve')}>{t('content.providers.approve')}</Button><Button size="sm" variant="ghost" onClick={() => void decide(c.id, 'reject')}>{t('content.providers.reject')}</Button></Card>)}
    </div>
  )
}

function Libraries() {
  const { t } = useTranslation()
  const res = useGet<{ data: any }>('/admin/settings/external-libraries', undefined, { staleTime: 0 })
  const [s, setS] = useState<any>(null)
  const [q, setQ] = useState('')
  const [found, setFound] = useState<any[]>([])
  useEffect(() => { if (res.data) setS(res.data.data) }, [res.data])
  if (!s) return <Spinner />
  const set = (p: string, f: string, v: any) => setS({ ...s, [p]: { ...s[p], [f]: v } })
  const save = async () => { try { await api.put('/admin/settings/external-libraries', s); toast(t('content.standards.saved')) } catch (e) { toast(errorMessage(e), 'error') } }
  const search = async () => { try { const { data } = await api.get('/admin/external-libraries/search', { params: { q } }); setFound(data.data) } catch (e) { toast(errorMessage(e), 'error') } }
  const imp = async (r: any) => { try { await api.post('/admin/external-libraries/import', r); toast(t('content.libs.imported')) } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <div className="space-y-4">
      {['maktabati', 'qnl'].map((p) => <Card key={p} className="space-y-2"><div className="flex items-center gap-3"><b>{s[p].name_ar} / {s[p].name_en}</b><label className="flex items-center gap-2 text-sm"><input type="checkbox" checked={s[p].enabled} onChange={(e) => set(p, 'enabled', e.target.checked)} />{t('content.standards.enabled')}</label></div>
        {s[p].enabled && <div className="grid gap-3 sm:grid-cols-3"><Field label={t('content.providers.driver')}><select className={input} value={s[p].driver} onChange={(e) => set(p, 'driver', e.target.value)}><option value="link">Link / API</option><option value="fake">Demo</option></select></Field><Field label={t('content.libs.searchUrl')}><input dir="ltr" className={input} value={s[p].search_url} onChange={(e) => set(p, 'search_url', e.target.value)} /></Field><Field label={t('content.libs.apiUrl')}><input dir="ltr" className={input} value={s[p].api_url} onChange={(e) => set(p, 'api_url', e.target.value)} /></Field></div>}</Card>)}
      <Button variant="gold" onClick={() => void save()}>{t('content.standards.save')}</Button>
      <div className="flex gap-2"><input className={input} placeholder={t('content.libs.search')} value={q} onChange={(e) => setQ(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && void search()} /><Button variant="outline" disabled={q.length < 2} onClick={() => void search()}>🔍</Button></div>
      {found.map((r, i) => <Card key={i} className="flex items-center gap-3"><Badge>{r.library}</Badge><span className="flex-1">{r.title}</span>{r.deep_link ? <Button size="sm" variant="outline" onClick={() => window.open(r.url, '_blank', 'noopener')}>{t('content.libs.deepLink')}</Button> : <Button size="sm" variant="gold" onClick={() => void imp(r)}>{t('content.libs.import')}</Button>}</Card>)}
    </div>
  )
}
