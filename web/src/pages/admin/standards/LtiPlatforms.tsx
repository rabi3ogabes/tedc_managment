/* eslint-disable @typescript-eslint/no-explicit-any */
import clsx from 'clsx'
import { CheckCircle2, Copy, KeyRound, Plus, XCircle } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'
type Check = { key: string; ok: boolean; detail: string | null }
const blank = () => ({ name: '', version: '1.3', issuer: '', client_id: '', deployment_ids: '', auth_login_url: '', auth_token_url: '', jwks_url: '', public_key: '', consumer_key: '', consumer_secret: '', status: 'active',
  settings: { program_ids: [] as string[], trusted_domains: '', roles: { learner: 'employee', instructor: 'employee' } } })

/** A value to give the other LMS, with a copy button. */
function Copyable({ label, value }: { label: string; value: string }) {
  const { t } = useTranslation()
  return (
    <div className="flex items-center gap-2 text-xs">
      <span className="w-40 shrink-0 text-slate-500">{label}</span>
      <code dir="ltr" className="min-w-0 flex-1 truncate rounded-lg bg-ivory px-2 py-1.5">{value}</code>
      <button type="button" className="rounded-lg p-1.5 text-navy-700 hover:bg-navy-50" aria-label={String(t('lti.admin.copy', { what: label }))} onClick={() => { void navigator.clipboard?.writeText(value); toast(String(t('lti.admin.copied'))) }}><Copy className="size-4" /></button>
    </div>
  )
}

/**
 * Settings → Standards → Platforms that launch TEDC (TEDC as an LTI tool): what to give the other LMS, the platforms
 * registered, the programmes each may reach, which e-mail domains it is trusted for, and a configuration test.
 */
