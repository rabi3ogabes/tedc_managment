import clsx from 'clsx'
import { ArrowDown, ArrowLeft, ArrowRight, ArrowUp, Check, CheckCircle2, Clock, EyeOff, ListChecks, Loader2, ShieldCheck, Sparkles, Star, X } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { isVisible, missingRequired, paginate, type Answer, type Answers, type Question, type SurveySettings } from '@/lib/surveys'

export type FormSurvey = {
  title: string
  description?: string | null
  questions: Question[]
  settings?: SurveySettings
  anonymous?: boolean
  accent?: string | null
  estimated_minutes?: number
}

const DEFAULT_ACCENT = '#8A1538'

/** Large, full-screen-on-mobile popup used for survey forms, previews and reports. */
export function SurveyDialog({ open, onClose, children, size = 'lg', label }: { open: boolean; onClose: () => void; children: ReactNode; size?: 'md' | 'lg' | 'xl'; label?: string }) {
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && onClose()
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    window.addEventListener('keydown', onKey)
    return () => { window.removeEventListener('keydown', onKey); document.body.style.overflow = overflow }
  }, [open, onClose])
  if (!open) return null
  return (
    <div className="fixed inset-0 z-50 flex items-stretch justify-center bg-navy-950/60 backdrop-blur-md sm:items-center sm:p-6" onMouseDown={onClose} role="dialog" aria-modal="true" aria-label={label}>
      <div
        className={clsx('relative flex w-full animate-fade-up flex-col overflow-hidden bg-ivory shadow-2xl sm:max-h-[92vh] sm:rounded-3xl', { md: 'sm:max-w-2xl', lg: 'sm:max-w-3xl', xl: 'sm:max-w-6xl' }[size])}
        onMouseDown={(e) => e.stopPropagation()}
      >
        <button onClick={onClose} aria-label="close" className="absolute end-4 top-4 z-10 rounded-full bg-white/80 p-2 text-slate-500 shadow-sm backdrop-blur transition hover:bg-white hover:text-navy-900"><X className="size-5" /></button>
        {children}
      </div>
    </div>
  )
}

