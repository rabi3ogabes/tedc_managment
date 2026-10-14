import clsx from 'clsx'
import { Camera, CameraOff, CheckCircle2, ShieldAlert, ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

export type Integrity = {
  flags: string[]
  reviews: Record<string, { decision: 'clear' | 'uphold'; note: string; by_name?: string; at: string }>
  events: Record<string, number>
  consent: { given_at: string; version: string; camera: boolean } | null
  timeline: ({ minute: number } & Record<string, number>)[]
  snapshots: string[]
  purged_at: string | null
}

/** Event colours in the timeline: face events warm, page events cool. */
const TONE: Record<string, string> = {
  face_absent: 'bg-rose-500', multiple_faces: 'bg-red-700', looking_away: 'bg-amber-500', camera_off: 'bg-slate-700', face_check_degraded: 'bg-slate-300',
  visibility_hidden: 'bg-navy-700', blur: 'bg-navy-400', multi_tab: 'bg-navy-900', fullscreen_exit: 'bg-sky-500', paste: 'bg-violet-500', copy: 'bg-violet-300', contextmenu: 'bg-slate-400', devtools: 'bg-slate-900',
}

function FlagReview({ attemptId, flag, review, onSaved }: { attemptId: string; flag: string; review?: Integrity['reviews'][string]; onSaved: () => void }) {
  const { t } = useTranslation()
  const [decision, setDecision] = useState<'clear' | 'uphold' | null>(null)
  const [note, setNote] = useState('')
  const [busy, setBusy] = useState(false)
  const save = async () => {
    if (!decision) return
    setBusy(true)
    try {
      await api.post(`/admin/attempts/${attemptId}/flags/${flag}/review`, { decision, note })
      setDecision(null); setNote(''); onSaved()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <li className="space-y-2 rounded-xl border border-navy-50 bg-white p-3">
      <div className="flex flex-wrap items-center gap-2">
        <ShieldAlert className={clsx('size-4', review?.decision === 'clear' ? 'text-slate-400' : 'text-rose-600')} />
        <b className="text-navy-900">{t(`assess.flags.${flag}`, { defaultValue: flag })}</b>
        {review && <Badge color={review.decision === 'clear' ? 'gray' : 'amber'}>{t(review.decision === 'clear' ? 'assess.integrity.cleared' : 'assess.integrity.upheld')}</Badge>}
        <span className="ms-auto flex gap-1">
          {(['clear', 'uphold'] as const).map((d) => (
            <Button key={d} size="sm" variant={decision === d ? 'primary' : 'outline'} aria-pressed={decision === d} onClick={() => setDecision(decision === d ? null : d)}>{t(`assess.integrity.${d}`)}</Button>
          ))}
        </span>
      </div>
      <p className="text-sm text-slate-600">{t(`assess.integrity.reasons.${flag}`, { defaultValue: '' })}</p>
      {review && <p className="rounded-lg bg-ivory p-2 text-sm"><span className="text-slate-700">{review.note}</span> <span className="text-xs text-slate-500">— {t('assess.integrity.by', { name: review.by_name ?? '', at: fmt.dateTime(review.at) })}</span></p>}
      {decision && (
        <div className="space-y-2">
          <label className="block text-xs font-bold text-slate-600" htmlFor={`note-${flag}`}>{t('assess.integrity.note')}</label>
          <textarea id={`note-${flag}`} value={note} onChange={(e) => setNote(e.target.value)} rows={2} maxLength={500} placeholder={String(t('assess.integrity.notePlaceholder'))} className="w-full rounded-xl border border-navy-100 px-3 py-2 text-sm" />
          <Button size="sm" variant="gold" loading={busy} disabled={!note.trim()} onClick={() => void save()}>{t('assess.integrity.save')}</Button>
        </div>
      )}
    </li>
  )
}

/** Minute by minute: one column per minute of the attempt, its events stacked by type. */
function Timeline({ rows }: { rows: Integrity['timeline'] }) {
  const { t } = useTranslation()
  if (!rows.length) return <p className="text-sm text-slate-500">{t('assess.integrity.noEvents')}</p>
  const last = Math.max(...rows.map((r) => r.minute))
  const byMinute = new Map(rows.map((r) => [r.minute, r]))
  const max = Math.max(1, ...rows.map((r) => Object.entries(r).filter(([k]) => k !== 'minute').reduce((a, [, v]) => a + Number(v), 0)))
  const types = [...new Set(rows.flatMap((r) => Object.keys(r).filter((k) => k !== 'minute')))]
  const label = (m: number) => {
    const r = byMinute.get(m)
    const parts = r ? Object.entries(r).filter(([k]) => k !== 'minute').map(([k, v]) => `${t(`assess.events.${k}`, { defaultValue: k })} ×${v}`) : []
    return `${t('assess.integrity.minute', { n: m + 1 })}${parts.length ? ': ' + parts.join('، ') : ''}`
  }
  return (
    <div className="space-y-2">
      <div className="overflow-x-auto rounded-xl bg-ivory p-3">
        <div className="flex h-24 items-end gap-0.5" role="img" aria-label={String(t('assess.integrity.timeline'))}>
          {Array.from({ length: last + 1 }, (_, m) => {
            const r = byMinute.get(m)
            return (
              <div key={m} title={label(m)} className="flex h-full min-w-1.5 max-w-5 flex-1 flex-col-reverse rounded-sm bg-white">
                {r && Object.entries(r).filter(([k]) => k !== 'minute').map(([k, v]) => <span key={k} className={clsx('block w-full first:rounded-b-sm', TONE[k] ?? 'bg-slate-400')} style={{ height: `${(Number(v) / max) * 100}%` }} />)}
              </div>
            )
          })}
        </div>
        <div className="mt-1 flex justify-between text-[10px] tabular-nums text-slate-500"><span>1</span><span>{last + 1}</span></div>
      </div>
      <ul className="flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-600">
        {types.map((k) => <li key={k} className="flex items-center gap-1.5"><span className={clsx('size-2.5 rounded-sm', TONE[k] ?? 'bg-slate-400')} />{t(`assess.events.${k}`, { defaultValue: k })}</li>)}
      </ul>
    </div>
  )
}

/** The integrity record of an attempt for the reviewer: consent, flags with their plain reasons and decisions, the timeline and snapshots. */
export default function IntegrityPanel({ attemptId, integrity, onChanged }: { attemptId: string; integrity: Integrity; onChanged: () => void }) {
  const { t } = useTranslation()
  const c = integrity.consent
  if (!integrity.flags.length && !integrity.timeline.length && !c && !integrity.snapshots.length && !integrity.purged_at) return null
  return (
    <section className="space-y-4">
      <h3 className="flex items-center gap-2 font-bold text-navy-900"><ShieldCheck className="size-5 text-navy-800" />{t('assess.integrity.title')}</h3>
      {c && (
        <p className="flex items-center gap-2 text-sm text-slate-600">
          {c.camera ? <Camera className="size-4 text-emerald-600" /> : <CameraOff className="size-4 text-amber-600" />}
          <span><b className="text-navy-900">{t('assess.integrity.consent')}:</b> {t('assess.integrity.consentGiven', { at: fmt.dateTime(c.given_at), v: c.version })} · {t(c.camera ? 'assess.integrity.withCamera' : 'assess.integrity.withoutCamera')}</span>
        </p>
      )}
      {integrity.purged_at && <p className="flex items-center gap-2 rounded-xl bg-ivory p-3 text-sm text-slate-600"><CheckCircle2 className="size-4" />{t('assess.integrity.purged', { at: fmt.date(integrity.purged_at) })}</p>}
      {integrity.flags.length > 0 && <ul className="space-y-2">{integrity.flags.map((f) => <FlagReview key={f} attemptId={attemptId} flag={f} review={integrity.reviews[f]} onSaved={onChanged} />)}</ul>}
      {!integrity.purged_at && <div className="space-y-2"><p className="text-sm font-bold text-navy-900">{t('assess.integrity.timeline')}</p><Timeline rows={integrity.timeline} /></div>}
      {integrity.snapshots.length > 0 && (
        <div className="space-y-2">
          <p className="text-sm font-bold text-navy-900">{t('assess.integrity.snapshots')}</p>
          <div className="grid grid-cols-3 gap-2 sm:grid-cols-5">{integrity.snapshots.map((u, i) => <a key={i} href={u} target="_blank" rel="noreferrer"><img src={u} alt="" loading="lazy" className="aspect-[4/3] w-full rounded-lg object-cover ring-1 ring-navy-100" /></a>)}</div>
        </div>
      )}
    </section>
  )
}
