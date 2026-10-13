/* eslint-disable @typescript-eslint/no-explicit-any */
import clsx from 'clsx'
import { Database, FlaskConical, RefreshCw } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Field, Progress, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

type Run = { id: string; model: string; dim: number | null; status: 'queued' | 'running' | 'done' | 'failed'; total: number; done: number; error: string | null; started_at: string | null; finished_at: string | null }
type EvalRun = { id: string; embedder: string; store: string; with_model: boolean; questions: number; answerable: number; off_topic: number; hit_at_3: number; hit_at_5: number; citation_accuracy: number; refusal_accuracy: number; false_refusals: number; passed: boolean; created_at: string }
type Status = { embedder: { id: string; kind: 'local' | 'model'; label: string | null; dim: number | null }; store: { name: 'json' | 'pgvector'; choice: string; pgvector_available: boolean }; models: { id: string; label: string; residency: string }[]; chunks: number; sources: number; run: Run | null }

const MEASURES = [['hit_at_3', 'hit_at_3'], ['hit_at_5', 'hit_at_5'], ['citation_accuracy', 'citation'], ['refusal_accuracy', 'refusal']] as const
const pct = (x: number) => `${fmt.number(x * 100, 0)}%`
const wait = (ms: number) => new Promise((r) => window.setTimeout(r, ms))

/** One measure against its threshold: the bar shows the score, the tick where it must reach. */
function Meter({ label, value, need }: { label: string; value: number; need: number }) {
  const ok = value >= need
  return (
    <div className="space-y-1.5 rounded-xl bg-ivory p-3">
      <div className="flex items-baseline justify-between gap-2"><span className="text-xs font-semibold text-slate-600">{label}</span><b className={clsx('font-display text-xl tabular-nums', ok ? 'text-navy-900' : 'text-danger')}>{pct(value)}</b></div>
      <div className="relative">
        <Progress value={value * 100} tone={ok ? 'green' : 'red'} />
        <span aria-hidden className="absolute -top-1 h-4 w-0.5 rounded bg-navy-900/60" style={{ insetInlineStart: `${need * 100}%` }} />
      </div>
    </div>
  )
}

/** The last runs, oldest to newest: hit@3 and citation accuracy against the hit@3 threshold. */
function Trend({ runs, need, label }: { runs: EvalRun[]; need: number; label: string }) {
  const pts = [...runs].reverse()
  if (pts.length < 2) return null
  const x = (i: number) => (i / (pts.length - 1)) * 100
  const line = (k: 'hit_at_3' | 'citation_accuracy') => pts.map((r, i) => `${x(i).toFixed(2)},${(30 - r[k] * 28 - 1).toFixed(2)}`).join(' ')
  return (
    <figure className="space-y-1">
      <svg viewBox="0 0 100 30" preserveAspectRatio="none" className="h-16 w-full overflow-visible" role="img" aria-label={label}>
        <line x1="0" x2="100" y1={30 - need * 28 - 1} y2={30 - need * 28 - 1} className="stroke-navy-300" strokeWidth="0.6" strokeDasharray="2 2" vectorEffect="non-scaling-stroke" />
        <polyline points={line('citation_accuracy')} fill="none" className="stroke-navy-700" strokeWidth="1.5" vectorEffect="non-scaling-stroke" strokeLinejoin="round" />
        <polyline points={line('hit_at_3')} fill="none" className="stroke-gold-600" strokeWidth="2" vectorEffect="non-scaling-stroke" strokeLinejoin="round" />
      </svg>
      <figcaption className="text-xs text-slate-500">{label}</figcaption>
    </figure>
  )
}

/**
 * Settings → AI: what the assistant searches with (the embedder and the store), re-embedding with another embedder
 * with live progress, and the golden-set evaluation with its trend. The thresholds are part of the AI policy form.
 */
