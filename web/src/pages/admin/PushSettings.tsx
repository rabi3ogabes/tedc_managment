import clsx from 'clsx'
import {
  Award, BellRing, BookOpenCheck, CalendarClock, CheckCircle2, ClipboardList, ExternalLink, FileJson, KeyRound, Lightbulb, Loader2, Megaphone,
  Search, Send, ShieldCheck, Smartphone, Sparkles, Trash2, TrendingUp, UploadCloud, XCircle,
} from 'lucide-react'
import { useEffect, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field, PageHeader, Spinner } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { ColorField } from './brand/controls'

const PACKAGE = 'app.tedcmanagment.vercel'

type Client = { api_key: string | null; app_id: string | null; messaging_sender_id: string | null; project_id: string | null; storage_bucket: string | null }
type Settings = {
  enabled: boolean
  ready: boolean
  service_account: { project_id: string; client_email: string; private_key_id: string | null } | null
  client: Client
  categories: Record<string, boolean>
  android: { channel_name_ar: string; channel_name_en: string; color: string }
}
type Log = { id: string; type: string; title: string; recipients: number; devices: number; delivered: number; failed: number; pruned: number; error: string | null; created_at: string }
type Payload = { settings: Settings; categories: string[]; stats: { devices: number; users: number; by_platform: Record<string, number>; delivered_7d: number; failed_7d: number }; logs: Log[] }

const CATEGORY_ICONS: Record<string, typeof BellRing> = {
  registration: ClipboardList, session: CalendarClock, task: BookOpenCheck, certificate: Award, impact: TrendingUp, announcement: Megaphone, training_need: Lightbulb,
}

