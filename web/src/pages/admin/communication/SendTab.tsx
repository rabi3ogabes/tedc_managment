/* eslint-disable @typescript-eslint/no-explicit-any */
import { Send } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Field } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { AudienceBuilder, ChannelChecks, fromLocalInput, input, type AudienceFilter } from './shared'

/** Whether a moment (in Doha time) falls outside the allowed hours of a rule. */
function outside(date: Date, q: { days?: number[]; from?: string; to?: string }) {
  const parts = new Intl.DateTimeFormat('en-GB', { timeZone: 'Asia/Qatar', weekday: 'short', hour: '2-digit', minute: '2-digit', hour12: false }).formatToParts(date)
  const get = (t: string) => parts.find((p) => p.type === t)?.value ?? ''
  const day = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'].indexOf(get('weekday'))
  const mins = Number(get('hour')) * 60 + Number(get('minute'))
  const [fh, fm] = (q.from ?? '00:00').split(':').map(Number)
  const [th, tm] = (q.to ?? '23:59').split(':').map(Number)
  const a = fh * 60 + fm
  const b = th * 60 + tm
  const inside = a < b ? mins >= a && mins < b : mins >= a || mins < b
  return !(q.days ?? [0, 1, 2, 3, 4, 5, 6]).includes(day) || !inside
}

/** A message written now, sent now or later (once or repeating) to an audience built from several filters. */
export default function SendTab({ onSent }: { onSent: () => void }) {
  const { t } = useTranslation()
  const rules = useGet<{ data: any[] }>('/admin/notification-rules', undefined, { retry: false })
  const [f, setF] = useState({ title_ar: '', title_en: '', body_ar: '', body_en: '' })
  const [channels, setChannels] = useState<string[]>(['push'])
  const [audience, setAudience] = useState<AudienceFilter>({})
  const [mode, setMode] = useState<'now' | 'later'>('now')
  const [at, setAt] = useState('')
  const [repeat, setRepeat] = useState('none')
  const [until, setUntil] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const when = mode === 'now' ? new Date() : at ? new Date(at) : null
  const waiting = when ? channels.filter((c) => (rules.data?.data ?? []).some((r) => r.enabled && r.quiet_hours && ['*', 'announcement'].includes(r.event) && (!r.quiet_hours.channels?.length || r.quiet_hours.channels.includes(c)) && outside(when, r.quiet_hours))) : []

  const submit = async () => {
    if (!f.title_ar.trim() || !f.title_en.trim()) { setError(String(t('comm.send.need'))); return }
    setBusy(true); setError(null)
    try {
      await api.post('/admin/scheduled-notifications', { ...f, channels, audience, send_at: mode === 'now' ? new Date().toISOString() : fromLocalInput(at), repeat, repeat_until: repeat !== 'none' && until ? until : null })
      toast(String(mode === 'now' ? t('comm.send.sentNow') : t('comm.send.scheduledOk')))
      setF({ title_ar: '', title_en: '', body_ar: '', body_en: '' })
      onSent()
    } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }

  return (
    <Card>
      <h3 className="mb-4 font-bold text-navy-900">{t('comm.send.title')}</h3>
      <div className="grid gap-3 md:grid-cols-2">
        <Field label={t('comm.send.titleAr')}><input className={input} value={f.title_ar} onChange={(e) => setF({ ...f, title_ar: e.target.value })} /></Field>
        <Field label={t('comm.send.titleEn')}><input className={input} dir="ltr" value={f.title_en} onChange={(e) => setF({ ...f, title_en: e.target.value })} /></Field>
        <Field label={t('comm.send.bodyAr')}><textarea className={`${input} min-h-24`} value={f.body_ar} onChange={(e) => setF({ ...f, body_ar: e.target.value })} /></Field>
        <Field label={t('comm.send.bodyEn')}><textarea className={`${input} min-h-24`} dir="ltr" value={f.body_en} onChange={(e) => setF({ ...f, body_en: e.target.value })} /></Field>
        <Field label={t('comm.send.channels')} className="md:col-span-2"><ChannelChecks value={channels} onChange={setChannels} /></Field>
        <div className="md:col-span-2"><AudienceBuilder value={audience} onChange={setAudience} /></div>
        <Field label={t('comm.send.when')} className="md:col-span-2">
          <div className="flex flex-wrap items-center gap-4 text-sm">
            <label className="flex items-center gap-2"><input type="radio" className="accent-gold-600" checked={mode === 'now'} onChange={() => setMode('now')} />{t('comm.send.now')}</label>
            <label className="flex items-center gap-2"><input type="radio" className="accent-gold-600" checked={mode === 'later'} onChange={() => setMode('later')} />{t('comm.send.later')}</label>
            {mode === 'later' && <input type="datetime-local" className={`${input} w-auto`} value={at} onChange={(e) => setAt(e.target.value)} />}
            <select className={`${input} w-auto`} value={repeat} onChange={(e) => setRepeat(e.target.value)} aria-label={String(t('comm.send.repeat'))}>
              {['none', 'daily', 'weekly', 'monthly'].map((r) => <option key={r} value={r}>{t(`comm.send.repeats.${r}`)}</option>)}
            </select>
            {repeat !== 'none' && <label className="flex items-center gap-2">{t('comm.send.until')}<input type="date" className={`${input} w-auto`} value={until} onChange={(e) => setUntil(e.target.value)} /></label>}
          </div>
        </Field>
        {!!waiting.length && <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800 md:col-span-2">{t('comm.send.quiet', { c: waiting.map((c) => t(`comm.channels.${c}`)).join('، ') })}</p>}
      </div>
      {error && <p className="mt-3 text-sm text-danger">{error}</p>}
      <div className="mt-5 flex justify-end"><Button variant="gold" loading={busy} icon={<Send className="size-4" />} onClick={submit} disabled={mode === 'later' && !at}>{t('comm.send.submit')}</Button></div>
    </Card>
  )
}
