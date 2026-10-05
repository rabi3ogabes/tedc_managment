import clsx from 'clsx'
import { CheckCircle2, DoorOpen, MapPinCheck, ScanLine, Smartphone, ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Card, Field, PageHeader, Progress, Spinner, SaveDock } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'

type Payload = { settings: { geofence_enabled: boolean; radius_m: number; max_accuracy_m: number; checkin_window_minutes: number | null; checkout_window_minutes: number | null; manual_window_minutes: number | null; absence_warning_percent: number; absence_breach_percent: number | null; excuse_counts_as_attended: boolean; leave_notifies_trainee: boolean }; rooms: { total: number; located: number } }

/** Settings → Attendance: the location check the mobile app performs before recording attendance. */
export default function AttendanceSettings() {
  const { t } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: Payload }>('/admin/settings/attendance')
  const [draft, setDraft] = useState<Partial<Payload['settings']>>({})
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState<{ ok: boolean; text: string } | null>(null)

  if (isLoading || !data) return <Spinner />
  const s = { ...data.data.settings, ...draft }
  const { rooms } = data.data
  const dirty = Object.keys(draft).length > 0
  const coverage = rooms.total ? Math.round((rooms.located / rooms.total) * 100) : 0
  const set = <K extends keyof Payload['settings']>(key: K, value: Payload['settings'][K]) => setDraft((d) => ({ ...d, [key]: value }))

  const save = async () => {
    setSaving(true)
    setNotice(null)
    try {
      await api.put('/admin/settings/attendance', s)
      setDraft({})
      await refetch()
      setNotice({ ok: true, text: t('mgmt.settings.attendance.saved') })
    } catch (e) {
      setNotice({ ok: false, text: errorMessage(e) })
    } finally {
      setSaving(false)
    }
  }

  const steps = t('mgmt.settings.attendance.steps', { returnObjects: true }) as string[]
  const icons = [ScanLine, Smartphone, ShieldCheck]

  return (
    <div className="space-y-6">
      <PageHeader title={t('mgmt.settings.attendance.title')} />

      <Card>
        <label className="flex cursor-pointer items-start gap-4">
          <span className={clsx('relative mt-1 h-7 w-12 shrink-0 rounded-full transition', s.geofence_enabled ? 'bg-emerald-500' : 'bg-slate-300')}>
            <input type="checkbox" className="peer sr-only" checked={s.geofence_enabled} onChange={(e) => set('geofence_enabled', e.target.checked)} />
            <span className={clsx('absolute top-0.5 size-6 rounded-full bg-white shadow transition-all', s.geofence_enabled ? 'start-[1.5rem]' : 'start-0.5')} />
          </span>
          <span>
            <span className="flex items-center gap-2 text-lg font-bold text-navy-900"><MapPinCheck className="size-5 text-gold-600" />{t('mgmt.settings.attendance.enable')}</span>
            <span className="mt-1 block max-w-2xl text-sm leading-relaxed text-slate-500">{t('mgmt.settings.attendance.enableHint')}</span>
          </span>
        </label>

        <div className={clsx('mt-6 grid gap-4 sm:grid-cols-2', !s.geofence_enabled && 'pointer-events-none opacity-50')}>
          <Field label={t('mgmt.settings.attendance.radius')} hint={t('mgmt.settings.attendance.radiusHint')}>
            <input type="number" min={20} max={5000} step={10} className="input" value={s.radius_m} onChange={(e) => set('radius_m', Number(e.target.value))} />
          </Field>
          <Field label={t('mgmt.settings.attendance.accuracy')} hint={t('mgmt.settings.attendance.accuracyHint')}>
            <input type="number" min={20} max={2000} step={10} className="input" value={s.max_accuracy_m} onChange={(e) => set('max_accuracy_m', Number(e.target.value))} />
          </Field>
        </div>

        <div className="mt-6 flex flex-wrap items-center gap-3">
          <SaveDock label={t('kits.common.save')} loading={saving} disabled={!dirty} onClick={save} />
          {notice && <span className={clsx('text-sm font-semibold', notice.ok ? 'text-emerald-700' : 'text-danger')}>{notice.ok && <CheckCircle2 className="me-1 inline size-4" />}{notice.text}</span>}
        </div>
      </Card>

      <Card className="space-y-5">
        <div><h3 className="text-lg font-bold text-navy-900">{t('ops.settings.windows')}</h3>
          <div className="mt-3 grid gap-4 sm:grid-cols-3">
            {([['checkin_window_minutes', 'checkin'], ['checkout_window_minutes', 'checkout'], ['manual_window_minutes', 'manual']] as const).map(([k, l]) => (
              <Field key={k} label={t(`ops.settings.${l}`)}><input type="number" min={0} className="input" placeholder={t('ops.settings.off')} value={s[k] ?? ''} onChange={(e) => set(k, e.target.value === '' ? null : Number(e.target.value))} /></Field>
            ))}
          </div></div>
        <div><h3 className="text-lg font-bold text-navy-900">{t('ops.settings.absence')}</h3>
          <div className="mt-3 grid gap-4 sm:grid-cols-2">
            <Field label={t('ops.settings.warn')}><input type="number" min={1} max={100} className="input" value={s.absence_warning_percent} onChange={(e) => set('absence_warning_percent', Number(e.target.value))} /></Field>
            <Field label={t('ops.settings.breach')}><input type="number" min={0} max={100} className="input" value={s.absence_breach_percent ?? ''} onChange={(e) => set('absence_breach_percent', e.target.value === '' ? null : Number(e.target.value))} /></Field>
          </div></div>
        <div><h3 className="text-lg font-bold text-navy-900">{t('ops.settings.policy')}</h3>
          <label className="mt-3 flex items-center gap-2 text-sm font-semibold text-navy-900"><input type="checkbox" checked={s.excuse_counts_as_attended} onChange={(e) => set('excuse_counts_as_attended', e.target.checked)} />{t('ops.settings.excuseCounts')}</label>
          <label className="mt-2 flex items-center gap-2 text-sm font-semibold text-navy-900"><input type="checkbox" checked={s.leave_notifies_trainee} onChange={(e) => set('leave_notifies_trainee', e.target.checked)} />{t('ops.settings.leaveNotify')}</label></div>
        <div className="flex flex-wrap items-center gap-3"><SaveDock label={t('kits.common.save')} loading={saving} disabled={!dirty} onClick={save} /></div>
      </Card>

      <div className="grid gap-6 lg:grid-cols-2">
        <Card>
          <h3 className="text-lg font-bold text-navy-900">{t('mgmt.settings.attendance.coverage')}</h3>
          <div className="mt-3 flex items-end gap-2"><span className="text-4xl font-extrabold text-navy-900">{fmt.number(rooms.located)}</span><span className="pb-1 text-slate-500">/ {fmt.number(rooms.total)}</span></div>
          <Progress value={coverage} className="my-3" />
          <p className="text-sm text-slate-500">{t('mgmt.settings.attendance.coverageHint')}</p>
          <Link to="/admin/settings?tab=rooms" className="mt-4 inline-flex items-center gap-2 text-sm font-bold text-link hover:underline"><DoorOpen className="size-4" />{t('mgmt.settings.attendance.openRooms')}</Link>
        </Card>

        <Card>
          <h3 className="mb-4 text-lg font-bold text-navy-900">{t('mgmt.settings.attendance.how')}</h3>
          <ol className="space-y-4">
            {steps.map((text, i) => {
              const Icon = icons[i] ?? ScanLine
              return <li key={i} className="flex items-start gap-3"><span className="grid size-10 shrink-0 place-items-center rounded-xl bg-navy-900 text-gold-300"><Icon className="size-5" /></span><span className="pt-1.5 text-sm leading-relaxed text-navy-900">{text}</span></li>
            })}
          </ol>
        </Card>
      </div>
    </div>
  )
}
