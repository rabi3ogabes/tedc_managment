import clsx from 'clsx'
import { Building, CalendarClock, Globe2, GraduationCap, Landmark, Pencil, Plus, School, Search, Star, Trash2, UserRound, Users2 } from 'lucide-react'
import { useEffect, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Avatar, Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, Tabs } from '@/components/ui'
import { ChipSelect, TagInput } from '@/components/ui/Chips'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Paginated, Partner, Trainer, TrainerSource } from '@/lib/types'
import { Pager } from './shared'

type Options = {
  sources: { key: TrainerSource; ar: string; en: string; outside: boolean }[]
  partners: { id: string; name_ar: string; name_en: string; type: string; country: string }[]
  partner_types: string[]; countries: string[]; languages: string[]; specializations: string[]
  skills: { id: string; code: string; name_ar: string; name_en: string }[]
}
type Candidate = { employee_id: string; employee_no: string; name_ar: string | null; name_en: string | null; email: string | null; school_id: string | null; school: string | null; job_title: string | null; specialization: string | null; experience_years: number; already_trainer: boolean }
type Suggestion = { trainer: Trainer; score: number; available: boolean; matched_specializations: string[] }
type LookupSchools = { data: { schools: { id: string; name_ar: string; name_en: string }[] } }

const sourceIcon: Record<TrainerSource, typeof School> = { center: Building, school: School, ministry: Landmark, partner: Users2, external: UserRound, international: Globe2 }
const sourceTone: Record<TrainerSource, 'navy' | 'green' | 'gold' | 'blue' | 'amber' | 'red'> = { center: 'navy', school: 'green', ministry: 'gold', partner: 'blue', external: 'amber', international: 'red' }

function useLocaleName() {
  const { i18n } = useTranslation()
  return (row: { ar: string; en: string } | { name_ar: string; name_en: string }) => {
    const r = row as { ar?: string; en?: string; name_ar?: string; name_en?: string }
    return i18n.language === 'en' ? (r.en ?? r.name_en ?? '') : (r.ar ?? r.name_ar ?? '')
  }
}

function SourceBadge({ trainer }: { trainer: Trainer }) {
  const Icon = sourceIcon[trainer.source] ?? UserRound
  return <Badge color={sourceTone[trainer.source] ?? 'gray'}><Icon className="size-3" />{trainer.source_label}</Badge>
}

