import clsx from 'clsx'
import { Check, Mail, MessageSquareText, Save, Send, Smartphone, TriangleAlert } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button, Card, Field, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { Switch } from './notifications/shared'
import type { ChannelStatus } from './notifications/ChannelPicker'

type Settings = {
  defaults: Record<'push' | 'email' | 'sms', boolean>
  email: Record<string, string | number | boolean | null> & { secrets_set: Record<string, boolean> }
  sms: Record<string, string | number | boolean | null> & { secrets_set: Record<string, boolean> }
  ready: { email: boolean; sms: boolean }
}
type Delivery = { id: string; channel: 'email' | 'sms'; type: string | null; status: 'queued' | 'sent' | 'failed' | 'skipped'; reason: string | null; to: string | null; at: string }
type Payload = { settings: Settings; status: ChannelStatus; stats: Record<'email' | 'sms', Record<'sent' | 'failed' | 'skipped' | 'queued', number>>; recent: Delivery[] }
type Draft = Record<string, string | number | boolean | null | Record<string, boolean>>

const STATE = { on: 'bg-emerald-50 text-emerald-700', setup: 'bg-amber-50 text-amber-700', off: 'bg-slate-100 text-slate-500' }

/** Settings → Notification channels: push is the core; e-mail and SMS are prepared here and switched on when the provider details are added. */
export default function ChannelsSettings() {
  const { t } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: Payload }>('/admin/notification-channels')
  const [draft, setDraft] = useState<{ email: Draft; sms: Draft; defaults: Settings['defaults'] } | null>(null)
  const [busy, setBusy] = useState(false)
  const [note, setNote] = useState<{ ok: boolean; text: string } | null>(null)
  const [testTo, setTestTo] = useState({ email: '', sms: '' })
  const [testing, setTesting] = useState<'email' | 'sms' | null>(null)

  useEffect(() => {
    if (data && !draft) setDraft({ email: { ...data.data.settings.email }, sms: { ...data.data.settings.sms }, defaults: { ...data.data.settings.defaults } })
  }, [data, draft])
  if (isLoading || !data || !draft) return <Spinner />
  const d = data.data

  const set = (channel: 'email' | 'sms', key: string, value: string | number | boolean | null) => setDraft((x) => x && { ...x, [channel]: { ...x[channel], [key]: value } })
  const save = async () => {
    setBusy(true); setNote(null)
    try {
      const strip = (c: Draft) => Object.fromEntries(Object.entries(c).filter(([k]) => k !== 'secrets_set'))
      await api.put('/admin/notification-channels', { defaults: draft.defaults, email: strip(draft.email), sms: strip(draft.sms) })
      setDraft(null); await refetch(); setNote({ ok: true, text: t('channels.saved') })
    } catch (e) { setNote({ ok: false, text: errorMessage(e) }) } finally { setBusy(false) }
  }
  const test = async (channel: 'email' | 'sms') => {
    setTesting(channel); setNote(null)
    try {
      const { data: r } = await api.post('/admin/notification-channels/test', { channel, to: testTo[channel] })
      setNote(r.data.ok ? { ok: true, text: t('channels.testOk') } : { ok: false, text: `${t('channels.testFail')}: ${r.data.error === 'not_configured' ? t('channels.reasons.not_configured') : r.data.error}` })
    } catch (e) { setNote({ ok: false, text: errorMessage(e) }) } finally { setTesting(null) }
  }
  const state = (c: 'push' | 'email' | 'sms'): keyof typeof STATE => (!d.status[c].on ? 'off' : d.status[c].ready || c === 'push' ? 'on' : 'setup')
  const text = (channel: 'email' | 'sms', key: string, label: string, opts: { type?: string; secret?: boolean; hint?: string; dir?: 'ltr' } = {}) => {
    const secretSet = opts.secret && (draft[channel].secrets_set as Record<string, boolean>)?.[key]
    return (
      <Field label={label} hint={opts.hint}>
        <input className="input" type={opts.type ?? 'text'} dir={opts.dir} autoComplete="off" value={String(draft[channel][key] ?? '')} placeholder={secretSet ? t('channels.secretSet') : undefined}
          onChange={(e) => set(channel, key, e.target.value)} />
      </Field>
    )
  }

  const card = (channel: 'email' | 'sms') => {
    const Icon = channel === 'email' ? Mail : MessageSquareText
    const c = draft[channel]
    const driver = String(c.driver)
    const stats = d.stats[channel]
    return (
      <Card className="space-y-5">
        <div className="flex flex-wrap items-center gap-3">
          <span className="grid size-11 place-items-center rounded-xl bg-navy-900 text-gold-300"><Icon className="size-5" /></span>
          <div className="min-w-0 flex-1"><h2 className="text-lg font-bold text-navy-900">{t(`channels.${channel}`)}</h2><span className={clsx('mt-0.5 inline-block rounded-full px-2.5 py-0.5 text-xs font-bold', STATE[d.settings.ready[channel] ? 'on' : 'setup'])}>{d.settings.ready[channel] ? t('channels.on') : t('channels.setup')}</span></div>
          <label className="flex items-center gap-2 text-xs font-semibold text-slate-500">{t('channels.enabled')}<Switch checked={Boolean(c.enabled)} label={t('channels.enabled')} onChange={(v) => set(channel, 'enabled', v)} /></label>
        </div>

        <Field label={t('channels.provider')}>
          <select className="input" value={driver} onChange={(e) => set(channel, 'driver', e.target.value)}>
            {(channel === 'email' ? ['none', 'smtp', 'log'] : ['none', 'twilio', 'unifonic', 'http', 'log']).map((x) => <option key={x} value={x}>{t(`channels.driver.${x}`)}</option>)}
          </select>
        </Field>

        {channel === 'email' && driver !== 'none' && (
          <div className="grid gap-4 sm:grid-cols-2">
            {text('email', 'from_name', t('channels.fromName'))}{text('email', 'from_address', t('channels.fromAddress'), { type: 'email', dir: 'ltr' })}
            {text('email', 'reply_to', t('channels.replyTo'), { type: 'email', dir: 'ltr' })}
            {driver === 'smtp' && <>
              {text('email', 'smtp_host', t('channels.host'), { dir: 'ltr' })}{text('email', 'smtp_port', t('channels.port'), { type: 'number', dir: 'ltr' })}
              <Field label={t('channels.encryption')}><select className="input" value={String(c.smtp_encryption ?? 'tls')} onChange={(e) => set('email', 'smtp_encryption', e.target.value)}><option value="tls">TLS (STARTTLS)</option><option value="ssl">SSL</option><option value="none">—</option></select></Field>
              {text('email', 'smtp_username', t('channels.username'), { dir: 'ltr' })}{text('email', 'smtp_password', t('channels.password'), { type: 'password', secret: true, dir: 'ltr' })}
            </>}
          </div>
        )}
        {channel === 'sms' && driver !== 'none' && (
          <div className="grid gap-4 sm:grid-cols-2">
            {text('sms', 'sender', t('channels.sender'), { dir: 'ltr' })}{text('sms', 'default_country_code', t('channels.countryCode'), { hint: t('channels.countryHint'), dir: 'ltr' })}
            {driver === 'twilio' && <>{text('sms', 'twilio_sid', t('channels.twilioSid'), { dir: 'ltr' })}{text('sms', 'twilio_token', t('channels.twilioToken'), { type: 'password', secret: true, dir: 'ltr' })}{text('sms', 'twilio_from', t('channels.twilioFrom'), { dir: 'ltr' })}</>}
            {driver === 'unifonic' && text('sms', 'unifonic_app_sid', t('channels.unifonicSid'), { type: 'password', secret: true, dir: 'ltr' })}
            {driver === 'http' && <>
              <div className="sm:col-span-2">{text('sms', 'http_url', t('channels.httpUrl'), { dir: 'ltr' })}</div>
              <Field label={t('channels.httpMethod')}><select className="input" value={String(c.http_method)} onChange={(e) => set('sms', 'http_method', e.target.value)}><option>POST</option><option>GET</option></select></Field>
              <Field label={t('channels.httpFormat')}><select className="input" value={String(c.http_format)} onChange={(e) => set('sms', 'http_format', e.target.value)}><option value="json">JSON</option><option value="form">Form</option></select></Field>
              <Field label={t('channels.httpBody')} hint={t('channels.httpBodyHint')} className="sm:col-span-2"><textarea className="input min-h-20 font-mono text-xs" dir="ltr" value={String(c.http_body ?? '')} onChange={(e) => set('sms', 'http_body', e.target.value)} /></Field>
              <Field label={t('channels.httpHeaders')} className="sm:col-span-2"><textarea className="input min-h-16 font-mono text-xs" dir="ltr" value={String(c.http_headers ?? '')} onChange={(e) => set('sms', 'http_headers', e.target.value)} /></Field>
              {text('sms', 'http_auth', t('channels.httpAuth'), { type: 'password', secret: true, dir: 'ltr' })}
            </>}
          </div>
        )}
        {channel === 'sms' && <p className="text-xs text-slate-400">{t('channels.smsHint')}</p>}

        {driver !== 'none' && (
          <div className="flex flex-wrap items-end gap-3 rounded-2xl bg-ivory p-3">
            <Field label={t(channel === 'email' ? 'channels.testToEmail' : 'channels.testToSms')} className="min-w-52 flex-1"><input className="input" dir="ltr" type={channel === 'email' ? 'email' : 'tel'} value={testTo[channel]} onChange={(e) => setTestTo((x) => ({ ...x, [channel]: e.target.value }))} /></Field>
            <Button variant="outline" icon={<Send className="size-4" />} loading={testing === channel} disabled={!testTo[channel] || !d.settings.ready[channel]} onClick={() => void test(channel)}>{t('channels.test')}</Button>
          </div>
        )}

        <div>
          <div className="mb-2 text-xs font-bold text-slate-500">{t('channels.lastWeek')}</div>
          <div className="grid grid-cols-4 gap-2">
            {(['sent', 'failed', 'skipped', 'queued'] as const).map((k) => <div key={k} className="rounded-xl border border-navy-100 p-2.5 text-center"><div className="text-xl font-extrabold tabular-nums text-navy-900">{fmt.number(stats[k])}</div><div className="text-[11px] text-slate-500">{t(`channels.${k}`)}</div></div>)}
          </div>
        </div>
      </Card>
    )
  }

  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('channels.title')} subtitle={t('channels.subtitle')} actions={<Button variant="gold" icon={<Save className="size-4" />} loading={busy} onClick={save}>{t('channels.save')}</Button>} />
      {note && <div role="status" className={clsx('flex items-center gap-2 rounded-2xl p-3 text-sm font-semibold', note.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-danger')}>{note.ok ? <Check className="size-4" /> : <TriangleAlert className="size-4" />}{note.text}</div>}

      {/* Push is the core; the defaults decide which channels every notification uses */}
      <Card>
        <h2 className="font-bold text-navy-900">{t('channels.defaultsTitle')}</h2>
        <p className="mt-1 text-sm text-slate-500">{t('channels.defaultsHint')}</p>
        <div className="mt-4 grid gap-3 sm:grid-cols-3">
          {([['push', Smartphone], ['email', Mail], ['sms', MessageSquareText]] as const).map(([c, Icon]) => (
            <div key={c} className="flex items-center gap-3 rounded-2xl border border-navy-100 p-3.5">
              <span className="grid size-10 place-items-center rounded-xl bg-navy-900 text-gold-300"><Icon className="size-5" /></span>
              <div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{t(`channels.${c}`)}</div><span className={clsx('mt-0.5 inline-block rounded-full px-2 py-0.5 text-[11px] font-bold', STATE[state(c)])}>{t(`channels.${state(c)}`)}</span></div>
              <Switch checked={draft.defaults[c]} label={t(`channels.${c}`)} onChange={(v) => setDraft((x) => x && { ...x, defaults: { ...x.defaults, [c]: v } })} />
            </div>
          ))}
        </div>
        <Link to="/admin/settings?tab=notifications" className="mt-3 inline-block text-sm font-bold text-gold-700 hover:underline">{t('channels.managePush')}</Link>
      </Card>

      <div className="grid gap-6 xl:grid-cols-2">{card('email')}{card('sms')}</div>

      <Card padded={false}>
        <div className="p-5 pb-3 font-bold text-navy-900">{t('channels.recent')}</div>
        {d.recent.length === 0 ? <p className="p-6 pt-0 text-center text-sm text-slate-400">{t('channels.none')}</p> : (
          <ul className="divide-y divide-navy-100">
            {d.recent.map((r) => (
              <li key={r.id} className="flex flex-wrap items-center gap-3 px-5 py-2.5 text-sm">
                {r.channel === 'email' ? <Mail className="size-4 text-slate-400" /> : <MessageSquareText className="size-4 text-slate-400" />}
                <code className="font-mono text-[11px] text-slate-500" dir="ltr">{r.type}</code>
                <span className="text-slate-500" dir="ltr">{r.to ?? '—'}</span>
                <span className={clsx('ms-auto rounded-full px-2.5 py-0.5 text-xs font-bold', r.status === 'sent' ? 'bg-emerald-50 text-emerald-700' : r.status === 'failed' ? 'bg-red-50 text-danger' : 'bg-slate-100 text-slate-500')}>{t(`channels.${r.status}`)}{r.reason ? ` · ${t(`channels.reasons.${r.reason}`, { defaultValue: r.reason })}` : ''}</span>
                <span className="text-xs text-slate-400">{fmt.dateTime(r.at)}</span>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </div>
  )
}
