/* eslint-disable @typescript-eslint/no-explicit-any */
import clsx from 'clsx'
import { CheckCircle2, CircleDashed, Clock, XCircle } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Modal, Spinner } from '@/components/ui'
import IntegrityPanel, { type Integrity } from '@/components/assessment/IntegrityPanel'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'

type Text = { ar: string; en: string }
type Step = { question_id: string; level: 'easy' | 'medium' | 'hard'; skill: Text | null; correct: boolean | null; skipped: boolean; unanswered?: boolean; answered_at: string | null; why: Text }
type Review = {
  id: string; status: string; attempt_no: number; delivery: string; employee: string | null; score_percent: number | null; passed: boolean | null; points: number; max_points: number; flags: string[]
  assessment: { title_ar: string; title_en: string; kind: string; adaptive: boolean }
  items: { id: string; type: string; stem_ar: string; stem_en: string | null; points: number; answer: any; correct: boolean | null; points_awarded: number | null; correct_answer: any; options: { id: string; text_ar: string | null; text_en: string | null }[] }[]
  adaptive: null | { total: number; answered: number; competencies: { skill: Text | null; start_mastery: number | null; start_level: string; level: string; asked: number }[]; steps: Step[] }
  integrity?: Integrity
}

const Y = { easy: 74, medium: 46, hard: 18 } as const

/** The difficulty path of an adaptive attempt: one dot per question, its height the level, its colour the result. */
function Path({ steps }: { steps: Step[] }) {
  const { t, i18n } = useTranslation()
  const rtl = i18n.language === 'ar'
  const gap = 44
  const width = Math.max(steps.length * gap + 56, 160)
  // Time runs in the reading direction: right to left in Arabic.
  const x = (i: number) => (rtl ? width - 48 - i * gap : 48 + i * gap)
  const asked = steps.map((s, i) => ({ s, i })).filter(({ s }) => !s.skipped)
  return (
    <div className="overflow-x-auto rounded-xl bg-ivory p-3">
      <svg width={width} height={96} viewBox={`0 0 ${width} 96`} role="img" aria-label={String(t('assess.review.trajectoryHint'))} className="block">
        {(['hard', 'medium', 'easy'] as const).map((l) => (
          <g key={l}>
            <line x1={0} x2={width} y1={Y[l]} y2={Y[l]} className="stroke-navy-100" strokeDasharray="3 4" />
            <text x={rtl ? width - 4 : 4} y={Y[l] - 5} textAnchor={rtl ? 'end' : 'start'} className="fill-slate-400 text-[9px]">{t(`assess.difficulty.${l}`)}</text>
          </g>
        ))}
        <polyline points={asked.map(({ s, i }) => `${x(i)},${Y[s.level]}`).join(' ')} fill="none" className="stroke-navy-300" strokeWidth={2} strokeLinejoin="round" />
        {steps.map((s, i) => (
          <g key={s.question_id}>
            <circle cx={x(i)} cy={Y[s.level]} r={7} strokeWidth={2}
              className={clsx(s.skipped || s.unanswered ? 'fill-white stroke-slate-300' : s.correct === true ? 'fill-emerald-500 stroke-emerald-600' : s.correct === false ? 'fill-rose-500 stroke-rose-600' : 'fill-white stroke-gold-600')}
              strokeDasharray={s.skipped || s.unanswered ? '2 2' : undefined} />
            <text x={x(i)} y={92} textAnchor="middle" className="fill-slate-500 text-[10px] tabular-nums">{i + 1}</text>
          </g>
        ))}
      </svg>
    </div>
  )
}

