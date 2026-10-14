/* eslint-disable @typescript-eslint/no-explicit-any */
import clsx from 'clsx'
import { Check, CheckCircle2, Copy, Download, PlugZap, Unplug, XCircle } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLang } from '@/components/dashboard/Widget'
import { Badge, Button, Card, Field, Spinner, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { dialogs } from '@/lib/dialogs'
import { toast } from '@/lib/toast'
import { useStepUp } from '../SecurityAdmin'

type Overview = {
  ready: boolean; driver: string; probe: { ok: boolean; error: string | null }
  permissions: { permission: string; type: 'application' | 'delegated'; feature: string; ar: string; en: string; granted: boolean }[]
  townhalls: { enabled: boolean; invite_only: boolean; organizer: { connected: boolean; account: string | null; hint: string | null } }
  activity_feed: { enabled: boolean; active: boolean; app_id: string | null; version: string; types: number }
  redirect_uri: string; tab_url: string
}

function CopyLine({ value }: { value: string }) {
  const { t } = useTranslation()
  const [done, setDone] = useState(false)
  return (
    <div className="flex items-center gap-2 rounded-xl bg-ivory p-2.5">
      <code className="min-w-0 flex-1 break-all font-mono text-xs" dir="ltr">{value}</code>
      <button type="button" aria-label={t('m365.sso.saml.copy')} className="grid size-8 shrink-0 place-items-center rounded-lg text-navy-700 hover:bg-white" onClick={() => { void navigator.clipboard?.writeText(value); setDone(true); setTimeout(() => setDone(false), 1500) }}>
        {done ? <Check className="size-4 text-emerald-600" /> : <Copy className="size-4" />}
      </button>
    </div>
  )
}

function Switch({ checked, onChange, label, hint, disabled }: { checked: boolean; onChange: (on: boolean) => void; label: string; hint?: string; disabled?: boolean }) {
  return (
    <label className={clsx('flex items-start gap-3', disabled && 'opacity-60')}>
      <input type="checkbox" className="mt-1 size-4 accent-gold-600" checked={checked} disabled={disabled} onChange={(e) => onChange(e.target.checked)} />
      <span><span className="block text-sm font-bold text-navy-900">{label}</span>{hint && <span className="mt-0.5 block text-xs leading-relaxed text-slate-500">{hint}</span>}</span>
    </label>
  )
}

/** Settings → Integrations → Microsoft Teams: town halls and their organiser account, the activity feed and the app package, and the permissions the Ministry's administrator must consent to. */
export default function TeamsPanel({ item, onEdit, onChanged }: { item: any | undefined; onEdit: (item: any) => void; onChanged: () => void }) {
  const { t } = useTranslation()
  const L = useLang()
  const step = useStepUp()
  const res = useGet<{ data: Overview }>('/admin/teams/overview', undefined, { staleTime: 0 })
  const [busy, setBusy] = useState<string | null>(null)
  const o = res.data?.data
  if (!o) return res.error ? <p className="text-sm text-danger">{errorMessage(res.error)}</p> : <Spinner />
  const refresh = () => { void res.refetch(); onChanged() }
  const run = (key: string, fn: () => Promise<void>) => step.guard(async () => { setBusy(key); try { await fn() } finally { setBusy(null) } })
  const setting = (key: string, value: boolean) => run(key, async () => { await api.put('/admin/integrations/teams', { settings: { [key]: value } }); toast(t('m365.teams.townhalls.saved')); refresh() })
  const connect = () => run('connect', async () => {
    const w = window.open('about:blank', '_blank')
    try { const { data } = await api.post('/admin/teams/organizer'); if (w) w.location.href = data.data.url; else window.location.href = data.data.url } catch (e) { w?.close(); throw e }
  })
  const disconnect = async () => {
    if (!(await dialogs.confirm(t('m365.teams.townhalls.organizer.confirmDisconnect')))) return
    await run('disconnect', async () => { await api.delete('/admin/teams/organizer'); refresh() })
  }
  const download = () => run('package', async () => { await downloadFile('/admin/teams/app-package', 'tedc-teams-app.zip'); toast(t('m365.teams.feed.downloaded')); refresh() })

  const org = o.townhalls.organizer
  const fake = o.driver === 'fake'
  return (
    <div className="space-y-5">
      <Card>
        <div className="flex flex-wrap items-start justify-between gap-3">
          <div><h2 className="text-lg font-bold text-navy-900">{t('m365.teams.title')}</h2><p className="mt-1 text-sm text-slate-500">{t('m365.teams.subtitle')}</p></div>
          <div className="flex flex-wrap gap-2">
            {item && <Button variant="outline" onClick={() => onEdit(item)}>{t('m365.teams.connection.settings')}</Button>}
            <Button variant="outline" loading={res.isFetching} onClick={() => void res.refetch()}>{t('m365.teams.connection.recheck')}</Button>
          </div>
        </div>
        <div className={clsx('mt-4 flex items-start gap-3 rounded-2xl border p-4', fake ? 'border-gold-300 bg-gold-100/40' : o.probe.ok ? 'border-emerald-200 bg-emerald-50/70' : 'border-red-200 bg-red-50/70')}>
          {fake ? <PlugZap className="mt-0.5 size-5 shrink-0 text-gold-700" /> : o.probe.ok ? <CheckCircle2 className="mt-0.5 size-5 shrink-0 text-emerald-600" /> : <XCircle className="mt-0.5 size-5 shrink-0 text-red-600" />}
          <div className="text-sm font-semibold text-navy-900">
            {fake ? t('m365.teams.connection.training') : o.probe.ok ? t('m365.teams.connection.ok') : o.probe.error === 'not_configured' ? t('m365.teams.connection.notConfigured') : t('m365.teams.connection.failed', { error: o.probe.error })}
          </div>
        </div>
      </Card>

      <Card className="space-y-4">
        <div><h3 className="font-bold text-navy-900">{t('m365.teams.townhalls.title')}</h3><p className="mt-1 text-sm text-slate-500">{t('m365.teams.townhalls.hint')}</p></div>
        <Switch checked={o.townhalls.enabled} label={t('m365.teams.townhalls.on')} disabled={busy === 'townhalls'} onChange={(v) => void setting('townhalls', v)} />
        <Switch checked={o.townhalls.invite_only} label={t('m365.teams.townhalls.inviteOnly')} disabled={busy === 'townhall_invite_only' || !o.townhalls.enabled} onChange={(v) => void setting('townhall_invite_only', v)} />
        {!fake && (
          <div className="space-y-3 rounded-2xl border border-navy-100 p-4">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div className="text-sm font-bold text-navy-900">{t('m365.teams.townhalls.organizer.title')}</div>
              <Badge color={org.connected ? 'green' : 'amber'}>{org.connected ? t('m365.teams.townhalls.organizer.connected', { account: org.account ?? '' }) : t('m365.teams.townhalls.organizer.none')}</Badge>
            </div>
            <p className="text-xs leading-relaxed text-slate-500">{t('m365.teams.townhalls.organizer.why')}</p>
            <Field label={t('m365.teams.townhalls.organizer.redirect')}><CopyLine value={o.redirect_uri} /></Field>
            <div className="flex flex-wrap gap-2">
              <Button variant="gold" icon={<PlugZap className="size-4" />} loading={busy === 'connect'} onClick={() => void connect()}>{org.connected ? t('m365.teams.townhalls.organizer.reconnect') : t('m365.teams.townhalls.organizer.connect')}</Button>
              {org.connected && <Button variant="outline" icon={<Unplug className="size-4" />} loading={busy === 'disconnect'} onClick={() => void disconnect()}>{t('m365.teams.townhalls.organizer.disconnect')}</Button>}
            </div>
          </div>
        )}
      </Card>

      <Card className="space-y-4">
        <div className="flex flex-wrap items-start justify-between gap-2">
          <div><h3 className="font-bold text-navy-900">{t('m365.teams.feed.title')}</h3><p className="mt-1 text-sm text-slate-500">{t('m365.teams.feed.hint')}</p></div>
          <Badge color={o.activity_feed.active ? 'green' : 'gray'}>{o.activity_feed.active ? t('m365.teams.feed.active') : t('m365.teams.feed.inactive')}</Badge>
        </div>
        <Switch checked={o.activity_feed.enabled} label={t('m365.teams.feed.on')} disabled={busy === 'activity_feed' || (!o.activity_feed.enabled && !o.activity_feed.app_id)} onChange={(v) => void setting('activity_feed', v)} />
        {o.activity_feed.app_id && <Field label={t('m365.teams.feed.appId')} hint={t('m365.teams.feed.appIdHint')}><CopyLine value={o.activity_feed.app_id} /></Field>}
        <ol className="list-decimal space-y-1 ps-5 text-sm text-slate-600">{(t('m365.teams.feed.steps', { returnObjects: true }) as unknown as string[]).map((x, i) => <li key={i}>{x}</li>)}</ol>
        <div className="flex flex-wrap items-center gap-3">
          <Button variant="gold" icon={<Download className="size-4" />} loading={busy === 'package'} onClick={() => void download()}>{t('m365.teams.feed.package')}</Button>
          <span className="text-xs text-slate-400">{t('m365.teams.feed.version', { v: o.activity_feed.version, n: o.activity_feed.types })}</span>
        </div>
        <Field label={t('m365.teams.urls.tab')}><CopyLine value={o.tab_url} /></Field>
      </Card>

      <Card padded={false}>
        <div className="p-5 pb-2"><h3 className="font-bold text-navy-900">{t('m365.teams.permissions.title')}</h3><p className="mt-1 text-sm text-slate-500">{t('m365.teams.permissions.hint')}</p></div>
        <Table head={[t('m365.teams.permissions.permission'), t('m365.teams.permissions.kind'), t('m365.teams.permissions.for'), t('m365.teams.permissions.status')]}>
          {o.permissions.map((p, i) => (
            <tr key={i} className="border-b border-navy-50 last:border-0">
              <Td><div className="font-mono text-xs font-semibold text-navy-900" dir="ltr">{p.permission}</div><div className="mt-0.5 text-xs text-slate-500">{L({ ar: p.ar, en: p.en })}</div></Td>
              <Td><Badge color={p.type === 'application' ? 'navy' : 'gold'}>{t(`m365.teams.permissions.type.${p.type}`)}</Badge></Td>
              <Td className="text-sm">{t(`m365.teams.permissions.feature.${p.feature}`)}</Td>
              <Td>{p.granted ? <Badge color="green">✓ {t('m365.teams.permissions.granted')}</Badge> : <Badge color="gray">{t('m365.teams.permissions.missing')}</Badge>}</Td>
            </tr>
          ))}
        </Table>
        <p className="p-4 text-xs text-slate-400">{t('m365.teams.permissions.note')}</p>
      </Card>
      {step.dialog}
    </div>
  )
}
