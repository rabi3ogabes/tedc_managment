import { ArrowRight, Check, Lock } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { Button, Card, ErrorState, Field, PageHeader, Spinner } from '@/components/ui'
import { useGet, useSend } from '@/hooks/useApi'
import { errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { SchoolPicker } from './PlcHub'
import { Stepper } from './SchoolsStep'
import { type Catalog, type Need, type PlcSource, type SchoolRef, type WorkshopDetail } from './plcApi'

type Form = {
  scenario: 'shared' | 'internal'; title_ar: string; title_en: string; summary_ar: string; domain: string; objectives: string; outcomes: string; target_audience: string; prerequisites: string
  source: PlcSource; source_ref: string; need_id: string; organiser: string; coordinator_user_id: string; priority: string; expected_participants: string
  delivery_mode: string; total_hours: string; start_date: string; end_date: string
}
const EMPTY: Form = { scenario: 'shared', title_ar: '', title_en: '', summary_ar: '', domain: '', objectives: '', outcomes: '', target_audience: '', prerequisites: '', source: 'centre', source_ref: '', need_id: '', organiser: '', coordinator_user_id: '', priority: 'normal', expected_participants: '', delivery_mode: 'in_person', total_hours: '', start_date: '', end_date: '' }

/**
 * The multi-step workshop form. Phase 1 ships step 1 (basics, source, host); the later steps are shown locked so the
 * person sees the whole journey without being offered controls that do nothing yet.
 */
export default function WorkshopWizard() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const { id } = useParams()
  const editing = !!id && id !== 'new'
  const catalog = useGet<{ data: Catalog }>('/admin/plc/workshops/catalog')
  const existing = useGet<{ data: WorkshopDetail }>(editing ? `/admin/plc/workshops/${id}` : null, undefined, { staleTime: 0 })
  const needs = useGet<{ data: Need[] }>('/admin/plc/needs', { review_status: 'new,under_review,converted', per_page: 100 })
  const [f, setF] = useState<Form>(EMPTY)
  const [host, setHost] = useState<SchoolRef[]>([])
  const [coordinator, setCoordinator] = useState<{ id: string; name: string } | null>(null)
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setF((p) => ({ ...p, [k]: v }))
  const can = catalog.data?.data.can
  const sharedAllowed = !!can?.manage

  useEffect(() => {
    const w = existing.data?.data
    if (!w) return
    setF({
      scenario: w.scenario, title_ar: w.title_ar, title_en: w.title_en ?? '', summary_ar: w.summary_ar ?? '', domain: w.domain ?? '', objectives: (w.objectives ?? []).join('\n'), outcomes: w.outcomes ?? '',
      target_audience: w.target_audience ?? '', prerequisites: w.prerequisites ?? '', source: w.source, source_ref: w.source_ref ?? '', need_id: w.need?.id ?? '', organiser: w.organiser ?? '',
      coordinator_user_id: w.coordinator_user?.id ?? '', priority: w.priority, expected_participants: w.expected_participants ? String(w.expected_participants) : '', delivery_mode: w.delivery_mode,
      total_hours: w.total_hours ? String(w.total_hours) : '', start_date: w.start_date ?? '', end_date: w.end_date ?? '',
    })
    if (w.host_school) setHost([{ id: w.host_school.id, name: w.host_school.name, name_en: w.host_school.name_en }])
    if (w.coordinator_user) setCoordinator(w.coordinator_user)
  }, [existing.data])
  useEffect(() => { if (catalog.data && !sharedAllowed && !editing) set('scenario', 'internal') }, [catalog.data, sharedAllowed, editing])

  const create = useSend<Record<string, unknown>, { data: WorkshopDetail }>('post', '/admin/plc/workshops', ['/admin/plc'])
  const update = useSend<Record<string, unknown>, { data: WorkshopDetail }>('put', `/admin/plc/workshops/${id}`, ['/admin/plc'])
  const selectedNeed = needs.data?.data.find((n) => n.id === f.need_id)

  const submit = async () => {
    if (!f.title_ar.trim()) { toast(String(t('plc.wizard.required')), 'error'); return }
    const body: Record<string, unknown> = {
      scenario: f.scenario, title_ar: f.title_ar, title_en: f.title_en || null, summary_ar: f.summary_ar || null, domain: f.domain || null,
      objectives: f.objectives.split('\n').map((s) => s.trim()).filter(Boolean), outcomes: f.outcomes || null, target_audience: f.target_audience || null, prerequisites: f.prerequisites || null,
      source: f.source, source_ref: f.source_ref || null, need_id: f.need_id || null, organiser: f.organiser || null, coordinator_user_id: coordinator?.id ?? null, priority: f.priority,
      expected_participants: f.expected_participants ? Number(f.expected_participants) : null, delivery_mode: f.delivery_mode, total_hours: f.total_hours ? Number(f.total_hours) : null,
      start_date: f.start_date || null, end_date: f.end_date || f.start_date || null, host_school_id: host[0]?.id ?? null,
    }
    try {
      const { data } = editing ? await update.mutateAsync(body) : await create.mutateAsync(body)
      toast(String(t(editing ? 'plc.wizard.saved' : 'plc.wizard.created')))
      navigate(editing ? `/admin/communities/workshops/${data.id}` : `/admin/communities/workshops/${data.id}/schools`)
    } catch (e) { toast(errorMessage(e), 'error') }
  }

  if (catalog.isLoading || (editing && existing.isLoading)) return <Spinner />
  if (catalog.error) return <ErrorState error={catalog.error} onRetry={() => catalog.refetch()} home="/admin/communities" />
  if (editing && (existing.error || !existing.data)) return <ErrorState error={existing.error} onRetry={() => existing.refetch()} home="/admin/communities?tab=workshops" />
  const internal = f.scenario === 'internal'
  const steps = t('plc.wizard.steps', { returnObjects: true }) as string[]

  return (
    <>
      <PageHeader title={t(editing ? 'plc.wizard.edit' : 'plc.wizard.title')} subtitle={t('plc.subtitle')} actions={<Button variant="outline" to="/admin/communities?tab=workshops">{t('plc.detail.back')}</Button>} />
      {editing ? <Stepper current={0} id={id!} /> : (
        <ol className="mb-6 flex flex-wrap gap-2">
          {steps.map((s, i) => (
            <li key={s} className={`flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-semibold ${i === 0 ? 'bg-navy-900 text-white' : 'bg-slate-100 text-slate-500'}`}>
              <span className={`grid size-5 place-items-center rounded-full text-[11px] ${i === 0 ? 'bg-gold-400 text-navy-950' : 'bg-white text-slate-500'}`}>{i === 0 ? <Check className="size-3" /> : i + 1}</span>
              {s}{i > 0 && <span className="inline-flex items-center gap-1 text-[10px] font-normal opacity-80"><Lock className="size-3" />{t('plc.wizard.locked')}</span>}
            </li>
          ))}
        </ol>
      )}
      <div className="grid gap-5 lg:grid-cols-3">
        <div className="space-y-5 lg:col-span-2">
          <Card>
            <h3 className="mb-4 font-display text-base font-bold text-navy-900">{t('plc.wizard.basics')}</h3>
            <div className="grid gap-4 md:grid-cols-2">
              <Field label={t('plc.wizard.scenario')} className="md:col-span-2">
                <div className="grid gap-2 sm:grid-cols-2">
                  {(['shared', 'internal'] as const).map((s) => (
                    <button key={s} type="button" disabled={(s === 'shared' && !sharedAllowed) || editing} onClick={() => set('scenario', s)}
                      className={`rounded-xl border px-4 py-3 text-start text-sm transition disabled:cursor-not-allowed disabled:opacity-50 ${f.scenario === s ? 'border-navy-900 bg-navy-50 font-semibold text-navy-900' : 'border-slate-200 hover:border-navy-300'}`}>
                      {t(`plc.scenario.${s}`)}
                    </button>
                  ))}
                </div>
              </Field>
              <Field label={t('plc.wizard.titleAr')}><input className="input" dir="rtl" value={f.title_ar} onChange={(e) => set('title_ar', e.target.value)} maxLength={200} /></Field>
              <Field label={t('plc.wizard.titleEn')}><input className="input" dir="ltr" value={f.title_en} onChange={(e) => set('title_en', e.target.value)} maxLength={200} /></Field>
              <Field label={t('plc.wizard.summary')} className="md:col-span-2"><textarea className="input min-h-20" value={f.summary_ar} onChange={(e) => set('summary_ar', e.target.value)} maxLength={2000} /></Field>
              <Field label={t('plc.wizard.domain')}><input className="input" value={f.domain} onChange={(e) => set('domain', e.target.value)} maxLength={64} /></Field>
              <Field label={t('plc.wizard.audience')}><input className="input" value={f.target_audience} onChange={(e) => set('target_audience', e.target.value)} maxLength={200} /></Field>
              <Field label={t('plc.wizard.objectives')} hint={t('plc.wizard.objectivesHint')} className="md:col-span-2"><textarea className="input min-h-24" value={f.objectives} onChange={(e) => set('objectives', e.target.value)} /></Field>
              <Field label={t('plc.wizard.outcomes')}><textarea className="input min-h-16" value={f.outcomes} onChange={(e) => set('outcomes', e.target.value)} maxLength={2000} /></Field>
              <Field label={t('plc.wizard.prerequisites')}><textarea className="input min-h-16" value={f.prerequisites} onChange={(e) => set('prerequisites', e.target.value)} maxLength={2000} /></Field>
            </div>
          </Card>
          <Card>
            <h3 className="mb-4 font-display text-base font-bold text-navy-900">{t('plc.wizard.source')}</h3>
            <div className="grid gap-4 md:grid-cols-2">
              <Field label={t('plc.wizard.need')} hint={t('plc.wizard.needHint')} className="md:col-span-2">
                <select className="input" value={f.need_id} onChange={(e) => { const n = needs.data?.data.find((x) => x.id === e.target.value); set('need_id', e.target.value); if (n) { set('source', n.source); set('source_ref', n.reference_no ?? ''); if (!f.domain && n.domain) set('domain', n.domain) } }}>
                  <option value="">{t('plc.wizard.noNeed')}</option>
                  {(needs.data?.data ?? []).map((n) => <option key={n.id} value={n.id}>{n.title}{n.reference_no ? ` · ${n.reference_no}` : ''}</option>)}
                </select>
              </Field>
              <Field label={t('plc.wizard.source')}>
                <select className="input" value={f.source} disabled={!!selectedNeed || !sharedAllowed} onChange={(e) => set('source', e.target.value as PlcSource)}>
                  {(catalog.data?.data.sources ?? []).map((s) => <option key={s} value={s}>{t(`plc.source.${s}`)}</option>)}
                </select>
              </Field>
              <Field label={t('plc.wizard.sourceRef')} hint={f.source === 'rased' ? t('plc.wizard.rasedNote') : undefined}><input className="input" dir="ltr" value={f.source_ref} disabled={!!selectedNeed} onChange={(e) => set('source_ref', e.target.value)} maxLength={64} /></Field>
              {!internal && <Field label={t('plc.wizard.organiser')}><input className="input" value={f.organiser} onChange={(e) => set('organiser', e.target.value)} maxLength={200} /></Field>}
              <Field label={t('plc.wizard.priority')}>
                <select className="input" value={f.priority} onChange={(e) => set('priority', e.target.value)}>{(catalog.data?.data.priorities ?? []).map((p) => <option key={p} value={p}>{t(`plc.priority.${p}`)}</option>)}</select>
              </Field>
              <Field label={t('plc.wizard.expected')}><input type="number" min={1} className="input" value={f.expected_participants} onChange={(e) => set('expected_participants', e.target.value)} /></Field>
              <Field label={t('plc.wizard.coordinator')}><UserPicker value={coordinator} onChange={setCoordinator} placeholder={String(t('plc.wizard.coordinatorSearch'))} /></Field>
            </div>
          </Card>
          <Card>
            <h3 className="mb-4 font-display text-base font-bold text-navy-900">{t('plc.wizard.hostSchool')}</h3>
            {internal && !sharedAllowed ? <p className="text-sm text-slate-600">{t('plc.wizard.hostFixed')}</p> : <SchoolPicker single value={host} onChange={setHost} placeholder={String(t('plc.wizard.hostSearch'))} />}
            <div className="mt-4 grid gap-4 md:grid-cols-4">
              <Field label={t('plc.wizard.mode')}>
                <select className="input" value={f.delivery_mode} onChange={(e) => set('delivery_mode', e.target.value)}>{(catalog.data?.data.delivery_modes ?? []).map((m) => <option key={m} value={m}>{t(`plc.mode.${m}`)}</option>)}</select>
              </Field>
              <Field label={t('plc.wizard.hours')}><input type="number" min={0} step={0.5} className="input" value={f.total_hours} onChange={(e) => set('total_hours', e.target.value)} /></Field>
              <Field label={t('plc.wizard.start')}><input type="date" className="input" value={f.start_date} onChange={(e) => set('start_date', e.target.value)} /></Field>
              <Field label={t('plc.wizard.end')}><input type="date" className="input" value={f.end_date} min={f.start_date || undefined} onChange={(e) => set('end_date', e.target.value)} /></Field>
            </div>
          </Card>
        </div>
        <aside className="space-y-4">
          <Card>
            <h3 className="mb-2 font-display text-sm font-bold text-navy-900">{t('plc.detail.path')}</h3>
            <ol className="space-y-1.5 text-sm text-slate-600">
              {(catalog.data?.data.approval_paths[f.scenario] ?? []).map((s, i) => <li key={s.key} className="flex items-center gap-2"><span className="grid size-5 place-items-center rounded-full bg-slate-100 text-[11px] font-bold">{i + 1}</span>{t(`plc.step.${s.key}`, { defaultValue: s.key })}</li>)}
            </ol>
            <p className="mt-3 text-xs text-slate-500">{t('plc.phase')}</p>
          </Card>
          <div className="flex flex-col gap-2">
            <Button variant="gold" size="lg" loading={create.isPending || update.isPending} onClick={submit} icon={<ArrowRight className="size-4 rtl:rotate-180" />}>{t(editing ? 'plc.wizard.save' : 'plc.wizard.next')}</Button>
            <Button variant="outline" to={editing ? `/admin/communities/workshops/${id}` : '/admin/communities?tab=workshops'}>{t('common.cancel')}</Button>
          </div>
          <p className="text-xs text-slate-400">{i18n.language === 'en' ? 'Saving keeps the workshop as a draft; submit it for review from its page.' : 'الحفظ يبقي الورشة مسودة؛ قدّمها للمراجعة من صفحتها.'}</p>
        </aside>
      </div>
    </>
  )
}

