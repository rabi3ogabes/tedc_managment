/* eslint-disable @typescript-eslint/no-explicit-any */
import clsx from 'clsx'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Field } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api } from '@/lib/api'
import type { Paginated, Program } from '@/lib/types'

export const input = 'w-full rounded-xl border border-navy-100 px-3 py-2 text-sm'

export type AudienceFilter = {
  roles?: string[]; job_titles?: string[]; schools?: string[]; school_groups?: string[]; programs?: string[]; trainers?: boolean; supervisors?: boolean
}

export type Lookups = {
  job_titles: { id: string; name_ar: string; name_en: string }[]
  schools: { id: string; name_ar: string; name_en: string }[]
  school_groups: { id: string; name_ar: string; name_en: string }[]
  roles: { slug: string; name_ar: string; name_en: string }[]
  categories: { id: string; name_ar: string; name_en: string }[]
}

export function useLookups() {
  return useGet<{ data: Lookups }>('/admin/lookups', undefined, { staleTime: 5 * 60_000 }).data?.data
}

export const CHANNELS = ['push', 'email', 'sms'] as const

/** Checkboxes for push, e-mail and SMS. */
export function ChannelChecks({ value, onChange }: { value: string[]; onChange: (v: string[]) => void }) {
  const { t } = useTranslation()
  return (
    <div className="flex flex-wrap gap-3">
      {CHANNELS.map((c) => (
        <label key={c} className="flex items-center gap-2 text-sm">
          <input type="checkbox" className="accent-gold-600" checked={value.includes(c)} onChange={(e) => onChange(e.target.checked ? [...value, c] : value.filter((x) => x !== c))} />
          {t(`comm.channels.${c}`)}
        </label>
      ))}
    </div>
  )
}

/** Chips to pick several values from a list; the picked ones are highlighted. */
function Chips({ options, value, onChange }: { options: { id: string; label: string }[]; value: string[]; onChange: (v: string[]) => void }) {
  const [q, setQ] = useState('')
  const shown = options.filter((o) => !q || o.label.toLowerCase().includes(q.toLowerCase()))
  return (
    <div>
      {options.length > 8 && <input className={clsx(input, 'mb-2')} value={q} onChange={(e) => setQ(e.target.value)} placeholder="…" />}
      <div className="flex max-h-32 flex-wrap gap-1.5 overflow-y-auto">
        {shown.map((o) => {
          const on = value.includes(o.id)
          return (
            <button key={o.id} type="button" onClick={() => onChange(on ? value.filter((x) => x !== o.id) : [...value, o.id])}
              className={clsx('rounded-full px-3 py-1 text-xs font-semibold transition', on ? 'bg-navy-900 text-gold-300' : 'bg-navy-50 text-navy-700 hover:bg-navy-100')}>{o.label}</button>
          )
        })}
      </div>
    </div>
  )
}

