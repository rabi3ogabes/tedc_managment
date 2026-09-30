import clsx from 'clsx'
import { CheckCircle2, Clock, Eye, LockKeyhole, Save, ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'

type Settings = { idle_lock_enabled: boolean; idle_lock_minutes: number }
const PRESETS = [5, 10, 15, 30, 60]

/** Settings → Security: lock the administration team's dashboard after a period without activity. */
export default function SessionLockSettings() {
  const { t } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: Settings }>('/admin/settings/security')
  const [draft, setDraft] = useState<Partial<Settings>>({})
  const [saving, setSaving] = useState(false)
  const [notice, setNotice] = useState<{ ok: boolean; text: string } | null>(null)
  if (isLoading || !data) return <Spinner />

  const s = { ...data.data, ...draft }
  const dirty = Object.keys(draft).length > 0
  const set = <K extends keyof Settings>(k: K, v: Settings[K]) => setDraft((d) => ({ ...d, [k]: v }))
  const save = async () => {
    setSaving(true)
    setNotice(null)
    try {
      await api.put('/admin/settings/security', s)
      setDraft({})
      await refetch()
      setNotice({ ok: true, text: t('mgmt.security.saved') })
    } catch (e) {
      setNotice({ ok: false, text: errorMessage(e) })
    } finally {
      setSaving(false)
    }
  }

  return (
    <div className="space-y-6">
      <PageHeader
        title={<span className="flex items-center gap-3"><span className="grid size-11 place-items-center rounded-2xl bg-navy-900 text-gold-300"><ShieldCheck className="size-5" /></span>{t('mgmt.settings.sections.security.title')}</span>}
        subtitle={t('mgmt.security.subtitle')}
      />

      <div className="grid gap-6 lg:grid-cols-[1.3fr_1fr]">
        <Card>
          <label className="flex cursor-pointer items-start gap-4">
            <span className={clsx('relative mt-1 inline-flex h-8 w-14 shrink-0 items-center rounded-full p-0.5 transition-colors', s.idle_lock_enabled ? 'justify-end bg-emerald-500' : 'justify-start bg-slate-300')}>
              <input type="checkbox" role="switch" className="peer sr-only" checked={s.idle_lock_enabled} onChange={(e) => set('idle_lock_enabled', e.target.checked)} />
              <span className="inline-block size-7 rounded-full bg-white shadow-md transition-all peer-focus-visible:ring-4 peer-focus-visible:ring-gold-300" />
            </span>
            <span>
              <span className="flex items-center gap-2 text-lg font-bold text-navy-900"><LockKeyhole className="size-5 text-gold-600" />{t('mgmt.security.enable')}
                <span className={clsx('rounded-full px-2 py-0.5 text-xs font-bold', s.idle_lock_enabled ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500')}>{s.idle_lock_enabled ? t('admin.push.on') : t('admin.push.off')}</span>
              </span>
              <span className="mt-1 block max-w-xl text-sm leading-relaxed text-slate-500">{t('mgmt.security.enableHint')}</span>
            </span>
          </label>

          <div className={clsx('mt-6 transition', !s.idle_lock_enabled && 'pointer-events-none opacity-50')}>
            <div className="mb-2 flex items-center gap-2 text-sm font-bold text-navy-900"><Clock className="size-4 text-gold-600" />{t('mgmt.security.after')}</div>
            <div className="flex flex-wrap items-center gap-2">
              {PRESETS.map((m) => (
                <button key={m} type="button" aria-pressed={s.idle_lock_minutes === m} onClick={() => set('idle_lock_minutes', m)}
                  className={clsx('rounded-xl px-4 py-2 text-sm font-bold ring-1 ring-inset transition', s.idle_lock_minutes === m ? 'bg-navy-900 text-white ring-navy-900' : 'bg-white text-slate-600 ring-navy-100 hover:ring-gold-400')}>
                  {t('mgmt.security.minutes', { count: m })}
                </button>
              ))}
              <span className="flex items-center gap-2 text-sm text-slate-500">{t('mgmt.security.custom')}
                <input type="number" min={1} max={240} className="input !w-24" value={s.idle_lock_minutes} onChange={(e) => set('idle_lock_minutes', Math.max(1, Math.min(240, Number(e.target.value) || 1)))} />
              </span>
            </div>
            <p className="mt-3 text-xs text-slate-500">{t('mgmt.security.appliesTo')}</p>
          </div>

          <div className="mt-6 flex flex-wrap items-center gap-3">
            <Button variant="primary" icon={<Save className="size-4" />} loading={saving} disabled={!dirty} onClick={save}>{t('kits.common.save')}</Button>
            {notice && <span className={clsx('text-sm font-semibold', notice.ok ? 'text-emerald-700' : 'text-danger')}>{notice.ok && <CheckCircle2 className="me-1 inline size-4" />}{notice.text}</span>}
          </div>
        </Card>

        <Card className="bg-gradient-to-br from-navy-950 via-navy-900 to-navy-800 text-white">
          <div className="mb-3 flex items-center gap-2 text-sm font-bold text-gold-300"><Eye className="size-4" />{t('mgmt.security.preview')}</div>
          <div className="rounded-2xl bg-white/10 p-5 text-center backdrop-blur">
            <span className="mx-auto grid size-14 place-items-center rounded-full bg-gold-500 text-navy-950"><LockKeyhole className="size-7" /></span>
            <div className="mt-3 text-lg font-bold">{t('mgmt.lock.title')}</div>
            <p className="mt-1 text-xs text-white/70">{t('mgmt.lock.subtitle', { minutes: s.idle_lock_minutes })}</p>
            <div className="mt-4 rounded-xl bg-white/10 px-3 py-2.5 text-start text-xs text-white/50">{t('mgmt.lock.password')}</div>
            <div className="mt-3 rounded-xl bg-gold-500 py-2.5 text-sm font-bold text-navy-950">{t('mgmt.lock.unlock')}</div>
          </div>
        </Card>
      </div>
    </div>
  )
}
