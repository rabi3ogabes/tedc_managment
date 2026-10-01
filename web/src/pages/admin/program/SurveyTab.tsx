import clsx from 'clsx'
import { BellRing, CalendarClock, ChevronDown, ChevronUp, ClipboardCheck, Hand, Infinity as InfinityIcon, Lock, LockOpen, Timer } from 'lucide-react'
import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import type { Program } from '@/lib/types'
import CampaignTracking from '../notifications/CampaignTracking'
import SendNotificationDialog from '../notifications/SendNotificationDialog'

type State = {
  mode: 'always' | 'manual' | 'auto'; auto_hours: number; status: 'always' | 'open' | 'closed' | 'scheduled'; is_open: boolean; opens_at: string | null; ends_at: string
  opened_at: string | null; closed_at: string | null; trainees: number; submitted: number; pending: number
}

/** The program survey: open it by hand or automatically, close it, and tell the trainees. */
export default function SurveyTab({ program }: { program: Program }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const { data, isLoading, refetch } = useGet<{ data: State }>(`/admin/programs/${program.id}/survey`, undefined, { staleTime: 0 })
  const refreshSent = () => qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0]).startsWith('/admin/notifications') })
  const [hours, setHours] = useState(24)
  const [notify, setNotify] = useState(true)
  const [busy, setBusy] = useState<string | null>(null)
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null)
  const [sending, setSending] = useState(false)
  const s = data?.data
  useEffect(() => { if (s) setHours(s.auto_hours) }, [s?.auto_hours]) // eslint-disable-line react-hooks/exhaustive-deps

  if (isLoading || !s) return <Spinner />

  const run = async (key: string, call: () => Promise<unknown>, ok: string) => {
    setBusy(key); setMessage(null)
    try { await call(); await refetch(); await refreshSent(); setMessage({ ok: true, text: ok }) } catch (e) { setMessage({ ok: false, text: errorMessage(e) }) } finally { setBusy(null) }
  }
  const setMode = (mode: State['mode']) => run('mode', () => api.put(`/admin/programs/${program.id}/survey`, { mode, auto_hours: hours }), t('mgmt.notif.survey.saved'))
  const pct = s.trainees ? Math.round((s.submitted / s.trainees) * 100) : 0
  const tone = { always: 'bg-sky-50 text-sky-700', open: 'bg-emerald-50 text-emerald-700', closed: 'bg-slate-100 text-slate-600', scheduled: 'bg-amber-50 text-amber-700' }[s.status]
  const modes = [
    { id: 'always' as const, icon: InfinityIcon }, { id: 'manual' as const, icon: Hand }, { id: 'auto' as const, icon: Timer },
  ]

  return (
    <div className="space-y-6">
      {message && <div className={clsx('rounded-2xl p-3 text-sm font-semibold', message.ok ? 'bg-emerald-50 text-emerald-700' : 'bg-red-50 text-danger')}>{message.text}</div>}

      <section className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-navy-950 via-navy-900 to-navy-800 p-6 text-white shadow-glass">
        <div className="pointer-events-none absolute -end-16 -top-20 size-64 rounded-full bg-gold-500/15 blur-3xl" />
        <div className="relative flex flex-wrap items-center gap-6">
          <div className="min-w-0 flex-1 basis-72">
            <span className={clsx('inline-flex items-center gap-1.5 rounded-full px-3 py-1 text-xs font-bold', tone)}>{s.is_open ? <LockOpen className="size-3.5" /> : <Lock className="size-3.5" />}{t(`mgmt.notif.survey.status.${s.status}`)}</span>
            <h2 className="mt-3 text-2xl font-bold">{t('mgmt.notif.survey.title')}</h2>
            <p className="mt-1 max-w-xl text-sm text-white/70">{t('mgmt.notif.survey.subtitle')}</p>
            {s.status === 'scheduled' && s.opens_at && <p className="mt-3 inline-flex items-center gap-2 rounded-xl bg-white/10 px-3 py-2 text-sm"><CalendarClock className="size-4 text-gold-300" />{t('mgmt.notif.survey.opensAt', { when: fmt.dateTime(s.opens_at) })}</p>}
          </div>
          <div className="flex items-center gap-5">
            <div className="relative grid size-24 place-items-center">
              <svg viewBox="0 0 36 36" className="absolute inset-0 -rotate-90"><circle cx="18" cy="18" r="15.5" fill="none" stroke="rgba(255,255,255,.15)" strokeWidth="3" /><circle cx="18" cy="18" r="15.5" fill="none" stroke="#e2b54f" strokeWidth="3" strokeLinecap="round" strokeDasharray={`${pct} 100`} pathLength={100} className="transition-all duration-700" /></svg>
              <span className="text-xl font-extrabold">{pct}%</span>
            </div>
            <div className="text-sm"><div className="text-white/70">{t('mgmt.notif.survey.answered')}</div><div className="text-3xl font-extrabold">{s.submitted}<span className="text-lg font-semibold text-white/60"> / {s.trainees}</span></div></div>
          </div>
        </div>
        <div className="relative mt-6 flex flex-wrap items-center gap-3">
          {s.mode !== 'always' && !s.is_open && <Button variant="gold" icon={<LockOpen className="size-4" />} loading={busy === 'open'} onClick={() => run('open', () => api.post(`/admin/programs/${program.id}/survey/open`, { notify }), t('mgmt.notif.survey.opened'))}>{t('mgmt.notif.survey.openNow')}</Button>}
          {s.mode !== 'always' && s.is_open && <Button variant="light" icon={<Lock className="size-4" />} loading={busy === 'close'} onClick={() => run('close', () => api.post(`/admin/programs/${program.id}/survey/close`), t('mgmt.notif.survey.closedMsg'))}>{t('mgmt.notif.survey.close')}</Button>}
          <Button variant="light" icon={<BellRing className="size-4" />} onClick={() => setSending(true)}>{t('mgmt.notif.survey.notify')}</Button>
          {s.mode !== 'always' && !s.is_open && (
            <label className="ms-1 flex cursor-pointer items-center gap-2 text-sm text-white/80"><input type="checkbox" className="size-4 accent-gold-500" checked={notify} onChange={(e) => setNotify(e.target.checked)} />{t('mgmt.notif.survey.notifyOnOpen')}</label>
          )}
        </div>
      </section>

      <Card>
        <h3 className="mb-1 flex items-center gap-2 text-lg font-bold text-navy-900"><ClipboardCheck className="size-5 text-gold-600" />{t('mgmt.notif.survey.availability')}</h3>
        <p className="mb-4 text-sm text-slate-500">{t('mgmt.notif.survey.availabilityHint')}</p>
        <div role="radiogroup" className="grid gap-3 md:grid-cols-3">
          {modes.map(({ id, icon: Icon }) => (
            <button key={id} type="button" role="radio" aria-checked={s.mode === id} disabled={busy === 'mode'} onClick={() => void setMode(id)}
              className={clsx('flex items-start gap-3 rounded-2xl border p-4 text-start transition', s.mode === id ? 'border-gold-400 bg-gold-100/40 shadow-sm ring-1 ring-gold-300' : 'border-navy-100 bg-white hover:border-gold-300')}>
              <span className={clsx('grid size-10 shrink-0 place-items-center rounded-xl', s.mode === id ? 'bg-navy-900 text-gold-300' : 'bg-navy-100/60 text-slate-500')}><Icon className="size-5" /></span>
              <span><span className="block font-bold text-navy-900">{t(`mgmt.notif.survey.modes.${id}`)}</span><span className="mt-0.5 block text-xs leading-relaxed text-slate-500">{t(`mgmt.notif.survey.modes.${id}Hint`)}</span></span>
            </button>
          ))}
        </div>
        {s.mode === 'auto' && (
          <div className="mt-4 flex flex-wrap items-end gap-4 rounded-2xl bg-ivory p-4">
            <label className="text-sm font-semibold text-navy-900">{t('mgmt.notif.survey.afterHours')}
              <div className="mt-1 flex items-center gap-2"><input type="number" min={0} max={720} className="input !w-28" value={hours} onChange={(e) => setHours(Math.max(0, Math.min(720, Number(e.target.value) || 0)))} /><span className="text-sm text-slate-500">{t('mgmt.notif.survey.hours')}</span></div>
            </label>
            <div className="flex flex-wrap gap-1.5">{[0, 6, 24, 48, 72].map((h) => <button key={h} type="button" onClick={() => setHours(h)} className={clsx('rounded-full px-3 py-1 text-xs font-bold ring-1 ring-inset', hours === h ? 'bg-navy-900 text-white ring-navy-900' : 'bg-white text-slate-600 ring-navy-100')}>{h === 0 ? t('mgmt.notif.survey.rightAway') : `${h} ${t('mgmt.notif.survey.hoursShort')}`}</button>)}</div>
            <Button variant="primary" loading={busy === 'mode'} disabled={hours === s.auto_hours} onClick={() => void setMode('auto')}>{t('common.save')}</Button>
            <p className="basis-full text-xs text-slate-500">{t('mgmt.notif.survey.autoExplain', { end: fmt.dateTime(s.ends_at), hours: s.auto_hours })}</p>
          </div>
        )}
      </Card>

      <SentBox programId={program.id} />

      {sending && <SendNotificationDialog programId={program.id} defaultEvent="survey.open" defaultAudience="pending_survey" onClose={() => setSending(false)} onSent={() => { void refetch(); void refreshSent() }} />}
    </div>
  )
}