/** Builds an audience from roles, job titles, schools, school groups and programs, and shows how many people it reaches while you choose. */
export function AudienceBuilder({ value, onChange, preview = true }: { value: AudienceFilter; onChange: (v: AudienceFilter) => void; preview?: boolean }) {
  const { t, i18n } = useTranslation()
  const lookups = useLookups()
  const programs = useGet<Paginated<Program>>('/admin/programs', { per_page: 100 }, { staleTime: 5 * 60_000 })
  const [result, setResult] = useState<{ count: number; sample: { id: string; name: string; school?: string | null }[] } | null>(null)
  const nm = (o: { name_ar: string; name_en: string }) => (i18n.language === 'ar' ? o.name_ar : o.name_en)
  const set = (patch: AudienceFilter) => onChange({ ...value, ...patch })

  useEffect(() => {
    if (!preview) return
    const id = window.setTimeout(() => {
      api.post('/admin/notifications/audience/preview', { audience: value }).then((r) => setResult(r.data.data)).catch(() => setResult(null))
    }, 400)
    return () => window.clearTimeout(id)
  }, [value, preview])

  return (
    <div className="space-y-3 rounded-2xl border border-navy-100 bg-ivory/60 p-4">
      <div className="flex items-center justify-between gap-3">
        <span className="font-bold text-navy-900">{t('comm.audience.title')}</span>
        {preview && result && (
          <span className={clsx('rounded-full px-3 py-1 text-xs font-bold', result.count ? 'bg-emerald-100 text-emerald-800' : 'bg-red-100 text-red-700')} aria-live="polite">
            {result.count === 0 ? t('comm.audience.nobody') : result.count === 1 ? t('comm.audience.reachesOne') : t('comm.audience.reaches', { n: result.count })}
          </span>
        )}
      </div>
      <p className="text-xs text-slate-500">{t('comm.audience.hint')}</p>
      <div className="grid gap-3 md:grid-cols-2">
        {lookups && (
          <>
            <Field label={t('comm.audience.roles')}><Chips options={lookups.roles.map((r) => ({ id: r.slug, label: nm(r) }))} value={value.roles ?? []} onChange={(v) => set({ roles: v })} /></Field>
            <Field label={t('comm.audience.jobTitles')}><Chips options={lookups.job_titles.map((r) => ({ id: r.id, label: nm(r) }))} value={value.job_titles ?? []} onChange={(v) => set({ job_titles: v })} /></Field>
            <Field label={t('comm.audience.schools')}><Chips options={lookups.schools.map((r) => ({ id: r.id, label: nm(r) }))} value={value.schools ?? []} onChange={(v) => set({ schools: v })} /></Field>
            <Field label={t('comm.audience.schoolGroups')}><Chips options={lookups.school_groups.map((r) => ({ id: r.id, label: nm(r) }))} value={value.school_groups ?? []} onChange={(v) => set({ school_groups: v })} /></Field>
          </>
        )}
        <Field label={t('comm.audience.programs')} className="md:col-span-2"><Chips options={(programs.data?.data ?? []).map((p) => ({ id: p.id, label: p.title }))} value={value.programs ?? []} onChange={(v) => set({ programs: v })} /></Field>
      </div>
      <div className="flex flex-wrap gap-4 text-sm">
        <label className="flex items-center gap-2"><input type="checkbox" className="accent-gold-600" checked={!!value.trainers} onChange={(e) => set({ trainers: e.target.checked })} />{t('comm.audience.trainers')}</label>
        <label className="flex items-center gap-2"><input type="checkbox" className="accent-gold-600" checked={!!value.supervisors} onChange={(e) => set({ supervisors: e.target.checked })} />{t('comm.audience.supervisors')}</label>
      </div>
      {preview && !!result?.sample.length && <p className="text-xs text-slate-500">{t('comm.audience.sample')}: {result.sample.map((s) => s.name).join('، ')}</p>}
    </div>
  )
}

/** "datetime-local" value ⇄ ISO. */
export const toLocalInput = (iso?: string | null) => {
  if (!iso) return ''
  const d = new Date(iso)
  const p = (n: number) => String(n).padStart(2, '0')
  return `${d.getFullYear()}-${p(d.getMonth() + 1)}-${p(d.getDate())}T${p(d.getHours())}:${p(d.getMinutes())}`
}
export const fromLocalInput = (v: string) => (v ? new Date(v).toISOString() : null)

export const statusColor = (s: string): 'navy' | 'gold' | 'green' | 'red' | 'gray' =>
  ({ read: 'green', delivered: 'green', sent: 'green', published: 'green', failed: 'red', cancelled: 'red', expired: 'gray', archived: 'gray', skipped: 'gray', queued: 'gold', scheduled: 'gold', sending: 'gold', draft: 'navy' } as Record<string, 'navy' | 'gold' | 'green' | 'red' | 'gray'>)[s] ?? 'navy'