function UserPicker({ value, onChange, placeholder }: { value: { id: string; name: string } | null; onChange: (v: { id: string; name: string } | null) => void; placeholder: string }) {
  const [q, setQ] = useState('')
  const res = useGet<{ data: { id: string; name: string; email: string }[] }>(q.length >= 2 ? '/admin/plc/lookup/users' : null, { q })
  if (value) {
    return <div className="flex items-center justify-between rounded-xl border border-slate-200 px-3 py-2 text-sm"><span className="font-semibold">{value.name}</span><button type="button" className="text-slate-400 hover:text-red-600" onClick={() => onChange(null)} aria-label="remove">×</button></div>
  }
  return (
    <div className="relative">
      <input className="input" value={q} onChange={(e) => setQ(e.target.value)} placeholder={placeholder} />
      {q.length >= 2 && (res.data?.data.length ?? 0) > 0 && (
        <ul className="absolute z-10 mt-1 max-h-56 w-full overflow-auto rounded-xl border border-slate-200 bg-white py-1 shadow-lg">
          {res.data!.data.map((u) => <li key={u.id}><button type="button" className="flex w-full items-center justify-between px-3 py-1.5 text-start text-sm hover:bg-slate-50" onClick={() => { onChange(u); setQ('') }}><span>{u.name}</span><span className="text-xs text-slate-400" dir="ltr">{u.email}</span></button></li>)}
        </ul>
      )}
    </div>
  )
}
