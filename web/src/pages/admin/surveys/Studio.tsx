import clsx from 'clsx'
import { AlignLeft, ArrowDown, ArrowUp, AtSign, Calendar, CheckSquare, ChevronDown, CircleDot, Clock, Copy, FileUp, GitBranch, Grid3x3, Hash, Heading, Info, ListOrdered, Phone, Save, Scissors, SlidersHorizontal, Star, ThumbsUp, Trash2, Type, Gauge, Rows3 } from 'lucide-react'
import { useEffect, useRef, useState, type ClipboardEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import EngineForm from '@/components/surveys/EngineForm'
import DistributionTab from './DistributionTab'
import { Badge, Button, Card, CardTitle, ErrorState, Field, Modal, PageHeader, Spinner, StatCard, Tabs } from '@/components/ui'
import { useGet, useSend } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { errorMessage } from '@/lib/api'
import { CHOICE_TYPES, JUMP_TYPES, NO_ANSWER, OPERATORS, SCALED_TYPES, blank, uid, type Condition, type Item, type Jump, type Question, type QuestionType, type Settings } from '@/lib/surveyEngine'
import { toast } from '@/lib/toast'

type Detail = {
  id: string; kind: string; title_ar: string; title_en?: string | null; description_ar?: string | null; description_en?: string | null; status: string; privacy_mode: 'identified' | 'confidential' | 'anonymous'; legacy_type?: 'needs_survey' | 'evaluation_form' | null; legacy_id?: string | null
  questions: Question[]; audience: Record<string, unknown> | null; settings: Settings; opens_at?: string | null; closes_at?: string | null; retention_days?: number | null
  approval: { approved: boolean; required: boolean; reviewed_by?: string | null; reviewed_at?: string | null; note?: string | null }; version: { number: number; published_at: string } | null
  has_unpublished_changes: boolean; stats: { participants: number; answered: number; response_rate: number | null; submissions: number }; is_open: boolean
}
type Catalog = { kinds: string[]; privacy_modes: string[]; segment_fields: string[]; accept: string[]; defaults: { segment_threshold: number; retention_days: number } }
type Tab = 'build' | 'settings' | 'audience' | 'distribute' | 'preview' | 'versions'
/** Kinds whose respondents come from the evaluation centre's assignments, not from audience filters. */
const ASSIGNED_KINDS = ['trainer_reflection', 'planning_evaluation', 'supervisor_feedback', 'specialist_feedback', 'custom', 'impact_trainee', 'impact_manager']
const TONE: Record<string, 'gray' | 'amber' | 'green' | 'navy'> = { draft: 'gray', in_review: 'amber', approved: 'green', published: 'navy', closed: 'gray', archived: 'gray' }
const ICONS: Record<QuestionType, typeof Type> = { section: Heading, instructions: Info, page_break: Scissors, short_text: Type, long_text: AlignLeft, email: AtSign, phone: Phone, number: Hash, date: Calendar, time: Clock, single: CircleDot, multiple: CheckSquare, dropdown: ChevronDown, yes_no: ThumbsUp, rating: Star, scale: SlidersHorizontal, likert: Rows3, nps: Gauge, matrix: Grid3x3, ranking: ListOrdered, file_upload: FileUp }
const GROUPS: { key: string; types: QuestionType[] }[] = [
  { key: 'structure', types: ['section', 'instructions', 'page_break'] }, { key: 'text', types: ['short_text', 'long_text', 'email', 'phone', 'number', 'date', 'time'] },
  { key: 'choice', types: ['single', 'multiple', 'dropdown', 'yes_no', 'ranking'] }, { key: 'scales', types: ['rating', 'scale', 'likert', 'nps', 'matrix'] }, { key: 'misc', types: ['file_upload'] },
]

/** The studio of the new survey engine: build, settings, audience, live preview, versions and the lifecycle. */
export default function Studio() {
  const { t, i18n } = useTranslation()
  const { id } = useParams()
  const { can } = useAuth()
  const en = i18n.language === 'en'
  const query = useGet<{ data: Detail }>(`/admin/surveys/${id}`, undefined, { staleTime: 0 })
  const catalog = useGet<{ data: Catalog }>('/admin/surveys/catalog')
  const [tab, setTab] = useState<Tab>('build')
  const [questions, setQuestions] = useState<Question[]>([])
  const [settings, setSettings] = useState<Settings>({})
  const [meta, setMeta] = useState<Record<string, unknown>>({})
  const [audience, setAudience] = useState<Record<string, unknown>>({})
  const [selected, setSelected] = useState<string | null>(null)
  const [dirty, setDirty] = useState(false)
  const [action, setAction] = useState<string | null>(null)
  const [note, setNote] = useState('')
  const loaded = useRef<string | null>(null)
  const save = useSend<Record<string, unknown>, { data: Detail }>('put', `/admin/surveys/${id}`, ['/admin/surveys'])
  const act = useSend<{ action: string; body?: Record<string, unknown> }, { data: Detail; version?: number; added?: number }>('post', (b) => `/admin/surveys/${id}/${b.action}`, ['/admin/surveys'])

  useEffect(() => {
    const d = query.data?.data
    if (!d || loaded.current === d.id + String(d.stats?.submissions) + d.status) return
    loaded.current = d.id + String(d.stats?.submissions) + d.status
    if (!dirty) { setQuestions(d.questions); setSettings(d.settings ?? {}); setAudience(d.audience ?? {}); setMeta({ title_ar: d.title_ar, title_en: d.title_en ?? '', description_ar: d.description_ar ?? '', description_en: d.description_en ?? '', kind: d.kind, privacy_mode: d.privacy_mode, opens_at: d.opens_at ?? '', closes_at: d.closes_at ?? '', retention_days: d.retention_days ?? '' }) }
  }, [query.data, dirty])

  const persist = async () => {
    try {
      await save.mutateAsync({ ...meta, title_en: meta.title_en || null, description_ar: meta.description_ar || null, description_en: meta.description_en || null, opens_at: meta.opens_at || null, closes_at: meta.closes_at || null, retention_days: meta.retention_days || null, questions, settings, audience: Object.keys(audience).length ? audience : null })
      setDirty(false); toast(String(t('ss.studio.saved'))); await query.refetch()
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  const run = async (name: string, body?: Record<string, unknown>) => {
    try {
      if (dirty) await persist()
      const r = await act.mutateAsync({ action: name, body })
      const msgs: Record<string, string> = { 'submit-review': 'reviewed', approve: 'approvedMsg', return: 'returned', close: 'closed', reopen: 'reopened' }
      if (name === 'publish') toast(String(t('ss.studio.published', { v: r.version, n: r.added })))
      else if (msgs[name]) toast(String(t(`ss.studio.${msgs[name]}`)))
      setAction(null); setNote(''); await query.refetch()
    } catch (e) { toast(errorMessage(e), 'error') }
  }
  const archive = useSend<unknown, unknown>('delete', `/admin/surveys/${id}`, ['/admin/surveys'])
  const duplicate = useSend<unknown, { data: Detail }>('post', `/admin/surveys/${id}/duplicate`, ['/admin/surveys'])

  if (query.isLoading || catalog.isLoading) return <Spinner />
  if (query.error || !query.data) return <ErrorState error={query.error} onRetry={() => query.refetch()} home="/admin/surveys" />
  const d = query.data.data
  const cat = catalog.data!.data
  const update = (qs: Question[]) => { setQuestions(qs); setDirty(true) }
  const editable = !['archived'].includes(d.status) && can('surveys.manage')
  const title = en && d.title_en ? d.title_en : d.title_ar

  return (
    <>
      <PageHeader
        title={<span className="flex flex-wrap items-center gap-3">{title}<Badge color={TONE[d.status] ?? 'gray'}>{t(`ss.state.${d.status}`)}</Badge>{d.version && <Badge color="gold">{t('ss.list.version')} {d.version.number}</Badge>}<Badge color="gray">{t(`ss.privacy.${d.privacy_mode}`)}</Badge>{d.legacy_type && <Badge color="amber">{t(`ss.legacy.${d.legacy_type}`)}</Badge>}</span>}
        subtitle={d.approval.required ? (d.approval.approved ? t('ss.studio.approved') : t('ss.studio.notApproved')) : undefined}
        actions={<div className="flex flex-wrap gap-2">
          <Button variant="outline" to="/admin/surveys">{t('ss.list.title')}</Button>
          {d.legacy_type && <Button variant="ghost" to={d.legacy_type === 'needs_survey' ? `/admin/needs/surveys/${d.legacy_id}?view=results` : '/admin/evaluation-forms'}>{t('ss.legacy.openLegacy')}</Button>}
          {editable && <Button variant={dirty ? 'gold' : 'outline'} icon={<Save className="size-4" />} loading={save.isPending} onClick={persist}>{dirty ? t('ss.studio.save') : t('ss.studio.saved')}</Button>}
          {editable && d.status === 'draft' && d.approval.required && !d.approval.approved && <Button variant="outline" onClick={() => run('submit-review')}>{t('ss.studio.actions.submitReview')}</Button>}
          {can('surveys.approve') && ['draft', 'in_review', 'published', 'closed'].includes(d.status) && !d.approval.approved && <Button variant="primary" onClick={() => setAction('approve')}>{t('ss.studio.actions.approve')}</Button>}
          {can('surveys.approve') && ['in_review', 'approved'].includes(d.status) && <Button variant="outline" onClick={() => setAction('return')}>{t('ss.studio.actions.return')}</Button>}
          {can('surveys.publish') && ['draft', 'in_review', 'approved', 'published', 'closed'].includes(d.status) && (d.status !== 'published' || d.has_unpublished_changes) && <Button variant="gold" onClick={() => run('publish')}>{t(d.version ? 'ss.studio.actions.republish' : 'ss.studio.actions.publish')}</Button>}
          {can('surveys.publish') && d.status === 'published' && <Button variant="outline" onClick={() => run('close')}>{t('ss.studio.actions.close')}</Button>}
          {can('surveys.publish') && d.status === 'closed' && <Button variant="outline" onClick={() => run('reopen')}>{t('ss.studio.actions.reopen')}</Button>}
          {can('surveys.manage') && <Button variant="ghost" icon={<Copy className="size-4" />} onClick={async () => { try { const r = await duplicate.mutateAsync({}); window.location.assign(`/admin/surveys/${r.data.id}`) } catch (e) { toast(errorMessage(e), 'error') } }}>{t('ss.studio.actions.duplicate')}</Button>}
          {can('surveys.manage') && ['draft', 'in_review', 'approved', 'closed'].includes(d.status) && <Button variant="ghost" icon={<Trash2 className="size-4" />} onClick={async () => { if (!window.confirm(String(t('ss.studio.actions.archive')) + '?')) return; try { await archive.mutateAsync({}); toast(String(t('ss.studio.archived'))); query.refetch() } catch (e) { toast(errorMessage(e), 'error') } }}>{t('ss.studio.actions.archive')}</Button>}
        </div>}
      />
      {d.version && d.has_unpublished_changes && <p className="mb-4 rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">{t('ss.studio.changesPending')}</p>}
      <div className="mb-5 grid gap-3 sm:grid-cols-4">
        <StatCard label={t('ss.studio.stats.participants')} value={d.stats.participants} />
        <StatCard label={t('ss.studio.stats.answered')} value={d.stats.answered} />
        <StatCard label={t('ss.studio.stats.rate')} value={d.stats.response_rate != null ? `${d.stats.response_rate}%` : '—'} />
        <StatCard label={t('ss.studio.stats.submissions')} value={d.stats.submissions} accent />
      </div>
      <Tabs<Tab> value={tab} onChange={setTab} tabs={(['build', 'settings', 'audience', 'distribute', 'preview', 'versions'] as Tab[]).map((k) => ({ id: k, label: t(`ss.studio.tabs.${k}`) }))} />

      {tab === 'build' && (
        <div className="grid gap-5 lg:grid-cols-[260px_1fr]">
          <aside className="space-y-4 lg:sticky lg:top-20 lg:self-start">
            <Card>
              <h3 className="mb-3 font-display text-sm font-bold text-navy-900">{t('ss.studio.palette')}</h3>
              {GROUPS.map((g) => (
                <div key={g.key} className="mb-3">
                  <div className="mb-1 text-[11px] font-bold uppercase tracking-wide text-slate-400">{t(`ss.studio.${g.key}`)}</div>
                  <div className="grid grid-cols-2 gap-1">
                    {g.types.map((type) => { const Icon = ICONS[type]; return (
                      <button key={type} type="button" disabled={!editable} onClick={() => { const q = blank(type, (k, n) => (k === 'likert' ? (t('ss.defaults.likert', { returnObjects: true }) as string[])[(n ?? 1) - 1] : String(t(`ss.defaults.${k}`)))); const i = selected ? questions.findIndex((x) => x.id === selected) + 1 : questions.length; update([...questions.slice(0, i), q, ...questions.slice(i)]); setSelected(q.id) }}
                        className="flex items-center gap-1.5 rounded-lg border border-slate-200 px-2 py-1.5 text-start text-xs text-slate-700 transition hover:border-gold-400 hover:bg-gold-50 disabled:opacity-40"><Icon className="size-3.5 shrink-0 text-navy-700" /><span className="truncate">{t(`ss.types.${type}`)}</span></button>) })}
                  </div>
                </div>
              ))}
            </Card>
          </aside>
          <div className="space-y-3">
            {!questions.length && <Card><p className="text-sm text-slate-500">{t('ss.studio.empty')}</p></Card>}
            {questions.map((q, i) => (
              <QuestionCard key={q.id} q={q} index={i} all={questions} selected={selected === q.id} editable={editable} en={en} accept={cat.accept}
                onSelect={() => setSelected(q.id)}
                onChange={(next) => update(questions.map((x) => (x.id === q.id ? next : x)))}
                onMove={(d) => { const j = i + d; if (j < 0 || j >= questions.length) return; const n = [...questions]; [n[i], n[j]] = [n[j], n[i]]; update(n) }}
                onDuplicate={() => { const copy = { ...structuredClone(q), id: uid(), visible_if: undefined, jumps: undefined }; update([...questions.slice(0, i + 1), copy, ...questions.slice(i + 1)]); setSelected(copy.id) }}
                onRemove={() => { update(questions.filter((x) => x.id !== q.id).map((x) => ({ ...x, visible_if: x.visible_if ? { ...x.visible_if, conditions: x.visible_if.conditions.filter((c) => c.question !== q.id) } : undefined, jumps: x.jumps?.filter((j) => j.to !== q.id) }))); if (selected === q.id) setSelected(null) }}
              />
            ))}
          </div>
        </div>
      )}

      {tab === 'settings' && (
        <SettingsTab d={d} cat={cat} meta={meta} settings={settings} editable={editable} onMeta={(m) => { setMeta(m); setDirty(true) }} onSettings={(s) => { setSettings(s); setDirty(true) }} />
      )}
      {tab === 'audience' && (ASSIGNED_KINDS.includes(d.kind) ? <Card><p className="text-sm leading-7 text-slate-600">{t('ss.studio.audienceByAssignment')}</p></Card> : <AudienceTab id={d.id} audience={audience} editable={editable} onChange={(a) => { setAudience(a); setDirty(true) }} />)}
      {tab === 'preview' && (
        <Card padded={false}>
          <p className="border-b border-slate-100 px-5 py-3 text-xs text-slate-500">{t('ss.studio.previewHint')}</p>
          <div className="flex min-h-[70vh] flex-col"><EngineForm preview survey={{ title, description: en ? d.description_en || d.description_ar : d.description_ar, questions, settings, privacy_mode: d.privacy_mode }} /></div>
        </Card>
      )}
      {tab === 'distribute' && <DistributionTab surveyId={d.id} title={title} isOpen={d.is_open} canManage={can('surveys.manage')} />}
      {tab === 'versions' && <VersionsTab id={d.id} />}

      <Modal open={!!action} onClose={() => setAction(null)} title={action ? t(`ss.studio.actions.${action}`) : ''}>
        <Field label={t('ss.studio.note')}><textarea className="input min-h-20" value={note} onChange={(e) => setNote(e.target.value)} maxLength={1000} /></Field>
        <div className="mt-4 flex justify-end gap-2"><Button variant="outline" onClick={() => setAction(null)}>{t('common.cancel')}</Button><Button variant="gold" loading={act.isPending} disabled={action === 'return' && !note.trim()} onClick={() => action && run(action, { note })}>{t('ss.studio.confirm')}</Button></div>
      </Modal>
    </>
  )
}

// Question card ---------------------------------------------------------------------------------------------------

function QuestionCard({ q, index, all, selected, editable, en, accept, onSelect, onChange, onMove, onDuplicate, onRemove }: {
  q: Question; index: number; all: Question[]; selected: boolean; editable: boolean; en: boolean; accept: string[]
  onSelect: () => void; onChange: (q: Question) => void; onMove: (d: number) => void; onDuplicate: () => void; onRemove: () => void
}) {
  const { t } = useTranslation()
  const Icon = ICONS[q.type]
  const earlier = all.slice(0, index).filter((x) => !NO_ANSWER.includes(x.type))
  const later = all.slice(index + 1).filter((x) => x.type !== 'section' && x.type !== 'instructions')
  const set = (patch: Partial<Question>) => onChange({ ...q, ...patch })
  const number = all.slice(0, index + 1).filter((x) => !NO_ANSWER.includes(x.type)).length
  const isBreak = q.type === 'page_break'

  if (!selected) {
    return (
      <button type="button" onClick={onSelect} className={clsx('flex w-full items-center gap-3 rounded-2xl border bg-white px-4 py-3 text-start transition hover:border-navy-300', isBreak ? 'border-dashed border-navy-200 bg-slate-50' : 'border-slate-200')}>
        <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-navy-100 text-navy-900"><Icon className="size-4" /></span>
        <span className="min-w-0 flex-1">
          <span className="block truncate text-sm font-semibold text-navy-900">{NO_ANSWER.includes(q.type) ? '' : `${number}. `}{(en && q.title_en ? q.title_en : q.title) || (isBreak ? t('ss.studio.pageBreak') : t(`ss.types.${q.type}`))}</span>
          <span className="block text-[11px] text-slate-400">{t(`ss.types.${q.type}`)}{q.required ? ` · ${t('ss.studio.required')}` : ''}{q.visible_if?.conditions.length ? ` · ${t('ss.studio.logic')}` : ''}{q.jumps?.length ? ` · ${t('ss.studio.jumps')}` : ''}</span>
        </span>
      </button>
    )
  }
  return (
    <div className="rounded-2xl border-2 border-gold-400 bg-white p-4 shadow-sm">
      <div className="mb-3 flex flex-wrap items-center gap-2">
        <span className="grid size-8 place-items-center rounded-lg bg-navy-900 text-white"><Icon className="size-4" /></span>
        <select className="input w-auto py-1.5 text-xs" value={q.type} disabled={!editable} onChange={(e) => onChange(blank(e.target.value as QuestionType, (k, n) => (k === 'likert' ? (t('ss.defaults.likert', { returnObjects: true }) as string[])[(n ?? 1) - 1] : String(t(`ss.defaults.${k}`))), q))}>
          {GROUPS.flatMap((g) => g.types).map((type) => <option key={type} value={type}>{t(`ss.types.${type}`)}</option>)}
        </select>
        {!NO_ANSWER.includes(q.type) && <label className="ms-auto flex items-center gap-1.5 text-xs font-semibold text-slate-600"><input type="checkbox" className="accent-gold-600" checked={!!q.required} disabled={!editable} onChange={(e) => set({ required: e.target.checked })} />{t('ss.studio.required')}</label>}
        <div className="flex gap-0.5">
          <IconBtn title={t('ss.studio.up')} onClick={() => onMove(-1)} disabled={!editable || index === 0}><ArrowUp className="size-4" /></IconBtn>
          <IconBtn title={t('ss.studio.down')} onClick={() => onMove(1)} disabled={!editable || index === all.length - 1}><ArrowDown className="size-4" /></IconBtn>
          <IconBtn title={t('ss.studio.duplicate')} onClick={onDuplicate} disabled={!editable}><Copy className="size-4" /></IconBtn>
          <IconBtn title={t('ss.studio.remove')} onClick={onRemove} disabled={!editable} danger><Trash2 className="size-4" /></IconBtn>
        </div>
      </div>
      <div className="grid gap-3 md:grid-cols-2">
        <Field label={isBreak ? t('ss.studio.pageTitle') : t('ss.studio.question')}><input className="input" dir="rtl" value={q.title ?? ''} disabled={!editable} onChange={(e) => set({ title: e.target.value })} maxLength={500} /></Field>
        <Field label={t('ss.studio.questionEn')}><input className="input" dir="ltr" value={q.title_en ?? ''} disabled={!editable} onChange={(e) => set({ title_en: e.target.value || undefined })} maxLength={500} /></Field>
        <Field label={t('ss.studio.description')} className="md:col-span-2"><textarea className="input min-h-16" value={q.description ?? ''} disabled={!editable} onChange={(e) => set({ description: e.target.value || undefined })} maxLength={2000} /></Field>
      </div>

      {CHOICE_TYPES.includes(q.type) && <ItemsEditor label={t('ss.studio.options')} addLabel={t('ss.studio.addOption')} items={q.options ?? []} editable={editable} prefix="o" onChange={(options) => set({ options })} max={q.type === 'likert' ? 11 : 60} />}
      {q.type === 'matrix' && <ItemsEditor label={t('ss.studio.rows')} addLabel={t('ss.studio.addRow')} items={q.rows ?? []} editable={editable} prefix="r" onChange={(rows) => set({ rows })} max={40} />}
      {(q.type === 'single' || q.type === 'multiple') && <label className="mt-3 flex items-center gap-2 text-xs text-slate-600"><input type="checkbox" className="accent-gold-600" checked={!!q.other} disabled={!editable} onChange={(e) => set({ other: e.target.checked || undefined })} />{t('ss.studio.other')}</label>}
      {q.type === 'multiple' && <div className="mt-3 grid max-w-sm grid-cols-2 gap-3"><Field label={t('ss.studio.minSelect')}><input type="number" min={1} className="input" value={q.min_select ?? ''} disabled={!editable} onChange={(e) => set({ min_select: e.target.value ? Number(e.target.value) : undefined })} /></Field><Field label={t('ss.studio.maxSelect')}><input type="number" min={1} className="input" value={q.max_select ?? ''} disabled={!editable} onChange={(e) => set({ max_select: e.target.value ? Number(e.target.value) : undefined })} /></Field></div>}
      {SCALED_TYPES.includes(q.type) && (
        <div className="mt-3 grid gap-3 sm:grid-cols-4">
          <Field label={t('ss.studio.min')}><input type="number" min={0} max={1} className="input" value={q.scale?.min ?? 1} disabled={!editable} onChange={(e) => set({ scale: { ...(q.scale ?? { min: 1, max: 5 }), min: Number(e.target.value) } })} /></Field>
          <Field label={t('ss.studio.max')}><input type="number" min={2} max={10} className="input" value={q.scale?.max ?? 5} disabled={!editable} onChange={(e) => set({ scale: { ...(q.scale ?? { min: 1, max: 5 }), max: Number(e.target.value) } })} /></Field>
          <Field label={t('ss.studio.minLabel')}><input className="input" value={q.scale?.min_label ?? ''} disabled={!editable} onChange={(e) => set({ scale: { ...(q.scale ?? { min: 1, max: 5 }), min_label: e.target.value || undefined } })} /></Field>
          <Field label={t('ss.studio.maxLabel')}><input className="input" value={q.scale?.max_label ?? ''} disabled={!editable} onChange={(e) => set({ scale: { ...(q.scale ?? { min: 1, max: 5 }), max_label: e.target.value || undefined } })} /></Field>
        </div>
      )}
      {(q.type === 'short_text' || q.type === 'long_text') && <div className="mt-3 grid gap-3 sm:grid-cols-2"><Field label={t('ss.studio.maxLength')}><input type="number" min={1} className="input" value={q.max_length ?? ''} disabled={!editable} onChange={(e) => set({ max_length: e.target.value ? Number(e.target.value) : undefined })} /></Field><Field label={t('ss.studio.placeholder')}><input className="input" value={q.placeholder ?? ''} disabled={!editable} onChange={(e) => set({ placeholder: e.target.value || undefined })} /></Field></div>}
      {q.type === 'number' && <div className="mt-3 grid max-w-sm grid-cols-2 gap-3"><Field label={t('ss.studio.numMin')}><input type="number" className="input" value={q.min ?? ''} disabled={!editable} onChange={(e) => set({ min: e.target.value === '' ? null : Number(e.target.value) })} /></Field><Field label={t('ss.studio.numMax')}><input type="number" className="input" value={q.max ?? ''} disabled={!editable} onChange={(e) => set({ max: e.target.value === '' ? null : Number(e.target.value) })} /></Field></div>}
      {q.type === 'file_upload' && (
        <div className="mt-3 grid gap-3 sm:grid-cols-3">
          <Field label={t('ss.studio.files')}><input type="number" min={1} max={5} className="input" value={q.max_files ?? 1} disabled={!editable} onChange={(e) => set({ max_files: Number(e.target.value) })} /></Field>
          <Field label={t('ss.studio.mb')}><input type="number" min={1} max={25} className="input" value={q.max_mb ?? 10} disabled={!editable} onChange={(e) => set({ max_mb: Number(e.target.value) })} /></Field>
          <Field label={t('ss.studio.accept')}><div className="flex flex-wrap gap-1">{accept.map((a) => { const on = (q.accept ?? []).includes(a); return <button key={a} type="button" disabled={!editable} onClick={() => set({ accept: on ? (q.accept ?? []).filter((x) => x !== a) : [...(q.accept ?? []), a] })} className={clsx('rounded-full border px-2 py-0.5 text-xs', on ? 'border-navy-900 bg-navy-900 text-white' : 'border-slate-200 text-slate-600')}>{a}</button> })}</div></Field>
        </div>
      )}

      {(earlier.length > 0 || q.visible_if) && <LogicEditor q={q} earlier={earlier} editable={editable} en={en} onChange={(visible_if) => set({ visible_if })} />}
      {JUMP_TYPES.includes(q.type) && later.length > 0 && <JumpsEditor q={q} later={later} editable={editable} en={en} onChange={(jumps) => set({ jumps })} />}
    </div>
  )
}

function IconBtn({ children, onClick, title, disabled, danger }: { children: React.ReactNode; onClick: () => void; title: string; disabled?: boolean; danger?: boolean }) {
  return <button type="button" title={title} aria-label={title} disabled={disabled} onClick={onClick} className={clsx('rounded-lg p-1.5 text-slate-400 transition disabled:opacity-30', danger ? 'hover:bg-red-50 hover:text-red-600' : 'hover:bg-navy-100/60 hover:text-navy-900')}>{children}</button>
}

function ItemsEditor({ label, addLabel, items, editable, prefix, onChange, max }: { label: string; addLabel: string; items: Item[]; editable: boolean; prefix: 'o' | 'r'; onChange: (items: Item[]) => void; max: number }) {
  const { t } = useTranslation()
  const update = (i: number, patch: Partial<Item>) => onChange(items.map((o, j) => (j === i ? { ...o, ...patch } : o)))
  const paste = (i: number, e: ClipboardEvent<HTMLInputElement>) => {
    const lines = e.clipboardData.getData('text').split(/\r?\n/).map((l) => l.replace(/^\s*(?:[-•*]|\d+[.)])\s*/, '').trim()).filter(Boolean)
    if (lines.length < 2) return
    e.preventDefault()
    onChange([...items.slice(0, i), ...(items[i].label ? [items[i]] : []), ...lines.map((l) => ({ id: uid(prefix), label: l })), ...items.slice(i + 1)].slice(0, max))
  }
  return (
    <div className="mt-3">
      <div className="mb-1 flex items-center justify-between"><span className="label">{label}</span><span className="text-[11px] text-slate-400">{t('ss.studio.pasteHint')}</span></div>
      <div className="space-y-1.5">
        {items.map((o, i) => (
          <div key={o.id} className="flex items-center gap-2">
            <span className="w-5 text-center text-xs text-slate-400">{i + 1}</span>
            <input className="input flex-1 py-1.5" value={o.label} disabled={!editable} onPaste={(e) => paste(i, e)} onChange={(e) => update(i, { label: e.target.value })} maxLength={300} />
            <input className="input w-40 py-1.5" dir="ltr" placeholder="EN" value={o.label_en ?? ''} disabled={!editable} onChange={(e) => update(i, { label_en: e.target.value || undefined })} maxLength={300} />
            <IconBtn title={t('ss.studio.remove')} disabled={!editable || items.length <= 1} onClick={() => onChange(items.filter((_, j) => j !== i))} danger><Trash2 className="size-3.5" /></IconBtn>
          </div>
        ))}
      </div>
      {editable && items.length < max && <button type="button" className="mt-2 text-xs font-semibold text-gold-700 hover:underline" onClick={() => onChange([...items, { id: uid(prefix), label: '' }])}>+ {addLabel}</button>}
    </div>
  )
}

function ValueInput({ refQ: q, value, onChange, disabled, en }: { refQ: Question | undefined; value: unknown; onChange: (v: unknown) => void; disabled: boolean; en: boolean }) {
  if (q?.options?.length) return <select className="input py-1.5 text-xs" value={String(value ?? '')} disabled={disabled} onChange={(e) => onChange(e.target.value)}><option value="">—</option>{q.options.map((o) => <option key={o.id} value={o.id}>{en && o.label_en ? o.label_en : o.label}</option>)}</select>
  if (q?.type === 'yes_no') return <select className="input py-1.5 text-xs" value={String(value ?? '')} disabled={disabled} onChange={(e) => onChange(e.target.value)}><option value="">—</option><option value="yes">yes</option><option value="no">no</option></select>
  return <input className="input py-1.5 text-xs" value={String(value ?? '')} disabled={disabled} onChange={(e) => onChange(['rating', 'scale', 'nps', 'number'].includes(q?.type ?? '') && e.target.value !== '' && !Number.isNaN(Number(e.target.value)) ? Number(e.target.value) : e.target.value)} />
}

function LogicEditor({ q, earlier, editable, en, onChange }: { q: Question; earlier: Question[]; editable: boolean; en: boolean; onChange: (v: Question['visible_if']) => void }) {
  const { t } = useTranslation()
  const group = q.visible_if
  const conditions = group?.conditions ?? []
  const setConds = (conditions: Condition[]) => onChange(conditions.length ? { match: group?.match ?? 'all', conditions } : undefined)
  return (
    <div className="mt-4 rounded-xl border border-dashed border-navy-200 bg-slate-50 p-3">
      <div className="mb-2 flex flex-wrap items-center gap-2 text-xs font-semibold text-navy-900"><GitBranch className="size-3.5" />{t('ss.studio.visibleIf')}
        {conditions.length > 1 && <select className="input w-auto py-1 text-xs" value={group?.match ?? 'all'} disabled={!editable} onChange={(e) => onChange({ match: e.target.value as 'all' | 'any', conditions })}>{(['all', 'any'] as const).map((m) => <option key={m} value={m}>{t(`ss.studio.match.${m}`)}</option>)}</select>}
      </div>
      {!earlier.length ? <p className="text-xs text-slate-400">{t('ss.studio.noEarlier')}</p> : (
        <div className="space-y-1.5">
          {conditions.map((c, i) => { const ref = earlier.find((x) => x.id === c.question); const needsValue = !['answered', 'not_answered'].includes(c.op); return (
            <div key={i} className="grid gap-1.5 sm:grid-cols-[1fr_auto_1fr_auto]">
              <select className="input py-1.5 text-xs" value={c.question} disabled={!editable} onChange={(e) => setConds(conditions.map((x, j) => (j === i ? { ...x, question: e.target.value, value: undefined } : x)))}>{earlier.map((x) => <option key={x.id} value={x.id}>{(en && x.title_en ? x.title_en : x.title) || x.id}</option>)}</select>
              <select className="input py-1.5 text-xs" value={c.op} disabled={!editable} onChange={(e) => setConds(conditions.map((x, j) => (j === i ? { ...x, op: e.target.value as Condition['op'] } : x)))}>{OPERATORS.map((o) => <option key={o} value={o}>{t(`ss.studio.op.${o}`)}</option>)}</select>
              {needsValue ? <ValueInput refQ={ref} value={c.value} disabled={!editable} en={en} onChange={(value) => setConds(conditions.map((x, j) => (j === i ? { ...x, value } : x)))} /> : <span />}
              <IconBtn title={t('ss.studio.remove')} disabled={!editable} onClick={() => setConds(conditions.filter((_, j) => j !== i))} danger><Trash2 className="size-3.5" /></IconBtn>
            </div>) })}
          {editable && conditions.length < 10 && <button type="button" className="text-xs font-semibold text-gold-700 hover:underline" onClick={() => setConds([...conditions, { question: earlier[earlier.length - 1].id, op: 'equals' }])}>+ {t('ss.studio.addCondition')}</button>}
        </div>
      )}
    </div>
  )
}

function JumpsEditor({ q, later, editable, en, onChange }: { q: Question; later: Question[]; editable: boolean; en: boolean; onChange: (j: Jump[] | undefined) => void }) {
  const { t } = useTranslation()
  const jumps = q.jumps ?? []
  const setJumps = (js: Jump[]) => onChange(js.length ? js : undefined)
  return (
    <div className="mt-3 rounded-xl border border-dashed border-navy-200 bg-slate-50 p-3">
      <div className="mb-2 text-xs font-semibold text-navy-900">{t('ss.studio.jumps')}</div>
      <div className="space-y-1.5">
        {jumps.map((j, i) => (
          <div key={i} className="grid items-center gap-1.5 sm:grid-cols-[auto_auto_1fr_auto_1fr_auto]">
            <span className="text-xs text-slate-500">{t('ss.studio.when')}</span>
            <select className="input py-1.5 text-xs" value={j.op} disabled={!editable} onChange={(e) => setJumps(jumps.map((x, k) => (k === i ? { ...x, op: e.target.value as Jump['op'] } : x)))}>{OPERATORS.filter((o) => !['answered', 'not_answered', 'contains'].includes(o)).map((o) => <option key={o} value={o}>{t(`ss.studio.op.${o}`)}</option>)}</select>
            <ValueInput refQ={q} value={j.value} disabled={!editable} en={en} onChange={(value) => setJumps(jumps.map((x, k) => (k === i ? { ...x, value } : x)))} />
            <span className="text-xs text-slate-500">{t('ss.studio.jumpTo')}</span>
            <select className="input py-1.5 text-xs" value={j.to} disabled={!editable} onChange={(e) => setJumps(jumps.map((x, k) => (k === i ? { ...x, to: e.target.value } : x)))}><option value="end">{t('ss.studio.end')}</option>{later.map((x) => <option key={x.id} value={x.id}>{(en && x.title_en ? x.title_en : x.title) || (x.type === 'page_break' ? t('ss.studio.pageBreak') : x.id)}</option>)}</select>
            <IconBtn title={t('ss.studio.remove')} disabled={!editable} onClick={() => setJumps(jumps.filter((_, k) => k !== i))} danger><Trash2 className="size-3.5" /></IconBtn>
          </div>
        ))}
        {editable && jumps.length < 10 && <button type="button" className="text-xs font-semibold text-gold-700 hover:underline" onClick={() => setJumps([...jumps, { op: 'equals', to: 'end' }])}>+ {t('ss.studio.addJump')}</button>}
      </div>
    </div>
  )
}

// Settings ---------------------------------------------------------------------------------------------------------

function SettingsTab({ d, cat, meta, settings, editable, onMeta, onSettings }: { d: Detail; cat: Catalog; meta: Record<string, unknown>; settings: Settings; editable: boolean; onMeta: (m: Record<string, unknown>) => void; onSettings: (s: Settings) => void }) {
  const { t } = useTranslation()
  const m = (k: string, v: unknown) => onMeta({ ...meta, [k]: v })
  const s = <K extends keyof Settings>(k: K, v: Settings[K]) => onSettings({ ...settings, [k]: v })
  const frozen = d.stats.submissions > 0
  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card>
        <CardTitle>{t('ss.list.titleAr')}</CardTitle>
        <div className="grid gap-3">
          <Field label={t('ss.list.titleAr')}><input className="input" dir="rtl" value={String(meta.title_ar ?? '')} disabled={!editable} onChange={(e) => m('title_ar', e.target.value)} maxLength={200} /></Field>
          <Field label={t('ss.list.titleEn')}><input className="input" dir="ltr" value={String(meta.title_en ?? '')} disabled={!editable} onChange={(e) => m('title_en', e.target.value)} maxLength={200} /></Field>
          <Field label={t('ss.studio.settings.description')}><textarea className="input min-h-20" value={String(meta.description_ar ?? '')} disabled={!editable} onChange={(e) => m('description_ar', e.target.value)} maxLength={2000} /></Field>
          <Field label={t('ss.studio.settings.kind')}><select className="input" value={String(meta.kind ?? 'general')} disabled={!editable} onChange={(e) => m('kind', e.target.value)}>{cat.kinds.map((k) => <option key={k} value={k}>{t(`ss.kind.${k}`)}</option>)}</select></Field>
          <div className="grid gap-3 sm:grid-cols-3">
            <Field label={t('ss.studio.settings.opensAt')}><input type="datetime-local" className="input" value={String(meta.opens_at ?? '').slice(0, 16)} disabled={!editable} onChange={(e) => m('opens_at', e.target.value)} /></Field>
            <Field label={t('ss.studio.settings.closesAt')}><input type="datetime-local" className="input" value={String(meta.closes_at ?? '').slice(0, 16)} disabled={!editable} onChange={(e) => m('closes_at', e.target.value)} /></Field>
            <Field label={t('ss.studio.settings.retention')}><input type="number" min={30} max={3650} className="input" value={String(meta.retention_days ?? '')} placeholder={String(cat.defaults.retention_days)} disabled={!editable} onChange={(e) => m('retention_days', e.target.value)} /></Field>
          </div>
        </div>
      </Card>
      <Card>
        <CardTitle subtitle={frozen ? t('ss.studio.settings.frozen') : t(`ss.privacy.${String(meta.privacy_mode)}Hint`)}>{t('ss.studio.settings.privacy')}</CardTitle>
        <div className="grid gap-2 sm:grid-cols-3">{cat.privacy_modes.map((p) => <button key={p} type="button" disabled={!editable || frozen} onClick={() => m('privacy_mode', p)} className={clsx('rounded-xl border px-3 py-2 text-sm disabled:opacity-60', meta.privacy_mode === p ? 'border-navy-900 bg-navy-50 font-semibold text-navy-900' : 'border-slate-200 hover:border-navy-300')}>{t(`ss.privacy.${p}`)}</button>)}</div>
        <div className="mt-4 grid gap-3">
          <Field label={t('ss.studio.settings.editPolicy')}><select className="input" value={settings.edit_policy ?? 'until_close'} disabled={!editable} onChange={(e) => s('edit_policy', e.target.value as Settings['edit_policy'])}><option value="until_close">{t('ss.studio.settings.untilClose')}</option><option value="never">{t('ss.studio.settings.never')}</option></select></Field>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={settings.requires_approval !== false} disabled={!editable} onChange={(e) => s('requires_approval', e.target.checked)} />{t('ss.studio.settings.requiresApproval')}</label>
          <Field label={t('ss.studio.settings.threshold')}><input type="number" min={2} max={50} className="input max-w-32" value={settings.segment_threshold ?? cat.defaults.segment_threshold} disabled={!editable} onChange={(e) => s('segment_threshold', Number(e.target.value))} /></Field>
          <Field label={t('ss.studio.settings.segmentFields')} hint={t('ss.studio.settings.segmentHint')}>
            <div className="flex flex-wrap gap-1.5">{cat.segment_fields.map((f) => { const on = (settings.segment_fields ?? []).includes(f); return <button key={f} type="button" disabled={!editable} onClick={() => s('segment_fields', on ? (settings.segment_fields ?? []).filter((x) => x !== f) : [...(settings.segment_fields ?? []), f])} className={clsx('rounded-full border px-2.5 py-1 text-xs', on ? 'border-navy-900 bg-navy-900 text-white' : 'border-slate-200 text-slate-600')}>{t(`ss.studio.settings.fields.${f}`)}</button> })}</div>
          </Field>
        </div>
      </Card>
      <Card className="lg:col-span-2">
        <CardTitle>{t('ss.studio.tabs.settings')}</CardTitle>
        <div className="grid gap-3 md:grid-cols-2">
          <Field label={t('ss.studio.settings.welcome')}><textarea className="input min-h-20" value={settings.welcome ?? ''} disabled={!editable} onChange={(e) => s('welcome', e.target.value || null)} maxLength={2000} /></Field>
          <Field label={t('ss.studio.settings.thankYou')}><textarea className="input min-h-20" value={settings.thank_you ?? ''} disabled={!editable} onChange={(e) => s('thank_you', e.target.value || null)} maxLength={1000} /></Field>
          <div className="flex flex-wrap gap-5 md:col-span-2">
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={!!settings.one_per_page} disabled={!editable} onChange={(e) => s('one_per_page', e.target.checked)} />{t('ss.studio.settings.onePerPage')}</label>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={settings.progress !== false} disabled={!editable} onChange={(e) => s('progress', e.target.checked)} />{t('ss.studio.settings.progress')}</label>
            <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={settings.notify !== false} disabled={!editable} onChange={(e) => s('notify', e.target.checked)} />{t('ss.studio.settings.notify')}</label>
            <label className="flex items-center gap-2 text-sm">{t('ss.studio.settings.accent')}<input type="color" className="h-8 w-12 cursor-pointer rounded border border-slate-200" value={settings.accent ?? '#8a1538'} disabled={!editable} onChange={(e) => s('accent', e.target.value)} /></label>
          </div>
        </div>
      </Card>
    </div>
  )
}

// Audience ---------------------------------------------------------------------------------------------------------

const REGIONS = ['doha', 'al_rayyan', 'al_wakrah', 'al_khor', 'al_shamal', 'umm_salal', 'al_daayen', 'al_shahaniya']
function AudienceTab({ id, audience, editable, onChange }: { id: string; audience: Record<string, unknown>; editable: boolean; onChange: (a: Record<string, unknown>) => void }) {
  const { t } = useTranslation()
  const options = useGet<{ data: Record<string, string[]> }>('/admin/surveys/audience/options')
  const preview = useSend<{ audience: Record<string, unknown> }, { data: { count: number; schools: number } }>('post', '/admin/surveys/audience/preview')
  const [size, setSize] = useState<{ count: number; schools: number } | null>(null)
  const list = (k: string) => (audience[k] as string[] | undefined) ?? []
  const toggle = (k: string, v: string) => { const cur = list(k); const next = cur.includes(v) ? cur.filter((x) => x !== v) : [...cur, v]; const a = { ...audience }; if (next.length) a[k] = next; else delete a[k]; onChange(a) }
  const chips = (k: string, values: string[], label?: (v: string) => string) => (
    <Field label={t(`surveys.audience.${{ specializations: 'specializations', nationalities: 'nationalities', qualifications: 'qualifications', stages: 'stages', genders: 'genders', regions: 'regions', school_types: 'schoolTypes' }[k] ?? k}`, { defaultValue: k })}>
      <div className="flex flex-wrap gap-1.5">{values.map((v) => { const on = list(k).includes(v); return <button key={v} type="button" disabled={!editable} onClick={() => toggle(k, v)} className={clsx('rounded-full border px-2.5 py-1 text-xs', on ? 'border-navy-900 bg-navy-900 text-white' : 'border-slate-200 text-slate-600 hover:border-navy-300')}>{label ? label(v) : v}</button> })}</div>
    </Field>
  )
  const o = options.data?.data ?? {}
  return (
    <Card>
      <CardTitle subtitle={t('ss.studio.audience.hint')} action={<Button size="sm" variant="outline" loading={preview.isPending} onClick={async () => { try { const r = await preview.mutateAsync({ audience }); setSize(r.data) } catch (e) { toast(errorMessage(e), 'error') } }}>{t('ss.studio.audience.apply')}</Button>}>{t('ss.studio.audience.title')}</CardTitle>
      {size && <p className="mb-4 rounded-xl bg-gold-50 px-3 py-2 text-sm font-semibold text-gold-800">{t('ss.studio.audience.matched', { n: size.count, s: size.schools })}</p>}
      {!Object.keys(audience).length && <p className="mb-4 text-xs text-slate-500">{t('ss.studio.audience.none')}</p>}
      {void id}
      <div className="grid gap-4 md:grid-cols-2">
        {chips('regions', REGIONS, (v) => String(t(`regions.${v}`, { defaultValue: v })))}
        {chips('school_types', ['government', 'private', 'community', 'international'], (v) => String(t(`schoolTypes.${v}`, { defaultValue: v })))}
        {chips('stages', o.stages ?? [], (v) => String(t(`stages.${v}`, { defaultValue: v })))}
        {chips('genders', o.genders ?? ['male', 'female'], (v) => (v === 'male' ? 'ذكور' : v === 'female' ? 'إناث' : v))}
        {chips('specializations', o.specializations ?? [])}
        {chips('nationalities', o.nationalities ?? [])}
        {chips('qualifications', o.qualifications ?? [])}
      </div>
    </Card>
  )
}

// Versions ---------------------------------------------------------------------------------------------------------

function VersionsTab({ id }: { id: string }) {
  const { t, i18n } = useTranslation()
  const versions = useGet<{ data: { number: number; published_at: string; published_by?: string | null; questions: number; submissions: number; current: boolean }[] }>(`/admin/surveys/${id}/versions`, undefined, { staleTime: 0 })
  const events = useGet<{ data: { event: string; actor?: string | null; data?: Record<string, unknown> | null; at: string }[] }>(`/admin/surveys/${id}/events`, undefined, { staleTime: 0 })
  const en = i18n.language === 'en'
  return (
    <div className="grid gap-5 lg:grid-cols-2">
      <Card><CardTitle>{t('ss.studio.versions.title')}</CardTitle>
        {!versions.data?.data.length ? <p className="text-sm text-slate-500">{t('ss.studio.versions.none')}</p> : (
          <ul className="divide-y divide-slate-100 text-sm">{versions.data.data.map((v) => <li key={v.number} className="flex items-center justify-between py-2"><span className="font-semibold text-navy-900">{t('ss.list.version')} {v.number} {v.current && <Badge color="gold">{t('ss.studio.versions.current')}</Badge>}</span><span className="text-xs text-slate-500">{v.questions} {t('ss.studio.versions.questions')} · {v.submissions} {t('ss.studio.versions.submissions')} · {new Date(v.published_at).toLocaleString(en ? 'en-GB' : 'ar-QA')}{v.published_by ? ` · ${v.published_by}` : ''}</span></li>)}</ul>
        )}
      </Card>
      <Card><CardTitle>{t('ss.studio.versions.events')}</CardTitle>
        {!events.data?.data.length ? <p className="text-sm text-slate-500">{t('ss.studio.versions.noEvents')}</p> : (
          <ol className="relative space-y-2 border-s border-slate-200 ps-4 text-sm">{events.data.data.map((e, i) => <li key={i} className="relative"><span className="absolute -start-[21px] top-1.5 size-2.5 rounded-full bg-gold-400 ring-4 ring-white" /><b className="text-navy-900">{e.event}</b><span className="ms-2 text-xs text-slate-400">{e.actor ? `${e.actor} · ` : ''}{new Date(e.at).toLocaleString(en ? 'en-GB' : 'ar-QA')}</span></li>)}</ol>
        )}
      </Card>
    </div>
  )
}

