import clsx from 'clsx'
import { CalendarDays, CheckCircle2, ChevronDown, GraduationCap, Lock, Users } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { QuestionInput } from '@/components/surveys/SurveyForm'
import { Button, Modal } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { isVisible, missingRequired, type Answers, type Question } from '@/lib/surveys'
import { toast } from '@/lib/toast'
import type { Registration } from '@/lib/types'

type Form = {
  title: string; intro: string; questions: (Question & { title_en?: string | null })[]; open: boolean; submitted: boolean
  context: { program: string; code: string; group: string | null; trainers: string[]; from: string | null; to: string | null }
}

/**
 * The trainee form (docs/saber/08-centre-forms.md): the centre's questions — the trainer, the content, the environment, the expected
 * impact, an overall rating and the trainee's words — with what the old form asked first (programme, group, trainer) already filled in.
 */
export default function TraineeEvaluation({ registration, onClose }: { registration: Registration; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const { data } = useGet<{ data: Form }>(`/me/registrations/${registration.id}/evaluation`, undefined, { staleTime: 0 })
  const f = data?.data
  const [answers, setAnswers] = useState<Answers>({})
  const [allow, setAllow] = useState(false)
  const [missing, setMissing] = useState<Set<string>>(new Set())
  const [introOpen, setIntroOpen] = useState(false)
  const [busy, setBusy] = useState(false)
  const qs = (f?.questions ?? []).map((q) => ({ ...q, title: en && q.title_en ? q.title_en : q.title }))
  const required = qs.filter((q) => q.required && q.type !== 'section' && isVisible(q, answers))
  const done = required.filter((q) => !missingRequired([q], answers).length).length

  const submit = async () => {
    const gaps = missingRequired(qs, answers)
    if (gaps.length) {
      setMissing(new Set(gaps))
      document.getElementById(`tq-${gaps[0]}`)?.scrollIntoView({ behavior: 'smooth', block: 'center' })
      toast(t('portal.traineeForm.missing', { n: gaps.length }), 'error')
      return
    }
    setBusy(true)
    try {
      await api.post(`/me/registrations/${registration.id}/evaluation`, { answers, allow_testimonial: allow })
      toast(t('portal.traineeForm.thanks'))
      onClose()
    } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }

  return (
    <Modal open wide onClose={onClose} title={f?.title ?? t('portal.evaluate')}>
      {!f ? <div className="space-y-3"><div className="h-20 animate-pulse rounded-2xl bg-ivory" /><div className="h-64 animate-pulse rounded-2xl bg-ivory" /></div> : !f.open || f.submitted ? (
        <div className="flex flex-col items-center gap-3 py-10 text-center">
          {f.submitted ? <CheckCircle2 className="size-12 text-emerald-600" /> : <Lock className="size-12 text-slate-400" />}
          <p className="max-w-sm text-sm text-slate-600">{f.submitted ? t('portal.traineeForm.already') : t('portal.traineeForm.closed')}</p>
          <Button variant="outline" onClick={onClose}>{t('common.close', { defaultValue: 'إغلاق' })}</Button>
        </div>
      ) : (
        <div className="space-y-4">
          <div className="flex flex-wrap gap-2 text-xs">
            <Chip icon={GraduationCap}>{f.context.program}</Chip>
            {f.context.group && <Chip icon={Users}>{f.context.group}</Chip>}
            {f.context.trainers.length > 0 && <Chip icon={Users}>{t('portal.traineeForm.trainer')}: {f.context.trainers.join('، ')}</Chip>}
            {f.context.from && <Chip icon={CalendarDays}>{fmt.date(f.context.from)}{f.context.to && f.context.to !== f.context.from ? ` – ${fmt.date(f.context.to)}` : ''}</Chip>}
          </div>

          {f.intro && (
            <section className="rounded-2xl border border-gold-200 bg-gradient-to-br from-gold-50/70 to-ivory p-4">
              <p className={clsx('whitespace-pre-line text-sm leading-7 text-navy-800', !introOpen && 'line-clamp-3')}>{f.intro}</p>
              <button type="button" onClick={() => setIntroOpen((o) => !o)} className="mt-1 inline-flex items-center gap-1 text-xs font-bold text-gold-700 hover:text-navy-900">
                {introOpen ? t('portal.traineeForm.less') : t('portal.traineeForm.more')}<ChevronDown className={clsx('size-3.5 transition', introOpen && 'rotate-180')} />
              </button>
            </section>
          )}

          <div className="sticky top-0 z-10 -mx-1 bg-white/95 px-1 py-2 backdrop-blur">
            <div className="flex items-center justify-between text-[11px] font-semibold text-slate-500"><span>{t('portal.traineeForm.progress', { done, total: required.length })}</span><span className="tabular-nums">{required.length ? Math.round((done / required.length) * 100) : 0}%</span></div>
            <div className="mt-1 h-1.5 overflow-hidden rounded-full bg-slate-100"><div className="h-full rounded-full bg-gold-500 transition-all" style={{ width: `${required.length ? (done / required.length) * 100 : 0}%` }} /></div>
          </div>

          {qs.filter((q) => isVisible(q, answers)).map((q, n) => q.type === 'section' ? <h3 key={q.id} className="pt-2 text-base font-bold text-navy-900">{q.title}</h3> : (
            <section key={q.id} id={`tq-${q.id}`} className={clsx('scroll-mt-16 rounded-2xl border bg-white p-4 shadow-sm transition', missing.has(q.id) && missingRequired([q], answers).length ? 'border-rose-300 ring-2 ring-rose-100' : 'border-navy-100')}>
              <h4 className="mb-3 flex gap-2 text-sm font-bold text-navy-900"><span className="grid size-6 shrink-0 place-items-center rounded-full bg-navy-900 text-[11px] text-white">{n + 1}</span><span>{q.title}{q.required && <span className="text-rose-600"> *</span>}</span></h4>
              <QuestionInput q={q} value={answers[q.id]} onChange={(v) => setAnswers((a) => ({ ...a, [q.id]: v }))} accent="#8A1538" />
            </section>
          ))}

          <label className="flex items-center gap-2 text-sm text-slate-600"><input type="checkbox" className="accent-gold-600" checked={allow} onChange={(e) => setAllow(e.target.checked)} />{t('portal.traineeForm.testimonial')}</label>
          <Button variant="gold" className="w-full" loading={busy} onClick={() => void submit()}>{t('common.submit')}</Button>
        </div>
      )}
    </Modal>
  )
}

function Chip({ icon: Icon, children }: { icon: typeof Users; children: React.ReactNode }) {
  return <span className="inline-flex items-center gap-1.5 rounded-full bg-navy-50 px-3 py-1 font-semibold text-navy-800"><Icon className="size-3.5 text-gold-600" />{children}</span>
}
