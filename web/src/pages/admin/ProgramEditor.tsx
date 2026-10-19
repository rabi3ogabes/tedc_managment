import clsx from 'clsx'
import { AlertTriangle, Fingerprint, Languages, Lock, LockOpen, Mic, Plus, Trash2 } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { Button, Card, CardTitle, Field, PageHeader, Spinner, SaveDock } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { Switch } from './notifications/shared'
import { api, errorMessage } from '@/lib/api'
import { toDateInput, toDateTimeInput } from '@/lib/format'
import type { Program } from '@/lib/types'
import { useProgramTypes } from './plans/programTypes'
import { useAuth } from '@/lib/auth'
import SchedulePlanner, { type PlannerState } from './program/SchedulePlanner'
import { EMPTY_KITS, type KitsState } from './program/kitsState'
import GroupsCard, { newDraft, type GroupDraft } from './program/GroupsCard'
import ProgramKits from './program/ProgramKits'
import VoiceComposer, { type ParsedRequest } from './program/VoiceComposer'

type Lookups = {
  categories: { id: string; name_ar: string; name_en: string; program_type?: string | null }[]
  coordinators?: { id: string; name: string; name_ar: string | null }[]
  skills: { id: string; name_ar: string; name_en: string; category: string }[]
  job_titles: { id: string; name_ar: string; name_en: string }[]
  trainers: { id: string; name_ar: string; name_en: string }[]
  school_types: string[]
  weekend?: number[]
  stages: string[]
}

const MODES = ['self', 'school_nomination', 'center_nomination', 'bulk_import']

const empty = {
  code: '', category_id: '', program_type: '', coordinator_id: '', title_ar: '', title_en: '', summary_ar: '', summary_en: '', description_ar: '', description_en: '',
  objectives: [] as string[], delivery_mode: 'in_person', level: 'intermediate', total_hours: 12, capacity: 30, min_attendance_percent: 80, require_biometric: false,
  requires_tasks: true, requires_evaluation: true, start_date: '', end_date: '', registration_opens_at: '', registration_closes_at: '',
  registration_modes: MODES, status: 'draft', is_featured: false,
  skills: [] as { id: string; target_level: number }[], trainers: [] as { id: string; role: string }[],
  audience: { genders: [] as string[], experience_min: '' as string | number, experience_max: '' as string | number },
  target_groups: [] as { job_title_id?: string | null; school_type?: string | null; education_stage?: string | null; description?: string | null }[],
}