export default function RetrievalPanel({ policy, onChange }: { policy: any; onChange: (p: any) => void }) {
  const { t } = useTranslation()
  const status = useGet<{ data: Status }>('/admin/ai/retrieval', undefined, { staleTime: 0 })
  const evals = useGet<{ data: { thresholds: Record<string, number>; questions: number; runs: EvalRun[] } }>('/admin/ai/eval', undefined, { staleTime: 0 })
  const [target, setTarget] = useState('local')
  const [run, setRun] = useState<Run | null>(null)
  const [busy, setBusy] = useState(false)
  const [evaluating, setEvaluating] = useState(false)
  const [withModel, setWithModel] = useState(false)
  if (!status.data || !evals.data) return <Spinner />
  const s = status.data.data
  const e = evals.data.data
  const latest = e.runs[0]
  const shown = run ?? s.run
  const r = policy.retrieval
  const ev = policy.eval
  const setR = (kind: 'local' | 'model', k: 'min' | 'strong', v: number) => onChange({ ...policy, retrieval: { ...r, [kind]: { ...r[kind], [k]: v } } })

  const reembed = async () => {
    setBusy(true)
    try {
      let { data } = await api.post('/admin/ai/reindex', { model: target })
      let current: Run = data.data
      setRun(current)
      // Each call works for up to 20 seconds; the run continues where it stopped until nothing is missing.
      for (let i = 0; i < 300 && (current.status === 'running' || current.status === 'queued'); i++) {
        if (current.status === 'queued') await wait(1500)
        ;({ data } = await api.post(`/admin/ai/reindex/${current.id}/continue`))
        current = data.data
        setRun(current)
      }
      if (current.status === 'done') toast(String(t('aix.ret.done', { n: fmt.number(current.total) })))
      else toast(current.error || String(t('aix.ret.failed')), 'error')
      void status.refetch()
    } catch (err) { toast(errorMessage(err), 'error') } finally { setBusy(false) }
  }
  const evaluate = async () => {
    setEvaluating(true)
    try {
      const { data } = await api.post('/admin/ai/eval', { with_model: withModel })
      toast(String(t(data.data.passed ? 'aix.ret.passed' : 'aix.ret.below')), data.data.passed ? undefined : 'error')
      void evals.refetch()
    } catch (err) { toast(errorMessage(err), 'error') } finally { setEvaluating(false) }
  }
  const embedderName = s.embedder.kind === 'local' ? String(t('aix.ret.local')) : s.embedder.label ?? s.embedder.id

  return (
    <div className="space-y-6">
      <Card className="space-y-5">
        <div className="flex items-start gap-3">
          <Database className="mt-0.5 size-5 shrink-0 text-gold-600" />
          <div><h3 className="text-lg font-bold text-navy-900">{t('aix.ret.title')}</h3><p className="text-sm text-slate-500">{t('aix.ret.subtitle')}</p></div>
        </div>
        <dl className="grid gap-3 sm:grid-cols-3">
          <div className="rounded-xl bg-ivory p-3"><dt className="text-xs text-slate-500">{t('aix.ret.embedder')}</dt><dd className="mt-1 font-semibold text-navy-900">{embedderName}</dd>{s.embedder.dim && <dd className="text-xs text-slate-500">{t('aix.ret.dim', { n: s.embedder.dim })}</dd>}</div>
          <div className="rounded-xl bg-ivory p-3"><dt className="text-xs text-slate-500">{t('aix.ret.store')}</dt><dd className="mt-1 font-semibold text-navy-900">{t(`aix.ret.stores.${s.store.name}`)}</dd><dd className="mt-1"><Badge color={s.store.pgvector_available ? 'green' : 'gray'}>{t(s.store.pgvector_available ? 'aix.ret.pgAvailable' : 'aix.ret.pgMissing')}</Badge></dd></div>
          <div className="rounded-xl bg-ivory p-3"><dt className="text-xs text-slate-500">{t('aix.ret.indexed')}</dt><dd className="mt-1 font-display text-2xl font-bold tabular-nums text-navy-900">{fmt.number(s.chunks)}</dd><dd className="text-xs text-slate-500">{t('aix.ret.sources', { n: fmt.number(s.sources) })}</dd></div>
        </dl>

        <div className="space-y-3 rounded-2xl border border-navy-100 p-4">
          <div className="flex flex-wrap items-end gap-3">
            <Field label={t('aix.ret.target')} className="min-w-56 flex-1">
              <select className="input" value={target} onChange={(ev2) => setTarget(ev2.target.value)} disabled={busy}>
                <option value="local">{t('aix.ret.local')}</option>
                {s.models.map((m) => <option key={m.id} value={m.id}>{m.label} · {t(`aix.set.residencyBadge.${m.residency}`)}</option>)}
              </select>
            </Field>
            <Button variant="outline" loading={busy} icon={<RefreshCw className={clsx('size-4', busy && 'motion-safe:animate-spin')} />} onClick={() => void reembed()}>{t('aix.ret.reembed')}</Button>
          </div>
          <p className="text-xs text-slate-500">{t('aix.ret.reembedHint')}</p>
          {s.models.length === 0 && <p className="text-xs text-slate-500">{t('aix.ret.noModels')}</p>}
          {shown && (
            <div className="space-y-1.5" aria-live="polite">
              <div className="flex flex-wrap items-center gap-2 text-xs">
                <Badge color={shown.status === 'done' ? 'green' : shown.status === 'failed' ? 'red' : 'gold'}>{t(`aix.ret.status.${shown.status}`)}</Badge>
                <span className="text-slate-500">{shown.model === 'local-hash-1024' ? t('aix.ret.local') : shown.model}</span>
                <span className="ms-auto tabular-nums text-slate-600">{fmt.number(shown.done)} / {fmt.number(shown.total)}</span>
              </div>
              <Progress value={shown.total ? (shown.done / shown.total) * 100 : shown.status === 'done' ? 100 : 0} tone={shown.status === 'failed' ? 'red' : 'gold'} />
              {shown.error && <p className="text-xs text-danger">{shown.error}</p>}
              {shown.finished_at && <p className="text-xs text-slate-400">{fmt.dateTime(shown.finished_at)}</p>}
            </div>
          )}
        </div>

        <details className="group rounded-2xl border border-navy-100 p-4">
          <summary className="cursor-pointer text-sm font-semibold text-navy-900">{t('aix.ret.advanced')}</summary>
          <div className="mt-4 grid gap-3 sm:grid-cols-5">
            <Field label={t('aix.ret.storeChoice')}><select className="input" value={r.store} onChange={(ev2) => onChange({ ...policy, retrieval: { ...r, store: ev2.target.value } })}>{['auto', 'json', 'pgvector'].map((k) => <option key={k} value={k}>{t(`aix.ret.choices.${k}`)}</option>)}</select></Field>
            {(['local', 'model'] as const).flatMap((kind) => (['min', 'strong'] as const).map((k) => (
              <Field key={`${kind}${k}`} label={t(`aix.ret.th.${kind}_${k}`)} hint={k === 'strong' ? t('aix.ret.th.strongHint') : undefined}><input type="number" step="0.01" min={0} max={1} className="input" value={r[kind][k]} onChange={(ev2) => setR(kind, k, Number(ev2.target.value))} /></Field>)))}
          </div>
        </details>
      </Card>

      <Card className="space-y-5">
        <div className="flex flex-wrap items-start gap-3">
          <FlaskConical className="mt-0.5 size-5 shrink-0 text-gold-600" />
          <div className="min-w-0 flex-1"><h3 className="text-lg font-bold text-navy-900">{t('aix.ret.golden')}</h3><p className="text-sm text-slate-500">{t('aix.ret.goldenHint', { n: e.questions })}</p></div>
          {latest && <Badge color={latest.passed ? 'green' : 'red'}>{t(latest.passed ? 'aix.ret.passedBadge' : 'aix.ret.belowBadge')}</Badge>}
        </div>
        {latest ? (
          <>
            <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">{MEASURES.map(([k, th]) => <Meter key={k} label={t(`aix.ret.m.${k}`)} value={latest[k]} need={e.thresholds[th]} />)}</div>
            <p className="text-xs text-slate-500">{t('aix.ret.runMeta', { date: fmt.dateTime(latest.created_at), embedder: latest.embedder === 'local-hash-1024' ? t('aix.ret.local') : latest.embedder, store: t(`aix.ret.stores.${latest.store}`), answerable: latest.answerable, off: latest.off_topic })}{latest.with_model ? ` · ${t('aix.ret.withModel')}` : ''}{latest.false_refusals > 0 ? ` · ${t('aix.ret.falseRefusals', { n: latest.false_refusals })}` : ''}</p>
            <Trend runs={e.runs} need={e.thresholds.hit_at_3} label={String(t('aix.ret.trend'))} />
          </>
        ) : <p className="rounded-xl bg-ivory p-4 text-sm text-slate-500">{t('aix.ret.noRuns')}</p>}
        <div className="flex flex-wrap items-center gap-4">
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={withModel} onChange={(ev2) => setWithModel(ev2.target.checked)} />{t('aix.ret.useModel')}</label>
          <Button variant="outline" loading={evaluating} onClick={() => void evaluate()}>{t('aix.ret.run')}</Button>
        </div>
        <details className="rounded-2xl border border-navy-100 p-4">
          <summary className="cursor-pointer text-sm font-semibold text-navy-900">{t('aix.ret.thresholds')}</summary>
          <div className="mt-4 grid gap-3 sm:grid-cols-4">{(['hit_at_3', 'hit_at_5', 'citation', 'refusal'] as const).map((k) => (
            <Field key={k} label={t(`aix.ret.need.${k}`)}><input type="number" step="0.05" min={0} max={1} className="input" value={ev[k]} onChange={(ev2) => onChange({ ...policy, eval: { ...ev, [k]: Number(ev2.target.value) } })} /></Field>))}</div>
        </details>
      </Card>
    </div>
  )
}
