import clsx from 'clsx'
import { Award, CalendarClock, Check, Globe2, Link2, Sparkles, Video } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Badge, Button, Card, Field, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import PageCanvas, { useTemplateFile } from '../certificates/PageCanvas'
import type { CertTemplate, TemplateMeta } from '../certificates/types'
import type { Audience, BuilderOptions } from './types'

export type RemoteSettings = { platform: string; join_url: string; passcode: string; join_opens_minutes: string; instructions_ar: string; instructions_en: string }
export const emptyRemote: RemoteSettings = { platform: 'zoom', join_url: '', passcode: '', join_opens_minutes: '15', instructions_ar: '', instructions_en: '' }
export const PLATFORMS = ['zoom', 'teams', 'meet', 'webex', 'other'] as const

/** Who the program is for: every category, or chosen job categories (teachers, leaders ...). Maps onto the audience's job titles. */
export function TargetCategories({ audience, onChange, options }: { audience: Audience; onChange: (a: Audience) => void; options: BuilderOptions['filters'] }) {
  const { t } = useTranslation()
  const categories = useMemo(() => {
    const map = new Map<string, string[]>()
    options.job_titles.forEach((j) => map.set(j.category, [...(map.get(j.category) ?? []), j.id]))
    return [...map.entries()].map(([key, ids]) => ({ key, ids }))
  }, [options.job_titles])
  const chosen = audience.job_title_ids ?? []
  const all = chosen.length === 0
  const isOn = (ids: string[]) => ids.every((id) => chosen.includes(id))
  const toggle = (ids: string[]) => {
    const next = isOn(ids) ? chosen.filter((id) => !ids.includes(id)) : [...new Set([...chosen, ...ids])]
    onChange({ ...audience, job_title_ids: next.length ? next : undefined })
  }

  return (
    <Card className="space-y-3">
      <div className="flex items-center gap-2"><Globe2 className="size-5 text-gold-600" /><h3 className="font-bold text-navy-900">{t('studio.target.title')}</h3></div>
      <p className="text-sm text-slate-500">{t('studio.target.hint')}</p>
      <div className="flex flex-wrap gap-2">
        <button type="button" aria-pressed={all} onClick={() => onChange({ ...audience, job_title_ids: undefined })} className={clsx('inline-flex items-center gap-1.5 rounded-full border px-4 py-1.5 text-sm font-semibold transition', all ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-slate-600 hover:border-gold-400')}>{all && <Check className="size-4" />}{t('studio.target.all')}</button>
        {categories.map((c) => {
          const on = isOn(c.ids)
          return <button key={c.key} type="button" aria-pressed={on} onClick={() => toggle(c.ids)} className={clsx('inline-flex items-center gap-1.5 rounded-full border px-4 py-1.5 text-sm font-semibold transition', on ? 'border-gold-500 bg-gold-100 text-navy-900' : 'border-navy-100 bg-white text-slate-600 hover:border-gold-400')}>{on && <Check className="size-4" />}{t(`studio.target.categories.${c.key}`, { defaultValue: c.key })}<span className="text-xs font-normal text-slate-400">{c.ids.length}</span></button>
        })}
      </div>
    </Card>
  )
}

/** Remote delivery: platform, the meeting link, when joining opens and how attendance is recorded. */
export function DeliveryStep({ value, onChange }: { value: RemoteSettings; onChange: (v: RemoteSettings) => void }) {
  const { t } = useTranslation()
  const set = <K extends keyof RemoteSettings>(k: K, v: RemoteSettings[K]) => onChange({ ...value, [k]: v })
  const urlOk = value.join_url === '' || /^https?:\/\/\S+$/i.test(value.join_url)

  return (
    <div className="grid gap-5 lg:grid-cols-[minmax(0,1.3fr)_minmax(0,1fr)]">
      <Card className="space-y-5">
        <div>
          <div className="label">{t('studio.delivery.platform')}</div>
          <div className="grid grid-cols-2 gap-2 sm:grid-cols-5">
            {PLATFORMS.map((p) => (
              <button key={p} type="button" aria-pressed={value.platform === p} onClick={() => set('platform', p)} className={clsx('flex flex-col items-center gap-1.5 rounded-xl border p-3 text-xs font-semibold transition', value.platform === p ? 'border-navy-900 bg-navy-900 text-white shadow' : 'border-navy-100 bg-white text-navy-800 hover:border-gold-400')}>
                <Video className={clsx('size-5', value.platform === p ? 'text-gold-300' : 'text-gold-600')} />{t(`studio.delivery.platforms.${p}`)}
              </button>
            ))}
          </div>
        </div>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('studio.delivery.link')} hint={urlOk ? t('studio.delivery.linkHint') : <span className="text-danger">{t('studio.delivery.linkInvalid')}</span>} className="sm:col-span-2"><div className="relative"><Link2 className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input dir="ltr" type="url" placeholder="https://" className={clsx('input !ps-9', !urlOk && 'border-danger')} value={value.join_url} onChange={(e) => set('join_url', e.target.value.trim())} /></div></Field>
          <Field label={t('studio.delivery.passcode')}><input dir="ltr" className="input font-mono" value={value.passcode} onChange={(e) => set('passcode', e.target.value)} /></Field>
          <Field label={t('studio.delivery.opens')} hint={t('studio.delivery.opensHint')}><input type="number" min={0} max={240} className="input" value={value.join_opens_minutes} onChange={(e) => set('join_opens_minutes', e.target.value)} /></Field>
          <Field label={t('studio.delivery.instructionsAr')}><textarea rows={3} dir="rtl" className="input" value={value.instructions_ar} onChange={(e) => set('instructions_ar', e.target.value)} /></Field>
          <Field label={t('studio.delivery.instructionsEn')}><textarea rows={3} dir="ltr" className="input" value={value.instructions_en} onChange={(e) => set('instructions_en', e.target.value)} /></Field>
        </div>
      </Card>
      <Card className="h-fit space-y-3 bg-navy-900 text-white">
        <div className="flex items-center gap-2 text-gold-300"><Sparkles className="size-5" /><h3 className="font-bold">{t('studio.delivery.howTitle')}</h3></div>
        <ol className="space-y-3 text-sm text-white/85">
          {(t('studio.delivery.how', { returnObjects: true }) as unknown as string[]).map((line, i) => (
            <li key={i} className="flex gap-3"><span className="grid size-6 shrink-0 place-items-center rounded-full bg-gold-500 text-xs font-bold text-navy-950">{fmt.number(i + 1)}</span><span>{line}</span></li>
          ))}
        </ol>
      </Card>
    </div>
  )
}

function Option({ tpl, sample, on, onPick }: { tpl: CertTemplate; sample: Record<string, string>; on: boolean; onPick: () => void }) {
  const { t } = useTranslation()
  const bg = useTemplateFile(tpl.has_background ? tpl.id : null, 'background', tpl.updated_at)
  return (
    <button type="button" aria-pressed={on} onClick={onPick} className={clsx('overflow-hidden rounded-2xl border bg-white text-start transition', on ? 'border-gold-500 ring-2 ring-gold-100' : 'border-navy-100 hover:border-gold-300')}>
      <div className="bg-slate-100 p-3"><PageCanvas widthMm={tpl.width_mm} heightMm={tpl.height_mm} elements={tpl.elements} background={bg} values={sample} className="pointer-events-none shadow" /></div>
      <div className="flex items-center justify-between gap-2 p-3"><span className="min-w-0 truncate text-sm font-bold text-navy-900">{tpl.name}</span>{tpl.is_default ? <Badge color="green">{t('studio.list.default')}</Badge> : on && <Check className="size-4 text-gold-600" />}</div>
    </button>
  )
}

/** Picks the certificate designs of the program: one for trainees, one for trainers. An empty choice uses the default design. */
export function CertificatesStep({ trainee, trainer, setTrainee, setTrainer }: { trainee: string; trainer: string; setTrainee: (id: string) => void; setTrainer: (id: string) => void }) {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: CertTemplate[]; meta: TemplateMeta }>('/admin/certificate-templates')
  if (isLoading || !data) return <Spinner />

  const section = (kind: 'trainee' | 'trainer', value: string, set: (id: string) => void) => {
    const rows = data.data.filter((r) => r.kind === kind)
    const fallback = rows.find((r) => r.is_default)
    return (
      <Card className="space-y-4">
        <div className="flex items-center gap-2"><Award className="size-5 text-gold-600" /><h3 className="font-bold text-navy-900">{t(`studio.certs.${kind}`)}</h3></div>
        <p className="text-sm text-slate-500">{t(`studio.certs.${kind}Hint`)}</p>
        <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
          {rows.map((r) => <Option key={r.id} tpl={r} sample={data.meta.sample} on={(value || fallback?.id) === r.id} onPick={() => set(r.is_default ? '' : r.id)} />)}
        </div>
      </Card>
    )
  }

  return (
    <div className="space-y-5">
      <div className="flex flex-wrap items-center justify-between gap-3 rounded-xl bg-gold-100/40 px-4 py-2.5 text-sm text-navy-900">
        <span>{t('studio.certs.rules')}</span>
        <Link to="/admin/certificate-templates" target="_blank" className="font-semibold underline">{t('studio.certs.manage')}</Link>
      </div>
      {section('trainee', trainee, setTrainee)}
      {section('trainer', trainer, setTrainer)}
    </div>
  )
}