export default function ProgramEditor() {
  const { id } = useParams()
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const lookups = useGet<{ data: Lookups }>('/admin/lookups')
  const existing = useGet<{ data: Program }>(id ? `/admin/programs/${id}` : null)
  const [form, setForm] = useState(empty)
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  // Most programmes are Arabic only: the English fields stay out of the way until the person asks for them.
  const [english, setEnglish] = useState(false)
  const [voice, setVoice] = useState(false)
  const [applied, setApplied] = useState<string | null>(null)
  // The generated session plan of a new programme's first group (kept here so it is saved with the form).
  const { can } = useAuth()
  const [plan, setPlan] = useState<PlannerState>({ enabled: true, sessions: [], errors: 0, blocking: 0, closedDays: 0, first: null, last: null })
  const [approval, setApproval] = useState('')
  // The training kits chosen for the programme and the instruments generated from them (sent on a new programme always, on an existing one only once changed).
  const [kits, setKits] = useState<KitsState>(EMPTY_KITS)
  const [kitsTouched, setKitsTouched] = useState(false)
  // The programme's groups (a new programme saves them with it; an existing one saves each change at once).
  const [groupRows, setGroupRows] = useState<GroupDraft[]>(() => [newDraft()])
  const saved = useGet<{ data: { kits: { id: string }[]; instruments: { kind: keyof KitsState['instruments']; enabled: boolean; auto_hours?: number | null }[] } }>(id ? `/admin/programs/${id}/instruments` : null, undefined, { staleTime: 0 })
  useEffect(() => {
    const d = saved.data?.data
    if (!d) return
    const on = (k: string) => d.instruments.find((i) => i.kind === k)?.enabled ?? true
    setKits({ kitIds: d.kits.map((k) => k.id), instruments: { trainee_survey: on('trainee_survey'), trainer_reflection: on('trainer_reflection'), pre_test: on('pre_test'), post_test: on('post_test'), survey_auto_hours: d.instruments.find((i) => i.kind === 'trainee_survey')?.auto_hours ?? 24 } })
  }, [saved.data])
  // A new programme's code is stamped from its type (LIC-00001 …) unless the person unlocks the field and types one.
  const [codeLocked, setCodeLocked] = useState(true)
  const nextCode = useGet<{ data: { code: string; prefix: string } }>(!id && codeLocked ? '/admin/programs/next-code' : null, { program_type: form.program_type || undefined, category_id: form.category_id || undefined }, { staleTime: 0 })
  useEffect(() => { if (!id && codeLocked && nextCode.data) setForm((f) => (f.code === nextCode.data!.data.code ? f : { ...f, code: nextCode.data!.data.code })) }, [id, codeLocked, nextCode.data])
  const topRef = useRef<HTMLDivElement>(null)
  const name = (o: { name_ar: string; name_en: string }) => (i18n.language === 'ar' ? o.name_ar : o.name_en)
  const busy = !id && plan.enabled ? plan.sessions.flatMap((s) => s.flags.filter((f) => f.code === 'trainer_busy').map((f) => ({ flag: i18n.language === 'en' ? f.en : f.ar, date: s.starts_at.slice(0, 10) }))) : []

  useEffect(() => {
    const p = existing.data?.data
    if (!p) return
    setForm({
      ...empty,
      ...Object.fromEntries(Object.entries(p).filter(([k]) => k in empty)),
      category_id: p.category_id ?? '', program_type: (p as { program_type?: string | null }).program_type ?? '', coordinator_id: (p as { coordinator_id?: string | null }).coordinator_id ?? '',
      summary_ar: p.summary_ar ?? '', summary_en: p.summary_en ?? '', description_ar: p.description_ar ?? '', description_en: p.description_en ?? '',
      start_date: toDateInput(p.start_date), end_date: toDateInput(p.end_date),
      registration_opens_at: toDateTimeInput(p.registration_opens_at), registration_closes_at: toDateTimeInput(p.registration_closes_at),
      skills: (p.skills ?? []).map((s) => ({ id: s.id, target_level: s.target_level ?? 3 })),
      trainers: (p.trainers ?? []).map((tr) => ({ id: tr.id, role: tr.role ?? 'lead' })),
      audience: { genders: ((p as { audience?: { genders?: string[] } }).audience?.genders ?? []) as string[], experience_min: (p as { audience?: { experience_min?: number } }).audience?.experience_min ?? '', experience_max: (p as { audience?: { experience_max?: number } }).audience?.experience_max ?? '' },
      target_groups: (p.target_groups ?? []).map(({ job_title_id, school_type, education_stage, description }) => ({ job_title_id, school_type, education_stage, description })),
    } as typeof empty)
    setEnglish(Boolean((p.title_en && p.title_en !== p.title_ar) || p.summary_en || p.description_en))
  }, [existing.data])

  if (lookups.isLoading || (id && existing.isLoading)) return <Spinner />
  const L = lookups.data!.data
  const set = <K extends keyof typeof form>(k: K, v: (typeof form)[K]) => setForm((f) => ({ ...f, [k]: v }))
  const input = (k: keyof typeof form, type = 'text') => (
    <input className="input" type={type} value={String(form[k] ?? '')} onChange={(e) => set(k, (type === 'number' ? Number(e.target.value) : e.target.value) as never)} />
  )

  const save = async () => {
    setSaving(true)
    setError(null)
    const audience = { ...(form.target_groups.some((g) => g.job_title_id) ? { job_title_ids: form.target_groups.map((g) => g.job_title_id).filter(Boolean) } : {}), ...(form.audience.genders.length ? { genders: form.audience.genders } : {}), ...(form.audience.experience_min !== '' ? { experience_min: Number(form.audience.experience_min) } : {}), ...(form.audience.experience_max !== '' ? { experience_max: Number(form.audience.experience_max) } : {}) }
    const sessions = !id && plan.enabled ? plan.sessions.map((s) => ({ starts_at: s.starts_at, ends_at: s.ends_at, training_room_id: s.training_room_id, title_ar: s.title_ar, title_en: s.title_en, mode: s.mode })) : []
    const payload = { ...form, ...(!id ? { groups: groupRows.map((g) => ({ title_ar: g.title_ar.trim() || null, title_en: english ? g.title_en.trim() || null : null })) } : {}), ...(!id || kitsTouched ? { kit_ids: kits.kitIds, instruments: kits.instruments } : {}), ...(sessions.length ? { sessions, calendar_approval_reason: approval || undefined } : {}), audience, title_en: english ? form.title_en : '', summary_en: english ? form.summary_en : '', description_en: english ? form.description_en : '', category_id: form.category_id || null, program_type: form.program_type || null, coordinator_id: form.coordinator_id || null, start_date: form.start_date || null, end_date: form.end_date || null, registration_opens_at: form.registration_opens_at || null, registration_closes_at: form.registration_closes_at || null }
    try {
      const { data } = id ? await api.put(`/admin/programs/${id}`, payload) : await api.post('/admin/programs', payload)
      navigate(`/admin/programs/${data.data.id}`)
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <PageHeader title={id ? t('admin.programs.edit') : t('admin.programs.new')} subtitle={!id ? t('admin.programs.voice.lead') : undefined}
        actions={!id && <Button variant="gold" icon={<Mic className="size-4" />} onClick={() => setVoice(true)}>{t('admin.programs.voice.open')}</Button>} />
      {!id && <VoiceComposer open={voice} onClose={() => setVoice(false)} lookups={{ job_titles: L.job_titles, trainers: L.trainers }} onApply={(r: ParsedRequest) => {
        const p = r.program
        setForm((f) => ({ ...f, code: p.code, title_ar: p.title_ar, title_en: p.title_en, summary_ar: p.summary_ar, summary_en: p.summary_en, delivery_mode: p.delivery_mode, level: p.level, total_hours: p.total_hours, capacity: p.capacity, start_date: p.start_date ?? '',
          trainers: p.trainers, target_groups: p.target_groups.map((g) => ({ job_title_id: g.job_title_id })), audience: { genders: (p.audience.genders as string[] | undefined) ?? [], experience_min: (p.audience.experience_min as number | undefined) ?? '', experience_max: (p.audience.experience_max as number | undefined) ?? '' } }))
        if (p.english && p.title_en) setEnglish(true)
        setApplied(r.understood[i18n.language === 'en' ? 'en' : 'ar'])
        topRef.current?.scrollIntoView({ behavior: 'smooth', block: 'start' })
      }} />}
      <div ref={topRef} />
      {applied && <div className="mb-4 flex items-start gap-3 rounded-2xl border border-gold-200 bg-gold-50/70 p-4 text-sm"><Mic className="mt-0.5 size-4 shrink-0 text-gold-700" /><div><div className="font-bold text-navy-900">{t('admin.programs.voice.appliedTitle')}</div><div className="text-slate-700">{applied}</div><div className="mt-1 text-xs text-slate-500">{t('admin.programs.voice.appliedHint')}</div></div><button type="button" className="ms-auto text-xs text-slate-400 hover:text-navy-900" onClick={() => setApplied(null)}>{t('common.close')}</button></div>}
      <SaveDock label={t('common.save')} loading={saving} onClick={save} disabled={!id && plan.enabled && (plan.blocking > 0 || (plan.closedDays > 0 && approval.trim().length < 3))} />
      {error && <div className="mb-4 rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
      <div className="grid gap-6 xl:grid-cols-3">
        <div className="space-y-6 xl:col-span-2">
          <Card>
            <CardTitle>{t('admin.programs.basics')}</CardTitle>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label={t('admin.programs.code')} hint={!id ? (codeLocked ? t('admin.programs.codeAutoHint') : t('admin.programs.codeManualHint')) : undefined}>
                <div className="flex gap-2">
                  <input className={clsx('input font-mono', codeLocked && 'bg-ivory text-navy-900')} dir="ltr" readOnly={codeLocked} value={form.code} onChange={(e) => set('code', e.target.value)} />
                  <button type="button" className="inline-flex shrink-0 items-center gap-1 rounded-xl border border-navy-100 px-3 text-xs font-semibold text-slate-600 hover:border-gold-400 hover:text-navy-900" onClick={() => setCodeLocked(!codeLocked)} title={String(t(codeLocked ? 'admin.programs.codeUnlock' : 'admin.programs.codeLock'))}>{codeLocked ? <Lock className="size-3.5" /> : <LockOpen className="size-3.5" />}{t(codeLocked ? 'admin.programs.codeAuto' : 'admin.programs.codeManual')}</button>
                </div>
              </Field>
              <ProgramTypeField value={form.program_type} onChange={(v) => set('program_type', v)} inherited={L.categories.find((c) => c.id === form.category_id)?.program_type ?? null} />
              <Field label={t('lobby.coordinator')}>
                <select className="input" value={form.coordinator_id} onChange={(e) => set('coordinator_id', e.target.value)}>
                  <option value="">—</option>{(L.coordinators ?? []).map((u) => <option key={u.id} value={u.id}>{u.name_ar || u.name}</option>)}
                </select>
              </Field>
              <Field label={t('admin.programs.titleAr')}>{input('title_ar')}</Field>
              <div className={clsx('flex items-center gap-3 rounded-2xl border px-4 py-3 sm:col-span-2', english ? 'border-gold-300 bg-gold-50/60' : 'border-navy-100 bg-ivory')}>
                <span className="grid size-10 shrink-0 place-items-center rounded-xl bg-navy-900 text-gold-300"><Languages className="size-5" /></span>
                <div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{t('admin.programs.englishToggle')}</div><p className="text-xs text-slate-500">{t('admin.programs.englishHint')}</p></div>
                <Switch checked={english} label={t('admin.programs.englishToggle')} onChange={setEnglish} />
              </div>
              {english && <Field label={t('admin.programs.titleEn')} className="sm:col-span-2"><input className="input" dir="ltr" value={form.title_en} onChange={(e) => set('title_en', e.target.value)} /></Field>}
              <Field label={t('admin.programs.summaryAr')} className="sm:col-span-2">{input('summary_ar')}</Field>
              {english && <Field label={t('admin.programs.summaryEn')} className="sm:col-span-2"><input className="input" dir="ltr" value={form.summary_en} onChange={(e) => set('summary_en', e.target.value)} /></Field>}
              <Field label={t('admin.programs.descriptionAr')} className="sm:col-span-2"><textarea className="input min-h-28" value={form.description_ar} onChange={(e) => set('description_ar', e.target.value)} /></Field>
              {english && <Field label={t('admin.programs.descriptionEn')} className="sm:col-span-2"><textarea className="input min-h-28" dir="ltr" value={form.description_en} onChange={(e) => set('description_en', e.target.value)} /></Field>}
              <Field label={t('programs.objectives')} className="sm:col-span-2" hint="↵">
                <textarea className="input min-h-24" value={form.objectives.join('\n')} onChange={(e) => set('objectives', e.target.value.split('\n'))} />
              </Field>
            </div>
          </Card>

          <Card>
            <CardTitle>{t('admin.programs.schedule')}</CardTitle>
            <div className="grid gap-4 sm:grid-cols-3">
              <Field label={t('programs.mode')}><select className="input" value={form.delivery_mode} onChange={(e) => set('delivery_mode', e.target.value)}>{['in_person', 'online', 'hybrid'].map((m) => <option key={m} value={m}>{t(`modes.${m}`)}</option>)}</select></Field>
              <Field label={t('programs.level')}><select className="input" value={form.level} onChange={(e) => set('level', e.target.value)}>{['beginner', 'intermediate', 'advanced'].map((m) => <option key={m} value={m}>{t(`levels.${m}`)}</option>)}</select></Field>
              <Field label={t('common.status')}><select className="input" value={form.status} onChange={(e) => set('status', e.target.value)}>{['draft', 'published', 'registration_open', 'in_progress', 'completed', 'archived', 'cancelled'].map((m) => <option key={m} value={m}>{t(`status.${m}`)}</option>)}</select></Field>
              <Field label={t('admin.programs.hours')}>{input('total_hours', 'number')}</Field>
              <Field label={t('admin.programs.capacity')}>{input('capacity', 'number')}</Field>
              <Field label={t('admin.programs.minAttendance')}>{input('min_attendance_percent', 'number')}</Field>
              <Field label={t('admin.programs.startDate')}>{input('start_date', 'date')}</Field>
              <Field label={t('admin.programs.endDate')}>{input('end_date', 'date')}</Field>
              <div />
              <Field label={t('admin.programs.opensAt')}>{input('registration_opens_at', 'datetime-local')}</Field>
              <Field label={t('admin.programs.closesAt')}>{input('registration_closes_at', 'datetime-local')}</Field>
            </div>
            <div className={clsx('mt-5 flex items-center gap-4 rounded-2xl border p-4', form.require_biometric ? 'border-gold-300 bg-gold-50' : 'border-navy-100 bg-ivory')}>
              <span className="grid size-11 shrink-0 place-items-center rounded-xl bg-navy-900 text-gold-300"><Fingerprint className="size-5" /></span>
              <div className="min-w-0 flex-1"><div className="font-bold text-navy-900">{t('admin.programs.biometric')}</div><p className="text-xs text-slate-500">{t('admin.programs.biometricHint')}</p></div>
              <Switch checked={form.require_biometric} label={t('admin.programs.biometric')} onChange={(v) => set('require_biometric', v)} />
            </div>
            <div className="mt-5 flex flex-wrap gap-5">
              {(['requires_tasks', 'requires_evaluation', 'is_featured'] as const).map((k) => (
                <label key={k} className="flex items-center gap-2 text-sm font-medium text-navy-800">
                  <input type="checkbox" className="size-4 accent-gold-600" checked={form[k]} onChange={(e) => set(k, e.target.checked)} />
                  {t(`admin.programs.${k === 'is_featured' ? 'featured' : k === 'requires_tasks' ? 'requiresTasks' : 'requiresEvaluation'}`)}
                </label>
              ))}
            </div>
            <div className="mt-5">
              <span className="label">{t('admin.programs.modes')}</span>
              <div className="flex flex-wrap gap-4">
                {MODES.map((m) => (
                  <label key={m} className="flex items-center gap-2 text-sm text-navy-800">
                    <input type="checkbox" className="size-4 accent-gold-600" checked={form.registration_modes.includes(m)}
                      onChange={(e) => set('registration_modes', e.target.checked ? [...form.registration_modes, m] : form.registration_modes.filter((x) => x !== m))} />
                    {t(`sources.${m}`)}
                  </label>
                ))}
              </div>
            </div>
          </Card>

          {!id && (
            <SchedulePlanner
              inputs={{ start_date: form.start_date, total_hours: Number(form.total_hours) || 0, capacity: Number(form.capacity) || 0, delivery_mode: form.delivery_mode, trainer_id: form.trainers[0]?.id ?? null }}
              weekend={L.weekend ?? [5, 6]} canApprove={can('calendar.approve')} approval={approval} onApproval={setApproval} state={plan}
              onChange={(s) => {
                setPlan(s)
                // The programme's own dates follow its sessions.
                if (s.enabled && s.first && s.last) setForm((f) => (f.start_date === s.first && f.end_date === s.last ? f : { ...f, start_date: s.first!, end_date: s.last! }))
              }}
            />
          )}

          <ProgramKits deliveryMode={form.delivery_mode} state={kits} open={form.status === 'registration_open'} onChange={(s) => { setKits(s); setKitsTouched(true) }} />
        </div>

        <div className="space-y-6">
          <GroupsCard programId={id} audience={{ jobTitleIds: form.target_groups.map((g) => g.job_title_id).filter(Boolean) as string[], genders: form.audience.genders }} english={english} rows={groupRows} onRows={setGroupRows}
            programme={{ start_date: form.start_date, end_date: form.end_date, capacity: form.capacity }} />
          <Card>
            <CardTitle action={<Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => set('skills', [...form.skills, { id: L.skills[0].id, target_level: 3 }])} />}>{t('admin.programs.skills')}</CardTitle>
            <div className="space-y-2">
              {form.skills.map((s, i) => (
                <div key={i} className="flex gap-2">
                  <select className="input" value={s.id} onChange={(e) => set('skills', form.skills.map((x, j) => (j === i ? { ...x, id: e.target.value } : x)))}>{L.skills.map((k) => <option key={k.id} value={k.id}>{name(k)}</option>)}</select>
                  <select className="input w-20" value={s.target_level} onChange={(e) => set('skills', form.skills.map((x, j) => (j === i ? { ...x, target_level: Number(e.target.value) } : x)))}>{[1, 2, 3, 4, 5].map((n) => <option key={n}>{n}</option>)}</select>
                  <button className="text-slate-400 hover:text-danger" onClick={() => set('skills', form.skills.filter((_, j) => j !== i))}><Trash2 className="size-4" /></button>
                </div>
              ))}
            </div>
          </Card>
          <Card>
            <CardTitle action={<Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => set('trainers', [...form.trainers, { id: L.trainers[0].id, role: 'lead' }])} />}>{t('admin.programs.trainers')}</CardTitle>
            <div className="space-y-2">
              {form.trainers.map((tr, i) => (
                <div key={i}>
                  <div className="flex gap-2">
                    <select className="input" value={tr.id} onChange={(e) => set('trainers', form.trainers.map((x, j) => (j === i ? { ...x, id: e.target.value } : x)))}>{L.trainers.map((k) => <option key={k.id} value={k.id}>{name(k)}</option>)}</select>
                    <button className="text-slate-400 hover:text-danger" onClick={() => set('trainers', form.trainers.filter((_, j) => j !== i))}><Trash2 className="size-4" /></button>
                  </div>
                  {/* The trainer the session plan is built for: when they teach elsewhere at the same time, it is said here too, with the dates. */}
                  {i === 0 && busy.length > 0 && (
                    <p role="alert" className="mt-1.5 flex items-start gap-1.5 text-xs font-semibold text-danger">
                      <AlertTriangle className="mt-0.5 size-3.5 shrink-0" />
                      <span>{busy[0].flag}{' '}<span className="font-normal text-slate-500" dir="ltr">({busy.map((b) => b.date).join(' · ')})</span></span>
                    </p>
                  )}
                </div>
              ))}
            </div>
          </Card>
          <Card>
            <CardTitle action={<Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => set('target_groups', [...form.target_groups, {}])} />}>{t('admin.programs.audience')}</CardTitle>
            <div className="mb-4 space-y-2 rounded-xl border border-navy-100 bg-white p-3">
              <div className="text-xs font-semibold text-slate-500">{t('admin.programs.voice.gender')}</div>
              <div className="flex gap-1.5">{([['', 'all'], ['male', 'male'], ['female', 'female']] as const).map(([v, k]) => <button key={k} type="button" onClick={() => set('audience', { ...form.audience, genders: v ? [v] : [] })} className={clsx('rounded-full px-3 py-1 text-xs font-semibold transition', (v ? form.audience.genders[0] === v && form.audience.genders.length === 1 : !form.audience.genders.length) ? 'bg-navy-900 text-white' : 'bg-slate-100 text-slate-600 hover:bg-slate-200')}>{t(`admin.programs.voice.genders.${k}`)}</button>)}</div>
              <div className="text-xs font-semibold text-slate-500">{t('admin.programs.voice.experience')}</div>
              <div className="flex items-center gap-2"><input type="number" min={0} max={60} className="input" placeholder={String(t('admin.programs.voice.from'))} value={form.audience.experience_min} onChange={(e) => set('audience', { ...form.audience, experience_min: e.target.value })} /><span className="text-slate-400">–</span><input type="number" min={0} max={60} className="input" placeholder={String(t('admin.programs.voice.to'))} value={form.audience.experience_max} onChange={(e) => set('audience', { ...form.audience, experience_max: e.target.value })} /></div>
              <p className="text-[11px] text-slate-400">{t('admin.programs.voice.audienceHint')}</p>
            </div>
            <div className="space-y-4">
              {form.target_groups.map((g, i) => {
                const upd = (patch: Partial<typeof g>) => set('target_groups', form.target_groups.map((x, j) => (j === i ? { ...x, ...patch } : x)))
                return (
                  <div key={i} className="space-y-2 rounded-xl bg-ivory p-3">
                    <select className="input" value={g.job_title_id ?? ''} onChange={(e) => upd({ job_title_id: e.target.value || null })}><option value="">{t('admin.rules.fields.job_title')}: {t('common.all')}</option>{L.job_titles.map((j) => <option key={j.id} value={j.id}>{name(j)}</option>)}</select>
                    <div className="flex gap-2">
                      <select className="input" value={g.school_type ?? ''} onChange={(e) => upd({ school_type: e.target.value || null })}><option value="">{t('admin.rules.fields.school_type')}</option>{L.school_types.map((s) => <option key={s} value={s}>{t(`schoolTypes.${s}`)}</option>)}</select>
                      <select className="input" value={g.education_stage ?? ''} onChange={(e) => upd({ education_stage: e.target.value || null })}><option value="">{t('admin.rules.fields.education_stage')}</option>{L.stages.map((s) => <option key={s} value={s}>{t(`stages.${s}`)}</option>)}</select>
                    </div>
                    <div className="flex gap-2">
                      <input className="input" placeholder={t('common.details')} value={g.description ?? ''} onChange={(e) => upd({ description: e.target.value })} />
                      <button className="text-slate-400 hover:text-danger" onClick={() => set('target_groups', form.target_groups.filter((_, j) => j !== i))}><Trash2 className="size-4" /></button>
                    </div>
                  </div>
                )
              })}
            </div>
          </Card>
        </div>
      </div>
    </>
  )
}

/** The programme's type (NDS-08): its own, or the category's when left on "as the category". */
function ProgramTypeField({ value, onChange, inherited }: { value: string; onChange: (v: string) => void; inherited: string | null }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language !== 'en'
  const types = useProgramTypes()
  const label = (id: string | null) => { const ty = types.find((x) => x.id === id); return ty ? (ar ? ty.name_ar : ty.name_en) : t('plans.types.missing') }
  const chosen = types.find((x) => x.id === value)
  return (
    <Field label={t('plans.types.label')} hint={chosen ? (ar ? chosen.hint_ar : chosen.hint_en) : t('plans.types.inherited', { type: label(inherited) })}>
      <select className="input" value={value} onChange={(e) => onChange(e.target.value)}>
        <option value="">{t('plans.types.asCategory', { type: label(inherited) })}</option>
        {types.filter((ty) => ty.enabled !== false || ty.id === value).map((ty) => <option key={ty.id} value={ty.id}>{ar ? `${ty.name_ar} — ${ty.name_en}` : `${ty.name_en} — ${ty.name_ar}`}</option>)}
      </select>
    </Field>
  )
}
