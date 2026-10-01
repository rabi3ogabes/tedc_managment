import clsx from 'clsx'
import { Check, ChevronDown, ChevronUp, Copy, Plus, Save, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Field } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { newId, type Option, type QuizQ, type SurveyQ } from './api'

const move = <T,>(list: T[], i: number, d: -1 | 1): T[] => {
  const j = i + d
  if (j < 0 || j >= list.length) return list
  const next = [...list]
  ;[next[i], next[j]] = [next[j], next[i]]
  return next
}

function OptionRow({ option, mark, onChange, onRemove, canRemove }: { option: Option; mark?: 'radio' | 'check' | null; onChange: (o: Option) => void; onRemove: () => void; canRemove: boolean }) {
  const { t } = useTranslation()
  return (
    <div className="flex items-center gap-2">
      {mark && (
        <button type="button" aria-pressed={!!option.correct} aria-label={t('course.q.correct')} onClick={() => onChange({ ...option, correct: !option.correct })}
          className={clsx('grid size-7 shrink-0 place-items-center border-2 transition', mark === 'radio' ? 'rounded-full' : 'rounded-md', option.correct ? 'border-emerald-500 bg-emerald-500 text-white' : 'border-navy-200 bg-white text-transparent hover:border-emerald-400')}><Check className="size-4" /></button>
      )}
      <input dir="rtl" className="input !py-1.5 text-sm" placeholder={t('course.q.optionAr')} value={option.text_ar} onChange={(e) => onChange({ ...option, text_ar: e.target.value })} />
      <input dir="ltr" className="input !py-1.5 text-sm" placeholder={t('course.q.optionEn')} value={option.text_en ?? ''} onChange={(e) => onChange({ ...option, text_en: e.target.value })} />
      <button type="button" aria-label="remove" disabled={!canRemove} onClick={onRemove} className="rounded-lg p-1.5 text-slate-400 hover:bg-red-50 hover:text-danger disabled:opacity-30"><Trash2 className="size-4" /></button>
    </div>
  )
}

const blankQuiz = (): QuizQ => ({ type: 'single', text_ar: '', text_en: '', points: 1, explanation_ar: '', explanation_en: '', options: [{ id: newId(), text_ar: '', text_en: '', correct: true }, { id: newId(), text_ar: '', text_en: '', correct: false }] })

