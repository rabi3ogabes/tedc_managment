import { Bot, Quote, ShieldCheck, Sparkles } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardTitle, Progress, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Insight = { id: string; kind: 'summary' | 'suggestions'; question_id: string | null; answers_count: number; model?: string | null; redactions: number; by?: string | null; created_at: string
  payload: { summary?: string; sentiment?: { positive: number; neutral: number; negative: number }; themes?: { title: string; count: number; share: number; quotes: string[] }[]; recommendations?: string[] } }
type Status = { opt_in: boolean; available: boolean; can_summarise: boolean; can_suggest: boolean; text_questions: { id: string; title: string; count: number }[]; min_answers: number; insights: Insight[] }

/** AI analysis of open-text answers (Gate 8): only for surveys that opted in, through the platform's AI policy, with identities redacted; results are kept with who asked and when. */
export default function AiPanel({ id, canRun }: { id: string; canRun: boolean }) {
  const { t } = useTranslation()
  const res = useGet<{ data: Status }>(`/admin/surveys/${id}/ai`, undefined, { staleTime: 0 })
  const [busy, setBusy] = useState<string | null>(null)
  const [show, setShow] = useState<string | null>(null)
  const d = res.data?.data
  if (!d) return null
  const run = async (questionId: string | null) => {
    setBusy(questionId ?? 'all')
    try { const { data } = await api.post(`/admin/surveys/${id}/ai/summaries`, { question_id: questionId }); toast(String(t('ss.ai.done'))); setShow(data.data.id); await res.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }
  const summaries = d.insights.filter((i) => i.kind === 'summary')
  const current = summaries.find((i) => i.id === show) ?? summaries[0]
  const titleOf = (qid: string | null) => (qid ? d.text_questions.find((q) => q.id === qid)?.title ?? qid : t('ss.ai.allText'))
  return (
    <Card>
      <CardTitle subtitle={d.opt_in ? t('ss.ai.hint') : t('ss.ai.optOut')}><span className="inline-flex items-center gap-2"><Bot className="size-5 text-gold-600" />{t('ss.ai.title')}</span></CardTitle>
      {!d.opt_in ? null : !d.available ? <p className="rounded-xl bg-amber-50 px-3 py-2 text-sm text-amber-900">{t('ss.ai.unavailable')}</p> : (
        <>
          {canRun && (
            <div className="flex flex-wrap items-center gap-2">
              <Button size="sm" variant="gold" icon={<Sparkles className="size-4" />} loading={busy === 'all'} disabled={!d.can_summarise || !!busy} onClick={() => run(null)}>{t('ss.ai.summariseAll')}</Button>
              {d.text_questions.map((q) => <Button key={q.id} size="sm" variant="outline" loading={busy === q.id} disabled={q.count < d.min_answers || !!busy} onClick={() => run(q.id)} title={q.title}><span className="max-w-48 truncate">{q.title}</span> <span className="text-slate-400">({q.count})</span></Button>)}
              {!d.can_summarise && <span className="text-xs text-slate-500">{t('ss.ai.tooFew', { n: d.min_answers })}</span>}
            </div>
          )}
          {!!summaries.length && summaries.length > 1 && (
            <div className="mt-3 flex flex-wrap gap-1">{summaries.map((i) => <button key={i.id} type="button" onClick={() => setShow(i.id)} className={`rounded-full border px-2.5 py-0.5 text-xs ${current?.id === i.id ? 'border-gold-500 bg-gold-50 text-navy-900' : 'border-slate-200 text-slate-500'}`}>{titleOf(i.question_id)} · {fmt.dateTime(i.created_at)}</button>)}</div>
          )}
          {current ? <Summary i={current} title={titleOf(current.question_id)} /> : <p className="mt-3 text-sm text-slate-400">{t('ss.ai.none')}</p>}
        </>
      )}
    </Card>
  )
}

function Summary({ i, title }: { i: Insight; title: string }) {
  const { t } = useTranslation()
  const p = i.payload
  const s = p.sentiment ?? { positive: 0, neutral: 0, negative: 0 }
  const total = Math.max(1, s.positive + s.neutral + s.negative)
  return (
    <div className="mt-4 space-y-4">
      <div className="flex flex-wrap items-center gap-1.5 text-xs text-slate-500"><Badge color="gray">{title}</Badge><Badge color="gray">{t('ss.ai.answersN', { n: i.answers_count })}</Badge>{i.model && <Badge color="gray">{i.model}</Badge>}<Badge color="green"><ShieldCheck className="me-1 inline size-3" />{t('ss.ai.redactedN', { n: i.redactions })}</Badge><span>{i.by} · {fmt.dateTime(i.created_at)}</span></div>
      {p.summary && <p className="rounded-2xl bg-ivory p-4 text-sm leading-7 text-navy-900">{p.summary}</p>}
      <div>
        <div className="mb-1 text-xs font-semibold text-slate-500">{t('ss.ai.sentiment')}</div>
        <div className="flex h-3 overflow-hidden rounded-full bg-slate-100"><div className="bg-emerald-500" style={{ width: `${(s.positive / total) * 100}%` }} /><div className="bg-slate-300" style={{ width: `${(s.neutral / total) * 100}%` }} /><div className="bg-red-400" style={{ width: `${(s.negative / total) * 100}%` }} /></div>
        <div className="mt-1 flex gap-4 text-xs text-slate-500"><span>{t('ss.ai.positive')} {s.positive}</span><span>{t('ss.ai.neutral')} {s.neutral}</span><span>{t('ss.ai.negative')} {s.negative}</span></div>
      </div>
      {!!p.themes?.length && (
        <div className="grid gap-3 md:grid-cols-2">{p.themes.map((th, k) => (
          <div key={k} className="rounded-2xl border border-slate-200 p-3">
            <div className="flex items-center justify-between gap-2"><span className="font-semibold text-navy-900">{th.title}</span><span className="text-xs tabular-nums text-slate-500">{th.count} · {th.share}%</span></div>
            <Progress value={th.share} tone="gold" className="mt-2" />
            {!!th.quotes?.length && <ul className="mt-2 space-y-1">{th.quotes.map((q, j) => <li key={j} className="flex gap-1.5 text-xs text-slate-600"><Quote className="mt-0.5 size-3 shrink-0 text-gold-500" /><span>{q}</span></li>)}</ul>}
          </div>))}</div>
      )}
      {!!p.recommendations?.length && <div><div className="mb-1 text-xs font-semibold text-slate-500">{t('ss.ai.recommendations')}</div><ul className="list-disc space-y-1 ps-5 text-sm text-slate-700">{p.recommendations.map((r, k) => <li key={k}>{r}</li>)}</ul></div>}
      <p className="text-[11px] text-slate-400">{t('ss.ai.disclaimer')}</p>
    </div>
  )
}

export function AiPanelLoading() {
  return <Spinner />
}