/** Add / edit form. The chosen source decides which details are needed. */
function TrainerForm({ trainer, options, onClose, onSaved }: { trainer: Trainer | null; options: Options; onClose: () => void; onSaved: (deactivated?: boolean) => void }) {
  const { t } = useTranslation()
  const name = useLocaleName()
  const schools = useGet<LookupSchools>('/admin/lookups')
  const [source, setSource] = useState<TrainerSource>(trainer?.source ?? 'center')
  const [photo, setPhoto] = useState<string | null>(null)
  const [form, setForm] = useState(() => ({
    name_ar: trainer?.name_ar ?? '', name_en: trainer?.name_en ?? '', title_ar: trainer?.title_ar ?? '', title_en: trainer?.title_en ?? '', email: trainer?.email ?? '', phone: trainer?.phone ?? '',
    school_id: trainer?.school_id ?? '', employee_id: trainer?.employee_id ?? '', partner_id: trainer?.partner_id ?? '', organization: trainer?.organization ?? '', country: trainer?.country ?? '', city: trainer?.city ?? '',
    experience_years: trainer ? String(trainer.experience_years ?? 0) : '', hourly_rate: trainer?.hourly_rate != null ? String(trainer.hourly_rate) : '', currency: trainer?.currency ?? 'QAR',
    bio_ar: trainer?.bio_ar ?? '', bio_en: trainer?.bio_en ?? '', notes: trainer?.notes ?? '', status: trainer?.status ?? 'active',
  }))
  const [specializations, setSpecializations] = useState<string[]>(trainer?.specializations ?? [])
  const [languages, setLanguages] = useState<string[]>(trainer?.languages ?? [])
  const [staffQuery, setStaffQuery] = useState('')
  const [candidates, setCandidates] = useState<Candidate[]>([])
  const [filled, setFilled] = useState(false)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const set = <K extends keyof typeof form>(key: K, value: (typeof form)[K]) => setForm((f) => ({ ...f, [key]: value }))

  // School trainers are picked from the school staff list.
  useEffect(() => {
    if (source !== 'school' || staffQuery.trim().length < 2) return
    const id = setTimeout(() => {
      api.get<{ data: Candidate[] }>('/admin/trainers/candidates', { params: { q: staffQuery, school_id: form.school_id || undefined } }).then((r) => setCandidates(r.data.data)).catch(() => setCandidates([]))
    }, 250)
    return () => clearTimeout(id)
  }, [staffQuery, source, form.school_id])

  const pick = (c: Candidate) => {
    setForm((f) => ({ ...f, employee_id: c.employee_id, school_id: c.school_id ?? f.school_id, name_ar: c.name_ar ?? f.name_ar, name_en: c.name_en ?? f.name_en, email: c.email ?? f.email, experience_years: String(c.experience_years ?? '') }))
    if (c.specialization && !specializations.includes(c.specialization)) setSpecializations([...specializations, c.specialization])
    setCandidates([])
    setStaffQuery(c.name_ar ?? c.name_en ?? '')
    setFilled(true)
  }

  const partner = options.partners.find((p) => p.id === form.partner_id)
  const needsSchool = source === 'school'
  const needsPartner = source === 'partner'
  const needsCountry = source === 'international'
  const valid = form.name_ar.trim() && form.name_en.trim() && (!needsSchool || form.school_id) && (!needsPartner || form.partner_id) && (!needsCountry || form.country.trim())

  const save = async () => {
    setSaving(true)
    setError(null)
    try {
      const body = {
        source, ...form,
        school_id: needsSchool ? form.school_id || null : null, employee_id: needsSchool ? form.employee_id || null : null, partner_id: needsPartner ? form.partner_id || null : null,
        organization: form.organization || null, country: form.country || null, city: form.city || null, title_ar: form.title_ar || null, title_en: form.title_en || null,
        email: form.email || null, phone: form.phone || null, bio_ar: form.bio_ar || null, bio_en: form.bio_en || null, notes: form.notes || null,
        experience_years: form.experience_years ? Number(form.experience_years) : 0, hourly_rate: form.hourly_rate ? Number(form.hourly_rate) : null, currency: form.currency || 'QAR',
        specializations, languages,
      }
      if (trainer) await api.put(`/admin/trainers/${trainer.id}`, body)
      else await api.post('/admin/trainers', body)
      onSaved()
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setSaving(false)
    }
  }

  const sourceHelp: Record<TrainerSource, ReactNode> = {
    center: null, school: t('mgmt.trainers.employeeHint'), ministry: null, partner: null, external: null, international: t('mgmt.trainers.internationalHint'),
  }

  return (
    <Modal open onClose={onClose} wide title={trainer ? t('mgmt.trainers.edit') : t('mgmt.trainers.new')}>
      <div className="space-y-6">
        {trainer && (
          <section className="flex items-center gap-4">
            <Avatar name={trainer.name} src={photo ?? trainer.photo_url} size={64} />
            <label className="btn inline-flex cursor-pointer items-center gap-2 border border-navy-100 bg-white px-3 py-2 text-sm font-semibold">
              {t('hlp.photo')}
              <input type="file" accept="image/png,image/jpeg,image/webp" className="sr-only" onChange={async (e) => {
                const file = e.target.files?.[0]; e.target.value = ''
                if (!file) return
                const body = new FormData(); body.append('photo', file)
                try { const { data } = await api.post(`/admin/trainers/${trainer.id}/photo`, body); setPhoto(data.data.photo_url ?? null); toast(String(t('hlp.photoSaved'))) } catch (err) { toast(errorMessage(err), 'error') }
              }} />
            </label>
          </section>
        )}
        <section>
          <div className="label">{t('mgmt.trainers.source')}</div>
          <div className="grid gap-2 sm:grid-cols-3">
            {options.sources.map((s) => {
              const Icon = sourceIcon[s.key]
              const on = source === s.key
              return (
                <button key={s.key} type="button" aria-pressed={on} onClick={() => setSource(s.key)} className={clsx('flex items-center gap-2.5 rounded-xl border px-3 py-2.5 text-start text-sm font-semibold transition', on ? 'border-navy-900 bg-navy-900 text-white shadow' : 'border-navy-100 bg-white text-navy-800 hover:border-gold-400')}>
                  <Icon className={clsx('size-5 shrink-0', on ? 'text-gold-300' : 'text-slate-400')} />{name(s)}
                </button>
              )
            })}
          </div>
          <p className="mt-1.5 text-xs text-slate-400">{t('mgmt.trainers.sourceHint')}</p>
        </section>

        {needsSchool && (
          <section className="space-y-3 rounded-2xl border border-emerald-200 bg-emerald-50/40 p-4">
            <Field label={t('mgmt.trainers.school')}>
              <select className="input" value={form.school_id} onChange={(e) => set('school_id', e.target.value)}>
                <option value="">{t('mgmt.trainers.schoolPick')}</option>
                {schools.data?.data.schools.map((s) => <option key={s.id} value={s.id}>{name(s)}</option>)}
              </select>
            </Field>
            <Field label={t('mgmt.trainers.employeePick')} hint={filled ? t('mgmt.trainers.filled') : t('mgmt.trainers.employeeHint')}>
              <div className="relative">
                <Search className="absolute start-3 top-3 size-4 text-slate-400" />
                <input className="input ps-9" placeholder={t('mgmt.trainers.employeeSearch')} value={staffQuery} onChange={(e) => { setStaffQuery(e.target.value); setFilled(false) }} />
              </div>
              {candidates.length > 0 && staffQuery.trim().length >= 2 && (
                <ul className="mt-1 max-h-48 overflow-y-auto rounded-xl border border-navy-100 bg-white text-sm shadow-glass">
                  {candidates.map((c) => (
                    <li key={c.employee_id}>
                      <button type="button" disabled={c.already_trainer} onClick={() => pick(c)} className="flex w-full items-center justify-between gap-3 px-3 py-2 text-start hover:bg-ivory disabled:opacity-50">
                        <span className="min-w-0"><span className="block truncate font-semibold text-navy-900">{c.name_ar ?? c.name_en}</span><span className="block truncate text-xs text-slate-500">{[c.job_title, c.specialization, c.school].filter(Boolean).join(' · ')}</span></span>
                        <span className="shrink-0 text-xs text-slate-400">{c.already_trainer ? t('mgmt.trainers.alreadyTrainer') : c.employee_no}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </Field>
          </section>
        )}

        {needsPartner && (
          <section className="rounded-2xl border border-sky-200 bg-sky-50/40 p-4">
            <Field label={t('mgmt.trainers.partner')}>
              <select className="input" value={form.partner_id} onChange={(e) => set('partner_id', e.target.value)}>
                <option value="">{t('mgmt.trainers.partnerPick')}</option>
                {options.partners.map((p) => <option key={p.id} value={p.id}>{name(p)} — {t(`mgmt.trainers.partnerTypes.${p.type}`, { defaultValue: p.type })}</option>)}
              </select>
            </Field>
            {partner && <p className="mt-2 text-xs text-slate-500">{t('mgmt.trainers.organization')}: <b>{name(partner)}</b> · {partner.country}</p>}
          </section>
        )}

        {(source === 'external' || source === 'international' || source === 'ministry') && (
          <section className="grid gap-4 rounded-2xl border border-amber-200 bg-amber-50/40 p-4 sm:grid-cols-3">
            <Field label={t('mgmt.trainers.organization')}><input className="input" value={form.organization} onChange={(e) => set('organization', e.target.value)} /></Field>
            {needsCountry && <Field label={t('mgmt.trainers.country')} hint={sourceHelp.international}><input className="input" list="trainer-countries" value={form.country} onChange={(e) => set('country', e.target.value)} /><datalist id="trainer-countries">{options.countries.filter((c) => c !== 'Qatar').map((c) => <option key={c} value={c} />)}</datalist></Field>}
            {needsCountry && <Field label={t('mgmt.trainers.city')}><input className="input" value={form.city} onChange={(e) => set('city', e.target.value)} /></Field>}
          </section>
        )}

        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('mgmt.trainers.nameAr')}><input dir="rtl" className="input" value={form.name_ar} onChange={(e) => set('name_ar', e.target.value)} /></Field>
          <Field label={t('mgmt.trainers.nameEn')}><input dir="ltr" className="input" value={form.name_en} onChange={(e) => set('name_en', e.target.value)} /></Field>
          <Field label={t('mgmt.trainers.titleAr')}><input dir="rtl" className="input" value={form.title_ar} onChange={(e) => set('title_ar', e.target.value)} /></Field>
          <Field label={t('mgmt.trainers.titleEn')}><input dir="ltr" className="input" value={form.title_en} onChange={(e) => set('title_en', e.target.value)} /></Field>
          <Field label={t('mgmt.trainers.email')}><input type="email" dir="ltr" className="input" value={form.email} onChange={(e) => set('email', e.target.value)} /></Field>
          <Field label={t('mgmt.trainers.phone')}><input dir="ltr" className="input" value={form.phone} onChange={(e) => set('phone', e.target.value)} /></Field>
          <Field label={t('mgmt.trainers.experience')}><input type="number" min={0} className="input" value={form.experience_years} onChange={(e) => set('experience_years', e.target.value)} /></Field>
          <div className="grid grid-cols-[1fr_6rem] gap-2">
            <Field label={t('mgmt.trainers.hourlyRate')}><input type="number" min={0} className="input" value={form.hourly_rate} onChange={(e) => set('hourly_rate', e.target.value)} /></Field>
            <Field label={t('mgmt.trainers.currency')}><input dir="ltr" maxLength={3} className="input uppercase" value={form.currency} onChange={(e) => set('currency', e.target.value.toUpperCase())} /></Field>
          </div>
        </div>
        <Field label={t('mgmt.trainers.specializations')} hint={t('mgmt.trainers.specializationsHint')}>
          <TagInput value={specializations} onChange={setSpecializations} placeholder={options.skills[0]?.code} />
          <div className="mt-2 flex flex-wrap gap-1">{options.skills.slice(0, 40).filter((s) => !specializations.includes(s.code)).slice(0, 10).map((s) => <button key={s.id} type="button" className="rounded-full bg-slate-100 px-2 py-0.5 text-[11px] text-slate-500 hover:bg-navy-100" dir="ltr" onClick={() => setSpecializations([...specializations, s.code])}>+ {s.code}</button>)}</div>
        </Field>
        <Field label={t('mgmt.trainers.languages')}><TagInput value={languages} onChange={setLanguages} placeholder="ar, en" /></Field>
        <div className="grid gap-4 sm:grid-cols-2">
          <Field label={t('mgmt.trainers.bio') + ' (ع)'}><textarea rows={2} dir="rtl" className="input" value={form.bio_ar} onChange={(e) => set('bio_ar', e.target.value)} /></Field>
          <Field label={t('mgmt.trainers.bio') + ' (EN)'}><textarea rows={2} dir="ltr" className="input" value={form.bio_en} onChange={(e) => set('bio_en', e.target.value)} /></Field>
        </div>
        <div className="grid gap-4 sm:grid-cols-[1fr_12rem]">
          <Field label={t('mgmt.trainers.notes')}><textarea rows={2} className="input" value={form.notes} onChange={(e) => set('notes', e.target.value)} /></Field>
          <Field label={t('mgmt.common.status')}><select className="input" value={form.status} onChange={(e) => set('status', e.target.value)}><option value="active">{t('mgmt.common.active')}</option><option value="inactive">{t('mgmt.common.inactive')}</option></select></Field>
        </div>

        {error && <div className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
        <div className="flex justify-end gap-2">
          <Button variant="ghost" onClick={onClose}>{t('mgmt.common.cancel')}</Button>
          <Button loading={saving} disabled={!valid} onClick={save}>{saving ? t('mgmt.common.saving') : t('mgmt.common.save')}</Button>
        </div>
      </div>
    </Modal>
  )
}

function ScheduleModal({ trainer, onClose }: { trainer: Trainer; onClose: () => void }) {
  const { t } = useTranslation()
  const [{ from, to }] = useState(() => ({ from: new Date().toISOString().slice(0, 10), to: new Date(Date.now() + 90 * 86_400_000).toISOString().slice(0, 10) }))
  const { data, isLoading } = useGet<{ data: { id: string; title: string; program?: string | null; starts_at: string; ends_at: string }[] }>(`/admin/trainers/${trainer.id}/schedule`, { from, to })
  return (
    <Modal open onClose={onClose} title={`${t('mgmt.trainers.schedule')} · ${trainer.name}`}>
      {isLoading ? <Spinner /> : !data?.data.length ? <Empty text={t('mgmt.trainers.noSessions')} /> : (
        <ul className="space-y-2">
          {data.data.map((s) => (
            <li key={s.id} className="flex items-center justify-between gap-3 rounded-xl border border-navy-100 px-3 py-2 text-sm">
              <div className="min-w-0"><div className="truncate font-semibold text-navy-900">{s.program ?? s.title}</div><div className="truncate text-xs text-slate-500">{s.title}</div></div>
              <div className="shrink-0 text-end text-xs text-slate-500"><div>{fmt.date(s.starts_at, { weekday: 'short', day: 'numeric', month: 'short' })}</div><div dir="ltr">{fmt.time(s.starts_at)} – {fmt.time(s.ends_at)}</div></div>
            </li>
          ))}
        </ul>
      )}
    </Modal>
  )
}

/** Ranks trainers for a topic (skill) and an optional slot. */
function Suggest({ options }: { options: Options }) {
  const { t, i18n } = useTranslation()
  const [skill, setSkill] = useState('')
  const [slot, setSlot] = useState({ starts_at: '', ends_at: '' })
  const [sources, setSources] = useState<string[]>([])
  const [results, setResults] = useState<Suggestion[] | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const run = async () => {
    setLoading(true)
    setError(null)
    try {
      const res = await api.get<{ data: Suggestion[] }>('/admin/trainers/suggest', { params: {
        skill_ids: skill ? [skill] : undefined, sources: sources.length ? sources : undefined,
        starts_at: slot.starts_at ? slot.starts_at.replace('T', ' ') : undefined, ends_at: slot.ends_at ? slot.ends_at.replace('T', ' ') : undefined,
      } })
      setResults(res.data.data)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setLoading(false)
    }
  }

  return (
    <Card>
      <h3 className="text-lg font-bold text-navy-900">{t('mgmt.trainers.suggestTitle')}</h3>
      <p className="mt-1 text-sm text-slate-500">{t('mgmt.trainers.suggestHint')}</p>
      <div className="mt-5 grid gap-4 md:grid-cols-3">
        <Field label={t('mgmt.trainers.skill')}>
          <select className="input" value={skill} onChange={(e) => setSkill(e.target.value)}>
            <option value="">—</option>
            {options.skills.map((s) => <option key={s.id} value={s.id}>{i18n.language === 'en' ? s.name_en : s.name_ar}</option>)}
          </select>
        </Field>
        <Field label={t('mgmt.rooms.startsAt')}><input type="datetime-local" className="input" value={slot.starts_at} onChange={(e) => setSlot({ ...slot, starts_at: e.target.value })} /></Field>
        <Field label={t('mgmt.rooms.endsAt')}><input type="datetime-local" className="input" value={slot.ends_at} onChange={(e) => setSlot({ ...slot, ends_at: e.target.value })} /></Field>
      </div>
      <div className="mt-4"><ChipSelect value={sources} onChange={setSources} options={options.sources.map((s) => ({ value: s.key, label: i18n.language === 'en' ? s.en : s.ar }))} /></div>
      <div className="mt-4 flex justify-end"><Button variant="gold" loading={loading} icon={<Search className="size-4" />} disabled={!skill} onClick={run}>{t('mgmt.trainers.findButton')}</Button></div>
      {error && <div className="mt-4 rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
      {results && (
        <div className="mt-6 grid gap-3 lg:grid-cols-2">
          {results.length === 0 && <div className="lg:col-span-2"><Empty text={t('mgmt.trainers.empty')} /></div>}
          {results.map((r) => (
            <article key={r.trainer.id} className={clsx('flex gap-3 rounded-2xl border p-4', r.available ? 'border-emerald-200 bg-emerald-50/40' : 'border-navy-100 bg-white')}>
              <Avatar name={r.trainer.name} src={r.trainer.photo_url} size={48} />
              <div className="min-w-0 flex-1">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0"><div className="truncate font-bold text-navy-900">{r.trainer.name}</div><div className="truncate text-xs text-slate-500">{r.trainer.organization ?? r.trainer.title}</div></div>
                  <div className="text-end"><div className="text-xl font-bold text-navy-900">{r.score}</div><div className="text-[11px] text-slate-400">{t('mgmt.trainers.match')}</div></div>
                </div>
                <div className="mt-2 flex flex-wrap gap-1.5"><SourceBadge trainer={r.trainer} /><Badge color={r.available ? 'green' : 'red'}>{r.available ? t('mgmt.trainers.free') : t('mgmt.trainers.busy')}</Badge><Badge color="gold"><Star className="size-3" />{fmt.number(r.trainer.rating, 1)}</Badge></div>
                {r.matched_specializations.length > 0 && <div className="mt-2 text-xs text-slate-500">{t('mgmt.trainers.matched')}: <span dir="ltr">{r.matched_specializations.join(', ')}</span></div>}
              </div>
            </article>
          ))}
        </div>
      )}
    </Card>
  )
}

function PartnerForm({ partner, options, onClose, onSaved }: { partner: Partner | null; options: Options; onClose: () => void; onSaved: () => void }) {
  const { t } = useTranslation()
  const [form, setForm] = useState({
    name_ar: partner?.name_ar ?? '', name_en: partner?.name_en ?? '', type: partner?.type ?? 'institution', country: partner?.country ?? 'Qatar', contact_name: partner?.contact_name ?? '',
    email: partner?.email ?? '', phone: partner?.phone ?? '', website: partner?.website ?? '', notes: partner?.notes ?? '', status: partner?.status ?? 'active',
  })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const set = (k: keyof typeof form, v: string) => setForm((f) => ({ ...f, [k]: v }))
  const save = async () => {
    setSaving(true)
    setError(null)
    try {
      const body = { ...form, contact_name: form.contact_name || null, email: form.email || null, phone: form.phone || null, website: form.website || null, notes: form.notes || null }
      if (partner) await api.put(`/admin/partners/${partner.id}`, body)
      else await api.post('/admin/partners', body)
      onSaved()
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setSaving(false)
    }
  }
  return (
    <Modal open onClose={onClose} wide title={partner ? t('mgmt.trainers.editPartner') : t('mgmt.trainers.newPartner')}>
      <div className="grid gap-4 sm:grid-cols-2">
        <Field label={t('mgmt.trainers.partnerNameAr')}><input dir="rtl" className="input" value={form.name_ar} onChange={(e) => set('name_ar', e.target.value)} /></Field>
        <Field label={t('mgmt.trainers.partnerNameEn')}><input dir="ltr" className="input" value={form.name_en} onChange={(e) => set('name_en', e.target.value)} /></Field>
        <Field label={t('mgmt.trainers.partnerType')}><select className="input" value={form.type} onChange={(e) => set('type', e.target.value)}>{options.partner_types.map((p) => <option key={p} value={p}>{t(`mgmt.trainers.partnerTypes.${p}`, { defaultValue: p })}</option>)}</select></Field>
        <Field label={t('mgmt.trainers.country')}><input className="input" value={form.country} onChange={(e) => set('country', e.target.value)} /></Field>
        <Field label={t('mgmt.trainers.contact')}><input className="input" value={form.contact_name} onChange={(e) => set('contact_name', e.target.value)} /></Field>
        <Field label={t('mgmt.trainers.email')}><input type="email" dir="ltr" className="input" value={form.email} onChange={(e) => set('email', e.target.value)} /></Field>
        <Field label={t('mgmt.trainers.phone')}><input dir="ltr" className="input" value={form.phone} onChange={(e) => set('phone', e.target.value)} /></Field>
        <Field label={t('mgmt.trainers.website')}><input type="url" dir="ltr" className="input" placeholder="https://" value={form.website} onChange={(e) => set('website', e.target.value)} /></Field>
        <Field label={t('mgmt.trainers.notes')} className="sm:col-span-2"><textarea rows={2} className="input" value={form.notes} onChange={(e) => set('notes', e.target.value)} /></Field>
        <Field label={t('mgmt.common.status')}><select className="input" value={form.status} onChange={(e) => set('status', e.target.value)}><option value="active">{t('mgmt.common.active')}</option><option value="inactive">{t('mgmt.common.inactive')}</option></select></Field>
      </div>
      {error && <div className="mt-4 rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>{t('mgmt.common.cancel')}</Button>
        <Button loading={saving} disabled={!form.name_ar.trim() || !form.name_en.trim()} onClick={save}>{saving ? t('mgmt.common.saving') : t('mgmt.common.save')}</Button>
      </div>
    </Modal>
  )
}

function Partners({ options, canManage, onChanged }: { options: Options; canManage: boolean; onChanged: () => void }) {
  const { t, i18n } = useTranslation()
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<Partner | null | 'new'>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const list = useGet<Paginated<Partner>>('/admin/partners', { page })

  const remove = async (p: Partner) => {
    if (!window.confirm(t('mgmt.common.confirmDelete'))) return
    try {
      const res = await api.delete(`/admin/partners/${p.id}`)
      setNotice(res.data?.meta?.deactivated ? t('mgmt.common.deactivated') : null)
      list.refetch()
      onChanged()
    } catch (e) {
      setNotice(errorMessage(e))
    }
  }

  return (
    <Card padded={false}>
      <div className="flex flex-wrap items-center justify-between gap-3 border-b border-navy-100 p-4">
        <h3 className="font-bold text-navy-900">{t('mgmt.trainers.partnersTitle')}</h3>
        {canManage && <Button size="sm" variant="gold" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>{t('mgmt.trainers.newPartner')}</Button>}
      </div>
      {notice && <div className="m-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{notice}</div>}
      <DataView id="admin.partners" rows={list.data?.data} total={list.data?.meta?.total} loading={list.isLoading} rowKey={(p) => p.id} defaultMode="cards" emptyText={t('mgmt.trainers.partnerEmpty')} columns={[
        { key: 'media', header: '', role: 'media', hideInTable: true, cell: () => <span className="grid size-10 place-items-center rounded-xl bg-navy-900 text-gold-300"><Landmark className="size-5" /></span> },
        { key: 'name', header: t('mgmt.common.name'), role: 'title', cell: (p) => (i18n.language === 'en' ? p.name_en : p.name_ar) },
        { key: 'type', header: t('mgmt.trainers.partnerType'), role: 'subtitle', cell: (p) => t(`mgmt.trainers.partnerTypes.${p.type}`, { defaultValue: p.type }) },
        { key: 'country', header: t('mgmt.trainers.country'), cell: (p) => p.country },
        { key: 'contact', header: t('mgmt.trainers.contact'), cell: (p) => [p.contact_name, p.email].filter(Boolean).join(' · ') || '—' },
        { key: 'count', header: t('mgmt.trainers.trainersCount'), cell: (p) => fmt.number(p.trainers_count ?? 0) },
        { key: 'status', header: t('mgmt.common.status'), role: 'badge', cell: (p) => <Badge color={p.status === 'active' ? 'green' : 'gray'}>{p.status === 'active' ? t('mgmt.common.active') : t('mgmt.common.inactive')}</Badge> },
        { key: 'actions', header: '', role: 'actions', cell: (p) => canManage ? (
          <div className="flex gap-1">
            <Button size="sm" variant="ghost" icon={<Pencil className="size-4" />} onClick={() => setEditing(p)}>{t('mgmt.common.edit')}</Button>
            <Button size="sm" variant="ghost" icon={<Trash2 className="size-4" />} aria-label={t('mgmt.common.delete')} onClick={() => remove(p)} />
          </div>
        ) : null },
      ]} />
      <Pager page={page} last={list.data?.meta?.last_page} onChange={setPage} />
      {editing && <PartnerForm partner={editing === 'new' ? null : editing} options={options} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); list.refetch(); onChanged() }} />}
    </Card>
  )
}

export default function Trainers() {
  const { t } = useTranslation()
  const name = useLocaleName()
  const { can } = useAuth()
  const canManage = can('trainers.manage')
  const [tab, setTab] = useState<'list' | 'suggest' | 'partners'>('list')
  const [filters, setFilters] = useState({ q: '', source: [] as string[], country: '', status: '', language: '', specialization: '' })
  const [page, setPage] = useState(1)
  const [editing, setEditing] = useState<Trainer | null | 'new'>(null)
  const [schedule, setSchedule] = useState<Trainer | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const options = useGet<{ data: Options }>('/admin/trainers/options')
  const list = useGet<Paginated<Trainer>>('/admin/trainers', { q: filters.q || undefined, source: filters.source.join(',') || undefined, country: filters.country || undefined, status: filters.status || undefined, language: filters.language || undefined, specialization: filters.specialization || undefined, page })
  const opts = options.data?.data
  const patch = (p: Partial<typeof filters>) => { setFilters((f) => ({ ...f, ...p })); setPage(1) }

  const remove = async (tr: Trainer) => {
    if (!window.confirm(t('mgmt.common.confirmDelete'))) return
    try {
      const res = await api.delete(`/admin/trainers/${tr.id}`)
      setNotice(res.data?.meta?.deactivated ? t('mgmt.trainers.deactivatedMsg') : null)
      list.refetch()
    } catch (e) {
      setNotice(errorMessage(e))
    }
  }

  return (
    <>
      <PageHeader title={t('mgmt.trainers.title')} subtitle={t('mgmt.trainers.subtitle')} actions={canManage && opts && tab !== 'partners' && <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setEditing('new')}>{t('mgmt.trainers.new')}</Button>} />
      <Tabs tabs={[{ id: 'list', label: t('mgmt.trainers.tabs.list') }, { id: 'suggest', label: t('mgmt.trainers.tabs.suggest') }, { id: 'partners', label: t('mgmt.trainers.tabs.partners') }]} value={tab} onChange={setTab} />
      {notice && <div className="mb-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{notice}</div>}

      {!opts ? <Spinner /> : tab === 'suggest' ? <Suggest options={opts} /> : tab === 'partners' ? <Partners options={opts} canManage={canManage} onChanged={() => options.refetch()} /> : (
        <Card padded={false}>
          <div className="space-y-3 border-b border-navy-100 p-4">
            <ChipSelect value={filters.source} onChange={(source) => patch({ source })} options={opts.sources.map((s) => ({ value: s.key, label: name(s) }))} />
            <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-5">
              <div className="relative xl:col-span-2"><Search className="absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" placeholder={t('mgmt.common.search')} value={filters.q} onChange={(e) => patch({ q: e.target.value })} /></div>
              <select className="input" aria-label={t('mgmt.trainers.country')} value={filters.country} onChange={(e) => patch({ country: e.target.value })}><option value="">{t('mgmt.trainers.countryFilter')}</option>{opts.countries.map((c) => <option key={c} value={c}>{c}</option>)}</select>
              <select className="input" aria-label={t('mgmt.trainers.specializationFilter')} value={filters.specialization} onChange={(e) => patch({ specialization: e.target.value })}><option value="">{t('mgmt.trainers.specializationFilter')}</option>{opts.specializations.map((c) => <option key={c} value={c}>{c}</option>)}</select>
              <select className="input" aria-label={t('mgmt.common.status')} value={filters.status} onChange={(e) => patch({ status: e.target.value })}><option value="">{t('mgmt.trainers.allStatuses')}</option><option value="active">{t('mgmt.common.active')}</option><option value="inactive">{t('mgmt.common.inactive')}</option></select>
            </div>
          </div>
          <DataView id="admin.trainers" rows={list.data?.data} total={list.data?.meta?.total} loading={list.isLoading} rowKey={(r) => r.id} defaultMode="cards" emptyText={t('mgmt.trainers.empty')} columns={[
            { key: 'media', header: '', role: 'media', hideInTable: true, cell: (r) => <Avatar name={r.name} src={r.photo_url} size={44} /> },
            { key: 'avatar', header: '', hideInCards: true, cell: (r) => <Avatar name={r.name} src={r.photo_url} size={34} /> },
            { key: 'name', header: t('mgmt.common.name'), role: 'title', cell: (r) => <div><div>{r.name}</div><div className="text-xs font-normal text-slate-500">{r.title}</div></div> },
            { key: 'source', header: t('mgmt.trainers.source'), role: 'badge', cell: (r) => <SourceBadge trainer={r} /> },
            { key: 'org', header: t('mgmt.trainers.organization'), role: 'subtitle', cell: (r) => r.school?.name ?? r.partner?.name ?? r.organization ?? r.country ?? '—' },
            { key: 'spec', header: t('mgmt.trainers.specializations'), cell: (r) => <div className="flex max-w-xs flex-wrap gap-1">{r.specializations.slice(0, 3).map((s) => <span key={s} dir="ltr" className="rounded-full bg-navy-100/70 px-2 py-0.5 text-[11px] font-semibold text-navy-800">{s}</span>)}{r.specializations.length > 3 && <span className="text-[11px] text-slate-400">+{r.specializations.length - 3}</span>}</div> },
            { key: 'country', header: t('mgmt.trainers.country'), cell: (r) => <span className="inline-flex items-center gap-1.5">{r.source === 'international' && <Globe2 className="size-4 text-slate-400" />}{r.country ?? '—'}</span> },
            { key: 'exp', header: t('mgmt.trainers.experience'), cell: (r) => <span className="inline-flex items-center gap-1.5"><GraduationCap className="size-4 text-slate-400" />{fmt.number(r.experience_years, 0)}</span> },
            { key: 'rating', header: t('mgmt.trainers.rating'), cell: (r) => <span className="inline-flex items-center gap-1 font-semibold text-gold-700"><Star className="size-4 fill-gold-400 text-gold-500" />{fmt.number(r.rating, 1)}</span> },
            { key: 'usage', header: t('mgmt.trainers.programs'), cell: (r) => <span className="text-slate-600">{fmt.number(r.programs_count ?? 0)} <span className="text-xs text-slate-400">/ {fmt.number(r.sessions_count ?? 0)} {t('mgmt.trainers.sessions')}</span></span> },
            { key: 'status', header: t('mgmt.common.status'), hideInCards: true, cell: (r) => <Badge color={r.status === 'active' ? 'green' : 'gray'}>{r.status === 'active' ? t('mgmt.common.active') : t('mgmt.common.inactive')}</Badge> },
            { key: 'actions', header: '', role: 'actions', cell: (r) => (
              <div className="flex flex-wrap gap-1">
                <Button size="sm" variant="outline" icon={<CalendarClock className="size-4" />} onClick={() => setSchedule(r)}>{t('mgmt.trainers.schedule')}</Button>
                {canManage && <Button size="sm" variant="ghost" icon={<Pencil className="size-4" />} onClick={() => setEditing(r)}>{t('mgmt.common.edit')}</Button>}
                {canManage && <Button size="sm" variant="ghost" icon={<Trash2 className="size-4" />} aria-label={t('mgmt.common.delete')} onClick={() => remove(r)} />}
              </div>
            ) },
          ]} />
          <Pager page={page} last={list.data?.meta?.last_page} onChange={setPage} />
        </Card>
      )}

      {editing && opts && <TrainerForm trainer={editing === 'new' ? null : editing} options={opts} onClose={() => setEditing(null)} onSaved={() => { setEditing(null); list.refetch(); options.refetch() }} />}
      {schedule && <ScheduleModal trainer={schedule} onClose={() => setSchedule(null)} />}
    </>
  )
}