export default function LtiPlatforms() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const list = useGet<{ data: any[] }>('/admin/lti-platforms', undefined, { staleTime: 0 })
  const config = useGet<{ data: any }>('/admin/lti-platforms/tool-config', undefined, { staleTime: 60_000 })
  const programs = useGet<{ data: any[] }>('/admin/programs', { per_page: 200 }, { staleTime: 60_000 })
  const [cur, setCur] = useState<any | null>(null)
  const [busy, setBusy] = useState(false)
  const [checks, setChecks] = useState<{ name: string; ok: boolean; checks: Check[] } | null>(null)
  const [showKey, setShowKey] = useState(false)
  const [filter, setFilter] = useState('')
  const options = useMemo(() => (programs.data?.data ?? []).filter((p: any) => !filter || `${p.code} ${p.title_ar} ${p.title_en}`.toLowerCase().includes(filter.toLowerCase())), [programs.data, filter])
  if (!list.data || !config.data) return <Spinner />
  const c = config.data.data

  const edit = (p: any) => setCur({ ...blank(), ...p, deployment_ids: (p.deployment_ids ?? []).join(', '), public_key: '', consumer_secret: '',
    settings: { program_ids: p.settings?.program_ids ?? [], trusted_domains: (p.settings?.trusted_domains ?? []).join(', '), roles: { learner: 'employee', instructor: 'employee', ...(p.settings?.roles ?? {}) } } })
  const save = async () => {
    setBusy(true)
    try {
      const list13 = cur.version === '1.3'
      const body: any = { name: cur.name, version: cur.version, status: cur.status,
        settings: { program_ids: cur.settings.program_ids, trusted_domains: String(cur.settings.trusted_domains).split(/[\s,،]+/).map((x: string) => x.trim()).filter(Boolean), roles: cur.settings.roles } }
      if (list13) Object.assign(body, { issuer: cur.issuer, client_id: cur.client_id, deployment_ids: String(cur.deployment_ids).split(/[\s,،]+/).filter(Boolean), auth_login_url: cur.auth_login_url, auth_token_url: cur.auth_token_url, jwks_url: cur.jwks_url || null }, cur.public_key ? { public_key: cur.public_key } : {})
      else Object.assign(body, { consumer_key: cur.consumer_key }, cur.consumer_secret ? { consumer_secret: cur.consumer_secret } : {})
      cur.id ? await api.put(`/admin/lti-platforms/${cur.id}`, body) : await api.post('/admin/lti-platforms', body)
      toast(String(t('lti.admin.saved'))); setCur(null); void list.refetch()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const test = async (p: any) => { try { const { data } = await api.post(`/admin/lti-platforms/${p.id}/test`); setChecks({ name: p.name, ...data.data }) } catch (e) { toast(errorMessage(e), 'error') } }
  const remove = async (p: any) => { if (!(await dialogs.confirm(String(t('lti.admin.deleteConfirm', { name: p.name }))))) return; try { await api.delete(`/admin/lti-platforms/${p.id}`); void list.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const toggleProgram = (id: string) => setCur({ ...cur, settings: { ...cur.settings, program_ids: cur.settings.program_ids.includes(id) ? cur.settings.program_ids.filter((x: string) => x !== id) : [...cur.settings.program_ids, id] } })
  const txt = (k: string, label: string, ltr = true) => <Field label={label}><input dir={ltr ? 'ltr' : undefined} className={input} value={cur[k] ?? ''} onChange={(e) => setCur({ ...cur, [k]: e.target.value })} /></Field>

  return (
    <div className="space-y-5">
      <Card className="space-y-3">
        <div><h3 className="font-bold text-navy-900">{t('lti.admin.toolTitle')}</h3><p className="text-sm text-slate-500">{t('lti.admin.toolHint')}</p></div>
        <Copyable label={String(t('lti.admin.loginUrl'))} value={c.login_url} />
        <Copyable label={String(t('lti.admin.launchUrl'))} value={c.launch_url} />
        <Copyable label={String(t('lti.admin.deepLinkUrl'))} value={c.deep_link_url} />
        <Copyable label={String(t('lti.admin.jwksUrl'))} value={c.jwks_url} />
        <Copyable label={String(t('lti.admin.launch11Url'))} value={c.launch11_url} />
        <Copyable label={String(t('lti.admin.custom'))} value="tedc_target=lesson:<id>" />
        <button type="button" className="inline-flex items-center gap-1.5 text-xs font-semibold text-link" onClick={() => setShowKey(!showKey)}><KeyRound className="size-4" />{t(showKey ? 'lti.admin.hideKey' : 'lti.admin.showKey')}</button>
        {showKey && <pre dir="ltr" className="max-h-48 overflow-auto rounded-xl bg-ivory p-3 text-[11px]">{c.public_key}</pre>}
      </Card>

      <div className="flex items-center justify-between gap-3"><h3 className="font-bold text-navy-900">{t('lti.admin.platforms')}</h3><Button size="sm" icon={<Plus className="size-4" />} onClick={() => setCur(blank())}>{t('lti.admin.add')}</Button></div>
      {!list.data.data.length ? <Empty text={String(t('lti.admin.empty'))} /> : list.data.data.map((p) => (
        <Card key={p.id} className="space-y-2">
          <div className="flex flex-wrap items-center gap-2">
            <b className="text-navy-900">{p.name}</b><Badge>LTI {p.version}</Badge><Badge color={p.status === 'active' ? 'green' : 'gray'}>{t(`lti.admin.status.${p.status}`)}</Badge>
            <span className="ms-auto flex flex-wrap gap-2"><Button size="sm" variant="outline" onClick={() => void test(p)}>{t('lti.admin.test')}</Button><Button size="sm" variant="outline" onClick={() => edit(p)}>{t('common.edit')}</Button><Button size="sm" variant="outline" onClick={() => void remove(p)}>{t('common.delete')}</Button></span>
          </div>
          <div className="truncate text-xs text-slate-500" dir="ltr">{p.version === '1.3' ? `${p.issuer} · ${p.client_id}` : p.consumer_key}</div>
          <div className="flex flex-wrap gap-1.5 text-xs">{(p.programs ?? []).map((g: any) => <span key={g.id} className="rounded-full bg-navy-50 px-2 py-0.5 text-navy-800">{ar ? g.title_ar : g.title_en || g.title_ar}</span>)}{!(p.programs ?? []).length && <span className="text-amber-700">{t('lti.admin.noPrograms')}</span>}</div>
          <div className="text-xs text-slate-500">{t('lti.admin.launches', { n: fmt.number(p.launches) })}{p.last_launch_at && ` · ${fmt.dateTime(p.last_launch_at)}`}{(p.settings?.trusted_domains ?? []).length > 0 && ` · ${t('lti.admin.trusted', { d: p.settings.trusted_domains.join(', ') })}`}</div>
        </Card>))}

      <Modal wide open={!!cur} onClose={() => setCur(null)} title={cur?.id ? cur.name : t('lti.admin.add')}>
        {cur && (
          <div className="space-y-5">
            <div className="grid gap-3 sm:grid-cols-2">
              {txt('name', String(t('lti.admin.name')), false)}
              <Field label={t('lti.admin.version')}><select className={input} disabled={!!cur.id} value={cur.version} onChange={(e) => setCur({ ...cur, version: e.target.value })}><option value="1.3">LTI 1.3</option><option value="1.1">LTI 1.1</option></select></Field>
              {cur.version === '1.3' ? <>
                {txt('issuer', String(t('lti.admin.issuer')))}{txt('client_id', String(t('lti.admin.clientId')))}
                <Field label={t('lti.admin.deployments')} hint={t('lti.admin.deploymentsHint')}><input dir="ltr" className={input} value={cur.deployment_ids} onChange={(e) => setCur({ ...cur, deployment_ids: e.target.value })} /></Field>
                {txt('auth_login_url', String(t('lti.admin.authUrl')))}{txt('auth_token_url', String(t('lti.admin.tokenUrl')))}{txt('jwks_url', String(t('lti.admin.platformJwks')))}
                <Field label={t('lti.admin.publicKey')} hint={cur.id && cur.has_public_key ? t('lti.admin.keepKey') : t('lti.admin.publicKeyHint')} className="sm:col-span-2"><textarea dir="ltr" rows={3} className={input} value={cur.public_key} onChange={(e) => setCur({ ...cur, public_key: e.target.value })} /></Field>
              </> : <>
                {txt('consumer_key', String(t('lti.admin.consumerKey')))}
                <Field label={t('lti.admin.consumerSecret')} hint={cur.id ? t('lti.admin.keepSecret') : t('lti.admin.secretHint')}><input dir="ltr" type="password" autoComplete="new-password" className={input} value={cur.consumer_secret} onChange={(e) => setCur({ ...cur, consumer_secret: e.target.value })} /></Field>
              </>}
              <label className="flex items-center gap-2 text-sm sm:col-span-2"><input type="checkbox" checked={cur.status === 'active'} onChange={(e) => setCur({ ...cur, status: e.target.checked ? 'active' : 'disabled' })} />{t('lti.admin.active')}</label>
            </div>

            <fieldset className="space-y-3 rounded-2xl border border-navy-100 p-4">
              <legend className="px-2 text-sm font-bold text-navy-900">{t('lti.admin.reach')}</legend>
              <p className="text-xs text-slate-500">{t('lti.admin.reachHint')}</p>
              <input className={input} placeholder={String(t('lti.admin.searchPrograms'))} value={filter} onChange={(e) => setFilter(e.target.value)} />
              <div className="max-h-56 space-y-1 overflow-auto rounded-xl border border-navy-50 p-2">{options.map((p: any) => (
                <label key={p.id} className={clsx('flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm', cur.settings.program_ids.includes(p.id) && 'bg-gold-50')}>
                  <input type="checkbox" checked={cur.settings.program_ids.includes(p.id)} onChange={() => toggleProgram(p.id)} /><span className="font-mono text-xs text-slate-400" dir="ltr">{p.code}</span><span>{ar ? p.title_ar : p.title_en || p.title_ar}</span></label>))}</div>
              <p className="text-xs text-slate-500">{t('lti.admin.chosen', { n: cur.settings.program_ids.length })}</p>
            </fieldset>

            <fieldset className="space-y-3 rounded-2xl border border-navy-100 p-4">
              <legend className="px-2 text-sm font-bold text-navy-900">{t('lti.admin.accounts')}</legend>
              <Field label={t('lti.admin.trustedDomains')} hint={t('lti.admin.trustedHint')}><input dir="ltr" className={input} placeholder="moe.gov.qa" value={cur.settings.trusted_domains} onChange={(e) => setCur({ ...cur, settings: { ...cur.settings, trusted_domains: e.target.value } })} /></Field>
              <div className="grid gap-3 sm:grid-cols-2">{(['learner', 'instructor'] as const).map((r) => (
                <Field key={r} label={t(`lti.admin.role.${r}`)}><select className={input} value={cur.settings.roles[r]} onChange={(e) => setCur({ ...cur, settings: { ...cur.settings, roles: { ...cur.settings.roles, [r]: e.target.value } } })}>{['employee', 'trainer'].map((x) => <option key={x} value={x}>{t(`lti.admin.roles.${x}`)}</option>)}</select></Field>))}</div>
              <p className="rounded-xl bg-ivory p-3 text-xs text-slate-600">{t('lti.admin.safety')}</p>
            </fieldset>
            <Button variant="gold" loading={busy} onClick={() => void save()}>{t('common.save')}</Button>
          </div>
        )}
      </Modal>

      <Modal open={!!checks} onClose={() => setChecks(null)} title={t('lti.admin.testTitle', { name: checks?.name ?? '' })}>
        {checks && (
          <ul className="space-y-2">
            {checks.checks.map((k) => (
              <li key={k.key} className="flex items-start gap-2 rounded-xl bg-ivory p-3 text-sm">
                {k.ok ? <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-emerald-600" /> : <XCircle className="mt-0.5 size-5 shrink-0 text-danger" />}
                <span><b className="block text-navy-900">{t(`lti.admin.checks.${k.key}`)}</b>{k.detail && <span className="text-xs text-slate-500" dir="auto">{k.detail}</span>}</span>
              </li>))}
          </ul>
        )}
      </Modal>
    </div>
  )
}