export default function SurveyForm({ survey, initialAnswers, preview = false, closed = false, onSubmit, onClose }: {
  survey: FormSurvey
  initialAnswers?: Answers | null
  preview?: boolean
  closed?: boolean
  onSubmit?: (answers: Answers, durationSeconds: number) => Promise<string | void>
  onClose?: () => void
}) {
  const { t } = useTranslation()
  const settings = survey.settings ?? {}
  const accent = survey.accent ?? settings.accent ?? DEFAULT_ACCENT
  const [stage, setStage] = useState<'welcome' | 'form' | 'done'>(initialAnswers ? 'done' : 'welcome')
  const [answers, setAnswers] = useState<Answers>(initialAnswers ?? {})
  const [page, setPage] = useState(0)
  const [showErrors, setShowErrors] = useState(false)
  const [submitting, setSubmitting] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [thanks, setThanks] = useState<string | null>(null)
  const started = useRef(0)
  const scroller = useRef<HTMLDivElement>(null)

  const pages = useMemo(() => {
    const visible = survey.questions.filter((q) => isVisible(q, answers))
    return paginate(visible, settings.one_per_page)
  }, [survey.questions, answers, settings.one_per_page])
  const current = pages[Math.min(page, pages.length - 1)] ?? []
  const missing = missingRequired(current, answers)
  const total = survey.questions.filter((q) => q.type !== 'section').length
  const answeredCount = survey.questions.filter((q) => q.type !== 'section' && isVisible(q, answers) && answers[q.id] !== undefined).length
  const progress = total ? Math.round((answeredCount / Math.max(1, survey.questions.filter((q) => q.type !== 'section' && isVisible(q, answers)).length)) * 100) : 0
  const last = page >= pages.length - 1

  const set = (id: string, value: Answer | undefined) => setAnswers((a) => ({ ...a, [id]: value }))
  const scrollTop = () => scroller.current?.scrollTo({ top: 0, behavior: 'smooth' })

  const next = async () => {
    if (missing.length) { setShowErrors(true); document.getElementById(`sq-${missing[0]}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' }); return }
    setShowErrors(false)
    if (!last) { setPage((p) => p + 1); scrollTop(); return }
    if (preview || !onSubmit) { setThanks(settings.thank_you ?? null); setStage('done'); return }
    setSubmitting(true)
    setError(null)
    try {
      // Only answers to questions that are still visible are sent.
      const visibleIds = new Set(survey.questions.filter((q) => isVisible(q, answers)).map((q) => q.id))
      const payload = Object.fromEntries(Object.entries(answers).filter(([k, v]) => visibleIds.has(k) && v !== undefined))
      const message = await onSubmit(payload, started.current ? Math.round((Date.now() - started.current) / 1000) : 0)
      setThanks(message || settings.thank_you || null)
      setStage('done')
    } catch (e) {
      setError(e instanceof Error ? e.message : String(e))
    } finally {
      setSubmitting(false)
    }
  }

  const style = { '--accent': accent } as CSSProperties
  const header = (
    <div className="relative overflow-hidden px-6 pb-8 pt-10 text-white sm:px-10" style={{ background: `linear-gradient(135deg, ${accent}, color-mix(in srgb, ${accent} 55%, #1a0610))` }}>
      <div className="pattern-bg absolute inset-0 opacity-25" />
      <svg className="absolute -bottom-10 -start-10 size-48 text-white/10" viewBox="0 0 100 100" aria-hidden><path d="M50 2 L61 39 L98 50 L61 61 L50 98 L39 61 L2 50 L39 39 Z" fill="currentColor" /></svg>
      <div className="relative">
        {preview && <div className="mb-3 inline-flex items-center gap-1.5 rounded-full bg-white/15 px-3 py-1 text-xs font-semibold backdrop-blur"><Sparkles className="size-3.5" />{t('surveys.form.previewBanner')}</div>}
        <h2 className="pe-10 font-display text-2xl font-bold leading-snug sm:pe-0 sm:text-3xl">{survey.title}</h2>
        {survey.description && stage !== 'form' && <p className="mt-3 max-w-2xl text-sm leading-7 text-white/80">{survey.description}</p>}
      </div>
    </div>
  )

  return (
    <div className="flex min-h-0 flex-1 flex-col" style={style}>
      <div ref={scroller} className="min-h-0 flex-1 overflow-y-auto">
        {header}
        {stage === 'form' && settings.show_progress !== false && (
          <div className="sticky top-0 z-[5] border-b border-navy-100 bg-ivory/90 px-6 py-3 backdrop-blur sm:px-10">
            <div className="flex items-center justify-between text-xs font-semibold text-slate-500">
              <span>{pages.length > 1 ? t('surveys.form.page', { a: page + 1, b: pages.length }) : t('surveys.form.questions', { n: total })}</span>
              <span style={{ color: accent }}>{progress}%</span>
            </div>
            <div className="mt-2 h-1.5 overflow-hidden rounded-full bg-navy-100"><div className="h-full rounded-full transition-all duration-500" style={{ width: `${progress}%`, background: accent }} /></div>
          </div>
        )}

        <div className="px-5 py-6 sm:px-10 sm:py-8">
          {stage === 'welcome' && (
            <div className="mx-auto max-w-xl text-center">
              {settings.welcome && <p className="mb-6 whitespace-pre-line text-base leading-8 text-navy-900">{settings.welcome}</p>}
              <div className="grid gap-3 text-sm sm:grid-cols-3">
                <Fact icon={<ListChecks className="size-5" />} text={t('surveys.form.questions', { n: total })} />
                <Fact icon={<Clock className="size-5" />} text={t('surveys.form.minutes', { n: survey.estimated_minutes ?? Math.max(1, Math.ceil(total * 0.4)) })} />
                <Fact icon={survey.anonymous || settings.anonymous ? <EyeOff className="size-5" /> : <ShieldCheck className="size-5" />} text={survey.anonymous || settings.anonymous ? t('surveys.form.anonymous') : t('surveys.form.secure')} />
              </div>
              <p className="mt-5 text-xs leading-6 text-slate-500">{t('surveys.form.profileNote')}</p>
              {closed ? (
                <p className="mt-8 rounded-2xl bg-slate-100 p-4 font-semibold text-slate-600">{t('surveys.form.closed')}</p>
              ) : (
                <button onClick={() => { setStage('form'); started.current = Date.now() }} className="mt-8 inline-flex items-center gap-2 rounded-2xl px-8 py-3.5 font-bold text-white shadow-lg transition hover:brightness-110" style={{ background: accent }}>
                  {t('surveys.form.start')}<ArrowLeft className="size-4 ltr:rotate-180" />
                </button>
              )}
            </div>
          )}

          {stage === 'form' && (
            <div className="mx-auto max-w-2xl space-y-5">
              {current.map((q) => q.type === 'section' ? (
                <div key={q.id} className="pt-2">
                  <h3 className="font-display text-xl font-bold text-navy-900">{q.title}</h3>
                  {q.description && <p className="mt-1 text-sm leading-7 text-slate-500">{q.description}</p>}
                  <div className="mt-3 h-0.5 w-16 rounded-full" style={{ background: accent }} />
                </div>
              ) : (
                <div key={q.id} id={`sq-${q.id}`} className={clsx('rounded-2xl border bg-white p-5 shadow-sm transition sm:p-6', showErrors && missing.includes(q.id) ? 'border-red-300 ring-4 ring-red-50' : 'border-navy-100')}>
                  <div className="mb-4">
                    <div className="font-bold leading-7 text-navy-900">{q.title}{q.required && <span className="ms-1 text-red-500">*</span>}</div>
                    {q.description && <p className="mt-1 text-sm leading-6 text-slate-500">{q.description}</p>}
                  </div>
                  <QuestionInput q={q} value={answers[q.id]} onChange={(v) => set(q.id, v)} accent={accent} />
                  {showErrors && missing.includes(q.id) && <p className="mt-3 text-xs font-semibold text-red-600">{t('surveys.form.required')}</p>}
                </div>
              ))}
              {showErrors && missing.length > 0 && <p className="text-center text-sm font-semibold text-red-600">{t('surveys.form.requiredMissing')}</p>}
              {error && <p className="rounded-xl bg-red-50 p-3 text-center text-sm text-red-700">{error}</p>}
            </div>
          )}

          {stage === 'done' && (
            <div className="mx-auto max-w-md py-6 text-center">
              <div className="mx-auto grid size-20 place-items-center rounded-full text-white shadow-xl" style={{ background: accent }}><CheckCircle2 className="size-10" /></div>
              <h3 className="mt-6 font-display text-2xl font-bold text-navy-900">{t('surveys.form.thanks')}</h3>
              <p className="mt-2 whitespace-pre-line leading-7 text-slate-600">{thanks ?? t('surveys.form.thanksText')}</p>
              <div className="mt-8 flex flex-wrap justify-center gap-3">
                {!closed && <button onClick={() => { setStage('form'); setPage(0) }} className="rounded-xl border border-navy-100 bg-white px-5 py-2.5 text-sm font-semibold text-navy-900 hover:border-gold-400">{t('surveys.form.edit')}</button>}
                {onClose && <button onClick={onClose} className="rounded-xl px-5 py-2.5 text-sm font-bold text-white" style={{ background: accent }}>{t('common.close')}</button>}
              </div>
            </div>
          )}
        </div>
      </div>

      {stage === 'form' && (
        <div className="flex items-center justify-between gap-3 border-t border-navy-100 bg-white px-5 py-4 sm:px-10">
          <button onClick={() => { setPage((p) => Math.max(0, p - 1)); scrollTop() }} disabled={page === 0} className="inline-flex items-center gap-2 rounded-xl px-4 py-2.5 text-sm font-semibold text-slate-500 transition hover:bg-navy-100/50 disabled:invisible">
            <ArrowRight className="size-4 ltr:rotate-180" />{t('surveys.form.prev')}
          </button>
          <button onClick={next} disabled={submitting} className="inline-flex items-center gap-2 rounded-xl px-6 py-2.5 text-sm font-bold text-white shadow-md transition hover:brightness-110 disabled:opacity-60" style={{ background: accent }}>
            {submitting && <Loader2 className="size-4 animate-spin" />}
            {last ? t('surveys.form.submit') : t('surveys.form.next')}
            {!last && <ArrowLeft className="size-4 ltr:rotate-180" />}
          </button>
        </div>
      )}
    </div>
  )
}

function Fact({ icon, text }: { icon: ReactNode; text: string }) {
  return <div className="flex flex-col items-center gap-2 rounded-2xl border border-navy-100 bg-white p-4 text-slate-600"><span style={{ color: 'var(--accent)' }}>{icon}</span>{text}</div>
}

const choiceClass = (active: boolean) => clsx('flex w-full items-center gap-3 rounded-xl border px-4 py-3 text-start text-sm transition', active ? 'border-[var(--accent)] bg-[color-mix(in_srgb,var(--accent)_7%,white)] font-semibold text-navy-900 shadow-sm' : 'border-navy-100 bg-white text-slate-600 hover:border-gold-400')

export function QuestionInput({ q, value, onChange, accent }: { q: Question; value: Answer | undefined; onChange: (v: Answer | undefined) => void; accent: string }) {
  const { t } = useTranslation()
  const scale = q.scale ?? { min: 1, max: 5 }
  const points = Array.from({ length: scale.max - scale.min + 1 }, (_, i) => scale.min + i)

  switch (q.type) {
    case 'short_text':
      return <input className="input" value={(value as string) ?? ''} maxLength={500} onChange={(e) => onChange(e.target.value || undefined)} />
    case 'long_text':
      return <textarea className="input min-h-28" value={(value as string) ?? ''} maxLength={5000} onChange={(e) => onChange(e.target.value || undefined)} />
    case 'number':
      return <input className="input max-w-48" type="number" inputMode="decimal" value={value === undefined ? '' : String(value)} onChange={(e) => onChange(e.target.value === '' ? undefined : Number(e.target.value))} />
    case 'date':
      return <input className="input max-w-56" type="date" value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value || undefined)} />
    case 'dropdown':
      return (
        <select className="input" value={(value as string) ?? ''} onChange={(e) => onChange(e.target.value || undefined)}>
          <option value="">{t('surveys.form.selectPlaceholder')}</option>
          {q.options?.map((o) => <option key={o.id} value={o.id}>{o.label}</option>)}
        </select>
      )
    case 'single':
      return (
        <div className="grid gap-2 sm:grid-cols-2">
          {q.options?.map((o) => (
            <button type="button" key={o.id} onClick={() => onChange(value === o.id ? undefined : o.id)} className={choiceClass(value === o.id)}>
              <span className={clsx('grid size-5 shrink-0 place-items-center rounded-full border-2', value === o.id ? 'border-[var(--accent)]' : 'border-slate-300')}>{value === o.id && <span className="size-2.5 rounded-full" style={{ background: accent }} />}</span>
              {o.label}
            </button>
          ))}
        </div>
      )
    case 'multiple': {
      const list = (value as string[] | undefined) ?? []
      const full = !!q.max_select && list.length >= q.max_select
      return (
        <>
          {q.max_select ? <div className="mb-2 text-xs font-semibold text-slate-400">{t('surveys.form.chooseUpTo', { n: q.max_select })} · {list.length}/{q.max_select}</div> : null}
          <div className="grid gap-2 sm:grid-cols-2">
            {q.options?.map((o) => {
              const on = list.includes(o.id)
              return (
                <button type="button" key={o.id} disabled={!on && full} onClick={() => { const n = on ? list.filter((x) => x !== o.id) : [...list, o.id]; onChange(n.length ? n : undefined) }} className={clsx(choiceClass(on), !on && full && 'opacity-40')}>
                  <span className={clsx('grid size-5 shrink-0 place-items-center rounded-md border-2 text-white', on ? 'border-transparent' : 'border-slate-300')} style={on ? { background: accent } : undefined}>{on && <Check className="size-3.5" />}</span>
                  {o.label}
                </button>
              )
            })}
          </div>
        </>
      )
    }
    case 'yes_no':
      return (
        <div className="grid max-w-sm grid-cols-2 gap-3">
          {(['yes', 'no'] as const).map((v) => <button type="button" key={v} onClick={() => onChange(value === v ? undefined : v)} className={clsx(choiceClass(value === v), 'justify-center py-4 text-base')}>{t(`surveys.form.${v}`)}</button>)}
        </div>
      )
    case 'rating':
      return (
        <div className="flex flex-wrap gap-1.5">
          {points.filter((p) => p > 0).map((p) => (
            <button type="button" key={p} aria-label={String(p)} onClick={() => onChange(value === p ? undefined : p)} className="rounded-lg p-1 transition hover:scale-110">
              <Star className="size-9" strokeWidth={1.5} style={{ color: accent }} fill={typeof value === 'number' && p <= value ? accent : 'transparent'} />
            </button>
          ))}
        </div>
      )
    case 'scale':
    case 'nps': {
      const pts = q.type === 'nps' ? Array.from({ length: 11 }, (_, i) => i) : points
      const low = q.type === 'nps' ? t('surveys.form.notLikely') : scale.min_label
      const high = q.type === 'nps' ? t('surveys.form.veryLikely') : scale.max_label
      return (
        <div>
          <div className="flex gap-1.5 sm:gap-2">
            {pts.map((p) => {
              const on = value === p
              const npsTone = q.type === 'nps' && !on ? (p <= 6 ? 'hover:border-red-300' : p <= 8 ? 'hover:border-amber-300' : 'hover:border-emerald-300') : ''
              return <button type="button" key={p} onClick={() => onChange(on ? undefined : p)} className={clsx('h-11 min-w-0 flex-1 rounded-xl border text-sm font-bold transition', on ? 'border-transparent text-white shadow-md' : clsx('border-navy-100 bg-white text-slate-600', npsTone))} style={on ? { background: accent } : undefined}>{p}</button>
            })}
          </div>
          {(low || high) && <div className="mt-2 flex justify-between text-xs text-slate-400"><span>{low}</span><span>{high}</span></div>}
        </div>
      )
    }
    case 'matrix': {
      const grid = (value as Record<string, number> | undefined) ?? {}
      const setCell = (row: string, p: number) => onChange({ ...grid, [row]: p })
      return (
        <div className="space-y-3">
          <div className="hidden items-end gap-3 text-center text-[11px] font-semibold leading-4 text-slate-400 sm:flex">
            <div className="w-[40%] shrink-0" />
            <div className="flex flex-1 gap-1.5">{points.map((p) => <span key={p} className="flex-1">{p === scale.min ? scale.min_label ?? p : p === scale.max ? scale.max_label ?? p : p}</span>)}</div>
          </div>
          {q.rows?.map((row) => (
            <div key={row.id} className={clsx('rounded-xl border p-3 transition sm:flex sm:items-center sm:gap-3 sm:border-0 sm:p-0', grid[row.id] ? 'border-navy-100' : 'border-dashed border-navy-100')}>
              <div className="mb-2 text-sm font-medium text-navy-900 sm:mb-0 sm:w-[40%] sm:shrink-0">{row.label}</div>
              <div className="flex flex-1 gap-1.5">
                {points.map((p) => {
                  const on = grid[row.id] === p
                  return <button type="button" key={p} aria-label={`${row.label}: ${p}`} onClick={() => setCell(row.id, p)} className={clsx('h-10 flex-1 rounded-lg border text-sm font-bold transition', on ? 'border-transparent text-white shadow' : 'border-navy-100 bg-white text-slate-500 hover:border-gold-400')} style={on ? { background: accent } : undefined}>{p}</button>
                })}
              </div>
            </div>
          ))}
          <div className="flex justify-between text-[11px] text-slate-400 sm:hidden"><span>{scale.min_label}</span><span>{scale.max_label}</span></div>
        </div>
      )
    }
    case 'ranking': {
      const order = (value as string[] | undefined) ?? q.options?.map((o) => o.id) ?? []
      const byId = Object.fromEntries((q.options ?? []).map((o) => [o.id, o]))
      const move = (i: number, d: number) => { const n = [...order]; [n[i], n[i + d]] = [n[i + d], n[i]]; onChange(n) }
      return (
        <div>
          <p className="mb-3 text-xs text-slate-400">{t('surveys.form.rankHint')}</p>
          <ol className="space-y-2">
            {order.map((id, i) => (
              <li key={id} className={clsx('flex items-center gap-3 rounded-xl border bg-white px-3 py-2.5 text-sm', value ? 'border-navy-100' : 'border-dashed border-navy-100')}>
                <span className="grid size-7 shrink-0 place-items-center rounded-full text-xs font-bold text-white" style={{ background: accent, opacity: 1 - i * 0.12 }}>{i + 1}</span>
                <span className="flex-1 font-medium text-navy-900">{byId[id]?.label}</span>
                <button type="button" aria-label="up" disabled={i === 0} onClick={() => move(i, -1)} className="rounded-lg p-1.5 text-slate-400 hover:bg-navy-100/60 hover:text-navy-900 disabled:opacity-30"><ArrowUp className="size-4" /></button>
                <button type="button" aria-label="down" disabled={i === order.length - 1} onClick={() => move(i, 1)} className="rounded-lg p-1.5 text-slate-400 hover:bg-navy-100/60 hover:text-navy-900 disabled:opacity-30"><ArrowDown className="size-4" /></button>
              </li>
            ))}
          </ol>
          {!value && <button type="button" onClick={() => onChange(order)} className="mt-3 text-xs font-semibold underline decoration-dotted" style={{ color: accent }}>{t('surveys.form.rankConfirm')}</button>}
        </div>
      )
    }
    default:
      return null
  }
}