/** Multiple-choice questions of a quiz lesson. */
export function QuizEditor({ lessonId, initial, onSaved }: { lessonId: string; initial: QuizQ[]; onSaved: () => void }) {
  const { t } = useTranslation()
  const [qs, setQs] = useState<QuizQ[]>(initial.length ? initial : [])
  const [open, setOpen] = useState<number | null>(initial.length ? null : 0)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null)

  const patch = (i: number, p: Partial<QuizQ>) => setQs(qs.map((q, j) => (j === i ? { ...q, ...p } : q)))
  const setType = (i: number, type: QuizQ['type']) => {
    const q = qs[i]
    if (type === 'true_false') return patch(i, { type, options: [{ id: 'true', text_ar: 'صح', text_en: 'True', correct: true }, { id: 'false', text_ar: 'خطأ', text_en: 'False', correct: false }] })
    // Leaving multiple choice keeps a single right answer.
    const firstCorrect = q.options.findIndex((o) => o.correct)
    patch(i, { type, options: type === 'single' ? q.options.map((o, k) => ({ ...o, correct: k === Math.max(0, firstCorrect) })) : q.options })
  }
  const setCorrect = (i: number, k: number, o: Option) => {
    const q = qs[i]
    patch(i, { options: q.options.map((x, j) => (j === k ? o : q.type === 'multiple' ? x : { ...x, correct: o.correct ? false : x.correct })) })
  }
  const valid = qs.length > 0 && qs.every((q) => q.text_ar.trim() && q.options.every((o) => o.text_ar.trim()) && q.options.some((o) => o.correct))

  const save = async () => {
    setBusy(true)
    setMessage(null)
    try {
      await api.put(`/admin/course/lessons/${lessonId}/questions`, { questions: qs.map(({ id: _id, ...q }) => ({ ...q, points: Number(q.points) || 1 })) })
      setMessage({ ok: true, text: t('course.q.saved') })
      onSaved()
    } catch (e) {
      setMessage({ ok: false, text: errorMessage(e) })
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-3">
      {qs.map((q, i) => (
        <div key={i} className={clsx('rounded-2xl border bg-white', open === i ? 'border-gold-400 shadow-sm' : 'border-navy-100')}>
          <button type="button" onClick={() => setOpen(open === i ? null : i)} className="flex w-full items-center gap-3 p-3 text-start">
            <span className="grid size-7 shrink-0 place-items-center rounded-full bg-navy-900 text-xs font-bold text-gold-300">{i + 1}</span>
            <span className="min-w-0 flex-1 truncate text-sm font-semibold text-navy-900">{q.text_ar || t('course.q.newQuestion')}</span>
            {!q.options.some((o) => o.correct) && <span className="text-xs font-semibold text-danger">{t('course.q.noCorrect')}</span>}
            <span className="text-xs text-slate-400">{q.points} {t('course.q.pts')}</span>
            <ChevronDown className={clsx('size-4 text-slate-400 transition', open === i && 'rotate-180')} />
          </button>
          {open === i && (
            <div className="space-y-4 border-t border-navy-100 p-4">
              <div className="grid gap-3 sm:grid-cols-[1fr_8rem_6rem]">
                <Field label={t('course.q.type')}><select className="input !py-1.5 text-sm" value={q.type} onChange={(e) => setType(i, e.target.value as QuizQ['type'])}>{(['single', 'multiple', 'true_false'] as const).map((k) => <option key={k} value={k}>{t(`course.q.types.${k}`)}</option>)}</select></Field>
                <Field label={t('course.q.points')}><input type="number" min={0} step={0.5} className="input !py-1.5 text-sm" value={q.points} onChange={(e) => patch(i, { points: Number(e.target.value) })} /></Field>
                <div className="flex items-end justify-end gap-1">
                  <Button variant="ghost" size="sm" aria-label="up" icon={<ChevronUp className="size-4" />} disabled={i === 0} onClick={() => { setQs(move(qs, i, -1)); setOpen(i - 1) }} />
                  <Button variant="ghost" size="sm" aria-label="copy" icon={<Copy className="size-4" />} onClick={() => { setQs([...qs.slice(0, i + 1), { ...q, id: undefined, options: q.options.map((o) => ({ ...o, id: newId() })) }, ...qs.slice(i + 1)]); setOpen(i + 1) }} />
                  <Button variant="ghost" size="sm" aria-label="delete" icon={<Trash2 className="size-4 text-danger" />} onClick={() => { setQs(qs.filter((_, j) => j !== i)); setOpen(null) }} />
                </div>
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                <Field label={t('course.q.textAr')}><textarea rows={2} dir="rtl" className="input text-sm" value={q.text_ar} onChange={(e) => patch(i, { text_ar: e.target.value })} /></Field>
                <Field label={t('course.q.textEn')}><textarea rows={2} dir="ltr" className="input text-sm" value={q.text_en ?? ''} onChange={(e) => patch(i, { text_en: e.target.value })} /></Field>
              </div>
              <div className="space-y-2">
                <div className="label">{t('course.q.options')} <span className="font-normal text-slate-400">— {t(q.type === 'multiple' ? 'course.q.hintMultiple' : 'course.q.hintSingle')}</span></div>
                {q.options.map((o, k) => <OptionRow key={o.id} option={o} mark={q.type === 'multiple' ? 'check' : 'radio'} canRemove={q.type !== 'true_false' && q.options.length > 2} onChange={(x) => setCorrect(i, k, x)} onRemove={() => patch(i, { options: q.options.filter((_, j) => j !== k) })} />)}
                {q.type !== 'true_false' && q.options.length < 8 && <Button variant="ghost" size="sm" icon={<Plus className="size-4" />} onClick={() => patch(i, { options: [...q.options, { id: newId(), text_ar: '', text_en: '', correct: false }] })}>{t('course.q.addOption')}</Button>}
              </div>
              <div className="grid gap-3 sm:grid-cols-2">
                <Field label={t('course.q.explanationAr')}><textarea rows={2} dir="rtl" className="input text-sm" value={q.explanation_ar ?? ''} onChange={(e) => patch(i, { explanation_ar: e.target.value })} /></Field>
                <Field label={t('course.q.explanationEn')}><textarea rows={2} dir="ltr" className="input text-sm" value={q.explanation_en ?? ''} onChange={(e) => patch(i, { explanation_en: e.target.value })} /></Field>
              </div>
            </div>
          )}
        </div>
      ))}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Button variant="outline" size="sm" icon={<Plus className="size-4" />} onClick={() => { setQs([...qs, blankQuiz()]); setOpen(qs.length) }}>{t('course.q.addQuestion')}</Button>
        <div className="flex items-center gap-3">
          {message && <span className={clsx('text-sm', message.ok ? 'text-emerald-700' : 'text-danger')}>{message.text}</span>}
          <span className="text-xs text-slate-500">{t('course.q.total', { count: qs.length, points: qs.reduce((a, q) => a + (Number(q.points) || 0), 0) })}</span>
          <Button variant="gold" loading={busy} disabled={!valid} icon={<Save className="size-4" />} onClick={save}>{t('course.q.save')}</Button>
        </div>
      </div>
    </div>
  )
}

const blankSurvey = (): SurveyQ => ({ type: 'rating', text_ar: '', text_en: '', required: true, options: null })