/** Settings → Notifications: Firebase Cloud Messaging for the mobile app, fully managed from the dashboard. */
export default function PushSettings() {
  const { t } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: Payload }>('/admin/settings/push')
  const [draft, setDraft] = useState<Partial<Settings> & { service_account_json?: string | null; remove_service_account?: boolean }>({})
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState<{ ok: boolean; text: string } | null>(null)

  if (isLoading || !data) return <Spinner />
  const saved = data.data.settings
  const s: Settings = { ...saved, ...draft, client: { ...saved.client, ...draft.client }, categories: { ...saved.categories, ...draft.categories }, android: { ...saved.android, ...draft.android } }
  const dirty = Object.keys(draft).length > 0
  const hasAccount = draft.remove_service_account ? !!draft.service_account_json : !!(saved.service_account || draft.service_account_json)
  const hasClient = !!(s.client.api_key && s.client.app_id && s.client.messaging_sender_id)

  const save = async () => {
    setSaving(true)
    setNotice(null)
    try {
      await api.put('/admin/settings/push', draft)
      setDraft({})
      await refetch()
      setNotice({ ok: true, text: t('admin.push.saved') })
    } catch (e) {
      setNotice({ ok: false, text: errorMessage(e) })
    } finally {
      setSaving(false)
    }
  }

  const steps = [
    { done: hasAccount, label: t('admin.push.steps.account') },
    { done: hasClient, label: t('admin.push.steps.app') },
    { done: s.enabled, label: t('admin.push.steps.enable') },
  ]

  return (
    <>
      <PageHeader
        title={<span className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-navy-900 text-gold-300"><BellRing className="size-5" /></span>{t('admin.push.title')}</span>}
        subtitle={t('admin.push.subtitle')}
        actions={<Button variant="gold" loading={saving} disabled={!dirty} icon={<CheckCircle2 className="size-4" />} onClick={save}>{t('admin.push.save')}</Button>}
      />

      {notice && (
        <div className={clsx('mb-5 flex items-center gap-2 rounded-2xl p-3 text-sm font-semibold', notice.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-danger')}>
          {notice.ok ? <CheckCircle2 className="size-4" /> : <XCircle className="size-4" />}{notice.text}
        </div>
      )}

      {/* Status hero */}
      <section className="relative mb-6 overflow-hidden rounded-[var(--radius-card,1.25rem)] bg-gradient-to-l from-navy-950 via-navy-900 to-navy-800 p-6 text-white shadow-glass">
        <div className="pattern-bg absolute inset-0 opacity-20" />
        <div className="relative grid gap-6 lg:grid-cols-[1.4fr_1fr]">
          <div>
            <div className="flex items-center gap-3">
              <Switch onDark checked={s.enabled} onChange={(v) => setDraft({ ...draft, enabled: v })} label={t('admin.push.enable')} />
              <div>
                <div className="text-lg font-bold">{t('admin.push.enable')}</div>
                <div className="text-sm text-white/70">{saved.ready ? t('admin.push.live') : t('admin.push.notLive')}</div>
              </div>
              <Badge color={saved.ready ? 'green' : 'amber'} className="ms-auto">{saved.ready ? t('admin.push.statusReady') : t('admin.push.statusSetup')}</Badge>
            </div>
            <ol className="mt-6 grid gap-3 sm:grid-cols-3">
              {steps.map((step, i) => (
                <li key={i} className={clsx('flex items-center gap-3 rounded-2xl border p-3 text-sm', step.done ? 'border-gold-300/40 bg-white/10' : 'border-white/10 bg-white/5 text-white/70')}>
                  <span className={clsx('grid size-8 shrink-0 place-items-center rounded-full font-bold', step.done ? 'bg-gold-500 text-navy-950' : 'bg-white/10')}>{step.done ? <CheckCircle2 className="size-4" /> : i + 1}</span>
                  {step.label}
                </li>
              ))}
            </ol>
          </div>
          <div className="grid grid-cols-2 gap-3">
            <Stat icon={<Smartphone className="size-4" />} label={t('admin.push.stats.devices')} value={fmt.number(data.data.stats.devices)} />
            <Stat icon={<ShieldCheck className="size-4" />} label={t('admin.push.stats.users')} value={fmt.number(data.data.stats.users)} />
            <Stat icon={<Send className="size-4" />} label={t('admin.push.stats.delivered')} value={fmt.number(data.data.stats.delivered_7d)} />
            <Stat icon={<XCircle className="size-4" />} label={t('admin.push.stats.failed')} value={fmt.number(data.data.stats.failed_7d)} />
          </div>
        </div>
      </section>

      <div className="grid gap-6 xl:grid-cols-3">
        <div className="space-y-6 xl:col-span-2">
          <ServiceAccountStep
            saved={saved.service_account}
            pending={draft.service_account_json ?? null}
            removed={!!draft.remove_service_account}
            onFile={(json) => setDraft({ ...draft, service_account_json: json, remove_service_account: false })}
            onRemove={() => setDraft({ ...draft, service_account_json: null, remove_service_account: true })}
            dirty={dirty}
          />
          <AndroidAppStep client={s.client} onChange={(client) => setDraft({ ...draft, client: { ...s.client, ...client } })} />

          <StepCard n={3} icon={<Sparkles className="size-5" />} title={t('admin.push.categories.title')} subtitle={t('admin.push.categories.subtitle')}>
            <div className="grid gap-3 sm:grid-cols-2">
              {data.data.categories.map((c) => {
                const Icon = CATEGORY_ICONS[c] ?? BellRing
                const on = !!s.categories[c]
                return (
                  <button
                    key={c}
                    type="button"
                    onClick={() => setDraft({ ...draft, categories: { ...s.categories, [c]: !on } })}
                    className={clsx('flex items-start gap-3 rounded-2xl border p-4 text-start transition', on ? 'border-gold-400 bg-gold-100/40 shadow-sm' : 'border-navy-100 bg-white hover:border-gold-300')}
                  >
                    <span className={clsx('grid size-10 shrink-0 place-items-center rounded-xl', on ? 'bg-navy-900 text-gold-300' : 'bg-navy-100/60 text-slate-400')}><Icon className="size-5" /></span>
                    <span className="min-w-0 flex-1">
                      <span className="block font-bold text-navy-900">{t(`admin.push.categories.items.${c}.title`)}</span>
                      <span className="mt-0.5 block text-xs leading-relaxed text-slate-500">{t(`admin.push.categories.items.${c}.hint`)}</span>
                    </span>
                    <Switch checked={on} onChange={() => undefined} label={t(`admin.push.categories.items.${c}.title`)} small decorative />
                  </button>
                )
              })}
            </div>
            <div className="mt-5 grid gap-3 sm:grid-cols-3">
              <Field label={t('admin.push.android.channelAr')}><input className="input" value={s.android.channel_name_ar} onChange={(e) => setDraft({ ...draft, android: { ...s.android, channel_name_ar: e.target.value } })} /></Field>
              <Field label={t('admin.push.android.channelEn')}><input className="input" dir="ltr" value={s.android.channel_name_en} onChange={(e) => setDraft({ ...draft, android: { ...s.android, channel_name_en: e.target.value } })} /></Field>
              <ColorField label={t('admin.push.android.color')} value={s.android.color} onChange={(color) => setDraft({ ...draft, android: { ...s.android, color } })} />
            </div>
          </StepCard>
        </div>

        <div className="space-y-6">
          <TestCard ready={saved.ready} dirty={dirty} onSent={refetch} />
          <Guide />
        </div>
      </div>

      <Card padded={false} className="mt-6">
        <div className="flex items-center gap-2 px-5 pt-5 text-lg font-bold text-navy-900"><Send className="size-5 text-gold-600" />{t('admin.push.log.title')}</div>
        <DataView<Log>
          id="admin.push.logs"
          rows={data.data.logs}
          rowKey={(r) => r.id}
          emptyText={t('admin.push.log.empty')}
          columns={[
            { key: 'title', header: t('admin.push.log.notification'), role: 'title', cell: (r) => <><div className="font-semibold text-navy-900">{r.title}</div><div className="font-mono text-[11px] text-slate-400" dir="ltr">{r.type}</div></> },
            { key: 'when', header: t('admin.push.log.when'), cell: (r) => <span className="text-xs text-slate-500">{fmt.dateTime(r.created_at)}</span> },
            { key: 'devices', header: t('admin.push.log.devices'), cell: (r) => <span className="font-semibold">{fmt.number(r.devices)}</span> },
            { key: 'delivered', header: t('admin.push.log.delivered'), cell: (r) => <Badge color="green">{fmt.number(r.delivered)}</Badge> },
            { key: 'failed', header: t('admin.push.log.failed'), cell: (r) => (r.failed ? <Badge color="red">{fmt.number(r.failed)}</Badge> : <span className="text-slate-300">0</span>) },
            { key: 'status', header: t('common.status'), role: 'badge', cell: (r) => <Badge color={r.failed === 0 ? 'green' : r.delivered > 0 ? 'amber' : 'red'}>{r.failed === 0 ? t('admin.push.log.ok') : r.delivered > 0 ? t('admin.push.log.partial') : t('admin.push.log.error')}</Badge> },
            { key: 'error', header: t('admin.push.log.details'), cell: (r) => <span className="line-clamp-2 text-xs text-slate-500" title={r.error ?? ''}>{r.error ?? (r.pruned ? t('admin.push.log.pruned', { count: r.pruned }) : '—')}</span> },
          ]}
        />
      </Card>
    </>
  )
}

function Stat({ icon, label, value }: { icon: ReactNode; label: string; value: ReactNode }) {
  return (
    <div className="rounded-2xl border border-white/10 bg-white/10 p-4 backdrop-blur">
      <div className="flex items-center gap-2 text-xs text-white/70">{icon}{label}</div>
      <div className="mt-1 text-2xl font-bold">{value}</div>
    </div>
  )
}

function Switch({ checked, onChange, label, small, decorative, onDark }: { checked: boolean; onChange: (v: boolean) => void; label: string; small?: boolean; decorative?: boolean; onDark?: boolean }) {
  const on = onDark ? 'bg-gold-400 ring-2 ring-gold-300/50' : 'bg-navy-900'
  const off = onDark ? 'bg-white/20' : 'bg-slate-300'
  // The knob sits at the inline end when on (left in Arabic, right in English).
  const cls = clsx('relative inline-flex shrink-0 items-center rounded-full p-0.5 transition-colors', small ? 'h-6 w-11' : 'h-8 w-14', checked ? clsx(on, 'justify-end') : clsx(off, 'justify-start'))
  const knob = <span className={clsx('inline-block rounded-full bg-white shadow-md transition-all', small ? 'size-5' : 'size-7')} />
  if (decorative) return <span aria-hidden className={cls}>{knob}</span>
  return <button type="button" role="switch" aria-checked={checked} aria-label={label} onClick={() => onChange(!checked)} className={cls}>{knob}</button>
}

function StepCard({ n, icon, title, subtitle, children, aside }: { n: number; icon: ReactNode; title: string; subtitle: string; children: ReactNode; aside?: ReactNode }) {
  return (
    <Card>
      <div className="mb-5 flex items-start gap-4">
        <div className="relative">
          <span className="grid size-12 place-items-center rounded-2xl bg-navy-900 text-gold-300">{icon}</span>
          <span className="absolute -end-1.5 -top-1.5 grid size-6 place-items-center rounded-full bg-gold-500 text-xs font-bold text-navy-950 ring-2 ring-white">{n}</span>
        </div>
        <div className="min-w-0 flex-1">
          <h2 className="text-lg font-bold text-navy-900">{title}</h2>
          <p className="text-sm text-slate-500">{subtitle}</p>
        </div>
        {aside}
      </div>
      {children}
    </Card>
  )
}

function JsonDrop({ label, hint, onJson }: { label: string; hint: string; onJson: (text: string, parsed: Record<string, unknown>) => void }) {
  const { t } = useTranslation()
  const input = useRef<HTMLInputElement>(null)
  const [error, setError] = useState<string | null>(null)
  const [over, setOver] = useState(false)
  const read = async (file: File) => {
    setError(null)
    try {
      const text = await file.text()
      onJson(text, JSON.parse(text))
    } catch {
      setError(t('admin.push.invalidJson'))
    }
  }
  return (
    <div>
      <button
        type="button"
        onClick={() => input.current?.click()}
        onDragOver={(e) => { e.preventDefault(); setOver(true) }}
        onDragLeave={() => setOver(false)}
        onDrop={(e) => { e.preventDefault(); setOver(false); const f = e.dataTransfer.files[0]; if (f) read(f) }}
        className={clsx('flex w-full flex-col items-center justify-center gap-2 rounded-2xl border-2 border-dashed p-6 text-center transition', over ? 'border-gold-500 bg-gold-100/40' : 'border-navy-100 bg-ivory/60 hover:border-gold-400')}
      >
        <UploadCloud className="size-7 text-gold-600" />
        <span className="font-bold text-navy-900">{label}</span>
        <span className="text-xs text-slate-500">{hint}</span>
      </button>
      <input ref={input} type="file" accept="application/json,.json" className="hidden" onChange={(e) => { const f = e.target.files?.[0]; if (f) read(f); e.target.value = '' }} />
      {error && <p className="mt-2 text-xs text-danger">{error}</p>}
    </div>
  )
}

function ServiceAccountStep({ saved, pending, removed, onFile, onRemove, dirty }: {
  saved: Settings['service_account']; pending: string | null; removed: boolean; onFile: (json: string) => void; onRemove: () => void; dirty: boolean
}) {
  const { t } = useTranslation()
  const [verify, setVerify] = useState<{ busy?: boolean; ok?: boolean; text?: string }>({})
  const [error, setError] = useState<string | null>(null)
  let preview: { project_id?: string; client_email?: string } | null = null
  if (pending) {
    try { preview = JSON.parse(pending) } catch { preview = null }
  }
  const shown = preview ?? (removed ? null : saved)

  const check = async () => {
    setVerify({ busy: true })
    try {
      const { data } = await api.post('/admin/settings/push/verify')
      setVerify({ ok: true, text: t('admin.push.account.verified', { project: data.data.project_id }) })
    } catch (e) {
      setVerify({ ok: false, text: errorMessage(e) })
    }
  }

  return (
    <StepCard n={1} icon={<KeyRound className="size-5" />} title={t('admin.push.account.title')} subtitle={t('admin.push.account.subtitle')}>
      {shown ? (
        <div className="flex flex-wrap items-center gap-4 rounded-2xl border border-navy-100 bg-ivory/60 p-4">
          <span className="grid size-11 place-items-center rounded-xl bg-emerald-50 text-emerald-600"><ShieldCheck className="size-5" /></span>
          <div className="min-w-0 flex-1" dir="ltr">
            <div className="truncate font-mono text-sm font-bold text-navy-900">{shown.project_id}</div>
            <div className="truncate font-mono text-xs text-slate-500">{shown.client_email}</div>
          </div>
          {preview && <Badge color="amber">{t('admin.push.unsaved')}</Badge>}
          <Button size="sm" variant="ghost" icon={<Trash2 className="size-4" />} onClick={onRemove}>{t('admin.brand.remove')}</Button>
        </div>
      ) : (
        <JsonDrop
          label={t('admin.push.account.drop')}
          hint={t('admin.push.account.dropHint')}
          onJson={(text, parsed) => {
            if (parsed.type !== 'service_account' || !parsed.private_key || !parsed.client_email) return setError(t('admin.push.account.wrongFile'))
            setError(null)
            onFile(text)
          }}
        />
      )}
      {error && <p className="mt-2 text-xs text-danger">{error}</p>}
      <div className="mt-4 flex flex-wrap items-center gap-3">
        <Button size="sm" variant="outline" icon={verify.busy ? <Loader2 className="size-4 animate-spin" /> : <ShieldCheck className="size-4" />} disabled={!saved || dirty || verify.busy} onClick={check}>{t('admin.push.account.verify')}</Button>
        {verify.text && <span className={clsx('text-sm font-semibold', verify.ok ? 'text-emerald-600' : 'text-danger')}>{verify.text}</span>}
        <span className="ms-auto flex items-center gap-1 text-xs text-slate-400"><ShieldCheck className="size-3.5" />{t('admin.push.account.secure')}</span>
      </div>
    </StepCard>
  )
}

function AndroidAppStep({ client, onChange }: { client: Client; onChange: (c: Partial<Client>) => void }) {
  const { t } = useTranslation()
  const [warning, setWarning] = useState<string | null>(null)

  const fromGoogleServices = (json: Record<string, unknown>) => {
    type GClient = { client_info?: { mobilesdk_app_id?: string; android_client_info?: { package_name?: string } }; api_key?: { current_key?: string }[] }
    const info = json.project_info as { project_number?: string; project_id?: string; storage_bucket?: string } | undefined
    const clients = (json.client as GClient[] | undefined) ?? []
    const match = clients.find((c) => c.client_info?.android_client_info?.package_name === PACKAGE)
    const chosen = match ?? clients[0]
    if (!info || !chosen) return setWarning(t('admin.push.app.wrongFile'))
    setWarning(match ? null : t('admin.push.app.packageMismatch', { package: PACKAGE }))
    onChange({
      api_key: chosen.api_key?.[0]?.current_key ?? null,
      app_id: chosen.client_info?.mobilesdk_app_id ?? null,
      messaging_sender_id: info.project_number ?? null,
      project_id: info.project_id ?? null,
      storage_bucket: info.storage_bucket ?? null,
    })
  }

  const fields: { key: keyof Client; label: string; placeholder: string }[] = [
    { key: 'app_id', label: t('admin.push.app.appId'), placeholder: '1:123456789012:android:abc123…' },
    { key: 'api_key', label: t('admin.push.app.apiKey'), placeholder: 'AIza…' },
    { key: 'messaging_sender_id', label: t('admin.push.app.senderId'), placeholder: '123456789012' },
    { key: 'project_id', label: t('admin.push.app.projectId'), placeholder: 'tedc-mobile' },
    { key: 'storage_bucket', label: t('admin.push.app.bucket'), placeholder: 'tedc-mobile.appspot.com' },
  ]

  return (
    <StepCard n={2} icon={<Smartphone className="size-5" />} title={t('admin.push.app.title')} subtitle={t('admin.push.app.subtitle', { package: PACKAGE })}>
      <JsonDrop label={t('admin.push.app.drop')} hint={t('admin.push.app.dropHint')} onJson={(_, parsed) => fromGoogleServices(parsed)} />
      {warning && <p className="mt-2 rounded-xl bg-amber-50 p-2 text-xs font-semibold text-amber-700">{warning}</p>}
      <div className="mt-4 grid gap-3 sm:grid-cols-2">
        {fields.map((f) => (
          <Field key={f.key} label={f.label}>
            <div className="relative">
              <FileJson className="absolute start-3 top-3 size-4 text-slate-300" />
              <input className="input ps-9 font-mono text-xs" dir="ltr" placeholder={f.placeholder} value={client[f.key] ?? ''} onChange={(e) => onChange({ [f.key]: e.target.value.trim() || null })} />
            </div>
          </Field>
        ))}
      </div>
    </StepCard>
  )
}

type Person = { id: string; name: string; email: string; roles: string[]; devices: number }
type Group = { id: string; name: string; users: number; devices: number }
type Recipients = { people: Person[]; groups: { role: Group[]; school: Group[]; program: Group[] } }
type Audience = 'me' | 'user' | 'group' | 'all'
type GroupType = keyof Recipients['groups']

/** Sends a test push to yourself, one person, a group (role, school, program participants) or every device. */
function TestCard({ ready, dirty, onSent }: { ready: boolean; dirty: boolean; onSent: () => void }) {
  const { t } = useTranslation()
  const [audience, setAudience] = useState<Audience>('me')
  const [title, setTitle] = useState('')
  const [body, setBody] = useState('')
  const [query, setQuery] = useState('')
  const [person, setPerson] = useState<Person | null>(null)
  const [groupType, setGroupType] = useState<GroupType>('role')
  const [groupId, setGroupId] = useState('')
  const [state, setState] = useState<{ busy?: boolean; ok?: boolean; text?: string }>({})
  const search = useDebounced(query.trim(), 300)
  const { data } = useGet<{ data: Recipients }>(ready && audience !== 'me' && audience !== 'all' ? '/admin/settings/push/recipients' : null, { q: audience === 'user' ? search : '' })
  const groups = data?.data.groups[groupType] ?? []
  const group = groups.find((g) => g.id === groupId)

  const can = ready && !dirty && (audience === 'me' || audience === 'all' || (audience === 'user' && !!person) || (audience === 'group' && !!group))

  const send = async () => {
    setState({ busy: true })
    try {
      const { data: res } = await api.post('/admin/settings/push/test', {
        audience, title_ar: title || undefined, title_en: title || undefined, body_ar: body || undefined, body_en: body || undefined,
        ...(audience === 'user' && person ? { user_id: person.id } : {}),
        ...(audience === 'group' ? { group_type: groupType, group_id: groupId } : {}),
      })
      const log = res.data as Log & { targeted?: number; with_devices?: number }
      const people = audience === 'user' || audience === 'group' ? ` ${t('admin.push.test.people', { with: log.with_devices, total: log.targeted })}` : ''
      setState({ ok: log.failed === 0, text: t('admin.push.test.result', { delivered: log.delivered, devices: log.devices }) + people })
      onSent()
    } catch (e) {
      setState({ ok: false, text: errorMessage(e) })
    }
  }

  const tabs: Audience[] = ['me', 'user', 'group', 'all']
  return (
    <Card>
      <div className="mb-4 flex items-center gap-3">
        <span className="grid size-11 place-items-center rounded-2xl bg-gold-100 text-gold-700"><Send className="size-5" /></span>
        <div>
          <h2 className="font-bold text-navy-900">{t('admin.push.test.title')}</h2>
          <p className="text-xs text-slate-500">{t('admin.push.test.subtitle')}</p>
        </div>
      </div>
      <div role="tablist" className="mb-3 grid grid-cols-4 rounded-xl border border-navy-100 p-1">
        {tabs.map((a) => (
          <button key={a} type="button" role="tab" aria-selected={audience === a} onClick={() => { setAudience(a); setState({}) }} className={clsx('rounded-lg px-1 py-1.5 text-xs font-bold transition', audience === a ? 'bg-navy-900 text-white' : 'text-slate-500')}>{t(`admin.push.test.${a}`)}</button>
        ))}
      </div>

      {audience === 'user' && (
        <div className="mb-3">
          {person ? (
            <div className="flex items-center gap-3 rounded-xl border border-gold-300 bg-gold-100/40 p-3">
              <span className="grid size-9 place-items-center rounded-full bg-navy-900 text-sm font-bold text-gold-300">{person.name.slice(0, 1)}</span>
              <div className="min-w-0 flex-1"><div className="truncate text-sm font-bold text-navy-900">{person.name}</div><div className="truncate text-xs text-slate-500" dir="ltr">{person.email}</div></div>
              <DeviceBadge count={person.devices} />
              <button type="button" onClick={() => setPerson(null)} aria-label={t('admin.brand.remove')} className="text-slate-400 hover:text-danger"><XCircle className="size-4" /></button>
            </div>
          ) : (
            <>
              <div className="relative"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input !ps-9" placeholder={t('admin.push.test.searchPlaceholder')} value={query} onChange={(e) => setQuery(e.target.value)} /></div>
              {search.length >= 2 && (
                <ul className="mt-2 max-h-56 overflow-auto rounded-xl border border-navy-100 bg-white">
                  {(data?.data.people ?? []).length === 0 && <li className="p-3 text-center text-xs text-slate-400">{t('admin.push.test.noPeople')}</li>}
                  {(data?.data.people ?? []).map((p) => (
                    <li key={p.id}>
                      <button type="button" onClick={() => setPerson(p)} className="flex w-full items-center gap-3 px-3 py-2 text-start hover:bg-ivory">
                        <span className="min-w-0 flex-1"><span className="block truncate text-sm font-semibold text-navy-900">{p.name}</span><span className="block truncate text-xs text-slate-500">{p.roles.join('، ')} · <span dir="ltr">{p.email}</span></span></span>
                        <DeviceBadge count={p.devices} />
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </>
          )}
        </div>
      )}

      {audience === 'group' && (
        <div className="mb-3 space-y-2">
          <div className="grid grid-cols-3 gap-1 rounded-xl bg-navy-100/50 p-1">
            {(['role', 'school', 'program'] as const).map((g) => (
              <button key={g} type="button" aria-pressed={groupType === g} onClick={() => { setGroupType(g); setGroupId('') }} className={clsx('rounded-lg px-2 py-1 text-xs font-semibold', groupType === g ? 'bg-white text-navy-900 shadow-sm' : 'text-slate-500')}>{t(`admin.push.test.groups.${g}`)}</button>
            ))}
          </div>
          <select className="input" value={groupId} onChange={(e) => setGroupId(e.target.value)} aria-label={t(`admin.push.test.groups.${groupType}`)}>
            <option value="">{t('admin.push.test.pickGroup')}</option>
            {groups.map((g) => <option key={g.id} value={g.id}>{g.name} — {t('admin.push.test.groupCounts', { users: g.users, devices: g.devices })}</option>)}
          </select>
          {group && group.devices === 0 && <p className="text-xs text-amber-600">{t('admin.push.test.noDevicesInGroup')}</p>}
        </div>
      )}

      <Field label={t('admin.push.test.titleLabel')}><input className="input" placeholder={t('admin.push.test.titlePlaceholder')} value={title} onChange={(e) => setTitle(e.target.value)} /></Field>
      <Field label={t('admin.push.test.bodyLabel')} className="mt-3"><textarea className="input min-h-20" placeholder={t('admin.push.test.bodyPlaceholder')} value={body} onChange={(e) => setBody(e.target.value)} /></Field>
      <Button className="mt-4 w-full" variant="primary" loading={state.busy} disabled={!can} icon={<Send className="size-4" />} onClick={send}>{t('admin.push.test.send')}</Button>
      {!ready && <p className="mt-2 text-xs text-slate-400">{t('admin.push.test.notReady')}</p>}
      {dirty && ready && <p className="mt-2 text-xs text-amber-600">{t('admin.push.test.saveFirst')}</p>}
      {state.text && <p className={clsx('mt-3 rounded-xl p-2 text-sm font-semibold', state.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-danger')}>{state.text}</p>}
    </Card>
  )
}

function DeviceBadge({ count }: { count: number }) {
  const { t } = useTranslation()
  return <Badge color={count > 0 ? 'green' : 'gray'}>{count > 0 ? t('admin.push.test.devicesN', { count }) : t('admin.push.test.noApp')}</Badge>
}

function useDebounced<T>(value: T, ms: number): T {
  const [v, setV] = useState(value)
  useEffect(() => { const id = setTimeout(() => setV(value), ms); return () => clearTimeout(id) }, [value, ms])
  return v
}

function Guide() {
  const { t } = useTranslation()
  const steps = t('admin.push.guide.steps', { returnObjects: true, package: PACKAGE }) as string[]
  return (
    <Card className="bg-gradient-to-b from-white to-ivory">
      <h2 className="flex items-center gap-2 font-bold text-navy-900"><Lightbulb className="size-5 text-gold-600" />{t('admin.push.guide.title')}</h2>
      <ol className="mt-4 space-y-3">
        {steps.map((step, i) => (
          <li key={i} className="flex gap-3 text-sm leading-relaxed text-slate-600">
            <span className="grid size-6 shrink-0 place-items-center rounded-full bg-navy-900 text-xs font-bold text-gold-300">{i + 1}</span>
            <span>{step}</span>
          </li>
        ))}
      </ol>
      <a href="https://console.firebase.google.com/" target="_blank" rel="noreferrer" className="mt-4 inline-flex items-center gap-1.5 text-sm font-bold text-link hover:underline">
        {t('admin.push.guide.console')}<ExternalLink className="size-3.5" />
      </a>
    </Card>
  )
}
