import clsx from 'clsx'
import { Check, CheckCircle2, Copy, Loader2, Search, Send, Users } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { SurveyDialog } from '@/components/surveys/SurveyForm'
import { Button } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import type { Audience, Survey, SurveySettings } from '@/lib/surveys'
import { Toggle } from './QuestionEditor'

type Named = { id: string; name_ar: string; name_en: string; region?: string }
type Lookups = { schools: Named[]; job_titles: Named[]; regions: string[]; school_types: string[]; stages: string[] }
type Options = { specializations: string[]; nationalities: string[]; qualifications: string[]; stages: string[]; genders: string[]; experience_bands: string[] }
type Bar = { label: string; value: number }
export type Preview = { count: number; schools: number; by_school: Bar[]; by_job_title: Bar[]; by_nationality: Bar[]; by_specialization: Bar[]; by_experience: Bar[] }

export function useAudiencePreview(audience: Audience, enabled: boolean) {
  const [preview, setPreview] = useState<Preview | null>(null)
  const [loading, setLoading] = useState(false)
  const key = JSON.stringify(audience)
  useEffect(() => {
    if (!enabled) return
    let cancelled = false
    setLoading(true)
    const timer = setTimeout(async () => {
      try {
        const { data } = await api.post('/admin/needs-surveys/audience/preview', { audience: JSON.parse(key) })
        if (!cancelled) setPreview(data.data)
      } finally { if (!cancelled) setLoading(false) }
    }, 350)
    return () => { cancelled = true; clearTimeout(timer) }
  }, [key, enabled])
  return { preview, loading }
}

