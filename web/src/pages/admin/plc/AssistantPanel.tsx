import { AlertTriangle, ArrowLeft, ArrowRight, CheckCircle2, Circle, Compass, Link2, Sparkles } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Card, Progress, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'

type Text = { ar: string; en: string }
type Item = { key: string; status: 'done' | 'todo' | 'warn' | 'na'; detail: Text; link?: string | null }
export type Assistant = {
  state: string; progress: number; done: number; total: number; items: Item[]; next?: Item | null
  explanations: { schools: { school_id: string; role: string; reasons: string[]; distance_km?: number | null }[]; trainees: { seated: number; with_clashes: number; by_status: Record<string, number>; schools: Record<string, number> } }
  decisions: { total: number; by_kind: Record<string, number>; exceptions: number }
  impact: { need?: { id: string; title: string; reference_no?: string | null; source: string } | null; seated: number; completed: number; attendance: number | null; evaluation: { assigned: number; submitted: number }; impact: { scheduled: number; sent: number; completed: number } }
  themes: { opt_in: boolean; available: boolean; answers: number; min_answers: number; can_generate: boolean; result: unknown }
}

/** The planning assistant on a workshop: the checklist with a reason per item, the completion tracker, the next step, and the need → workshop → impact chain. Advisory only: it points, a person decides. */
export default function AssistantPanel({ id, onKind, kind }: { id: string; onKind?: (k: string | null) => void; kind?: string | null }) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const res = useGet<{ data: Assistant }>(`/admin/plc/workshops/${id}/assistant`, undefined, { staleTime: 15_000 })
  if (res.isLoading && !res.data) return <Card><Spinner /></Card>
  if (!res.data) return null
  const a = res.data.data
  const tx = (x: Text) => (en ? x.en : x.ar)
  const Arrow = en ? ArrowRight : ArrowLeft
  const icon = (s: Item['status']) => s === 'done' ? <CheckCircle2 className="size-4 text-emerald-600" /> : s === 'warn' ? <AlertTriangle className="size-4 text-amber-600" /> : <Circle className="size-4 text-slate-300" />
  return (
    <>
      <Card>
        <div className="mb-2 flex items-center justify-between gap-2">
          <h3 className="font-display text-sm font-bold text-navy-900"><Compass className="me-1 inline size-4 text-gold-600" />{t('plc.assistant.title')}</h3>
          <span className="text-xs tabular-nums text-slate-500">{a.done}/{a.total}</span>
        </div>
        <Progress value={a.progress} tone={a.progress >= 100 ? 'green' : 'gold'} />
        {a.next && (
          <Link to={a.next.link ?? `/admin/communities/workshops/${id}`} className="mt-3 flex items-start gap-2 rounded-xl border border-gold-200 bg-gold-50/60 px-3 py-2 text-sm hover:bg-gold-50">
            <Sparkles className="mt-0.5 size-4 shrink-0 text-gold-600" />
            <span className="min-w-0"><span className="block text-[11px] font-semibold uppercase tracking-wide text-gold-700">{t('plc.assistant.next')}</span><span className="block font-semibold text-navy-900">{t(`plc.assistant.items.${a.next.key}`)}</span><span className="block text-xs text-slate-600">{tx(a.next.detail)}</span></span>
            <Arrow className="ms-auto mt-1 size-4 shrink-0 text-gold-700" />
          </Link>
        )}
        <ol className="mt-3 space-y-1.5 text-sm">
          {a.items.map((i) => (
            <li key={i.key} className="flex items-start gap-2">
              <span className="mt-0.5 shrink-0">{icon(i.status)}</span>
              <span className="min-w-0">
                {i.link && i.status !== 'done' ? <Link to={i.link} className="font-semibold text-navy-900 underline decoration-gold-300 hover:text-gold-700">{t(`plc.assistant.items.${i.key}`)}</Link> : <span className={`font-semibold ${i.status === 'done' ? 'text-slate-500' : 'text-navy-900'}`}>{t(`plc.assistant.items.${i.key}`)}</span>}
                <span className="block text-xs text-slate-500">{tx(i.detail)}</span>
              </span>
            </li>
          ))}
        </ol>
        <p className="mt-3 text-[11px] text-slate-400">{t('plc.assistant.advisory')}</p>
      </Card>

      <Card>
        <h3 className="mb-2 font-display text-sm font-bold text-navy-900"><Link2 className="me-1 inline size-4 text-gold-600" />{t('plc.assistant.chain')}</h3>
        <ol className="space-y-1 text-xs">
          <li className="flex items-center justify-between gap-2"><span>{t('plc.assistant.chainNeed')}</span><span className="font-semibold text-navy-900">{a.impact.need ? <Link className="underline" to={`/admin/communities?tab=reports&need_id=${a.impact.need.id}`}>{a.impact.need.reference_no ?? a.impact.need.title}</Link> : '—'}</span></li>
          <li className="flex items-center justify-between gap-2"><span>{t('plc.assistant.chainSeated')}</span><span className="font-semibold tabular-nums text-navy-900">{a.impact.seated}</span></li>
          <li className="flex items-center justify-between gap-2"><span>{t('plc.assistant.chainCompleted')}</span><span className="font-semibold tabular-nums text-navy-900">{a.impact.completed}{a.impact.attendance != null ? ` · ${a.impact.attendance}%` : ''}</span></li>
          <li className="flex items-center justify-between gap-2"><span>{t('plc.assistant.chainEvaluation')}</span><span className="font-semibold tabular-nums text-navy-900">{a.impact.evaluation.submitted}/{a.impact.evaluation.assigned}</span></li>
          <li className="flex items-center justify-between gap-2"><span>{t('plc.assistant.chainImpact')}</span><span className="font-semibold tabular-nums text-navy-900">{a.impact.impact.completed}/{a.impact.impact.scheduled + a.impact.impact.sent + a.impact.impact.completed}</span></li>
        </ol>
        {a.explanations.schools.some((s) => s.reasons.length) && (
          <div className="mt-3 border-t border-slate-100 pt-2 text-xs">
            <div className="mb-1 font-semibold text-navy-900">{t('plc.assistant.whySchools')}</div>
            <div className="flex flex-wrap gap-1">{[...new Set(a.explanations.schools.flatMap((s) => s.reasons.map((r) => (typeof r === 'string' ? r : (r as { key?: string }).key ?? ''))))].filter(Boolean).map((k) => <span key={k} className="rounded-full bg-slate-100 px-2 py-0.5 text-slate-600">{t(`plc.schools.reasons.${k}`, { defaultValue: k })}</span>)}</div>
          </div>
        )}
        {a.explanations.trainees.with_clashes > 0 && <p className="mt-2 text-xs text-amber-700">{t('plc.assistant.clashes', { n: a.explanations.trainees.with_clashes })}</p>}
        {onKind && a.decisions.total > 0 && (
          <div className="mt-3 border-t border-slate-100 pt-2 text-xs">
            <div className="mb-1 font-semibold text-navy-900">{t('plc.assistant.decisionsByKind')}</div>
            <div className="flex flex-wrap gap-1">
              <button type="button" onClick={() => onKind(null)} className={`rounded-full px-2 py-0.5 ${!kind ? 'bg-navy-900 text-white' : 'bg-slate-100 text-slate-600'}`}>{t('common.all')} {a.decisions.total}</button>
              {Object.entries(a.decisions.by_kind).map(([k, n]) => <button key={k} type="button" onClick={() => onKind(kind === k ? null : k)} className={`rounded-full px-2 py-0.5 ${kind === k ? 'bg-navy-900 text-white' : k === 'exception' ? 'bg-amber-50 text-amber-800' : 'bg-slate-100 text-slate-600'}`}>{t(`plc.detail.kinds.${k}`, { defaultValue: k })} {n}</button>)}
            </div>
          </div>
        )}
      </Card>
    </>
  )
}