type Sent = { id: string; title: string; created_at: string; sent: number; seen: number; read: number; read_rate: number }

/** The notifications sent to this program's trainees: a small summary box, expandable into the full list. */
function SentBox({ programId }: { programId: string }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(() => { try { return localStorage.getItem('tedc.survey.sent.open') === '1' } catch { return false } })
  const { data } = useGet<{ data: Sent[] }>('/admin/notifications/campaigns', { program_id: programId })
  const list = data?.data ?? []
  const total = list.reduce((n, c) => ({ sent: n.sent + c.sent, seen: n.seen + c.seen, read: n.read + c.read }), { sent: 0, seen: 0, read: 0 })
  const rate = total.sent ? Math.round((total.read / total.sent) * 100) : 0
  const toggle = () => setOpen((v) => { try { localStorage.setItem('tedc.survey.sent.open', v ? '0' : '1') } catch { /* storage unavailable */ } return !v })
  const latest = list[0]

  return (
    <Card>
      <div className="flex flex-wrap items-center gap-3">
        <span className="grid size-10 place-items-center rounded-xl bg-navy-900 text-gold-300"><BellRing className="size-5" /></span>
        <div className="min-w-0 flex-1 basis-56">
          <h3 className="font-bold text-navy-900">{t('mgmt.notif.survey.sentTitle')}</h3>
          <p className="truncate text-xs text-slate-500">{latest ? t('mgmt.notif.survey.sentBox.latest', { title: latest.title, when: fmt.dateTime(latest.created_at) }) : t('mgmt.notif.survey.sentBox.none')}</p>
        </div>
        {list.length > 0 && (
          <div className="flex items-center gap-4 text-center">
            {[{ l: t('mgmt.notif.survey.sentBox.campaigns'), v: list.length }, { l: t('mgmt.notif.tracking.sent'), v: total.sent }, { l: t('mgmt.notif.tracking.readRate'), v: `${rate}%`, accent: true }].map((x) => (
              <div key={x.l}><div className={clsx('text-xl font-extrabold', x.accent ? 'text-emerald-600' : 'text-navy-900')}>{x.v}</div><div className="text-[11px] text-slate-500">{x.l}</div></div>
            ))}
          </div>
        )}
        <Button size="sm" variant="outline" icon={open ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />} aria-expanded={open} onClick={toggle}>{open ? t('mgmt.notif.survey.sentBox.hide') : t('mgmt.notif.survey.sentBox.show')}</Button>
      </div>
      {list.length > 0 && !open && (
        <div className="mt-3 flex h-2 overflow-hidden rounded-full bg-navy-100/60" role="img" aria-label={`${total.read}/${total.sent}`}>
          <div className="bg-emerald-500" style={{ width: `${total.sent ? (total.read / total.sent) * 100 : 0}%` }} />
          <div className="bg-sky-400" style={{ width: `${total.sent ? (Math.max(0, total.seen - total.read) / total.sent) * 100 : 0}%` }} />
        </div>
      )}
      {open && <div className="mt-4"><CampaignTracking programId={programId} /></div>}
    </Card>
  )
}
