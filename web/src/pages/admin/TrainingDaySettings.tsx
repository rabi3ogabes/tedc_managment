import clsx from 'clsx'
import { AlertTriangle, CheckCircle2, Clock, DoorOpen } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, Field, PageHeader, Spinner, SaveDock } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'

type Payload = {
  settings: { day_start: string; day_end: string; enforce_window: boolean; one_session_per_room_per_day: boolean }; hours: number
  issues: { outside_window: number; shared_rooms: number; upcoming: number }
}

function Switch({ on, onChange, title, hint, icon }: { on: boolean; onChange: (v: boolean) => void; title: string; hint: string; icon: React.ReactNode }) {
  return (
    <button type="button" role="switch" aria-checked={on} onClick={() => onChange(!on)} className="flex w-full items-start gap-4 text-start">
      <span className={clsx('relative mt-1 h-7 w-12 shrink-0 rounded-full transition', on ? 'bg-emerald-500' : 'bg-slate-300')}><span className={clsx('absolute top-0.5 size-6 rounded-full bg-white shadow transition-all', on ? 'start-[1.5rem]' : 'start-0.5')} /></span>
      <span><span className="flex items-center gap-2 text-lg font-bold text-navy-900">{icon}{title}</span><span className="mt-1 block max-w-2xl text-sm leading-relaxed text-slate-500">{hint}</span></span>
    </button>
  )
}

/** Settings → Training day: the hours of the program day and the one-session-per-room-per-day rule. */
export default function TrainingDaySettings() {
  const { t } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: Payload }>('/admin/settings/training-day', undefined, { staleTime: 0 })
  const [draft, setDraft] = useState<Partial<Payload['settings']>>({})
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState<{ ok: boolean; text: string } | null>(null)
  if (isLoading || !data) return <Spinner />

  const s = { ...data.data.settings, ...draft }
  const dirty = Object.keys(draft).length > 0
  const set = <K extends keyof Payload['settings']>(k: K, v: Payload['settings'][K]) => setDraft((d) => ({ ...d, [k]: v }))
  const minutes = (x: string) => Number(x.slice(0, 2)) * 60 + Number(x.slice(3, 5))
  const hours = Math.max(0, (minutes(s.day_end) - minutes(s.day_start)) / 60)
  const valid = hours > 0
  const { issues } = data.data

  const save = async () => {
    setSaving(true)
    setNotice(null)
    try {
      await api.put('/admin/settings/training-day', s)
      setDraft({})
      await refetch()
      setNotice({ ok: true, text: t('studio.day.saved') })
    } catch (e) {
      setNotice({ ok: false, text: errorMessage(e) })
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader title={t('mgmt.settings.sections.trainingday.title')} subtitle={t('studio.day.subtitle')} />
      <SaveDock label={t('common.save')} loading={saving} disabled={!dirty || !valid} onClick={save} />
      {notice && <div className={clsx('rounded-xl p-3 text-sm', notice.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-danger')}>{notice.text}</div>}

      <Card className="space-y-5">
        <div className="flex items-center gap-2"><Clock className="size-5 text-gold-600" /><h3 className="text-lg font-bold text-navy-900">{t('studio.day.hoursTitle')}</h3></div>
        <div className="grid items-end gap-4 sm:grid-cols-[1fr_1fr_auto]">
          <Field label={t('studio.day.start')}><input type="time" dir="ltr" className="input text-lg font-bold" value={s.day_start} onChange={(e) => set('day_start', e.target.value)} /></Field>
          <Field label={t('studio.day.end')}><input type="time" dir="ltr" className={clsx('input text-lg font-bold', !valid && 'border-danger')} value={s.day_end} onChange={(e) => set('day_end', e.target.value)} /></Field>
          <div className="rounded-2xl bg-navy-900 px-6 py-3 text-center text-white"><div className="text-3xl font-extrabold">{fmt.number(hours, 1)}</div><div className="text-xs text-gold-300">{t('studio.day.hours')}</div></div>
        </div>
        {/* The day drawn on a 06:00–18:00 ruler */}
        <div dir="ltr" className="relative h-10 overflow-hidden rounded-xl bg-navy-100/60">
          {valid && <div className="absolute inset-y-0 rounded-lg bg-gradient-to-r from-gold-500 to-gold-300" style={{ left: `${Math.max(0, ((minutes(s.day_start) - 360) / 720) * 100)}%`, width: `${Math.min(100, (hours * 60 / 720) * 100)}%` }} />}
          <div className="absolute inset-0 flex justify-between px-2 text-[10px] font-semibold text-navy-800/60">{Array.from({ length: 13 }, (_, i) => <span key={i} className="self-end">{String(6 + i).padStart(2, '0')}</span>)}</div>
        </div>
        <Switch on={s.enforce_window} onChange={(v) => set('enforce_window', v)} icon={<CheckCircle2 className="size-5 text-gold-600" />} title={t('studio.day.enforce')} hint={t('studio.day.enforceHint', { from: s.day_start, to: s.day_end })} />
      </Card>

      <Card>
        <Switch on={s.one_session_per_room_per_day} onChange={(v) => set('one_session_per_room_per_day', v)} icon={<DoorOpen className="size-5 text-gold-600" />} title={t('studio.day.oneSession')} hint={t('studio.day.oneSessionHint')} />
      </Card>

      {(issues.outside_window > 0 || issues.shared_rooms > 0) && (
        <Card className="flex items-start gap-3 border-amber-300 bg-amber-50 text-amber-900">
          <AlertTriangle className="mt-0.5 size-5 shrink-0" />
          <div className="text-sm"><div className="font-bold">{t('studio.day.issuesTitle')}</div><ul className="mt-1 list-disc ps-5">{issues.outside_window > 0 && <li>{t('studio.day.issueOutside', { count: issues.outside_window })}</li>}{issues.shared_rooms > 0 && <li>{t('studio.day.issueShared', { count: issues.shared_rooms })}</li>}</ul><p className="mt-1 text-xs">{t('studio.day.issuesHint')}</p></div>
        </Card>
      )}
      <p className="text-xs text-slate-500">{t('studio.day.applies')}</p>
    </div>
  )
}