/** Questions of a survey lesson: ratings, NPS, choices and free text. */
export function SurveyEditor({ lessonId, initial, onSaved }: { lessonId: string; initial: SurveyQ[]; onSaved: () => void }) {
  const { t } = useTranslation()
  const [qs, setQs] = useState<SurveyQ[]>(initial)
  const [busy, setBusy] = useState(false)
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null)
  const patch = (i: number, p: Partial<SurveyQ>) => setQs(qs.map((q, j) => (j === i ? { ...q, ...p } : q)))
  const withOptions = (type: SurveyQ['type']) => type === 'choice' || type === 'multiple'
  const setType = (i: number, type: SurveyQ['type']) => patch(i, { type, options: withOptions(type) ? (qs[i].options?.length ? qs[i].options : [{ id: newId(), text_ar: '', text_en: '' }, { id: newId(), text_ar: '', text_en: '' }]) : null })
  const valid = qs.length > 0 && qs.every((q) => q.text_ar.trim() && (!withOptions(q.type) || (q.options ?? []).every((o) => o.text_ar.trim())))

  const save = async () => {
    setBusy(true)
    setMessage(null)
    try {
      await api.put(`/admin/course/lessons/${lessonId}/survey-questions`, { questions: qs.map(({ id: _id, ...q }) => ({ ...q, options: withOptions(q.type) ? q.options : null })) })
      setMessage({ ok: true, text: t('course.q.saved') })
      onSaved()
    } catch (e) {
      setMessage({ ok: false, text: errorMessage(e) })
    } finally {
      setBusy(false)
    }
  }

  return (
    <div className="space-y-3">
      {qs.map((q, i) => (
        <div key={i} className="space-y-3 rounded-2xl border border-navy-100 bg-white p-4">
          <div className="flex items-center gap-3">
            <span className="grid size-7 shrink-0 place-items-center rounded-full bg-navy-900 text-xs font-bold text-gold-300">{i + 1}</span>
            <select className="input !w-auto !py-1.5 text-sm" value={q.type} onChange={(e) => setType(i, e.target.value as SurveyQ['type'])}>{(['rating', 'nps', 'choice', 'multiple', 'text'] as const).map((k) => <option key={k} value={k}>{t(`course.s.types.${k}`)}</option>)}</select>
            <label className="ms-auto flex items-center gap-2 text-xs font-semibold text-navy-800"><input type="checkbox" className="size-4 accent-gold-600" checked={q.required} onChange={(e) => patch(i, { required: e.target.checked })} />{t('course.s.required')}</label>
            <Button variant="ghost" size="sm" aria-label="up" icon={<ChevronUp className="size-4" />} disabled={i === 0} onClick={() => setQs(move(qs, i, -1))} />
            <Button variant="ghost" size="sm" aria-label="delete" icon={<Trash2 className="size-4 text-danger" />} onClick={() => setQs(qs.filter((_, j) => j !== i))} />
          </div>
          <div className="grid gap-3 sm:grid-cols-2">
            <input dir="rtl" className="input text-sm" placeholder={t('course.q.textAr')} value={q.text_ar} onChange={(e) => patch(i, { text_ar: e.target.value })} />
            <input dir="ltr" className="input text-sm" placeholder={t('course.q.textEn')} value={q.text_en ?? ''} onChange={(e) => patch(i, { text_en: e.target.value })} />
          </div>
          {withOptions(q.type) && (
            <div className="space-y-2">
              {(q.options ?? []).map((o, k) => <OptionRow key={o.id} option={o} canRemove={(q.options ?? []).length > 2} onChange={(x) => patch(i, { options: (q.options ?? []).map((y, j) => (j === k ? x : y)) })} onRemove={() => patch(i, { options: (q.options ?? []).filter((_, j) => j !== k) })} />)}
              {(q.options ?? []).length < 10 && <Button variant="ghost" size="sm" icon={<Plus className="size-4" />} onClick={() => patch(i, { options: [...(q.options ?? []), { id: newId(), text_ar: '', text_en: '' }] })}>{t('course.q.addOption')}</Button>}
            </div>
          )}
          {q.type === 'rating' && <p className="text-xs text-slate-500">{t('course.s.ratingHint')}</p>}
          {q.type === 'nps' && <p className="text-xs text-slate-500">{t('course.s.npsHint')}</p>}
        </div>
      ))}
      <div className="flex flex-wrap items-center justify-between gap-3">
        <Button variant="outline" size="sm" icon={<Plus className="size-4" />} onClick={() => setQs([...qs, blankSurvey()])}>{t('course.s.add')}</Button>
        <div className="flex items-center gap-3">
          {message && <span className={clsx('text-sm', message.ok ? 'text-emerald-700' : 'text-danger')}>{message.text}</span>}
          <Button variant="gold" loading={busy} disabled={!valid} icon={<Save className="size-4" />} onClick={save}>{t('course.q.save')}</Button>
        </div>
      </div>
    </div>
  )
}
