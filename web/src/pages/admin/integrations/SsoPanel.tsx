/* eslint-disable @typescript-eslint/no-explicit-any */
import clsx from 'clsx'
import { AlertTriangle, Check, CheckCircle2, Copy, Download, KeyRound, Smartphone, XCircle } from 'lucide-react'
import { useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field, Modal, Spinner, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { useStepUp } from '../SecurityAdmin'

const cls = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
const WARN_DAYS = 30

type Cert = { subject: string; issuer: string; not_after: string | null; days_left: number | null; fingerprint: string }
type Overview = {
  protocol: 'oidc' | 'saml' | null; enabled: boolean; mandatory: boolean
  urls: { entity_id: string; acs: string; slo: string; metadata: string }
  app_redirects: string[]
  settings: Record<string, any>
  sp: { current: Cert | null; next: Cert | null }
  idp_certificates: Cert[]
  configured: { saml: boolean; oidc: boolean }
  oidc: { redirect_uri: string; enabled: boolean }
  metadata_refresh: { ok: boolean; changed: boolean; message: string | null; at: string } | null
}

/** A certificate with its expiry; amber from 30 days before, red once expired. */
function CertLine({ c, label }: { c: Cert; label?: string }) {
  const { t } = useTranslation()
  const days = c.days_left
  const state = days === null ? 'ok' : days < 0 ? 'expired' : days <= WARN_DAYS ? 'warn' : 'ok'
  return (
    <li className="rounded-xl border border-navy-100 p-3 text-sm">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <span className="font-semibold text-navy-900" dir="ltr">{label ?? c.subject}</span>
        <Badge color={state === 'ok' ? 'green' : state === 'warn' ? 'amber' : 'red'}>
          {state === 'expired' ? t('m365.sso.cert.expired') : state === 'warn' ? t('m365.sso.cert.warn', { n: days }) : days !== null ? t('m365.sso.cert.left', { n: days }) : '—'}
        </Badge>
      </div>
      <div className="mt-1 text-xs text-slate-500">{t('m365.sso.cert.notAfter')}: {fmt.date(c.not_after)}</div>
      <div className="mt-0.5 break-all font-mono text-[11px] text-slate-400" dir="ltr">{t('m365.sso.cert.fingerprint')}: {c.fingerprint}</div>
    </li>
  )
}

function CopyRow({ label, value }: { label: string; value: string }) {
  const { t } = useTranslation()
  const [done, setDone] = useState(false)
  const copy = () => {
    void navigator.clipboard?.writeText(value)
    setDone(true)
    setTimeout(() => setDone(false), 1500)
  }
  return (
    <div>
      <div className="mb-1 text-xs font-semibold text-slate-500">{label}</div>
      <div className="flex items-center gap-2 rounded-xl bg-ivory p-2.5">
        <code className="min-w-0 flex-1 break-all font-mono text-xs" dir="ltr">{value}</code>
        <button type="button" onClick={copy} className="grid size-8 shrink-0 place-items-center rounded-lg text-navy-700 hover:bg-white" aria-label={`${t('m365.sso.saml.copy')}: ${label}`}>
          {done ? <Check className="size-4 text-emerald-600" /> : <Copy className="size-4" />}
        </button>
      </div>
    </div>
  )
}

/** What a test sign-in found: the claims that arrived, the account TEDC would reach and the roles it would give. */
export function SsoTestDialog({ id, onClose }: { id: string; onClose: () => void }) {
  const { t } = useTranslation()
  const res = useGet<{ data: any }>(`/admin/sso/test/${id}`, undefined, { staleTime: 0, retry: false })
  const r = res.data?.data
  const fields = ['subject', 'email', 'upn', 'name', 'name_ar', 'employee_no', 'groups', 'email_verified', 'name_id', 'name_id_format']
  const show = (v: unknown) => (Array.isArray(v) ? v.join('، ') : typeof v === 'boolean' ? (v ? '✓' : '✗') : String(v))
  return (
    <Modal open onClose={onClose} title={t('m365.sso.test.title')} wide>
      {res.isLoading ? <Spinner /> : !r ? <p className="text-sm text-danger">{res.error ? errorMessage(res.error) : '—'}</p> : (
        <div className="space-y-5">
          <div className={clsx('flex items-start gap-3 rounded-2xl border p-4', r.ok ? 'border-emerald-200 bg-emerald-50/70' : 'border-red-200 bg-red-50/70')}>
            {r.ok ? <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-emerald-600" /> : <XCircle className="mt-0.5 size-5 shrink-0 text-red-600" />}
            <div className="min-w-0">
              <div className="font-bold text-navy-900">{r.ok ? t('m365.sso.test.ok') : t('m365.sso.test.failed')}</div>
              {!r.ok && <p className="mt-1 text-sm text-slate-700">{t(`m365.sso.test.why.${r.reason}`, { defaultValue: '' })}</p>}
              {!r.ok && r.detail && <p className="mt-1 break-words text-xs text-slate-500" dir="ltr">{t('m365.sso.test.detail')}: {r.detail}</p>}
              <div className="mt-1 text-xs text-slate-500">{t(`m365.sso.protocols.${r.protocol}`)}</div>
            </div>
          </div>
          {r.ok && r.claims && (
            <section>
              <h4 className="mb-2 text-sm font-bold text-navy-900">{t('m365.sso.test.claims')}</h4>
              <dl className="grid gap-x-6 gap-y-1.5 text-sm sm:grid-cols-[max-content_1fr]">
                {fields.filter((f) => r.claims[f] !== undefined && r.claims[f] !== null && r.claims[f] !== '').map((f) => (
                  <div key={f} className="contents"><dt className="text-slate-500">{t(`m365.sso.test.fields.${f}`)}</dt><dd className="break-all font-medium text-navy-900" dir="auto">{show(r.claims[f])}</dd></div>
                ))}
              </dl>
            </section>
          )}
          {r.ok && r.account && (
            <section>
              <h4 className="mb-2 text-sm font-bold text-navy-900">{t('m365.sso.test.account')}</h4>
              <p className={clsx('rounded-xl p-3 text-sm', r.account.outcome === 'refused' ? 'bg-red-50 text-red-800' : 'bg-ivory text-navy-900')}>
                {t(`m365.sso.test.outcome.${r.account.outcome}`)}
                {r.account.message && <span className="mt-1 block text-xs text-slate-600">{r.account.message}</span>}
              </p>
              {r.account.user && <p className="mt-2 text-xs text-slate-500">{t('m365.sso.test.user')}: <bdi dir="ltr">{r.account.user.name} · {r.account.user.email}</bdi></p>}
              {r.account.outcome !== 'refused' && (
                <div className="mt-3">
                  <div className="mb-1.5 text-xs font-semibold text-slate-500">{t('m365.sso.test.roles')}</div>
                  {r.account.roles.length ? <div className="flex flex-wrap gap-1.5">{r.account.roles.map((x: any, i: number) => <Badge key={i} color="navy">{x.role}{x.scope_type && x.scope_type !== 'ministry' ? ` · ${x.scope_type}` : ''}</Badge>)}</div> : <span className="text-sm text-slate-400">{t('m365.sso.test.noRoles')}</span>}
                </div>
              )}
              {r.account.ignored?.length > 0 && (
                <div className="mt-3 rounded-xl bg-amber-50 p-3 text-xs text-amber-900">
                  <div className="mb-1 font-semibold">{t('m365.sso.test.ignored')}</div>
                  <ul className="space-y-0.5">{r.account.ignored.map((x: any, i: number) => <li key={i}><bdi dir="ltr">{x.group} → {x.role}</bdi> — {t(`m365.sso.test.ignoredWhy.${x.reason}`)}</li>)}</ul>
                </div>
              )}
            </section>
          )}
          <div className="flex items-center justify-between gap-3 border-t border-navy-100 pt-4">
            <span className="text-xs text-slate-400">{t('m365.sso.test.closeWindow')}</span>
            <Button variant="outline" onClick={onClose}>{t('m365.sso.test.close')}</Button>
          </div>
        </div>
      )}
    </Modal>
  )
}

/** Settings → Integrations → Single sign-on: the protocol in force, OpenID Connect and SAML set-up, the mandatory switch and the test sign-in. */
export default function SsoPanel({ items, onEdit, onChanged }: { items: any[]; onEdit: (item: any) => void; onChanged: () => void }) {
  const { t } = useTranslation()
  const step = useStepUp()
  const res = useGet<{ data: Overview }>('/admin/sso/saml', undefined, { staleTime: 0 })
  const o = res.data?.data
  const [tab, setTab] = useState<'oidc' | 'saml' | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [xml, setXml] = useState('')
  const [url, setUrl] = useState('')
  const [preview, setPreview] = useState<any | null>(null)
  if (!o) return <Spinner />
  const current = tab ?? o.protocol ?? (o.configured.saml ? 'saml' : 'oidc')
  const entra = items.find((i) => i.key === 'entra')
  const samlItem = items.find((i) => i.key === 'saml')
  const refresh = () => { void res.refetch(); onChanged() }

  const run = (key: string, fn: () => Promise<void>) => step.guard(async () => {
    setBusy(key)
    try { await fn() } finally { setBusy(null) }
  })
  const put = (key: string, body: Record<string, unknown>, done: string) => run(key, async () => { await api.put(`/admin/integrations/${key}`, body); toast(done); refresh() })

  const setMandatory = async (on: boolean) => {
    if (!o.protocol) return
    if (!(await dialogs.confirm(on ? t('m365.sso.mandatory.confirmOn') : t('m365.sso.mandatory.confirmOff')))) return
    const key = o.protocol === 'saml' ? 'saml' : 'entra'
    await put(key, { settings: { sso_mandatory: on } }, t('m365.sso.mandatory.saved'))
  }
  const keys = (stage: 'current' | 'next' | 'promote', done: string) => run('keys-' + stage, async () => {
    if (stage === 'current' && o.sp.current && !(await dialogs.confirm(t('m365.sso.saml.key.confirmGenerate')))) return
    await api.post('/admin/sso/saml/keys', { stage }); toast(done); refresh()
  })
  const read = async () => {
    setBusy('read')
    try { const { data } = await api.post('/admin/sso/saml/metadata', url.trim() ? { url: url.trim() } : { xml }); setPreview(data.data) } catch (e) { setPreview(null); toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }
  const apply = () => run('apply', async () => {
    await api.post('/admin/sso/saml/metadata', url.trim() ? { url: url.trim(), apply: true } : { xml, apply: true })
    toast(t('m365.sso.saml.idp.done')); setPreview(null); setXml(''); setUrl(''); refresh()
  })
  /** The window opens at once (a popup blocker allows that) and is pointed at the identity provider when the address is ready. */
  const test = (protocol: 'oidc' | 'saml') => run('test-' + protocol, async () => {
    const w = window.open('about:blank', '_blank')
    try { const { data } = await api.post('/admin/sso/test', { protocol }); if (w) w.location.href = data.data.url; else window.location.href = data.data.url } catch (e) { w?.close(); throw e }
  })

  const s = o.settings
  const idpCerts = o.idp_certificates
  const soon = [...idpCerts, ...(o.sp.current ? [o.sp.current] : []), ...(o.sp.next ? [o.sp.next] : [])].filter((c) => c.days_left !== null && c.days_left <= WARN_DAYS)
  const refreshState = o.metadata_refresh
  const ready = o.configured.saml
  const block = (children: ReactNode) => <Card className="space-y-4">{children}</Card>

  return (
    <div className="space-y-5">
      <Card>
        <h2 className="text-lg font-bold text-navy-900">{t('m365.sso.title')}</h2>
        <p className="mt-1 text-sm text-slate-500">{t('m365.sso.subtitle')}</p>
        <div className="mt-4 grid gap-3 md:grid-cols-2">
          <div className="rounded-2xl bg-ivory p-4">
            <div className="text-xs font-semibold text-slate-500">{t('m365.sso.status.protocol')}</div>
            <div className="mt-1 flex items-center gap-2 text-base font-bold text-navy-900">{o.protocol ? t(`m365.sso.protocols.${o.protocol}`) : t('m365.sso.status.off')}<Badge color={o.protocol ? 'green' : 'gray'}>{o.protocol ? '●' : '○'}</Badge></div>
          </div>
          <div className="rounded-2xl bg-ivory p-4">
            <div className="flex items-center gap-1.5 text-xs font-semibold text-slate-500"><Smartphone className="size-3.5" />{t('m365.sso.status.app')}</div>
            <div className="mt-1 text-sm font-semibold text-navy-900">{o.protocol ? t('m365.sso.status.appOn') : t('m365.sso.status.appOff')}</div>
            <div className="mt-1 text-xs text-slate-500">{t('m365.sso.status.appNote', { uri: o.app_redirects[0] })}</div>
          </div>
        </div>
        <label className={clsx('mt-4 flex items-start gap-3 rounded-2xl border border-navy-100 p-4', !o.protocol && 'opacity-60')}>
          <input type="checkbox" className="mt-1 size-4 accent-gold-600" checked={o.mandatory} disabled={!o.protocol} onChange={(e) => void setMandatory(e.target.checked)} />
          <span><span className="block text-sm font-bold text-navy-900">{t('m365.sso.mandatory.title')}</span><span className="mt-0.5 block text-xs leading-relaxed text-slate-500">{o.protocol ? t('m365.sso.mandatory.hint') : t('m365.sso.mandatory.needsSso')}</span></span>
        </label>
        {soon.length > 0 && (
          <p className="mt-4 flex items-start gap-2 rounded-xl bg-amber-50 p-3 text-sm text-amber-900"><AlertTriangle className="mt-0.5 size-4 shrink-0" />{t('m365.sso.cert.warn', { n: Math.max(0, Math.min(...soon.map((c) => c.days_left as number))) })}</p>
        )}
      </Card>

      <Tabs<'oidc' | 'saml'> value={current} onChange={setTab} tabs={[{ id: 'oidc', label: t('m365.sso.protocols.oidc') }, { id: 'saml', label: t('m365.sso.protocols.saml') }]} />

      {current === 'oidc' && block(<>
        <div className="flex flex-wrap items-center justify-between gap-2"><h3 className="font-bold text-navy-900">{t('m365.sso.oidc.title')}</h3><Badge color={o.configured.oidc ? 'green' : 'amber'}>{o.configured.oidc ? t('m365.sso.oidc.configured') : t('m365.sso.oidc.notConfigured')}</Badge></div>
        <p className="text-sm text-slate-600">{t('m365.sso.oidc.hint')}</p>
        <CopyRow label={t('m365.sso.oidc.redirect')} value={o.oidc.redirect_uri} />
        <div className="flex flex-wrap gap-2">
          {entra && <Button variant="outline" onClick={() => onEdit(entra)}>{t('m365.sso.oidc.settings')}</Button>}
          <Button variant="gold" loading={busy === 'test-oidc'} disabled={!o.configured.oidc} onClick={() => void test('oidc')}>{t('m365.sso.test.button')}</Button>
        </div>
        <p className="text-xs text-slate-400">{t('m365.sso.test.hint')}</p>
      </>)}

      {current === 'saml' && (<>
        {block(<>
          <div><h3 className="font-bold text-navy-900">{t('m365.sso.saml.give')}</h3><p className="mt-1 text-sm text-slate-500">{t('m365.sso.saml.giveHint')}</p></div>
          <div className="grid gap-3 md:grid-cols-2">
            <CopyRow label={t('m365.sso.saml.entityId')} value={o.urls.entity_id} />
            <CopyRow label={t('m365.sso.saml.acs')} value={o.urls.acs} />
            <CopyRow label={t('m365.sso.saml.slo')} value={o.urls.slo} />
            <CopyRow label={t('m365.sso.saml.metadata')} value={o.urls.metadata} />
          </div>
          <a className="inline-flex w-fit items-center gap-2 rounded-xl border border-navy-100 bg-white px-4 py-2 text-sm font-semibold text-navy-900 hover:border-gold-400" href={o.urls.metadata} download="tedc-saml-metadata.xml" target="_blank" rel="noopener noreferrer"><Download className="size-4" />{t('m365.sso.saml.download')}</a>
        </>)}

        {block(<>
          <div className="flex items-center gap-2"><KeyRound className="size-5 text-gold-600" /><h3 className="font-bold text-navy-900">{t('m365.sso.saml.key.title')}</h3></div>
          {o.sp.current ? <ul className="space-y-2"><CertLine c={o.sp.current} label={t('m365.sso.saml.key.current')} />{o.sp.next && <CertLine c={o.sp.next} label={t('m365.sso.saml.key.upcoming')} />}</ul> : <p className="text-sm text-slate-500">{t('m365.sso.saml.key.none')}</p>}
          {o.sp.current && <p className="text-xs text-slate-500">{t('m365.sso.saml.key.rollover')}</p>}
          <div className="flex flex-wrap gap-2">
            {!o.sp.current && <Button variant="gold" loading={busy === 'keys-current'} onClick={() => void keys('current', t('m365.sso.saml.key.created'))}>{t('m365.sso.saml.key.generate')}</Button>}
            {o.sp.current && !o.sp.next && <Button variant="outline" loading={busy === 'keys-next'} onClick={() => void keys('next', t('m365.sso.saml.key.created'))}>{t('m365.sso.saml.key.next')}</Button>}
            {o.sp.next && <Button variant="gold" loading={busy === 'keys-promote'} onClick={() => void keys('promote', t('m365.sso.saml.key.promoted'))}>{t('m365.sso.saml.key.promote')}</Button>}
          </div>
        </>)}

        {block(<>
          <h3 className="font-bold text-navy-900">{t('m365.sso.saml.idp.title')}</h3>
          <div>
            <div className="mb-1.5 text-xs font-semibold text-slate-500">{t('m365.sso.saml.idp.applied')}</div>
            {s.idp_entity_id ? (
              <div className="space-y-2 text-sm">
                <dl className="grid gap-x-6 gap-y-1 sm:grid-cols-[max-content_1fr]">
                  <dt className="text-slate-500">{t('m365.sso.saml.idp.entity')}</dt><dd className="break-all font-medium" dir="ltr">{s.idp_entity_id}</dd>
                  <dt className="text-slate-500">{t('m365.sso.saml.idp.sso')}</dt><dd className="break-all font-medium" dir="ltr">{s.idp_sso_url}</dd>
                  {s.idp_slo_url && <><dt className="text-slate-500">{t('m365.sso.saml.idp.slo')}</dt><dd className="break-all font-medium" dir="ltr">{s.idp_slo_url}</dd></>}
                </dl>
                <ul className="space-y-2">{idpCerts.map((c, i) => <CertLine key={i} c={c} />)}</ul>
              </div>
            ) : <p className="text-sm text-slate-400">{t('m365.sso.saml.idp.none')}</p>}
          </div>
          {s.idp_metadata_url && (
            <div className="rounded-xl bg-ivory p-3 text-xs text-slate-600">
              <div className="font-semibold text-navy-900">{t('m365.sso.saml.idp.refresh.title')}</div>
              <div className="mt-0.5">{refreshState ? <span className={refreshState.ok ? 'text-emerald-700' : 'text-danger'}>{refreshState.ok ? t('m365.sso.saml.idp.refresh.ok') : `${t('m365.sso.saml.idp.refresh.failed')}${refreshState.message ? `: ${refreshState.message}` : ''}`} · {fmt.dateTime(refreshState.at)}</span> : t('m365.sso.saml.idp.refresh.never')}</div>
              <div className="mt-1 text-slate-500">{t('m365.sso.saml.idp.refresh.note')}</div>
            </div>
          )}
          <div className="grid gap-3 md:grid-cols-2">
            <Field label={t('m365.sso.saml.idp.paste')} className="md:col-span-2"><textarea className={`${cls} min-h-28 font-mono text-xs`} dir="ltr" value={xml} onChange={(e) => { setXml(e.target.value); setPreview(null) }} placeholder="<EntityDescriptor …" /></Field>
            <Field label={t('m365.sso.saml.idp.url')} className="md:col-span-2"><input className={cls} dir="ltr" placeholder="https://login.microsoftonline.com/…/federationmetadata/2007-06/federationmetadata.xml" value={url} onChange={(e) => { setUrl(e.target.value); setPreview(null) }} /></Field>
          </div>
          <div><Button variant="outline" loading={busy === 'read'} disabled={!xml.trim() && !url.trim()} onClick={() => void read()}>{t('m365.sso.saml.idp.read')}</Button></div>
          {preview && (
            <div className="space-y-3 rounded-2xl border border-gold-300 bg-gold-100/30 p-4">
              <div className="text-sm font-bold text-navy-900">{t('m365.sso.saml.idp.preview')}</div>
              <dl className="grid gap-x-6 gap-y-1 text-sm sm:grid-cols-[max-content_1fr]">
                <dt className="text-slate-500">{t('m365.sso.saml.idp.entity')}</dt><dd className="break-all font-medium" dir="ltr">{preview.entity_id}</dd>
                <dt className="text-slate-500">{t('m365.sso.saml.idp.sso')}</dt><dd className="break-all font-medium" dir="ltr">{preview.sso_url}</dd>
                {preview.slo_url && <><dt className="text-slate-500">{t('m365.sso.saml.idp.slo')}</dt><dd className="break-all font-medium" dir="ltr">{preview.slo_url}</dd></>}
              </dl>
              <div><div className="mb-1.5 text-xs font-semibold text-slate-500">{t('m365.sso.saml.idp.certs')}</div><ul className="space-y-2">{preview.certificates.map((c: Cert, i: number) => <CertLine key={i} c={c} />)}</ul></div>
              <Button variant="gold" loading={busy === 'apply'} onClick={() => void apply()}>{t('m365.sso.saml.idp.apply')}</Button>
            </div>
          )}
        </>)}

        {block(<>
          <div className="flex flex-wrap items-center justify-between gap-2">
            <label className="flex items-center gap-2 text-sm font-bold text-navy-900"><input type="checkbox" className="size-4 accent-gold-600" checked={!!samlItem?.enabled} disabled={!ready && !samlItem?.enabled} onChange={(e) => void put('saml', { driver: 'saml', enabled: e.target.checked }, t('m365.sso.saml.saved'))} />{t('m365.sso.saml.enable')}</label>
            <Badge color={ready ? 'green' : 'amber'}>{ready ? t('m365.sso.saml.ready') : t('m365.sso.saml.incomplete')}</Badge>
          </div>
          <p className="text-xs text-slate-500">{t('m365.sso.saml.enableHint')}</p>
          <div className="flex flex-wrap gap-2">
            <Button variant="gold" loading={busy === 'test-saml'} disabled={!ready} onClick={() => void test('saml')}>{t('m365.sso.test.button')}</Button>
            {samlItem && <Button variant="outline" onClick={() => onEdit(samlItem)}>{t('m365.sso.saml.advanced')}</Button>}
          </div>
          <p className="text-xs text-slate-400">{t('m365.sso.test.hint')}</p>
        </>)}
      </>)}
      {step.dialog}
    </div>
  )
}

