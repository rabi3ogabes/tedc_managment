import clsx from 'clsx'
import { Filter, Sparkles, UsersRound, X } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Card, Spinner } from '@/components/ui'
import { ChipSelect, SearchMulti } from '@/components/ui/Chips'
import { api } from '@/lib/api'
import { fmt } from '@/lib/format'
import { activeFilters, numberKeys } from './filters'
import type { Audience, AudiencePreview, BuilderOptions } from './types'

type Props = { audience: Audience; onChange: (next: Audience) => void; options: BuilderOptions['filters']; prefilled?: boolean; onPreview?: (p: AudiencePreview | null) => void }

function Section({ title, children }: { title: string; children: React.ReactNode }) {
  return (
    <section className="space-y-4 rounded-2xl border border-navy-100 bg-white p-4">
      <h4 className="text-sm font-bold text-navy-900">{title}</h4>
      {children}
    </section>
  )
}

function Group({ label, count, children }: { label: string; count?: number; children: React.ReactNode }) {
  return (
    <div>
      <div className="mb-1.5 flex items-center gap-2 text-xs font-semibold text-slate-500">{label}{!!count && <span className="rounded-full bg-gold-100 px-1.5 text-[10px] font-bold text-gold-700">{fmt.number(count)}</span>}</div>
      {children}
    </div>
  )
}

function Bars({ title, rows }: { title: string; rows: { label: string; value: number }[] }) {
  const max = Math.max(1, ...rows.map((r) => r.value))
  if (!rows.some((r) => r.value > 0)) return null
  return (
    <div>
      <div className="mb-1.5 text-xs font-bold text-slate-500">{title}</div>
      <ul className="space-y-1">
        {rows.filter((r) => r.value > 0).map((r) => (
          <li key={r.label} className="grid grid-cols-[minmax(0,8rem)_1fr_2rem] items-center gap-2 text-xs">
            <span className="truncate text-slate-600" title={r.label}>{r.label}</span>
            <span className="h-2 overflow-hidden rounded-full bg-navy-100/70"><span className="block h-full rounded-full bg-gradient-to-l from-gold-400 to-gold-600" style={{ width: `${(r.value / max) * 100}%` }} /></span>
            <span className="text-end font-semibold text-navy-900">{fmt.number(r.value)}</span>
          </li>
        ))}
      </ul>
    </div>
  )
}