type Slot = { date: string; time: string; starts_at: string; ends_at: string; busy: number; free: number; free_percent: number }

/** Best times for the online sessions: scored by how many people of the audience are free at that hour. */
export function SmartSlots({ audience, hours, from, onPick }: { audience: Audience; hours: number; from: string; onPick: (slot: Slot) => void }) {
  const { t } = useTranslation()
  const [slots, setSlots] = useState<Slot[] | null>(null)
  const [size, setSize] = useState(0)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const load = async () => {
    setBusy(true)
    setError(null)
    try {
      const res = await api.post<{ data: { audience: number; slots: Slot[] } }>('/admin/program-builder/slots', { audience, hours, from: from || new Date().toISOString().slice(0, 10), days: 14 })
      setSlots(res.data.data.slots)
      setSize(res.data.data.audience)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="rounded-2xl border border-gold-300 bg-gold-100/30 p-4">
      <div className="flex flex-wrap items-center justify-between gap-3">
        <div className="flex items-center gap-2"><CalendarClock className="size-5 text-gold-600" /><div><div className="font-bold text-navy-900">{t('studio.slots.title')}</div><div className="text-xs text-slate-500">{t('studio.slots.hint')}</div></div></div>
        <Button variant="outline" size="sm" loading={busy} icon={<Sparkles className="size-4" />} onClick={load}>{t('studio.slots.suggest')}</Button>
      </div>
      {error && <div className="mt-3 rounded-lg bg-red-50 p-2 text-sm text-danger">{error}</div>}
      {slots && (
        <div className="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
          {slots.map((s) => (
            <button key={s.starts_at} type="button" onClick={() => onPick(s)} className="rounded-xl border border-navy-100 bg-white p-3 text-start transition hover:border-gold-500">
              <div className="text-sm font-bold text-navy-900">{fmt.date(s.date, { weekday: 'short', day: 'numeric', month: 'short' })}</div>
              <div className="font-mono text-sm text-navy-800" dir="ltr">{s.time}</div>
              <div className={clsx('mt-1 text-xs font-semibold', s.free_percent >= 95 ? 'text-emerald-700' : s.free_percent >= 80 ? 'text-amber-700' : 'text-danger')}>{size > 0 ? t('studio.slots.free', { percent: fmt.number(s.free_percent), busy: fmt.number(s.busy) }) : t('studio.slots.noAudience')}</div>
            </button>
          ))}
        </div>
      )}
    </div>
  )
}