function Chips({ label, options, value = [], onChange }: { label: string; options: { value: string; label: string }[]; value?: string[]; onChange: (v: string[]) => void }) {
  const { t } = useTranslation()
  const toggle = (v: string) => onChange(value.includes(v) ? value.filter((x) => x !== v) : [...value, v])
  return (
    <div>
      <div className="mb-2 flex items-center justify-between"><span className="text-sm font-bold text-navy-900">{label}</span>{value.length > 0 && <button onClick={() => onChange([])} className="text-xs text-slate-400 hover:text-red-600">{t('surveys.audience.clear')}</button>}</div>
      {options.length ? (
        <div className="flex flex-wrap gap-1.5">
          {options.map((o) => {
            const on = value.includes(o.value)
            return <button type="button" key={o.value} onClick={() => toggle(o.value)} className={clsx('inline-flex items-center gap-1 rounded-full border px-3 py-1 text-xs font-semibold transition', on ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-slate-600 hover:border-gold-400')}>{on && <Check className="size-3" />}{o.label}</button>
          })}
        </div>
      ) : <p className="text-xs text-slate-400">{t('surveys.audience.noValues')}</p>}
    </div>
  )
}

function MiniBars({ title, rows }: { title: string; rows: Bar[] }) {
  const max = Math.max(1, ...rows.map((r) => r.value))
  if (!rows.length) return null
  return (
    <div>
      <div className="mb-2 text-xs font-bold text-white/60">{title}</div>
      <div className="space-y-1.5">
        {rows.slice(0, 5).map((r) => (
          <div key={r.label} className="text-xs">
            <div className="mb-0.5 flex justify-between gap-2 text-white/80"><span className="truncate">{r.label}</span><span className="font-bold text-white">{fmt.number(r.value)}</span></div>
            <div className="h-1.5 rounded-full bg-white/10"><div className="h-full rounded-full bg-gradient-to-l from-gold-300 to-gold-500" style={{ width: `${(r.value / max) * 100}%` }} /></div>
          </div>
        ))}
      </div>
    </div>
  )
}

export function AudienceDialog({ open, value, onClose, onApply }: { open: boolean; value: Audience; onClose: () => void; onApply: (a: Audience) => void }) {
  const { t, i18n } = useTranslation()
  const [a, setA] = useState<Audience>(value)
  const [schoolQuery, setSchoolQuery] = useState('')
  useEffect(() => { if (open) setA(value) }, [open, value])
  const lookups = useGet<{ data: Lookups }>(open ? '/admin/lookups' : null, undefined, { staleTime: 10 * 60_000 })
  const options = useGet<{ data: Options }>(open ? '/admin/needs-surveys/audience/options' : null, undefined, { staleTime: 5 * 60_000 })
  const { preview, loading } = useAudiencePreview(a, open)
  const nm = (o: Named) => (i18n.language === 'ar' ? o.name_ar : o.name_en)
  const L = lookups.data?.data
  const O = options.data?.data
  const set = (patch: Partial<Audience>) => setA((x) => ({ ...x, ...patch }))
  const schools = (L?.schools ?? []).filter((s) => !schoolQuery || s.name_ar.includes(schoolQuery) || s.name_en.toLowerCase().includes(schoolQuery.toLowerCase()))
  const bands: [string, number | null, number | null][] = [['0-2', 0, 2], ['3-5', 3, 5], ['6-10', 6, 10], ['11-15', 11, 15], ['16+', 16, null]]

  return (
    <SurveyDialog open={open} onClose={onClose} size="xl" label={t('surveys.audience.title')}>
      <div className="grid min-h-0 flex-1 lg:grid-cols-[1fr_320px]">
        <div className="min-h-0 space-y-6 overflow-y-auto p-6 sm:p-8">
          <div>
            <h2 className="font-display text-2xl font-bold text-navy-900">{t('surveys.audience.title')}</h2>
            <p className="mt-1 text-sm text-slate-500">{t('surveys.audience.subtitle')}</p>
          </div>
          {!L ? <Loader2 className="size-6 animate-spin text-gold-600" /> : (
            <>
              <div className="grid gap-6 md:grid-cols-2">
                <Chips label={t('surveys.audience.jobTitles')} options={L.job_titles.map((j) => ({ value: j.id, label: nm(j) }))} value={a.job_title_ids} onChange={(v) => set({ job_title_ids: v })} />
                <Chips label={t('surveys.audience.specializations')} options={(O?.specializations ?? []).map((s) => ({ value: s, label: s }))} value={a.specializations} onChange={(v) => set({ specializations: v })} />
                <Chips label={t('surveys.audience.nationalities')} options={(O?.nationalities ?? []).map((s) => ({ value: s, label: t(`surveys.audience.nationality.${s}`, { defaultValue: s }) }))} value={a.nationalities} onChange={(v) => set({ nationalities: v })} />
                <div>
                  <span className="mb-2 block text-sm font-bold text-navy-900">{t('surveys.audience.experience')}</span>
                  <div className="flex flex-wrap gap-1.5">
                    {bands.map(([label, min, max]) => {
                      const on = (a.experience_min ?? null) === min && (a.experience_max ?? null) === max
                      return <button type="button" key={label} onClick={() => set(on ? { experience_min: null, experience_max: null } : { experience_min: min, experience_max: max })} className={clsx('rounded-full border px-3 py-1 text-xs font-semibold transition', on ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-slate-600 hover:border-gold-400')} dir="ltr">{label}</button>
                    })}
                  </div>
                  <div className="mt-2 flex items-center gap-2 text-xs text-slate-500">
                    {t('surveys.audience.min')}<input type="number" min={0} className="input w-20 py-1 text-xs" value={a.experience_min ?? ''} onChange={(e) => set({ experience_min: e.target.value === '' ? null : Number(e.target.value) })} />
                    {t('surveys.audience.max')}<input type="number" min={0} className="input w-20 py-1 text-xs" value={a.experience_max ?? ''} onChange={(e) => set({ experience_max: e.target.value === '' ? null : Number(e.target.value) })} />
                    {t('surveys.audience.years')}
                  </div>
                </div>
                <Chips label={t('surveys.audience.regions')} options={L.regions.map((r) => ({ value: r, label: t(`regions.${r}`) }))} value={a.regions} onChange={(v) => set({ regions: v })} />
                <Chips label={t('surveys.audience.schoolTypes')} options={L.school_types.map((r) => ({ value: r, label: t(`schoolTypes.${r}`) }))} value={a.school_types} onChange={(v) => set({ school_types: v })} />
                <Chips label={t('surveys.audience.stages')} options={(O?.stages.length ? O.stages : L.stages).map((r) => ({ value: r, label: t(`stages.${r}`, { defaultValue: r }) }))} value={a.stages} onChange={(v) => set({ stages: v })} />
                <Chips label={t('surveys.audience.qualifications')} options={(O?.qualifications ?? []).map((q) => ({ value: q, label: t(`surveys.audience.qualification.${q}`, { defaultValue: q }) }))} value={a.qualifications} onChange={(v) => set({ qualifications: v })} />
                <Chips label={t('surveys.audience.genders')} options={['male', 'female'].map((g) => ({ value: g, label: t(`surveys.audience.gender.${g}`) }))} value={a.genders} onChange={(v) => set({ genders: v })} />
              </div>
              <div>
                <div className="mb-2 flex items-center justify-between"><span className="text-sm font-bold text-navy-900">{t('surveys.audience.schools')} {a.school_ids?.length ? <span className="text-gold-700">({a.school_ids.length})</span> : null}</span>{!!a.school_ids?.length && <button onClick={() => set({ school_ids: [] })} className="text-xs text-slate-400 hover:text-red-600">{t('surveys.audience.clear')}</button>}</div>
                <label className="relative mb-2 block"><Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" /><input className="input ps-9" placeholder={t('surveys.audience.searchSchools')} value={schoolQuery} onChange={(e) => setSchoolQuery(e.target.value)} /></label>
                <div className="grid max-h-56 gap-1 overflow-y-auto rounded-2xl border border-navy-100 bg-white p-2 sm:grid-cols-2">
                  {schools.map((s) => {
                    const on = a.school_ids?.includes(s.id) ?? false
                    return (
                      <label key={s.id} className={clsx('flex cursor-pointer items-center gap-2 rounded-lg px-2 py-1.5 text-sm transition', on ? 'bg-gold-100/50 font-semibold text-navy-900' : 'text-slate-600 hover:bg-ivory')}>
                        <input type="checkbox" className="accent-gold-600" checked={on} onChange={() => set({ school_ids: on ? a.school_ids!.filter((x) => x !== s.id) : [...(a.school_ids ?? []), s.id] })} />
                        <span className="truncate">{nm(s)}</span>{s.region && <span className="ms-auto shrink-0 text-[10px] text-slate-400">{t(`regions.${s.region}`)}</span>}
                      </label>
                    )
                  })}
                </div>
              </div>
            </>
          )}
        </div>
        <aside className="flex flex-col gap-5 bg-gradient-to-b from-navy-950 to-navy-900 p-6 text-white sm:p-8">
          <div className="flex items-center gap-2 text-sm font-semibold text-gold-300"><Users className="size-4" />{t('surveys.audience.composition')}</div>
          <div>
            <div className="flex items-end gap-2"><span className="font-display text-5xl font-bold text-white">{preview ? fmt.number(preview.count) : '—'}</span>{loading && <Loader2 className="mb-2 size-5 animate-spin text-gold-300" />}</div>
            <div className="mt-1 text-sm text-white/60">{t('surveys.audience.matched')} · {t('surveys.audience.inSchools', { n: preview?.schools ?? 0 })}</div>
          </div>
          {preview && (
            <div className="min-h-0 flex-1 space-y-5 overflow-y-auto">
              <MiniBars title={t('surveys.audience.byJob')} rows={preview.by_job_title} />
              <MiniBars title={t('surveys.audience.byExperience')} rows={preview.by_experience} />
              <MiniBars title={t('surveys.audience.byNationality')} rows={preview.by_nationality.map((r) => ({ ...r, label: t(`surveys.audience.nationality.${r.label}`, { defaultValue: r.label }) }))} />
              <MiniBars title={t('surveys.audience.bySpecialization')} rows={preview.by_specialization} />
            </div>
          )}
          <Button variant="gold" size="lg" onClick={() => { onApply(a); onClose() }}>{t('surveys.audience.apply')}</Button>
        </aside>
      </div>
    </SurveyDialog>
  )
}

const ACCENTS = ['#8A1538', '#A29475', '#0E7490', '#9D174D', '#A16207', '#1E3A8A', '#047857', '#334155']

export function SettingsDialog({ open, survey, onClose, onApply }: { open: boolean; survey: Survey; onClose: () => void; onApply: (settings: SurveySettings, closesAt: string | null) => void }) {
  const { t } = useTranslation()
  const [s, setS] = useState<SurveySettings>(survey.settings)
  const [closes, setCloses] = useState(survey.closes_at?.slice(0, 10) ?? '')
  useEffect(() => { if (open) { setS(survey.settings); setCloses(survey.closes_at?.slice(0, 10) ?? '') } }, [open, survey.settings, survey.closes_at])
  return (
    <SurveyDialog open={open} onClose={onClose} size="md" label={t('surveys.builder.settings')}>
      <div className="space-y-5 overflow-y-auto p-6 sm:p-8">
        <h2 className="font-display text-2xl font-bold text-navy-900">{t('surveys.builder.settings')}</h2>
        <div className="space-y-3 rounded-2xl border border-navy-100 bg-white p-4">
          <Toggle checked={!!s.anonymous} onChange={(v) => setS({ ...s, anonymous: v })} label={t('surveys.settingsModal.anonymous')} />
          <p className="ps-14 text-xs text-slate-400">{t('surveys.settingsModal.anonymousHint')}</p>
          <Toggle checked={s.show_progress !== false} onChange={(v) => setS({ ...s, show_progress: v })} label={t('surveys.settingsModal.progress')} />
          <br />
          <Toggle checked={!!s.one_per_page} onChange={(v) => setS({ ...s, one_per_page: v })} label={t('surveys.settingsModal.onePerPage')} />
        </div>
        <label className="block"><span className="label">{t('surveys.settingsModal.closesAt')}</span><input type="date" className="input" value={closes} onChange={(e) => setCloses(e.target.value)} /></label>
        <label className="block"><span className="label">{t('surveys.settingsModal.welcome')}</span><textarea className="input" rows={3} value={s.welcome ?? ''} onChange={(e) => setS({ ...s, welcome: e.target.value || undefined })} /></label>
        <label className="block"><span className="label">{t('surveys.settingsModal.thankYou')}</span><textarea className="input" rows={2} value={s.thank_you ?? ''} onChange={(e) => setS({ ...s, thank_you: e.target.value || undefined })} /></label>
        <div>
          <span className="label">{t('surveys.settingsModal.accent')}</span>
          <div className="flex flex-wrap gap-2">{ACCENTS.map((c) => <button type="button" key={c} aria-label={c} onClick={() => setS({ ...s, accent: c })} className={clsx('size-9 rounded-full ring-offset-2 transition hover:scale-110', (s.accent ?? '#8A1538') === c && 'ring-2 ring-navy-900')} style={{ background: c }} />)}</div>
        </div>
        <div className="flex justify-end gap-2"><Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button><Button variant="gold" onClick={() => { onApply(s, closes || null); onClose() }}>{t('common.save')}</Button></div>
      </div>
    </SurveyDialog>
  )
}

export function PublishDialog({ open, survey, onClose, onPublished }: { open: boolean; survey: Survey; onClose: () => void; onPublished: (s: Survey) => void }) {
  const { t } = useTranslation()
  const { preview, loading } = useAudiencePreview(survey.audience, open)
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [sent, setSent] = useState<number | null>(null)
  const [copied, setCopied] = useState(false)
  useEffect(() => { if (open) { setSent(null); setError(null) } }, [open])
  const link = `${window.location.origin}/portal/surveys?needs=${survey.id}`
  const publish = async () => {
    setBusy(true); setError(null)
    try {
      const { data } = await api.post(`/admin/needs-surveys/${survey.id}/publish`)
      setSent(data.added)
      onPublished(data.data)
    } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  const copy = async () => { await navigator.clipboard?.writeText(link); setCopied(true); setTimeout(() => setCopied(false), 1500) }
  return (
    <SurveyDialog open={open} onClose={onClose} size="md" label={t('surveys.publishModal.title')}>
      <div className="overflow-y-auto p-6 text-center sm:p-10">
        {sent === null ? (
          <>
            <div className="mx-auto grid size-16 place-items-center rounded-2xl bg-gradient-to-br from-gold-400 to-gold-600 text-white shadow-lg"><Send className="size-7" /></div>
            <h2 className="mt-5 font-display text-2xl font-bold text-navy-900">{t('surveys.publishModal.title')}</h2>
            <p className="mt-2 text-sm text-slate-500">{t('surveys.publishModal.text')}</p>
            <div className="my-6 font-display text-5xl font-bold text-navy-900">{loading || !preview ? <Loader2 className="mx-auto size-8 animate-spin text-gold-600" /> : fmt.number(preview.count)}</div>
            <div className="text-sm text-slate-500">{t('surveys.publishModal.people')} · {survey.audience_summary}</div>
            {preview?.count === 0 && <p className="mt-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-700">{t('surveys.publishModal.noAudience')}</p>}
            {error && <p className="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}
            <div className="mt-8 flex justify-center gap-2"><Button variant="outline" onClick={onClose}>{t('common.cancel')}</Button><Button variant="gold" loading={busy} disabled={!preview?.count} icon={<Send className="size-4" />} onClick={publish}>{t('surveys.publishModal.confirm')}</Button></div>
          </>
        ) : (
          <>
            <div className="mx-auto grid size-16 place-items-center rounded-full bg-emerald-500 text-white shadow-lg"><CheckCircle2 className="size-8" /></div>
            <h2 className="mt-5 font-display text-2xl font-bold text-navy-900">{t('surveys.publishModal.sent')}</h2>
            <p className="mt-2 text-sm text-slate-500">{sent ? t('surveys.publishModal.sentText', { n: sent }) : t('surveys.publishModal.noneNew')}</p>
            <div className="mt-5 flex items-center gap-2 rounded-xl border border-navy-100 bg-white p-2 text-start">
              <code className="min-w-0 flex-1 truncate px-2 text-xs text-slate-500" dir="ltr">{link}</code>
              <Button size="sm" variant="outline" icon={copied ? <Check className="size-3.5" /> : <Copy className="size-3.5" />} onClick={copy}>{copied ? t('surveys.actions.copied') : t('surveys.actions.copyLink')}</Button>
            </div>
            <div className="mt-6"><Button variant="primary" onClick={onClose}>{t('common.close')}</Button></div>
          </>
        )}
      </div>
    </SurveyDialog>
  )
}
