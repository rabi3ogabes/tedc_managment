import clsx from 'clsx'
import { AlertCircle, ArrowDown, ArrowLeft, ArrowRight, ArrowUp, Check, CheckCircle2, Clock, EyeOff, ListChecks, Loader2, Paperclip, Save, ShieldCheck, Sparkles, Star, UserCheck, X } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { NO_ANSWER, descOf, evaluate, filled, labelOf, pageProblems, titleOf, type Answer, type Answers, type Question, type Settings } from '@/lib/surveyEngine'

export type EngineSurvey = { title: string; description?: string | null; questions: Question[]; settings?: Settings | null; privacy_mode?: 'identified' | 'confidential' | 'anonymous'; version?: number }

const DEFAULT_ACCENT = '#8A1538'

/**
 * The respondent form of the survey engine: pages from page breaks, the server's logic evaluated live, validation per page
 * with an error summary and scroll-to-error, autosave with resume, submit, edit after submit, thank-you.
 */
export default function EngineForm({ survey, initialAnswers, draft, preview = false, closed = false, canEdit = false, onDraft, onSubmit, onClose }: {
  survey: EngineSurvey
  initialAnswers?: Answers | null
  draft?: { answers: Answers; page?: number } | null
  preview?: boolean
  closed?: boolean
  canEdit?: boolean
  onDraft?: (answers: Answers, page: number) => Promise<unknown>
  onSubmit?: (answers: Answers, durationSeconds: number) => Promise<string | void>
  onClose?: () => void
}) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const settings = survey.settings ?? {}
  const accent = settings.accent ?? DEFAULT_ACCENT
  const [stage, setStage] = useState<'welcome' | 'form' | 'done'>(initialAnswers && !draft ? 'done' : 'welcome')
  const [answers, setAnswers] = useState<Answers>(draft?.answers ?? initialAnswers ?? {})
  const [page, setPage] = useState(draft?.page ?? 0)
  const [showErrors, setShowErrors] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [thanks, setThanks] = useState<string | null>(null)
  const [saved, setSaved] = useState<'idle' | 'saving' | 'saved'>('idle')
  const started = useRef(0)
  const scroller = useRef<HTMLDivElement>(null)
  const dirty = useRef(false)

  const result = useMemo(() => evaluate(survey.questions, answers), [survey.questions, answers])
  const pages = useMemo(() => {
    if (settings.one_per_page) return result.pages.flatMap((p) => p.map((id) => [id]))
    return result.pages.length ? result.pages : [[]]
  }, [result.pages, settings.one_per_page])
  const pageIndex = Math.min(page, pages.length - 1)
  const current = pages[pageIndex] ?? []
  const byId = useMemo(() => Object.fromEntries(survey.questions.map((q) => [q.id, q])), [survey.questions])
  const problems = pageProblems(survey.questions, current, answers)
  const answerable = result.visible.filter((id) => !NO_ANSWER.includes(byId[id].type))
  const answered = answerable.filter((id) => filled(answers[id])).length
  const progress = answerable.length ? Math.round((answered / answerable.length) * 100) : 0
  const last = pageIndex >= pages.length - 1 || result.ended
  const total = survey.questions.filter((q) => !NO_ANSWER.includes(q.type)).length

  // Autosave: a short while after the last change.
  useEffect(() => {
    if (!onDraft || preview || stage !== 'form' || !dirty.current) return
    setSaved('saving')
    const h = setTimeout(async () => { try { await onDraft(answers, pageIndex); setSaved('saved') } catch { setSaved('idle') } }, 1200)
    return () => clearTimeout(h)
  }, [answers, pageIndex, onDraft, preview, stage])

  const set = (id: string, value: Answer) => { dirty.current = true; setAnswers((a) => ({ ...a, [id]: value })) }
  const scrollTop = () => scroller.current?.scrollTo({ top: 0, behavior: 'smooth' })
  const jumpTo = (id: string) => document.getElementById(`eq-${id}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })

  const next = async () => {
    const ids = Object.keys(problems)
    if (ids.length) { setShowErrors(true); jumpTo(ids[0]); return }
    setShowErrors(false)
    if (!last) { setPage(pageIndex + 1); scrollTop(); return }
    if (preview || !onSubmit) { setThanks((en ? settings.thank_you_en : settings.thank_you) ?? null); setStage('done'); return }
    setSubmitting(true); setError(null)
    try {
      const visible = new Set(result.visible)
      const payload = Object.fromEntries(Object.entries(answers).filter(([k, v]) => visible.has(k) && filled(v)))
      const message = await onSubmit(payload, started.current ? Math.round((Date.now() - started.current) / 1000) : 0)
      setThanks(message || (en ? settings.thank_you_en : settings.thank_you) || null)
      setStage('done'); dirty.current = false
    } catch (e) { setError(e instanceof Error ? e.message : String(e)) } finally { setSubmitting(false) }
  }

  const style = { '--accent': accent } as CSSProperties
  const privacyFact = survey.privacy_mode === 'anonymous' ? { icon: <EyeOff className="size-5" />, text: t('ss.form.anonymous') } : survey.privacy_mode === 'confidential' ? { icon: <UserCheck className="size-5" />, text: t('ss.form.confidential') } : { icon: <ShieldCheck className="size-5" />, text: t('ss.form.identified') }

  return (
    <div className="flex min-h-0 flex-1 flex-col" style={style}>
      <div ref={scroller} className="min-h-0 flex-1 overflow-y-auto">
        <div className="relative overflow-hidden px-6 pb-8 pt-10 text-white sm:px-10" style={{ background: `linear-gradient(135deg, ${accent}, color-mix(in srgb, ${accent} 55%, #1a0610))` }}>
          <div className="pattern-bg absolute inset-0 opacity-25" />
          <div className="relative">
            {preview && <div className="mb-3 inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold backdrop-blur"><Sparkles className="size-3.5" />{t('ss.form.preview')}</div>}
            <h2 className="pe-10 font-display text-2xl font-bold leading-snug sm:pe-0 sm:text-3xl">{survey.title}</h2>
            {survey.description && stage !== 'form' && <p className="mt-3 max-w-2xl text-sm leading-7 text-white/80">{survey.description}</p>}
          </div>
        </div>
        {stage === 'form' && settings.progress !== false && (
          <div className="sticky top-0 z-[5] border-b border-navy-100 bg-ivory/90 px-6 py-3 backdrop-blur sm:px-10">
            <div className="flex items-center justify-between text-xs font-semibold text-slate-500">
              <span>{pages.length > 1 ? t('ss.form.page', { a: pageIndex + 1, b: pages.length }) : t('ss.form.questions', { n: answerable.length })}</span>
              <span className="flex items-center gap-3">{onDraft && !preview && <span className="inline-flex items-center gap-1 text-[11px] font-normal text-slate-400">{saved === 'saving' ? <Loader2 className="size-3 animate-spin" /> : saved === 'saved' ? <Save className="size-3" /> : null}{saved === 'saving' ? t('ss.form.saving') : saved === 'saved' ? t('ss.form.saved') : ''}</span>}<span style={{ color: accent }}>{progress}%</span></span>
            </div>
            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-navy-100"><div className="h-full rounded-full transition-all duration-500" style={{ width: `${progress}%`, background: accent }} /></div>
          </div>
        )}
        <div className="px-5 py-6 sm:px-10 sm:py-8">
          {stage === 'welcome' && (
            <div className="mx-auto max-w-xl text-center">
              {(en ? settings.welcome_en || settings.welcome : settings.welcome) && <p className="mb-6 whitespace-pre-line text-base leading-8 text-navy-900">{en ? settings.welcome_en || settings.welcome : settings.welcome}</p>}
              <div className="grid gap-3 text-sm sm:grid-cols-3">
                <Fact icon={<ListChecks className="size-5" />} text={t('ss.form.questions', { n: total })} />
                <Fact icon={<Clock className="size-5" />} text={t('ss.form.minutes', { n: Math.max(1, Math.ceil(total * 0.4)) })} />
                <Fact icon={privacyFact.icon} text={privacyFact.text} />
              </div>
              {draft && <p className="mt-5 inline-flex items-center gap-2 rounded-full bg-gold-100 px-3 py-1 text-xs font-semibold text-gold-800"><Save className="size-3.5" />{t('ss.form.resumed')}</p>}
              {closed ? <p className="mt-8 rounded-2xl bg-slate-100 p-4 font-semibold text-slate-600">{t('ss.form.closed')}</p> : (
                <button onClick={() => { setStage('form'); started.current = Date.now() }} className="mt-8 inline-flex items-center gap-2 rounded-2xl px-8 py-3.5 font-bold text-white shadow-lg transition hover:brightness-110" style={{ background: accent }}>
                  {draft ? t('ss.form.continue') : initialAnswers ? t('ss.form.editAnswers') : t('ss.form.start')}<ArrowLeft className="size-4 ltr:rotate-180" />
                </button>
              )}
            </div>
          )}
          {stage === 'form' && (
            <div className="mx-auto max-w-2xl space-y-5">
              {showErrors && Object.keys(problems).length > 0 && (
                <div className="rounded-2xl border border-red-200 bg-red-50 p-4 text-sm text-red-800" role="alert">
                  <div className="mb-1 flex items-center gap-2 font-bold"><AlertCircle className="size-4" />{t('ss.form.fixFirst', { n: Object.keys(problems).length })}</div>
                  <ul className="list-disc space-y-0.5 ps-5">{Object.entries(problems).map(([id, p]) => <li key={id}><button type="button" className="underline decoration-dotted" onClick={() => jumpTo(id)}>{titleOf(byId[id], en)}</button> — {t(`ss.form.problem.${p}`)}</li>)}</ul>
                </div>
              )}
              {current.map((id) => {
                const q = byId[id]
                if (q.type === 'section' || q.type === 'instructions') return (
                  <div key={q.id} className={clsx('pt-2', q.type === 'instructions' && 'rounded-2xl border border-navy-100 bg-white p-5')}>
                    <h3 className={clsx('font-display font-bold text-navy-900', q.type === 'section' ? 'text-xl' : 'text-base')}>{titleOf(q, en)}</h3>
                    {descOf(q, en) && <p className="mt-1 whitespace-pre-line text-sm leading-7 text-slate-600">{descOf(q, en)}</p>}
                    {q.type === 'section' && <div className="mt-3 h-0.5 w-16 rounded-full" style={{ background: accent }} />}
                  </div>
                )
                const bad = showErrors && problems[q.id]
                return (
                  <div key={q.id} id={`eq-${q.id}`} className={clsx('rounded-2xl border bg-white p-5 shadow-sm transition sm:p-6', bad ? 'border-red-300 ring-4 ring-red-100' : 'border-navy-100')}>
                    <div className="mb-4">
                      <div className="font-bold leading-7 text-navy-900">{titleOf(q, en)}{q.required && <span className="ms-1 text-red-500">*</span>}</div>
                      {descOf(q, en) && <p className="mt-1 text-sm leading-6 text-slate-500">{descOf(q, en)}</p>}
                    </div>
                    <EngineInput q={q} value={answers[q.id]} onChange={(v) => set(q.id, v)} accent={accent} en={en} />
                    {bad && <p className="mt-3 text-xs font-semibold text-red-600">{t(`ss.form.problem.${problems[q.id]}`)}</p>}
                  </div>
                )
              })}
              {error && <p className="rounded-xl bg-red-50 p-3 text-center text-sm text-red-700">{error}</p>}
            </div>
          )}
          {stage === 'done' && (
            <div className="mx-auto max-w-md py-6 text-center">
              <div className="mx-auto grid size-20 place-items-center rounded-full text-white shadow-xl" style={{ background: accent }}><CheckCircle2 className="size-10" /></div>
              <h3 className="mt-6 font-display text-2xl font-bold text-navy-900">{t('ss.form.thanks')}</h3>
              <p className="mt-2 whitespace-pre-line leading-7 text-slate-600">{thanks ?? t('ss.form.thanksText')}</p>
              <div className="mt-8 flex flex-wrap justify-center gap-3">
                {(preview || (canEdit && !closed)) && <button onClick={() => { setStage('form'); setPage(0); dirty.current = false }} className="rounded-xl border border-navy-100 bg-white px-5 py-2.5 text-sm font-semibold text-navy-900 hover:border-gold-400">{t('ss.form.editAnswers')}</button>}
                {onClose && <button onClick={onClose} className="rounded-xl px-5 py-2.5 text-sm font-bold text-white" style={{ background: accent }}>{t('common.close')}</button>}
              </div>
            </div>
          )}
        </div>
      </div>
      {stage === 'form' && (
        <div className="flex items-center justify-between gap-3 border-t border-navy-100 bg-white px-5 py-4 sm:px-10">
          <button onClick={() => { setPage(Math.max(0, pageIndex - 1)); scrollTop() }} disabled={pageIndex === 0} className="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-600 hover:bg-navy-100/60 disabled:opacity-30"><ArrowRight className="size-4 ltr:rotate-180" />{t('ss.form.prev')}</button>
          <button onClick={next} disabled={submitting} className="inline-flex items-center gap-2 rounded-xl px-6 py-2.5 text-sm font-bold text-white shadow-md transition hover:brightness-110 disabled:opacity-60" style={{ background: accent }}>
            {submitting && <Loader2 className="size-4 animate-spin" />}{last ? t('ss.form.submit') : t('ss.form.next')}{!last && <ArrowLeft className="size-4 ltr:rotate-180" />}
          </button>
        </div>
      )}
    </div>
  )
}

function Fact({ icon, text }: { icon: ReactNode; text: string }) {
  return <div className="flex flex-col items-center gap-2 rounded-2xl border border-navy-100 bg-white p-4 text-slate-600"><span style={{ color: 'var(--accent)' }}>{icon}</span>{text}</div>
}

const choiceClass = (active: boolean) => clsx('flex w-full items-center gap-3 rounded-xl border px-4 py-3 text-start text-sm transition', active ? 'border-[var(--accent)] bg-[color-mix(in_srgb,var(--accent)_8%,white)] font-semibold text-navy-900' : 'border-navy-100 bg-white hover:border-navy-300')

/** One answer control per type. */
export function EngineInput({ q, value, onChange, accent, en }: { q: Question; value: Answer; onChange: (v: Answer) => void; accent: string; en: boolean }) {
  const { t } = useTranslation()
  const scale = q.scale ?? { min: 1, max: 5 }
  const points = Array.from({ length: scale.max - scale.min + 1 }, (_, i) => scale.min + i)
  const otherText = value && typeof value === 'object' && !Array.isArray(value) && 'other' in value ? (value as { other: string }).other : null

  switch (q.type) {
    case 'short_text': return <input className="input" value={(value as string) ?? ''} maxLength={q.max_length ?? 500} placeholder={q.placeholder ?? ''} onChange={(e) => onChange(e.target.value || undefined)} />
    case 'long_text': return <textarea className="input min-h-28" value={(value as string) ?? ''} maxLength={q.max_length ?? 5000} placeholder={q.placeholder ?? ''} onChange={(e) => onChange(e.target.value || undefined)} />
    case 'email': return <input className="input max-w-md" type="email" dir="ltr" inputMode="email" value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value || undefined)} />
    case 'phone': return <input className="input max-w-xs" type="tel" dir="ltr" inputMode="tel" value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value || undefined)} />
    case 'number': return <input className="input max-w-48" type="number" inputMode="decimal" min={q.min ?? undefined} max={q.max ?? undefined} value={value === undefined ? '' : String(value)} onChange={(e) => onChange(e.target.value === '' ? undefined : Number(e.target.value))} />
    case 'date': return <input className="input max-w-56" type="date" value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value || undefined)} />
    case 'time': return <input className="input max-w-40" type="time" value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value || undefined)} />
    case 'dropdown': return (
      <select className="input" value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value || undefined)}>
        <option value="">{t('ss.form.select')}</option>{q.options?.map((o) => <option key={o.id} value={o.id}>{labelOf(o, en)}</option>)}
      </select>
    )
    case 'single': case 'likert': return (
      <div className={clsx('grid gap-2', q.type === 'likert' ? 'sm:grid-cols-5' : 'sm:grid-cols-2')}>
        {q.options?.map((o) => (
          <button type="button" key={o.id} onClick={() => onChange(value === o.id ? undefined : o.id)} className={clsx(choiceClass(value === o.id), q.type === 'likert' && 'justify-center text-center')}>
            {q.type !== 'likert' && <span className={clsx('grid size-5 shrink-0 place-items-center rounded-full border-2', value === o.id ? 'border-[var(--accent)]' : 'border-slate-300')}>{value === o.id && <span className="size-2.5 rounded-full" style={{ background: accent }} />}</span>}
            {labelOf(o, en)}
          </button>
        ))}
        {q.other && (
          <div className={clsx(choiceClass(otherText !== null), 'sm:col-span-2')}>
            <span className={clsx('grid size-5 shrink-0 place-items-center rounded-full border-2', otherText !== null ? 'border-[var(--accent)]' : 'border-slate-300')} />
            <input className="w-full bg-transparent outline-none" placeholder={String(t('ss.form.other'))} value={otherText ?? ''} onChange={(e) => onChange(e.target.value ? { other: e.target.value } : undefined)} />
          </div>
        )}
      </div>
    )
    case 'multiple': {
      const list = (value as string[] | undefined) ?? []
      const full = !!q.max_select && list.length >= q.max_select
      return (
        <>
          {(q.max_select || q.min_select) ? <div className="mb-2 text-xs font-semibold text-slate-400">{q.max_select ? t('ss.form.chooseUpTo', { n: q.max_select }) : ''}{q.min_select ? ` · ${t('ss.form.chooseAtLeast', { n: q.min_select })}` : ''} · {list.length}</div> : null}
          <div className="grid gap-2 sm:grid-cols-2">
            {q.options?.map((o) => { const on = list.includes(o.id); return (
              <button type="button" key={o.id} disabled={!on && full} onClick={() => { const n = on ? list.filter((x) => x !== o.id) : [...list, o.id]; onChange(n.length ? n : undefined) }} className={clsx(choiceClass(on), !on && full && 'opacity-40')}>
                <span className={clsx('grid size-5 shrink-0 place-items-center rounded-md border-2 text-white', on ? 'border-transparent' : 'border-slate-300')} style={on ? { background: accent } : undefined}>{on && <Check className="size-3.5" />}</span>{labelOf(o, en)}
              </button>) })}
          </div>
        </>
      )
    }
    case 'yes_no': return (
      <div className="grid max-w-sm grid-cols-2 gap-3">{(['yes', 'no'] as const).map((v) => <button type="button" key={v} onClick={() => onChange(value === v ? undefined : v)} className={clsx(choiceClass(value === v), 'justify-center py-4 text-base font-bold')}>{t(`ss.form.${v}`)}</button>)}</div>
    )
    case 'rating': return (
      <div className="flex flex-wrap gap-1.5">{points.filter((p) => p > 0).map((p) => <button type="button" key={p} aria-label={String(p)} onClick={() => onChange(value === p ? undefined : p)} className="rounded-lg p-1 transition hover:scale-110"><Star className="size-9" strokeWidth={1.5} style={{ color: accent }} fill={typeof value === 'number' && p <= value ? accent : 'transparent'} /></button>)}</div>
    )
    case 'scale': case 'nps': {
      const pts = q.type === 'nps' ? Array.from({ length: 11 }, (_, i) => i) : points
      const low = q.type === 'nps' ? t('ss.form.notLikely') : scale.min_label
      const high = q.type === 'nps' ? t('ss.form.veryLikely') : scale.max_label
      return (
        <div>
          <div className="flex gap-1.5 sm:gap-2">{pts.map((p) => { const on = value === p; return <button type="button" key={p} onClick={() => onChange(on ? undefined : p)} className={clsx('h-11 min-w-0 flex-1 rounded-xl border text-sm font-bold transition', on ? 'border-transparent text-white' : 'border-navy-100 bg-white text-navy-900 hover:border-navy-300')} style={on ? { background: accent } : undefined}>{p}</button> })}</div>
          {(low || high) && <div className="mt-2 flex justify-between text-xs text-slate-400"><span>{low}</span><span>{high}</span></div>}
        </div>
      )
    }
    case 'matrix': {
      const grid = (value as Record<string, number> | undefined) ?? {}
      return (
        <div className="space-y-3">
          <div className="hidden items-end gap-3 text-center text-[11px] font-semibold leading-4 text-slate-400 sm:flex"><div className="w-[40%] shrink-0" /><div className="flex flex-1 gap-1.5">{points.map((p) => <span key={p} className="flex-1">{p === scale.min ? scale.min_label ?? p : p === scale.max ? scale.max_label ?? p : p}</span>)}</div></div>
          {q.rows?.map((row) => (
            <div key={row.id} className={clsx('rounded-xl border p-3 transition sm:flex sm:items-center sm:gap-3 sm:border-0 sm:p-0', grid[row.id] ? 'border-navy-100' : 'border-dashed border-navy-100')}>
              <div className="mb-2 text-sm font-medium text-navy-900 sm:mb-0 sm:w-[40%] sm:shrink-0">{labelOf(row, en)}</div>
              <div className="flex flex-1 gap-1.5">{points.map((p) => { const on = grid[row.id] === p; return <button type="button" key={p} aria-label={`${row.label}: ${p}`} onClick={() => onChange({ ...grid, [row.id]: p })} className={clsx('h-10 flex-1 rounded-lg border text-sm font-bold transition', on ? 'border-transparent text-white' : 'border-navy-100 bg-white text-navy-900 hover:border-navy-300')} style={on ? { background: accent } : undefined}>{p}</button> })}</div>
            </div>
          ))}
        </div>
      )
    }
    case 'ranking': {
      const order = (value as string[] | undefined) ?? q.options?.map((o) => o.id) ?? []
      const byId = Object.fromEntries((q.options ?? []).map((o) => [o.id, o]))
      const move = (i: number, d: number) => { const n = [...order]; [n[i], n[i + d]] = [n[i + d], n[i]]; onChange(n) }
      return (
        <div>
          <p className="mb-3 text-xs text-slate-400">{t('ss.form.rankHint')}</p>
          <ol className="space-y-2">{order.map((id, i) => (
            <li key={id} className={clsx('flex items-center gap-3 rounded-xl border bg-white px-3 py-2.5 text-sm', value ? 'border-navy-100' : 'border-dashed border-navy-100')}>
              <span className="grid size-7 shrink-0 place-items-center rounded-full text-xs font-bold text-white" style={{ background: accent, opacity: 1 - i * 0.12 }}>{i + 1}</span>
              <span className="flex-1 font-medium text-navy-900">{byId[id] ? labelOf(byId[id], en) : id}</span>
              <button type="button" aria-label="up" disabled={i === 0} onClick={() => move(i, -1)} className="rounded-lg p-1.5 text-slate-400 hover:bg-navy-100/60 disabled:opacity-30"><ArrowUp className="size-4" /></button>
              <button type="button" aria-label="down" disabled={i === order.length - 1} onClick={() => move(i, 1)} className="rounded-lg p-1.5 text-slate-400 hover:bg-navy-100/60 disabled:opacity-30"><ArrowDown className="size-4" /></button>
            </li>))}</ol>
          {!value && <button type="button" onClick={() => onChange(order)} className="mt-3 text-xs font-semibold underline decoration-dotted" style={{ color: accent }}>{t('ss.form.rankConfirm')}</button>}
        </div>
      )
    }
    case 'file_upload': return (
      <div className="rounded-xl border border-dashed border-navy-200 bg-slate-50 p-4 text-sm text-slate-500">
        <div className="flex items-center gap-2 font-semibold text-navy-900"><Paperclip className="size-4" />{t('ss.form.fileSoon')}</div>
        <p className="mt-1 text-xs">{t('ss.form.fileRules', { n: q.max_files ?? 1, mb: q.max_mb ?? 10, types: (q.accept ?? []).join(', ') })}</p>
        {Array.isArray(value) && value.length > 0 && <button type="button" className="mt-2 inline-flex items-center gap-1 text-xs text-red-600" onClick={() => onChange(undefined)}><X className="size-3" />{t('common.remove', { defaultValue: 'Remove' })}</button>}
      </div>
    )
    default: return null
  }
}
