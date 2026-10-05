import clsx from 'clsx'
import { ArrowRight, BarChart3, Check, CloudOff, Eye, GitBranch, Link2, Loader2, PenLine, Send, Settings2, Users, Wand2 } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams, useSearchParams } from 'react-router-dom'
import SurveyForm, { SurveyDialog } from '@/components/surveys/SurveyForm'
import { Button, ErrorState, StatusBadge } from '@/components/ui'
import { Skeleton } from '@/components/ui/Skeleton'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { autoLinkSkills, newQuestion, QUESTION_TYPES, uid, type Question, type QuestionType, type Skill, type Survey } from '@/lib/surveys'
import QuestionEditor, { linkedCount, TYPE_ICONS } from './QuestionEditor'
import { AudienceDialog, PublishDialog, SettingsDialog } from './StudioDialogs'
import SurveyReport from './SurveyReport'

type SaveState = 'saved' | 'dirty' | 'saving' | 'error'

export default function SurveyStudio() {
  const { id } = useParams()
  const { t } = useTranslation()
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const query = useGet<{ data: Survey }>(`/admin/needs-surveys/${id}`, undefined, { staleTime: 0 })
  const lookups = useGet<{ data: { skills: Skill[] } }>('/admin/lookups', undefined, { staleTime: 10 * 60_000 })
  const [draft, setDraft] = useState<Survey | null>(null)
  const [selected, setSelected] = useState<string | null>(null)
  const [save, setSave] = useState<SaveState>('saved')
  const [saveError, setSaveError] = useState<string | null>(null)
  const [dialog, setDialog] = useState<'preview' | 'audience' | 'settings' | 'publish' | null>(null)
  const [toast, setToast] = useState<string | null>(null)
  const latest = useRef<Survey | null>(null)
  const timer = useRef<ReturnType<typeof setTimeout>>(undefined)
  const view = params.get('view') === 'results' ? 'results' : 'design'
  const skills = lookups.data?.data.skills ?? []

  useEffect(() => {
    if (query.data && !draft) { setDraft(query.data.data); latest.current = query.data.data }
  }, [query.data, draft])

  const persist = useCallback(async () => {
    const s = latest.current
    if (!s) return
    setSave('saving')
    try {
      const { data } = await api.put(`/admin/needs-surveys/${s.id}`, { title: s.title, description: s.description, questions: s.questions, audience: s.audience, settings: s.settings, closes_at: s.closes_at })
      // Keep the local editing state; only refresh server-side summary fields.
      setDraft((d) => (d ? { ...d, audience_summary: data.data.audience_summary, questions_count: data.data.questions_count, status: data.data.status } : d))
      setSave((st) => (st === 'saving' ? 'saved' : st))
      setSaveError(null)
    } catch (e) {
      setSave('error')
      setSaveError(errorMessage(e))
    }
  }, [])

  const update = useCallback((patch: Partial<Survey>, immediate = false) => {
    setDraft((d) => {
      if (!d) return d
      const next = { ...d, ...patch }
      latest.current = next
      return next
    })
    setSave('dirty')
    clearTimeout(timer.current)
    timer.current = setTimeout(persist, immediate ? 0 : 1200)
  }, [persist])

  useEffect(() => () => clearTimeout(timer.current), [])
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => { if ((e.metaKey || e.ctrlKey) && e.key === 's') { e.preventDefault(); clearTimeout(timer.current); persist() } }
    const beforeUnload = (e: BeforeUnloadEvent) => { if (save === 'dirty' || save === 'saving') e.preventDefault() }
    window.addEventListener('keydown', onKey)
    window.addEventListener('beforeunload', beforeUnload)
    return () => { window.removeEventListener('keydown', onKey); window.removeEventListener('beforeunload', beforeUnload) }
  }, [persist, save])

  if (query.error) return <ErrorState onRetry={() => query.refetch()} />
  if (!draft) return <div className="space-y-4"><Skeleton className="h-24 w-full rounded-3xl" /><Skeleton className="h-[480px] w-full rounded-3xl" /></div>

  const questions = draft.questions
  const setQuestions = (qs: Question[]) => update({ questions: qs })
  const insert = (type: QuestionType) => {
    const q = newQuestion(type, t)
    const at = selected ? questions.findIndex((x) => x.id === selected) + 1 : questions.length
    setQuestions([...questions.slice(0, at), q, ...questions.slice(at)])
    setSelected(q.id)
    setTimeout(() => document.getElementById(`qe-${q.id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }), 50)
  }
  const replace = (q: Question) => setQuestions(questions.map((x) => (x.id === q.id ? q : x)))
  const move = (i: number, d: number) => { const n = [...questions]; [n[i], n[i + d]] = [n[i + d], n[i]]; setQuestions(n) }
  const smartLink = () => {
    const { questions: linked, linked: n } = autoLinkSkills(questions, skills)
    setQuestions(linked)
    flash(t('surveys.builder.autoLinked', { n }))
  }
  const flash = (msg: string) => { setToast(msg); setTimeout(() => setToast(null), 2500) }
  const refresh = async () => { const { data } = await query.refetch(); if (data) setDraft((d) => (d ? { ...d, ...data.data, questions: d.questions } : d)) }

  const numbered = questions.reduce<Record<string, number>>((acc, q) => { if (q.type !== 'section') acc[q.id] = Object.keys(acc).length + 1; return acc }, {})
  const totalLinked = questions.reduce((n, q) => n + linkedCount(q), 0)
  const accent = draft.settings.accent ?? '#8A1538'

  return (
    <div className="space-y-6">
      {/* Header */}
      <div className="card overflow-hidden">
        <div className="h-1.5" style={{ background: `linear-gradient(90deg, ${accent}, #A29475)` }} />
        <div className="flex flex-col gap-4 p-5 lg:flex-row lg:items-center">
          <div className="min-w-0 flex-1">
            <Link to="/admin/needs?tab=surveys" className="inline-flex items-center gap-1 text-xs font-semibold text-slate-400 hover:text-navy-900"><ArrowRight className="size-3.5 ltr:rotate-180" />{t('surveys.actions.back')}</Link>
            <div className="mt-1 flex items-center gap-3">
              <input className="min-w-0 flex-1 bg-transparent font-display text-2xl font-bold text-navy-900 outline-none focus:underline focus:decoration-gold-400 focus:decoration-2 focus:underline-offset-8" value={draft.title} onChange={(e) => update({ title: e.target.value })} aria-label={t('surveys.builder.titlePlaceholder')} />
              <StatusBadge status={draft.status} label={t(`surveys.status.${draft.status}`)} />
            </div>
            <SaveIndicator state={save} error={saveError} />
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Button variant="outline" size="sm" icon={<Eye className="size-4" />} onClick={() => setDialog('preview')}>{t('surveys.actions.preview')}</Button>
            <Button variant="outline" size="sm" icon={<Users className="size-4" />} onClick={() => setDialog('audience')}><span className="max-w-40 truncate">{draft.audience_summary}</span></Button>
            <Button variant="outline" size="sm" icon={<Settings2 className="size-4" />} onClick={() => setDialog('settings')}>{t('surveys.builder.settings')}</Button>
            <StatusBadge status={draft.approval_status ?? 'draft'} label={t(`needsHub.instrument.status.${draft.approval_status ?? 'draft'}`)} />
            {draft.approval_status !== 'approved' && draft.approval_status !== 'pending' && <Button variant="outline" size="sm" onClick={async () => { clearTimeout(timer.current); await persist(); await api.post(`/admin/needs-surveys/${draft.id}/submit-approval`).then(() => setDraft({ ...draft, approval_status: 'pending' })).catch((e) => setToast(errorMessage(e))) }}>{t('needsHub.instrument.submit')}</Button>}
            {draft.approval_status === 'pending' && can('instruments.approve') && <>
              <Button variant="outline" size="sm" onClick={() => void api.post(`/admin/needs-surveys/${draft.id}/approve`).then(() => setDraft({ ...draft, approval_status: 'approved' })).catch((e) => setToast(errorMessage(e)))}>{t('needsHub.instrument.approve')}</Button>
              <Button variant="outline" size="sm" onClick={() => { const note = window.prompt(t('needsHub.instrument.return')); if (note) void api.post(`/admin/needs-surveys/${draft.id}/return`, { note }).then(() => setDraft({ ...draft, approval_status: 'returned', approval_note: note })).catch((e) => setToast(errorMessage(e))) }}>{t('needsHub.instrument.return')}</Button></>}
            <Button variant="gold" size="sm" disabled={draft.approval_status !== 'approved'} icon={<Send className="size-4" />} onClick={async () => { clearTimeout(timer.current); await persist(); setDialog('publish') }}>{draft.status === 'draft' ? t('surveys.actions.publish') : t('surveys.actions.republish')}</Button>
          </div>
        </div>
        <div className="flex gap-1 border-t border-navy-100 px-5">
          {(['design', 'results'] as const).map((v) => (
            <button key={v} onClick={() => setParams(v === 'results' ? { view: 'results' } : {})} className={clsx('-mb-px inline-flex items-center gap-2 border-b-2 px-4 py-3 text-sm font-semibold transition', view === v ? 'border-gold-500 text-navy-900' : 'border-transparent text-slate-400 hover:text-navy-900')}>
              {v === 'design' ? <PenLine className="size-4" /> : <BarChart3 className="size-4" />}{t(`surveys.builder.${v}`)}
              {v === 'results' && draft.responses_count > 0 && <span className="rounded-full bg-gold-100 px-2 text-xs text-gold-700">{fmt.number(draft.responses_count)}</span>}
            </button>
          ))}
        </div>
      </div>

      {view === 'results' ? (
        draft.status === 'draft' ? <div className="card p-10 text-center text-slate-500">{t('surveys.report.noData')}</div> : <SurveyReport survey={draft} onChanged={refresh} />
      ) : (
        <div className="grid gap-6 lg:grid-cols-[250px_1fr]">
          {/* Palette */}
          <aside className="lg:sticky lg:top-24 lg:self-start">
            <div className="card p-4">
              <div className="mb-3 text-xs font-bold uppercase tracking-wider text-slate-400">{t('surveys.builder.palette')}</div>
              {(['choice', 'scale', 'text', 'layout'] as const).map((group) => (
                <div key={group} className="mb-3">
                  <div className="mb-1.5 text-[11px] font-semibold text-gold-700">{t(`surveys.groups.${group}`)}</div>
                  <div className="grid grid-cols-2 gap-1.5 lg:grid-cols-1">
                    {QUESTION_TYPES.filter((x) => x.group === group).map(({ type }) => {
                      const Icon = TYPE_ICONS[type]
                      return <button key={type} onClick={() => insert(type)} className="flex items-center gap-2 rounded-xl border border-transparent px-2.5 py-2 text-start text-sm text-slate-600 transition hover:border-gold-300 hover:bg-gold-100/40 hover:text-navy-900"><Icon className="size-4 text-gold-600" />{t(`surveys.types.${type}`)}</button>
                    })}
                  </div>
                </div>
              ))}
            </div>
            <div className="mt-4 rounded-2xl bg-gradient-to-br from-navy-950 to-navy-800 p-4 text-white">
              <div className="flex items-center gap-2 text-sm font-bold text-gold-300"><Wand2 className="size-4" />{t('surveys.builder.autoLink')}</div>
              <p className="mt-2 text-xs leading-5 text-white/60">{t('surveys.builder.skillHint')}</p>
              <div className="mt-3 flex items-center justify-between"><span className="text-xs text-white/70"><Link2 className="me-1 inline size-3.5" />{t('surveys.builder.linkedCount', { n: totalLinked })}</span><Button size="sm" variant="light" onClick={smartLink} disabled={!skills.length}>✨</Button></div>
            </div>
          </aside>

          {/* Canvas */}
          <div className="space-y-3">
            <div className="card p-5">
              <textarea rows={2} className="w-full resize-none bg-transparent text-sm leading-7 text-slate-600 outline-none placeholder:text-slate-300" placeholder={t('surveys.builder.descPlaceholder')} value={draft.description ?? ''} onChange={(e) => update({ description: e.target.value })} />
            </div>
            {!questions.length && <div className="card p-10 text-center text-sm text-slate-400">{t('surveys.builder.empty')}</div>}
            {questions.map((q, i) => {
              const Icon = TYPE_ICONS[q.type]
              const open = selected === q.id
              const links = linkedCount(q)
              return (
                <div key={q.id} id={`qe-${q.id}`} className={clsx('card transition', open ? 'ring-2 ring-gold-400' : 'hover:shadow-glass', q.type === 'section' && !open && 'bg-navy-900 text-white')}>
                  {open ? (
                    <div className="p-5">
                      <QuestionEditor
                        q={q} index={numbered[q.id] ?? 0} skills={skills}
                        previous={questions.slice(0, i).filter((p) => p.type !== 'section')}
                        onChange={replace}
                        onRemove={() => { setQuestions(questions.filter((x) => x.id !== q.id)); setSelected(null) }}
                        onDuplicate={() => { const copy = { ...structuredClone(q), id: uid() }; setQuestions([...questions.slice(0, i + 1), copy, ...questions.slice(i + 1)]); setSelected(copy.id) }}
                        onMove={(d) => move(i, d)} canUp={i > 0} canDown={i < questions.length - 1}
                      />
                    </div>
                  ) : (
                    <button onClick={() => setSelected(q.id)} className="flex w-full items-start gap-4 p-4 text-start">
                      <span className={clsx('grid size-9 shrink-0 place-items-center rounded-xl', q.type === 'section' ? 'bg-white/10 text-gold-300' : 'bg-gold-100/60 text-gold-700')}><Icon className="size-4" /></span>
                      <span className="min-w-0 flex-1">
                        <span className={clsx('block font-semibold leading-7', q.type === 'section' ? 'font-display text-lg' : 'text-navy-900')}>
                          {numbered[q.id] && <span className="me-2 text-slate-400">{numbered[q.id]}.</span>}{q.title}{q.required && <span className="ms-1 text-red-500">*</span>}
                        </span>
                        <span className={clsx('mt-1 flex flex-wrap gap-2 text-[11px]', q.type === 'section' ? 'text-white/50' : 'text-slate-400')}>
                          <span>{t(`surveys.types.${q.type}`)}</span>
                          {q.options && <span>· {q.options.length} {t('surveys.builder.options')}</span>}
                          {q.rows && <span>· {q.rows.length} {t('surveys.builder.rows')}</span>}
                          {links > 0 && <span className="font-semibold text-gold-700"><Link2 className="me-0.5 inline size-3" />{links}</span>}
                          {q.show_if?.question && <span className="font-semibold text-sky-600"><GitBranch className="me-0.5 inline size-3" />{t('surveys.builder.logic')}</span>}
                        </span>
                      </span>
                    </button>
                  )}
                </div>
              )
            })}
            <div className="flex flex-wrap justify-center gap-2 pt-2">
              {(['matrix', 'multiple', 'single', 'long_text'] as QuestionType[]).map((type) => {
                const Icon = TYPE_ICONS[type]
                return <button key={type} onClick={() => insert(type)} className="inline-flex items-center gap-1.5 rounded-full border border-dashed border-navy-100 bg-white px-4 py-2 text-sm font-semibold text-slate-500 transition hover:border-gold-400 hover:text-navy-900"><Icon className="size-4 text-gold-600" />{t(`surveys.types.${type}`)}</button>
              })}
            </div>
          </div>
        </div>
      )}

      {toast && <div className="fixed bottom-6 start-1/2 z-50 -translate-x-1/2 animate-fade-up rounded-full bg-navy-900 px-5 py-2.5 text-sm font-semibold text-white shadow-2xl rtl:translate-x-1/2">{toast}</div>}

      <SurveyDialog open={dialog === 'preview'} onClose={() => setDialog(null)} label={t('surveys.actions.preview')}>
        {dialog === 'preview' && <SurveyForm preview survey={{ title: draft.title, description: draft.description, questions: draft.questions, settings: draft.settings }} onClose={() => setDialog(null)} />}
      </SurveyDialog>
      <AudienceDialog open={dialog === 'audience'} value={draft.audience} onClose={() => setDialog(null)} onApply={(audience) => update({ audience }, true)} />
      <SettingsDialog open={dialog === 'settings'} survey={draft} onClose={() => setDialog(null)} onApply={(settings, closes_at) => update({ settings, closes_at }, true)} />
      <PublishDialog open={dialog === 'publish'} survey={draft} onClose={() => setDialog(null)} onPublished={(s) => setDraft((d) => (d ? { ...d, ...s, questions: d.questions } : d))} />
    </div>
  )
}

function SaveIndicator({ state, error }: { state: SaveState; error: string | null }) {
  const { t } = useTranslation()
  const map = {
    saved: { icon: <Check className="size-3.5" />, text: t('surveys.actions.saved'), cls: 'text-emerald-600' },
    dirty: { icon: <span className="size-2 rounded-full bg-amber-400" />, text: t('surveys.actions.unsaved'), cls: 'text-amber-600' },
    saving: { icon: <Loader2 className="size-3.5 animate-spin" />, text: t('surveys.actions.saving'), cls: 'text-slate-400' },
    error: { icon: <CloudOff className="size-3.5" />, text: error ?? '', cls: 'text-red-600' },
  }[state]
  return <div className={clsx('mt-1 inline-flex items-center gap-1.5 text-xs font-semibold', map.cls)}>{map.icon}{map.text}</div>
}