/** The smart filters plus a live preview of who they reach. */
export default function AudienceBuilder({ audience, onChange, options, prefilled, onPreview }: Props) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const [preview, setPreview] = useState<AudiencePreview | null>(null)
  const [loading, setLoading] = useState(false)

  // Debounced live preview whenever a filter changes.
  const signature = JSON.stringify(audience)
  useEffect(() => {
    let cancelled = false
    setLoading(true)
    const id = setTimeout(() => {
      api.post<{ data: AudiencePreview }>('/admin/program-builder/audience/preview', { audience: JSON.parse(signature) })
        .then((r) => { if (!cancelled) { setPreview(r.data.data); onPreview?.(r.data.data) } })
        .catch(() => { if (!cancelled) { setPreview(null); onPreview?.(null) } })
        .finally(() => !cancelled && setLoading(false))
    }, 350)
    return () => { cancelled = true; clearTimeout(id) }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [signature])

  const set = <K extends keyof Audience>(key: K, value: Audience[K]) => {
    const next = { ...audience, [key]: value }
    if (Array.isArray(value) && value.length === 0) delete next[key]
    if (value === undefined) delete next[key]
    onChange(next)
  }
  const num = (key: (typeof numberKeys)[number], raw: string) => set(key, raw === '' ? undefined : Number(raw))
  const opt = (values: string[], label?: (v: string) => string) => values.map((v) => ({ value: v, label: label ? label(v) : v }))

  const specializationOptions = useMemo(() => opt(options.specializations), [options.specializations])
  const titles = useMemo(() => options.job_titles.map((j) => ({ value: j.id, label: en ? j.name_en : j.name_ar, hint: j.category })), [options.job_titles, en])
  const schools = useMemo(() => options.schools.map((s) => ({ value: s.id, label: en ? s.name_en : s.name_ar, hint: t(`regions.${s.region}`, { defaultValue: s.region }) })), [options.schools, en, t])
  const total = activeFilters(audience)

  const bandRange = (band: string): [number | undefined, number | undefined] => {
    if (band.startsWith('<')) return [undefined, Number(band.slice(1)) - 1]
    if (band.endsWith('+')) return [Number(band.slice(0, -1)), undefined]
    const [a, b] = band.split('-').map(Number)
    return [a, b]
  }
  const bandChip = (kind: 'age' | 'experience', band: string) => {
    const [lo, hi] = bandRange(band)
    const on = audience[`${kind}_min`] === lo && audience[`${kind}_max`] === hi
    return (
      <button key={band} type="button" aria-pressed={on} onClick={() => onChange((() => {
        const next = { ...audience }
        if (on) { delete next[`${kind}_min`]; delete next[`${kind}_max`] } else {
          if (lo === undefined) delete next[`${kind}_min`]; else next[`${kind}_min`] = lo
          if (hi === undefined) delete next[`${kind}_max`]; else next[`${kind}_max`] = hi
        }
        return next
      })())} className={clsx('rounded-full border px-3 py-1 text-xs font-semibold transition', on ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-slate-600 hover:border-gold-400')}>
        {t(`mgmt.wizard.audience.${kind === 'age' ? 'ageBandLabels' : 'expBandLabels'}.${band}`, { defaultValue: band })}
      </button>
    )
  }

  return (
    <div className="grid gap-5 xl:grid-cols-[minmax(0,1.15fr)_minmax(0,1fr)]">
      <div className="space-y-4">
        {prefilled && <div className="flex items-center gap-2 rounded-xl bg-gold-100/50 px-3 py-2 text-xs font-semibold text-gold-700"><Sparkles className="size-4" />{t('mgmt.wizard.audience.fromNeeds')}</div>}
        <Section title={t('mgmt.wizard.audience.groups.who')}>
          <Group label={t('mgmt.wizard.audience.jobTitles')} count={audience.job_title_ids?.length}><SearchMulti options={titles} value={audience.job_title_ids ?? []} onChange={(v) => set('job_title_ids', v)} placeholder={t('mgmt.common.search')} /></Group>
          <Group label={t('mgmt.wizard.audience.specializations')} count={audience.specializations?.length}><ChipSelect options={specializationOptions} value={audience.specializations ?? []} onChange={(v) => set('specializations', v)} /></Group>
          <div className="grid gap-4 sm:grid-cols-2">
            <Group label={t('mgmt.wizard.audience.genders')} count={audience.genders?.length}><ChipSelect options={opt(options.genders, (g) => t(`mgmt.wizard.audience.${g}`))} value={audience.genders ?? []} onChange={(v) => set('genders', v)} /></Group>
            <Group label={t('mgmt.wizard.audience.qualifications')} count={audience.qualifications?.length}><ChipSelect options={opt(options.qualifications, (q) => t(`mgmt.wizard.audience.qual.${q}`, { defaultValue: q }))} value={audience.qualifications ?? []} onChange={(v) => set('qualifications', v)} /></Group>
          </div>
          <Group label={t('mgmt.wizard.audience.nationalities')} count={audience.nationalities?.length}><ChipSelect options={opt(options.nationalities)} value={audience.nationalities ?? []} onChange={(v) => set('nationalities', v)} /></Group>
        </Section>

        <Section title={t('mgmt.wizard.audience.groups.level')}>
          <div className="grid gap-5 sm:grid-cols-2">
            <div className="space-y-2">
              <div className="text-xs font-semibold text-slate-500">{t('mgmt.wizard.audience.age')} <span className="text-slate-400">({t('mgmt.wizard.audience.years')})</span></div>
              <div className="grid grid-cols-2 gap-2">
                <input type="number" min={16} max={80} className="input" aria-label={`${t('mgmt.wizard.audience.age')} ${t('mgmt.wizard.audience.min')}`} placeholder={t('mgmt.wizard.audience.min')} value={audience.age_min ?? ''} onChange={(e) => num('age_min', e.target.value)} />
                <input type="number" min={16} max={80} className="input" aria-label={`${t('mgmt.wizard.audience.age')} ${t('mgmt.wizard.audience.max')}`} placeholder={t('mgmt.wizard.audience.max')} value={audience.age_max ?? ''} onChange={(e) => num('age_max', e.target.value)} />
              </div>
              <div className="flex flex-wrap gap-1.5">{options.age_bands.map((b) => bandChip('age', b))}</div>
            </div>
            <div className="space-y-2">
              <div className="text-xs font-semibold text-slate-500">{t('mgmt.wizard.audience.experience')} <span className="text-slate-400">({t('mgmt.wizard.audience.years')})</span></div>
              <div className="grid grid-cols-2 gap-2">
                <input type="number" min={0} max={60} className="input" aria-label={`${t('mgmt.wizard.audience.experience')} ${t('mgmt.wizard.audience.min')}`} placeholder={t('mgmt.wizard.audience.min')} value={audience.experience_min ?? ''} onChange={(e) => num('experience_min', e.target.value)} />
                <input type="number" min={0} max={60} className="input" aria-label={`${t('mgmt.wizard.audience.experience')} ${t('mgmt.wizard.audience.max')}`} placeholder={t('mgmt.wizard.audience.max')} value={audience.experience_max ?? ''} onChange={(e) => num('experience_max', e.target.value)} />
              </div>
              <div className="flex flex-wrap gap-1.5">{options.experience_bands.map((b) => bandChip('experience', b))}</div>
            </div>
          </div>
        </Section>

        <Section title={t('mgmt.wizard.audience.groups.where')}>
          <Group label={t('mgmt.wizard.audience.schools')} count={audience.school_ids?.length}><SearchMulti options={schools} value={audience.school_ids ?? []} onChange={(v) => set('school_ids', v)} placeholder={t('mgmt.wizard.audience.pickSchools')} /></Group>
          <div className="grid gap-4 sm:grid-cols-2">
            <Group label={t('mgmt.wizard.audience.regions')} count={audience.regions?.length}><ChipSelect options={opt(options.regions, (r) => t(`regions.${r}`, { defaultValue: r }))} value={audience.regions ?? []} onChange={(v) => set('regions', v)} /></Group>
            <Group label={t('mgmt.wizard.audience.schoolTypes')} count={audience.school_types?.length}><ChipSelect options={opt(options.school_types, (r) => t(`schoolTypes.${r}`, { defaultValue: r }))} value={audience.school_types ?? []} onChange={(v) => set('school_types', v)} /></Group>
          </div>
          <Group label={t('mgmt.wizard.audience.stages')} count={audience.stages?.length}><ChipSelect options={opt(options.stages, (r) => t(`stages.${r}`, { defaultValue: r }))} value={audience.stages ?? []} onChange={(v) => set('stages', v)} /></Group>
        </Section>
      </div>

      <div className="xl:sticky xl:top-4 xl:self-start">
        <Card className="space-y-5">
          <div className="flex items-start justify-between gap-3">
            <div className="flex items-center gap-3">
              <span className="grid size-12 place-items-center rounded-2xl bg-navy-900 text-gold-300"><UsersRound className="size-6" /></span>
              <div>
                <div className="text-3xl font-bold leading-none text-navy-900">{loading && !preview ? '…' : fmt.number(preview?.count ?? 0)}</div>
                <div className="mt-1 text-xs text-slate-500">{t('mgmt.wizard.audience.count')} · {fmt.number(preview?.schools ?? 0)} {t('mgmt.wizard.audience.reach')}</div>
              </div>
            </div>
            {total > 0 && <button type="button" onClick={() => onChange({})} className="inline-flex items-center gap-1 text-xs font-semibold text-slate-500 hover:text-navy-900"><X className="size-3.5" />{t('mgmt.wizard.audience.clear')}</button>}
          </div>
          <div className="flex items-start gap-2 rounded-xl bg-ivory p-3 text-xs text-slate-600"><Filter className="mt-0.5 size-4 shrink-0 text-gold-600" /><span>{total === 0 ? t('mgmt.wizard.audience.noFilters') : (preview?.summary ?? '…')}</span></div>
          {loading && !preview ? <Spinner /> : preview && preview.count === 0 ? (
            <p className="rounded-xl bg-red-50 p-3 text-sm text-danger">{t('mgmt.wizard.audience.empty')}</p>
          ) : preview && (
            <div className={clsx('space-y-4 transition-opacity', loading && 'opacity-50')}>
              <Bars title={t('mgmt.wizard.audience.byAge')} rows={preview.by_age} />
              <Bars title={t('mgmt.wizard.audience.byExperience')} rows={preview.by_experience} />
              <Bars title={t('mgmt.wizard.audience.bySpecialization')} rows={preview.by_specialization} />
              <Bars title={t('mgmt.wizard.audience.bySchool')} rows={preview.by_school.slice(0, 5)} />
              <div>
                <div className="mb-1.5 text-xs font-bold text-slate-500">{t('mgmt.wizard.audience.sample')}</div>
                <ul className="divide-y divide-navy-100/70 rounded-xl border border-navy-100 text-xs">
                  {preview.sample.map((p) => (
                    <li key={p.id} className="flex items-center justify-between gap-3 px-3 py-2">
                      <span className="min-w-0"><span className="block truncate font-semibold text-navy-900">{p.name}</span><span className="block truncate text-slate-500">{[p.job_title, p.specialization, p.school].filter(Boolean).join(' · ')}</span></span>
                      <span className="shrink-0 text-end text-slate-500"><span className="block">{p.age != null ? `${fmt.number(p.age)} ${t('mgmt.wizard.audience.years')}` : '—'}</span><span className="block">{fmt.number(p.experience_years, 0)} {t('mgmt.wizard.audience.years')}</span></span>
                    </li>
                  ))}
                </ul>
              </div>
            </div>
          )}
        </Card>
      </div>
    </div>
  )
}
