import { Plus, Trash2 } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { Button, Card, CardTitle, Field, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { toDateInput, toDateTimeInput } from '@/lib/format'
import type { Program } from '@/lib/types'

type Lookups = {
  categories: { id: string; name_ar: string; name_en: string }[]
  skills: { id: string; name_ar: string; name_en: string; category: string }[]
  job_titles: { id: string; name_ar: string; name_en: string }[]
  trainers: { id: string; name_ar: string; name_en: string }[]
  school_types: string[]
  stages: string[]
}

const MODES = ['self', 'school_nomination', 'center_nomination', 'bulk_import']

const empty = {
  code: '', category_id: '', title_ar: '', title_en: '', summary_ar: '', summary_en: '', description_ar: '', description_en: '',
  objectives: [] as string[], delivery_mode: 'in_person', level: 'intermediate', total_hours: 12, capacity: 30, min_attendance_percent: 80,
  requires_tasks: true, requires_evaluation: true, start_date: '', end_date: '', registration_opens_at: '', registration_closes_at: '',
  registration_modes: MODES, status: 'draft', is_featured: false,
  skills: [] as { id: string; target_level: number }[], trainers: [] as { id: string; role: string }[],
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
  const name = (o: { name_ar: string; name_en: string }) => (i18n.language === 'ar' ? o.name_ar : o.name_en)

  useEffect(() => {
    const p = existing.data?.data
    if (!p) return
    setForm({
      ...empty,
      ...Object.fromEntries(Object.entries(p).filter(([k]) => k in empty)),
      category_id: p.category_id ?? '',
      summary_ar: p.summary_ar ?? '', summary_en: p.summary_en ?? '', description_ar: p.description_ar ?? '', description_en: p.description_en ?? '',
      start_date: toDateInput(p.start_date), end_date: toDateInput(p.end_date),
      registration_opens_at: toDateTimeInput(p.registration_opens_at), registration_closes_at: toDateTimeInput(p.registration_closes_at),
      skills: (p.skills ?? []).map((s) => ({ id: s.id, target_level: s.target_level ?? 3 })),
      trainers: (p.trainers ?? []).map((tr) => ({ id: tr.id, role: tr.role ?? 'lead' })),
      target_groups: (p.target_groups ?? []).map(({ job_title_id, school_type, education_stage, description }) => ({ job_title_id, school_type, education_stage, description })),
    } as typeof empty)
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
    const payload = { ...form, category_id: form.category_id || null, start_date: form.start_date || null, end_date: form.end_date || null, registration_opens_at: form.registration_opens_at || null, registration_closes_at: form.registration_closes_at || null }
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
      <PageHeader title={id ? t('admin.programs.edit') : t('admin.programs.new')} actions={<Button variant="gold" loading={saving} onClick={save}>{t('common.save')}</Button>} />
      {error && <div className="mb-4 rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
      <div className="grid gap-6 xl:grid-cols-3">
        <div className="space-y-6 xl:col-span-2">
          <Card>
            <CardTitle>{t('admin.programs.basics')}</CardTitle>
            <div className="grid gap-4 sm:grid-cols-2">
              <Field label={t('admin.programs.code')}>{input('code')}</Field>
              <Field label={t('programs.category')}>
                <select className="input" value={form.category_id} onChange={(e) => set('category_id', e.target.value)}>
                  <option value="">—</option>{L.categories.map((c) => <option key={c.id} value={c.id}>{name(c)}</option>)}
                </select>
              </Field>
              <Field label={t('admin.programs.titleAr')}>{input('title_ar')}</Field>
              <Field label={t('admin.programs.titleEn')}><input className="input" dir="ltr" value={form.title_en} onChange={(e) => set('title_en', e.target.value)} /></Field>
              <Field label={t('admin.programs.summaryAr')} className="sm:col-span-2">{input('summary_ar')}</Field>
              <Field label={t('admin.programs.summaryEn')} className="sm:col-span-2"><input className="input" dir="ltr" value={form.summary_en} onChange={(e) => set('summary_en', e.target.value)} /></Field>
              <Field label={t('admin.programs.descriptionAr')} className="sm:col-span-2"><textarea className="input min-h-28" value={form.description_ar} onChange={(e) => set('description_ar', e.target.value)} /></Field>
              <Field label={t('admin.programs.descriptionEn')} className="sm:col-span-2"><textarea className="input min-h-28" dir="ltr" value={form.description_en} onChange={(e) => set('description_en', e.target.value)} /></Field>
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
        </div>

        <div className="space-y-6">
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
                <div key={i} className="flex gap-2">
                  <select className="input" value={tr.id} onChange={(e) => set('trainers', form.trainers.map((x, j) => (j === i ? { ...x, id: e.target.value } : x)))}>{L.trainers.map((k) => <option key={k.id} value={k.id}>{name(k)}</option>)}</select>
                  <button className="text-slate-400 hover:text-danger" onClick={() => set('trainers', form.trainers.filter((_, j) => j !== i))}><Trash2 className="size-4" /></button>
                </div>
              ))}
            </div>
          </Card>
          <Card>
            <CardTitle action={<Button size="sm" variant="outline" icon={<Plus className="size-4" />} onClick={() => set('target_groups', [...form.target_groups, {}])} />}>{t('admin.programs.audience')}</CardTitle>
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
