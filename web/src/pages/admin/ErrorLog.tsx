import clsx from 'clsx'
import { AlertOctagon, BadgeCheck, Bug, CheckCircle2, Copy, EyeOff, Globe, RotateCcw, Search, Server, Settings2, Smartphone, Sparkles, Trash2, Users, Wand2, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'

type Entry = {
  id: string; source: 'server' | 'web' | 'app'; level: 'warning' | 'error' | 'critical'; message: string; exception: string | null; location: string | null; status_code: number | null; url: string | null; method: string | null
  occurrences: number; users_count: number; user_email: string | null; app_version: string | null; status: 'open' | 'fixed' | 'ignored'; auto_fixed: boolean; note: string | null
  first_seen_at: string; last_seen_at: string; resolved_at: string | null; fixable: boolean; fix_label: string | null; fix_attempts: number
  stack?: string | null; device?: Record<string, unknown> | null; context?: Record<string, unknown> | null; user_agent?: string | null; ip?: string | null
}
type List = { data: Entry[]; total: number; last_page: number; current_page: number; stats: { open: number; critical: number; today: number; auto_fixed: number; fixed: number; total: number; by_source: Record<string, number>; trend: { date: string; count: number }[] } }
type Settings = { enabled: boolean; auto_fix: boolean; capture_clients: boolean; retention_days: number }

const SOURCE_ICON = { server: Server, web: Globe, app: Smartphone } as const
const LEVEL_TONE = { critical: 'bg-red-500', error: 'bg-amber-500', warning: 'bg-sky-400' } as const

const ago = (iso: string, lang: string) => {
  const s = Math.max(0, (Date.now() - new Date(iso).getTime()) / 1000)
  const rtf = new Intl.RelativeTimeFormat(lang, { numeric: 'auto' })
  return s < 3600 ? rtf.format(-Math.round(s / 60), 'minute') : s < 86400 ? rtf.format(-Math.round(s / 3600), 'hour') : rtf.format(-Math.round(s / 86400), 'day')
}

function Toggle({ on, onChange, label, hint }: { on: boolean; onChange: (v: boolean) => void; label: string; hint: string }) {
  return (
    <button type="button" role="switch" aria-checked={on} onClick={() => onChange(!on)} className="flex w-full items-start gap-3 text-start">
      <span className={clsx('mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition', on ? 'bg-emerald-500' : 'bg-slate-300')}><span className={clsx('size-4 rounded-full bg-white shadow transition', on && 'ltr:translate-x-4 rtl:-translate-x-4')} /></span>
      <span><span className="block text-sm font-semibold text-navy-900">{label}</span><span className="block text-xs text-slate-500">{hint}</span></span>
    </button>
  )
}

function SettingsModal({ onClose }: { onClose: () => void }) {
  const { t } = useTranslation()
  const { data, refetch } = useGet<{ data: Settings }>('/admin/error-logs/settings', undefined, { staleTime: 0 })
  const [draft, setDraft] = useState<Settings | null>(null)
  const [busy, setBusy] = useState(false)
  useEffect(() => { if (data && !draft) setDraft(data.data) }, [data, draft])
  if (!draft) return null
  const save = async () => { setBusy(true); try { await api.put('/admin/error-logs/settings', draft); await refetch(); onClose() } finally { setBusy(false) } }
  return (
    <Modal open onClose={onClose} title={t('logs.settings.title')}>
      <div className="space-y-4">
        <Toggle on={draft.enabled} onChange={(v) => setDraft({ ...draft, enabled: v })} label={t('logs.settings.enabled')} hint={t('logs.settings.enabledHint')} />
        <Toggle on={draft.auto_fix} onChange={(v) => setDraft({ ...draft, auto_fix: v })} label={t('logs.settings.autoFix')} hint={t('logs.settings.autoFixHint')} />
        <Toggle on={draft.capture_clients} onChange={(v) => setDraft({ ...draft, capture_clients: v })} label={t('logs.settings.clients')} hint={t('logs.settings.clientsHint')} />
        <Field label={t('logs.settings.retention')} hint={t('logs.settings.retentionHint')}><input type="number" min={7} max={365} dir="ltr" className="input" value={draft.retention_days} onChange={(e) => setDraft({ ...draft, retention_days: Number(e.target.value) })} /></Field>
        <div className="flex justify-end gap-2"><Button variant="ghost" onClick={onClose}>{t('common.cancel')}</Button><Button variant="gold" loading={busy} onClick={save}>{t('common.save')}</Button></div>
      </div>
    </Modal>
  )
}

function Detail({ id, onClose, onChanged }: { id: string; onClose: () => void; onChanged: () => void }) {
  const { t, i18n } = useTranslation()
  const { data, refetch } = useGet<{ data: Entry }>(`/admin/error-logs/${id}`, undefined, { staleTime: 0 })
  const [note, setNote] = useState<string | null>(null)
  const [busy, setBusy] = useState<string | null>(null)
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null)
  const e = data?.data
  if (!e) return <Modal open onClose={onClose} title="…" wide><Spinner /></Modal>

  const act = async (key: string, fn: () => Promise<void>) => { setBusy(key); setMessage(null); try { await fn(); await refetch(); onChanged() } catch (x) { setMessage({ ok: false, text: errorMessage(x) }) } finally { setBusy(null) } }
  const set = (status: Entry['status']) => act(status, async () => { await api.put(`/admin/error-logs/${id}`, { status }) })
  const SourceIcon = SOURCE_ICON[e.source]

  return (
    <Modal open onClose={onClose} wide title={<span className="flex items-center gap-2"><span className={clsx('size-2.5 rounded-full', LEVEL_TONE[e.level])} />{t(`logs.level.${e.level}`)}<Badge color="navy"><span className="inline-flex items-center gap-1"><SourceIcon className="size-3" />{t(`logs.source.${e.source}`)}</span></Badge></span>}>
      <div className="space-y-5">
        <p className="rounded-xl bg-red-50 p-3 text-sm font-semibold leading-relaxed text-red-900" dir="auto">{e.message}</p>
        {message && <div className={clsx('rounded-xl p-3 text-sm', message.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-danger')}>{message.text}</div>}

        <div className="flex flex-wrap items-center gap-2">
          {e.status === 'open' && e.fixable && <Button variant="gold" size="sm" loading={busy === 'fix'} icon={<Wand2 className="size-4" />} onClick={() => act('fix', async () => { const r = await api.post<{ result: { fixed: boolean; note: string } }>(`/admin/error-logs/${id}/fix`); setMessage({ ok: r.data.result.fixed, text: r.data.result.note }) })}>{t('logs.tryFix')}{e.fix_label && <span className="opacity-70"> · {e.fix_label}</span>}</Button>}
          {e.status !== 'fixed' && <Button variant="primary" size="sm" loading={busy === 'fixed'} icon={<CheckCircle2 className="size-4" />} onClick={() => set('fixed')}>{t('logs.markFixed')}</Button>}
          {e.status !== 'ignored' && <Button variant="outline" size="sm" loading={busy === 'ignored'} icon={<EyeOff className="size-4" />} onClick={() => set('ignored')}>{t('logs.ignore')}</Button>}
          {e.status !== 'open' && <Button variant="outline" size="sm" loading={busy === 'open'} icon={<RotateCcw className="size-4" />} onClick={() => set('open')}>{t('logs.reopen')}</Button>}
          <Button variant="ghost" size="sm" className="ms-auto" icon={<Trash2 className="size-4 text-danger" />} onClick={() => window.confirm(t('logs.confirmDelete')) && act('del', async () => { await api.delete(`/admin/error-logs/${id}`); onChanged(); onClose() })}>{t('common.delete')}</Button>
        </div>
        {e.auto_fixed && <p className="flex items-center gap-2 text-sm text-emerald-700"><Sparkles className="size-4" />{t('logs.autoFixed')}</p>}

        <dl className="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
          {([
            [t('logs.occurrences'), `${fmt.number(e.occurrences)} · ${t('logs.usersAffected', { count: e.users_count })}`], [t('logs.lastSeen'), `${fmt.dateTime(e.last_seen_at)} (${ago(e.last_seen_at, i18n.language)})`],
            [t('logs.firstSeen'), fmt.dateTime(e.first_seen_at)], [t('logs.location'), e.location], [t('logs.request'), [e.method, e.url].filter(Boolean).join(' ')], [t('logs.statusCode'), e.status_code],
            [t('logs.user'), e.user_email], [t('logs.version'), e.app_version], [t('logs.exception'), e.exception], [t('logs.ip'), e.ip],
          ] as [string, string | number | null | undefined][]).filter(([, v]) => v).map(([k, v]) => <div key={k} className="min-w-0"><dt className="text-xs text-slate-500">{k}</dt><dd className="break-words font-medium text-navy-900" dir="auto">{v}</dd></div>)}
        </dl>

        {e.device && Object.keys(e.device).length > 0 && <div><div className="label">{t('logs.device')}</div><div className="flex flex-wrap gap-1.5">{Object.entries(e.device).map(([k, v]) => <span key={k} className="rounded-lg bg-navy-100/70 px-2 py-1 text-xs"><b>{k}</b> {String(v)}</span>)}</div></div>}
        {e.context && Object.keys(e.context).length > 0 && <div><div className="label">{t('logs.context')}</div><pre className="max-h-40 overflow-auto rounded-xl bg-ivory p-3 text-xs text-slate-700" dir="ltr">{JSON.stringify(e.context, null, 2)}</pre></div>}
        {e.stack && (
          <div>
            <div className="mb-1 flex items-center justify-between"><div className="label !mb-0">{t('logs.stack')}</div><button type="button" className="inline-flex items-center gap-1 text-xs font-semibold text-link" onClick={() => void navigator.clipboard.writeText(`${e.message}\n${e.stack}`)}><Copy className="size-3.5" />{t('logs.copy')}</button></div>
            <pre className="max-h-64 overflow-auto rounded-xl bg-navy-950 p-3 text-[11px] leading-relaxed text-emerald-200" dir="ltr">{e.stack}</pre>
          </div>
        )}
        <Field label={t('logs.note')}><div className="flex gap-2"><textarea rows={2} className="input text-sm" value={note ?? e.note ?? ''} onChange={(x) => setNote(x.target.value)} /><Button variant="outline" size="sm" loading={busy === 'note'} disabled={note === null} onClick={() => act('note', async () => { await api.put(`/admin/error-logs/${id}`, { note }); setNote(null) })}>{t('common.save')}</Button></div></Field>
      </div>
    </Modal>
  )
}

/** The system administrator's error log: everything that failed on the server, the website or the app. */
export default function ErrorLog() {
  const { t, i18n } = useTranslation()
  const [f, setF] = useState({ source: '', level: '', status: 'open', q: '', days: '', sort: 'recent', page: 1 })
  const [selected, setSelected] = useState<string[]>([])
  const [open, setOpen] = useState<string | null>(null)
  const [settings, setSettings] = useState(false)
  const [busy, setBusy] = useState(false)
  const [term, setTerm] = useState('')
  useEffect(() => { const id = setTimeout(() => setF((x) => ({ ...x, q: term, page: 1 })), 350); return () => clearTimeout(id) }, [term])

  const params = Object.fromEntries(Object.entries({ source: f.source, level: f.level, status: f.status, q: f.q, days: f.days, sort: f.sort, page: f.page, per_page: 25 }).filter(([, v]) => v !== ''))
  const { data, isLoading, refetch } = useGet<List>('/admin/error-logs', params, { refetchInterval: 30_000, staleTime: 0 })
  const stats = data?.stats

  const bulk = async (action: 'fix' | 'ignore' | 'reopen' | 'delete', body: Record<string, unknown>) => {
    if (action === 'delete' && !window.confirm(t('logs.confirmDeleteMany'))) return
    setBusy(true)
    try { await api.post('/admin/error-logs/bulk', { action, ...body }); setSelected([]); await refetch() } finally { setBusy(false) }
  }
  const allOnPage = !!data?.data.length && data.data.every((e) => selected.includes(e.id))
  const max = Math.max(1, ...(stats?.trend.map((x) => x.count) ?? [1]))

  return (
    <>
      <PageHeader title={t('logs.title')} subtitle={t('logs.subtitle')} actions={<>
        {f.status === 'fixed' && (data?.total ?? 0) > 0 && <Button variant="outline" icon={<Trash2 className="size-4" />} onClick={() => bulk('delete', { status: 'fixed' })}>{t('logs.clearFixed')}</Button>}
        <Button variant="outline" icon={<Settings2 className="size-4" />} onClick={() => setSettings(true)}>{t('logs.settings.button')}</Button>
      </>} />

      <div className="mb-6 grid gap-4 lg:grid-cols-[1fr_1fr_1fr_1fr_1.4fr]">
        {([[t('logs.stats.open'), stats?.open, AlertOctagon, 'text-red-600'], [t('logs.stats.critical'), stats?.critical, Bug, 'text-red-700'], [t('logs.stats.today'), stats?.today, Users, 'text-navy-900'], [t('logs.stats.auto'), stats?.auto_fixed, Sparkles, 'text-emerald-600']] as const).map(([label, value, Icon, tone]) => (
          <Card key={label} className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-navy-100/70"><Icon className={clsx('size-5', tone)} /></span><div><div className="text-2xl font-extrabold text-navy-900">{fmt.number(value ?? 0)}</div><div className="text-xs text-slate-500">{label}</div></div></Card>
        ))}
        <Card className="flex flex-col justify-between"><div className="text-xs font-semibold text-slate-500">{t('logs.stats.trend')}</div><div className="flex h-12 items-end gap-1" dir="ltr">{stats?.trend.map((x) => <div key={x.date} title={`${x.date}: ${x.count}`} className="flex-1 rounded-t bg-gradient-to-t from-gold-600 to-gold-300" style={{ height: `${Math.max(6, (x.count / max) * 100)}%` }} />)}</div></Card>
      </div>

      <Card className="mb-4 space-y-3">
        <div className="flex flex-wrap items-center gap-2">
          {([['open', t('logs.status.open')], ['fixed', t('logs.status.fixed')], ['ignored', t('logs.status.ignored')], ['', t('logs.status.all')]] as const).map(([k, label]) => <button key={k} type="button" onClick={() => setF({ ...f, status: k, page: 1 })} className={clsx('rounded-xl px-4 py-1.5 text-sm font-semibold transition', f.status === k ? 'bg-navy-900 text-white shadow' : 'bg-ivory text-slate-600 hover:bg-gold-100')}>{label}</button>)}
          <div className="relative ms-auto min-w-[14rem] flex-1 sm:flex-none"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input !ps-9" placeholder={t('logs.search')} value={term} onChange={(e) => setTerm(e.target.value)} /></div>
        </div>
        <div className="flex flex-wrap items-center gap-2 text-sm">
          {([['', t('logs.source.all')], ['server', t('logs.source.server')], ['web', t('logs.source.web')], ['app', t('logs.source.app')]] as const).map(([k, label]) => <button key={k} type="button" onClick={() => setF({ ...f, source: k, page: 1 })} className={clsx('rounded-full border px-3 py-1 text-xs font-semibold', f.source === k ? 'border-gold-500 bg-gold-100 text-navy-900' : 'border-navy-100 text-slate-600 hover:border-gold-300')}>{label}{k && stats?.by_source[k] ? ` · ${stats.by_source[k]}` : ''}</button>)}
          <select className="input !w-auto !py-1 text-xs" value={f.level} onChange={(e) => setF({ ...f, level: e.target.value, page: 1 })}><option value="">{t('logs.level.all')}</option>{(['critical', 'error', 'warning'] as const).map((l) => <option key={l} value={l}>{t(`logs.level.${l}`)}</option>)}</select>
          <select className="input !w-auto !py-1 text-xs" value={f.days} onChange={(e) => setF({ ...f, days: e.target.value, page: 1 })}><option value="">{t('logs.anyTime')}</option>{[1, 7, 30].map((d) => <option key={d} value={d}>{t('logs.lastDays', { count: d })}</option>)}</select>
          <select className="input !w-auto !py-1 text-xs" value={f.sort} onChange={(e) => setF({ ...f, sort: e.target.value })}>{(['recent', 'frequent', 'users'] as const).map((s) => <option key={s} value={s}>{t(`logs.sort.${s}`)}</option>)}</select>
        </div>
      </Card>

      {selected.length > 0 && (
        <div className="sticky top-2 z-20 mb-3 flex flex-wrap items-center gap-2 rounded-2xl border border-navy-100 bg-white/95 p-3 shadow-glass backdrop-blur">
          <span className="text-sm font-semibold text-navy-900">{t('logs.selected', { count: selected.length })}</span>
          <Button size="sm" variant="primary" loading={busy} icon={<CheckCircle2 className="size-4" />} onClick={() => bulk('fix', { ids: selected })}>{t('logs.markFixed')}</Button>
          <Button size="sm" variant="outline" icon={<EyeOff className="size-4" />} onClick={() => bulk('ignore', { ids: selected })}>{t('logs.ignore')}</Button>
          <Button size="sm" variant="outline" icon={<RotateCcw className="size-4" />} onClick={() => bulk('reopen', { ids: selected })}>{t('logs.reopen')}</Button>
          <Button size="sm" variant="danger" icon={<Trash2 className="size-4" />} onClick={() => bulk('delete', { ids: selected })}>{t('common.delete')}</Button>
          <button type="button" className="ms-auto text-slate-400 hover:text-slate-700" aria-label="clear" onClick={() => setSelected([])}><X className="size-4" /></button>
        </div>
      )}

      {isLoading ? <Spinner /> : !data?.data.length ? <Card><Empty icon={<BadgeCheck className="size-8 text-emerald-500" />} text={f.status === 'open' ? t('logs.emptyOpen') : t('logs.empty')} /></Card> : (
        <Card padded={false} className="overflow-hidden">
          <div className="flex items-center gap-3 border-b border-navy-100 bg-ivory/70 px-4 py-2 text-xs font-semibold text-slate-500">
            <input type="checkbox" className="size-4 accent-gold-600" checked={allOnPage} onChange={() => setSelected(allOnPage ? [] : data.data.map((e) => e.id))} aria-label="select all" />
            <span>{t('logs.total', { count: data.total })}</span>
          </div>
          <ul className="divide-y divide-navy-100/70">
            {data.data.map((e) => {
              const Icon = SOURCE_ICON[e.source]
              return (
                <li key={e.id} className={clsx('flex items-start gap-3 px-4 py-3 transition hover:bg-ivory/60', e.status !== 'open' && 'opacity-70')}>
                  <input type="checkbox" className="mt-1.5 size-4 accent-gold-600" checked={selected.includes(e.id)} onChange={() => setSelected(selected.includes(e.id) ? selected.filter((x) => x !== e.id) : [...selected, e.id])} aria-label="select" />
                  <button type="button" onClick={() => setOpen(e.id)} className="flex min-w-0 flex-1 items-start gap-3 text-start">
                    <span className={clsx('mt-2 size-2.5 shrink-0 rounded-full', LEVEL_TONE[e.level])} title={t(`logs.level.${e.level}`)} />
                    <span className="min-w-0 flex-1">
                      <span className="block truncate font-semibold text-navy-900" dir="auto">{e.message}</span>
                      <span className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500"><span className="inline-flex items-center gap-1"><Icon className="size-3.5" />{t(`logs.source.${e.source}`)}</span>{e.location && <span className="max-w-[22rem] truncate font-mono" dir="ltr">{e.location}</span>}{e.user_email && <span dir="ltr">{e.user_email}</span>}<span>{ago(e.last_seen_at, i18n.language)}</span></span>
                    </span>
                    <span className="flex shrink-0 flex-col items-end gap-1">
                      <span className="rounded-full bg-navy-100/80 px-2.5 py-0.5 text-xs font-bold text-navy-900">×{fmt.number(e.occurrences)}</span>
                      {e.auto_fixed ? <Badge color="green"><span className="inline-flex items-center gap-1"><Sparkles className="size-3" />{t('logs.auto')}</span></Badge> : e.status !== 'open' ? <Badge color={e.status === 'fixed' ? 'green' : 'gray'}>{t(`logs.status.${e.status}`)}</Badge> : e.fixable ? <Badge color="gold"><span className="inline-flex items-center gap-1"><Wand2 className="size-3" />{t('logs.fixable')}</span></Badge> : null}
                    </span>
                  </button>
                </li>
              )
            })}
          </ul>
          {data.last_page > 1 && <div className="flex items-center justify-center gap-3 border-t border-navy-100 p-3"><Button size="sm" variant="outline" disabled={f.page <= 1} onClick={() => setF({ ...f, page: f.page - 1 })}>{t('logs.prev')}</Button><span className="text-sm text-slate-500">{f.page} / {data.last_page}</span><Button size="sm" variant="outline" disabled={f.page >= data.last_page} onClick={() => setF({ ...f, page: f.page + 1 })}>{t('logs.next')}</Button></div>}
        </Card>
      )}

      {open && <Detail id={open} onClose={() => setOpen(null)} onChanged={() => void refetch()} />}
      {settings && <SettingsModal onClose={() => setSettings(false)} />}
    </>
  )
}