/** One attempt as a teacher reviews it: the answers and results, and for adaptive practice the difficulty path with the reason for each question. */
export default function AttemptReview({ attemptId, onClose }: { attemptId: string | null; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const tx = (v: Text | null | undefined) => (v ? (ar ? v.ar : v.en) || v.ar || v.en : '')
  const { data, refetch } = useGet<{ data: Review }>(`/admin/attempts/${attemptId}/review`, undefined, { enabled: !!attemptId, staleTime: 0 })
  const r = data?.data
  const show = (item: Review['items'][number], v: any) => {
    if (v === null || v === undefined || v === '') return '—'
    const opt = (id: string) => { const o = item.options.find((x) => x.id === id); return o ? (ar ? o.text_ar || o.text_en : o.text_en || o.text_ar) || id : id }
    if (typeof v === 'string' && item.options.length) return opt(v)
    if (Array.isArray(v) && item.options.length) return v.map((x) => opt(String(x))).join('، ')
    return typeof v === 'object' ? JSON.stringify(v) : String(v)
  }
  return (
    <Modal wide open={!!attemptId} onClose={onClose} title={r ? `${t('assess.review.title')} · ${r.employee ?? ''}` : t('assess.review.title')}>
      {!r ? <Spinner /> : (
        <div className="space-y-6">
          <div className="flex flex-wrap items-center gap-2">
            {r.score_percent !== null && <span className="font-display text-3xl font-bold tabular-nums text-navy-900">{fmt.number(r.score_percent, 1)}%</span>}
            {r.passed !== null && <Badge color={r.passed ? 'green' : 'red'}>{r.passed ? t('assess.runner.passed') : t('assess.runner.failed')}</Badge>}
            <Badge color={r.delivery === 'offline' ? 'blue' : 'gray'}>{t(`assess.deliveries.${r.delivery}`, { defaultValue: r.delivery })}</Badge>
            {r.flags.map((f) => <Badge key={f} color="red">{t(`assess.flags.${f}`, { defaultValue: f })}</Badge>)}
            <span className="ms-auto text-sm text-slate-500">{t('assess.review.points', { got: fmt.number(r.points, 1), max: fmt.number(r.max_points, 1) })}</span>
          </div>

          {r.adaptive && (
            <section className="space-y-3">
              <div><h3 className="font-bold text-navy-900">{t('assess.review.trajectory')}</h3><p className="text-xs text-slate-500">{t('assess.review.trajectoryHint')}</p></div>
              <Path steps={r.adaptive.steps} />
              <ul className="flex flex-wrap gap-2 text-xs">
                {r.adaptive.competencies.map((c, i) => (
                  <li key={i} className="rounded-full bg-gold-50 px-3 py-1 text-gold-900 ring-1 ring-inset ring-gold-200">
                    <b>{tx(c.skill) || t('assess.review.general')}</b>{' '}
                    {t('assess.review.start', { level: t(`assess.difficulty.${c.start_level}`) })} {c.start_mastery === null ? t('assess.review.noEvidence') : t('assess.review.startMastery', { n: c.start_mastery })} {t('assess.review.ended', { level: t(`assess.difficulty.${c.level}`) })}
                  </li>))}
              </ul>
              <ol className="space-y-2">
                {r.adaptive.steps.map((s, i) => (
                  <li key={s.question_id} className="flex items-start gap-3 rounded-xl border border-navy-50 bg-white p-3 text-sm">
                    <span className="grid size-6 shrink-0 place-items-center rounded-full bg-navy-50 text-xs font-bold tabular-nums text-navy-900">{i + 1}</span>
                    <div className="min-w-0 flex-1">
                      <div className="flex flex-wrap items-center gap-2"><b className="text-navy-900">{t(`assess.difficulty.${s.level}`)}</b>{s.skill && <span className="text-xs text-slate-500">{tx(s.skill)}</span>}</div>
                      <p className="text-slate-600">{tx(s.why)}</p>
                      {s.unanswered && <p className="text-xs font-semibold text-slate-500">{t('assess.review.unanswered')}</p>}
                    </div>
                    {s.skipped || s.unanswered ? <CircleDashed className="size-5 text-slate-300" aria-label={String(t(s.skipped ? 'assess.review.skipped' : 'assess.review.unanswered'))} /> : s.correct === true ? <CheckCircle2 className="size-5 text-emerald-600" aria-label={String(t('assess.adaptive.correct'))} /> : s.correct === false ? <XCircle className="size-5 text-rose-600" aria-label={String(t('assess.adaptive.notQuite'))} /> : <Clock className="size-5 text-gold-600" aria-label={String(t('assess.review.pending'))} />}
                  </li>))}
              </ol>
            </section>
          )}

          {r.integrity && <IntegrityPanel attemptId={r.id} integrity={r.integrity} onChanged={() => void refetch()} />}

          <section className="space-y-3">
            <h3 className="font-bold text-navy-900">{t('assess.review.items')}</h3>
            {r.items.map((it, i) => (
              <div key={it.id} className="space-y-2 rounded-xl border border-navy-50 p-3 text-sm">
                <div className="flex items-start gap-2"><span className="font-bold tabular-nums text-slate-400">{i + 1}.</span><p className="flex-1 font-semibold text-navy-900" dir="auto">{ar ? it.stem_ar : it.stem_en || it.stem_ar}</p>
                  <span className="shrink-0 text-xs text-slate-500">{it.points_awarded === null ? t('assess.review.pending') : t('assess.review.points', { got: fmt.number(it.points_awarded, 1), max: fmt.number(it.points, 1) })}</span></div>
                <dl className="grid gap-2 sm:grid-cols-2">
                  <div className={clsx('rounded-lg p-2', it.correct === true ? 'bg-emerald-50' : it.correct === false ? 'bg-rose-50' : 'bg-ivory')}><dt className="text-xs text-slate-500">{t('assess.review.answer')}</dt><dd dir="auto">{show(it, it.answer)}</dd></div>
                  <div className="rounded-lg bg-ivory p-2"><dt className="text-xs text-slate-500">{t('assess.review.correctAnswer')}</dt><dd dir="auto">{show(it, it.correct_answer)}</dd></div>
                </dl>
              </div>))}
          </section>
        </div>
      )}
    </Modal>
  )
}
