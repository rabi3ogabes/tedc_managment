import clsx from 'clsx'
import { BarChart3, ChevronDown, ChevronUp, Download, Filter, GitCompare, ListChecks, Lock, Paperclip, Sparkles, Wand2 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, CardTitle, Empty, Field, Modal, Progress, Spinner, StatCard, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import type { Question } from '@/lib/surveyEngine'
import { toast } from '@/lib/toast'

type Option = { id: string; label: string; count?: number; percent?: number; score?: number }
type Stat = { id: string; type: string; title: string; answered: number; options?: Option[]; average?: number | null; nps?: number | null; distribution?: { label: string; value: number }[]; rows?: { id: string; label: string; average: number | null; answered: number }[]; scale?: { min: number; max: number }; samples?: string[]; files?: number }
type Need = { key: string; skill_id: string | null; skill_name: string; index: number; priority: string; in_need: number; respondents: number; share: number; generated: boolean; by_school: { key: string; label: string; index: number; in_need: number; respondents: number }[] }
type Results = {
  summary: { participants: number; answered: number; response_rate: number | null; submissions: number; filtered: number; edited: number; avg_duration_seconds: number; last_response_at?: string | null; timeline: { date: string; value: number }[]; by_source: Record<string, number>; versions: { number: number; published_at: string; submissions: number }[]; version: number | null; threshold: number; hidden: boolean; privacy_mode: string }
  questions: Stat[]
  segments: Record<string, { value: string; label: string; count: number }[]>
  needs: Need[]
}
type AnswerRow = { id: string; submitted_at: string; edited_at?: string | null; edit_count: number; source: string; version: number | null; duration_seconds?: number | null; respondent: { id: string; name: string; school?: string | null } | null; segment: Record<string, string> | null; answers: Record<string, unknown>; files: { id: string; question_id: string; name: string }[] }
type Answers = { total: number; page: number; per_page: number; identified: boolean; segments_shown: boolean; rows: AnswerRow[] }
type Sub = 'summary' | 'answers' | 'compare'
const SEGMENT_ORDER = ['school_id', 'region', 'school_type', 'job_title_id', 'education_stage', 'specialization', 'nationality', 'gender', 'experience_band', 'qualification']

/** The results of an engine survey: summary, segment filters under the threshold rule, per-question statistics, the needs report, individual answers, comparison and exports. */
export default function ResultsTab({ id, kind, questions, status }: { id: string; kind: string; questions: Question[]; status: string }) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const [sub, setSub] = useState<Sub>('summary')
  const [filters, setFilters] = useState<Record<string, string>>({})
  const [version, setVersion] = useState('')
  const params = useMemo(() => ({ ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)), version: version || undefined }), [filters, version])
  const res = useGet<{ data: Results }>(`/admin/surveys/${id}/results`, params, { staleTime: 0, refetchInterval: status === 'published' ? 60_000 : false })
  const r = res.data?.data
  const byId = useMemo(() => Object.fromEntries(questions.map((q) => [q.id, q])), [questions])
  const exp = (format: string) => downloadFile(`/admin/surveys/${id}/export?format=${format}&lang=${i18n.language}${Object.entries(params).filter(([, v]) => v).map(([k, v]) => `&${k}=${encodeURIComponent(String(v))}`).join('')}`, `survey-results.${format}`)
  if (!r) return res.error ? <Card><p className="text-sm text-red-700">{errorMessage(res.error)}</p></Card> : <Spinner />
  const s = r.summary
  const filtered = Object.values(filters).some(Boolean)

  return (
    <div className="space-y-5">
      <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
        <StatCard label={t('ss.results.participants')} value={fmt.number(s.participants)} />
        <StatCard label={t('ss.results.answered')} value={fmt.number(s.answered)} />
        <StatCard label={t('ss.results.rate')} value={s.response_rate != null ? `${s.response_rate}%` : '—'} />
        <StatCard label={t('ss.results.submissions')} value={filtered ? `${fmt.number(s.filtered)} / ${fmt.number(s.submissions)}` : fmt.number(s.submissions)} accent />
        <StatCard label={t('ss.results.avgTime')} value={s.avg_duration_seconds ? `${Math.max(1, Math.round(s.avg_duration_seconds / 60))} ${t('ss.results.minutes')}` : '—'} />
      </div>

      <Card>
        <div className="flex flex-wrap items-start gap-4">
          <div className="min-w-0 flex-1">
            <div className="mb-2 flex items-center gap-2 text-sm font-bold text-navy-900"><Filter className="size-4 text-gold-600" />{t('ss.results.filters')}{filtered && <button type="button" className="text-xs font-semibold text-gold-700 underline" onClick={() => setFilters({})}>{t('ss.results.clear')}</button>}</div>
            <div className="flex flex-wrap gap-2">
              {SEGMENT_ORDER.filter((f) => r.segments[f]?.length).map((f) => (
                <select key={f} className="input w-auto max-w-56 text-xs" value={filters[f] ?? ''} onChange={(e) => setFilters({ ...filters, [f]: e.target.value })}>
                  <option value="">{t(`ss.studio.settings.fields.${f}`, { defaultValue: f })}</option>
                  {r.segments[f].map((v) => <option key={v.value} value={v.value}>{v.label} ({v.count})</option>)}
                </select>
              ))}
              {Object.keys(s.by_source).length > 1 && <select className="input w-auto text-xs" value={filters.source ?? ''} onChange={(e) => setFilters({ ...filters, source: e.target.value })}><option value="">{t('ss.results.allSources')}</option>{Object.entries(s.by_source).map(([k, n]) => <option key={k} value={k}>{t(`ss.results.sources.${k}`, { defaultValue: k })} ({n})</option>)}</select>}
              {s.versions.length > 1 && <select className="input w-auto text-xs" value={version} onChange={(e) => setVersion(e.target.value)}><option value="">{t('ss.results.currentVersion')}</option>{s.versions.map((v) => <option key={v.number} value={v.number}>{t('ss.list.version')} {v.number} ({v.submissions})</option>)}</select>}
              {!SEGMENT_ORDER.some((f) => r.segments[f]?.length) && <span className="text-xs text-slate-400">{t('ss.results.noSegments', { n: s.threshold })}</span>}
            </div>
          </div>
          <Sparkline data={s.timeline} />
        </div>
        <div className="mt-3 flex flex-wrap items-center gap-2 border-t border-slate-100 pt-3">
          <span className="me-auto text-xs text-slate-500">{t('ss.results.thresholdNote', { n: s.threshold })}{s.edited ? ` · ${t('ss.results.edited', { n: s.edited })}` : ''}</span>
          {can('surveys.export') && ['xlsx', 'pdf', 'docx', 'csv'].map((f) => <Button key={f} size="sm" variant="outline" icon={<Download className="size-3.5" />} disabled={s.hidden} onClick={() => void exp(f)}>{f.toUpperCase()}</Button>)}
        </div>
      </Card>

      <Tabs<Sub> value={sub} onChange={setSub} tabs={[{ id: 'summary', label: t('ss.results.tabs.summary') }, { id: 'answers', label: t('ss.results.tabs.answers') }, { id: 'compare', label: t('ss.results.tabs.compare') }]} />

      {s.hidden ? (
        <Card><div className="flex items-start gap-3 text-sm text-amber-900"><Lock className="mt-0.5 size-5 shrink-0 text-amber-600" /><div><div className="font-bold">{t('ss.results.hiddenTitle')}</div><p className="mt-1 leading-7">{t('ss.results.hiddenText', { n: s.threshold, m: s.filtered })}</p></div></div></Card>
      ) : sub === 'summary' ? (
        <>
          {kind === 'needs' && <NeedsPanel id={id} needs={r.needs} canGenerate={can('needs.manage')} onDone={() => res.refetch()} />}
          {!r.questions.length ? <Card><Empty text={String(t('ss.results.noAnswers'))} icon={<BarChart3 className="size-6" />} /></Card> : (
            <div className="grid gap-4 xl:grid-cols-2">{r.questions.map((q, i) => <QuestionCard key={q.id} q={q} n={i + 1} def={byId[q.id]} />)}</div>
          )}
        </>
      ) : sub === 'answers' ? <AnswersTable id={id} params={params} questions={questions} segments={r.segments} /> : <ComparePanel id={id} />}
    </div>
  )
}

function Sparkline({ data }: { data: { date: string; value: number }[] }) {
  const { t } = useTranslation()
  if (data.length < 2) return null
  const max = Math.max(1, ...data.map((d) => d.value))
  const w = 180; const h = 44
  const pts = data.map((d, i) => `${(i / (data.length - 1)) * w},${h - (d.value / max) * (h - 4) - 2}`)
  return (
    <div className="shrink-0 text-end">
      <svg width={w} height={h} className="overflow-visible"><polyline fill="none" stroke="#b8963e" strokeWidth={2} points={pts.join(' ')} /><polygon fill="#b8963e" fillOpacity={0.12} points={`0,${h} ${pts.join(' ')} ${w},${h}`} /></svg>
      <div className="text-[10px] text-slate-400">{t('ss.results.timeline', { from: fmt.date(data[0].date), to: fmt.date(data[data.length - 1].date) })}</div>
    </div>
  )
}

function QuestionCard({ q, n, def }: { q: Stat; n: number; def?: Question }) {
  const { t } = useTranslation()
  const total = q.distribution ? Math.max(1, q.distribution.reduce((a, d) => a + d.value, 0)) : 0
  return (
    <Card>
      <div className="mb-3 flex items-start justify-between gap-3">
        <div><div className="text-[11px] font-semibold uppercase tracking-wide text-slate-400">{n}. {t(`ss.types.${q.type}`, { defaultValue: q.type })}</div><div className="font-semibold text-navy-900">{q.title}</div></div>
        <div className="flex shrink-0 flex-wrap justify-end gap-1"><Badge color="gray">{t('ss.results.answeredN', { n: q.answered })}</Badge>{q.average != null && <Badge color="gold">{t('ss.results.average')} {q.average}</Badge>}{q.nps != null && <Badge color={q.nps >= 0 ? 'green' : 'red'}>NPS {q.nps}</Badge>}</div>
      </div>
      {q.options && <div className="space-y-1.5">{q.options.map((o) => { const v = o.percent ?? o.score ?? 0; return <div key={o.id} className="flex items-center gap-2 text-xs"><span className="w-36 truncate text-slate-700">{o.label}</span><div className="flex-1"><Progress value={o.percent ?? Math.min(100, (o.score ?? 0) * 10)} tone="gold" /></div><span className="w-16 text-end tabular-nums text-slate-500">{o.percent != null ? `${v}% · ${o.count}` : v}</span></div> })}</div>}
      {q.distribution && (
        <div className="flex h-24 items-end gap-1">{q.distribution.map((d) => <div key={d.label} className="flex flex-1 flex-col items-center gap-1" title={`${d.label}: ${d.value}`}><div className="w-full rounded-t-md bg-gradient-to-t from-navy-800 to-navy-600" style={{ height: `${Math.max(2, (d.value / total) * 80)}px` }} /><span className="text-[10px] text-slate-500">{d.label}</span></div>)}</div>
      )}
      {q.rows && <table className="w-full text-xs"><tbody>{q.rows.map((row) => <tr key={row.id} className="border-t border-slate-100"><td className="py-1.5 pe-2 text-slate-700">{row.label}</td><td className="w-40 py-1.5"><Progress value={q.scale ? ((row.average ?? q.scale.min) - q.scale.min) / Math.max(1, q.scale.max - q.scale.min) * 100 : 0} tone="navy" /></td><td className="w-14 py-1.5 text-end tabular-nums font-semibold text-navy-900">{row.average ?? '—'}</td></tr>)}</tbody></table>}
      {q.samples && <ul className="max-h-48 space-y-1 overflow-auto text-sm text-slate-700">{q.samples.map((x, i) => <li key={i} className="rounded-lg bg-slate-50 px-3 py-1.5">{x}</li>)}{!q.samples.length && <li className="text-xs text-slate-400">—</li>}</ul>}
      {q.files != null && <div className="flex items-center gap-2 text-sm text-slate-700"><Paperclip className="size-4 text-gold-600" />{t('ss.results.filesN', { n: q.files })}</div>}
      {def?.required && <div className="mt-2 text-[10px] text-slate-400">{t('ss.studio.required')}</div>}
    </Card>
  )
}

function NeedsPanel({ id, needs, canGenerate, onDone }: { id: string; needs: Need[]; canGenerate: boolean; onDone: () => void }) {
  const { t } = useTranslation()
  const [selected, setSelected] = useState<string[]>([])
  const [open, setOpen] = useState(false)
  const [split, setSplit] = useState<'overall' | 'school'>('overall')
  const [busy, setBusy] = useState(false)
  const TONE: Record<string, string> = { critical: 'from-red-500 to-rose-400', high: 'from-orange-500 to-amber-400', medium: 'from-sky-500 to-cyan-400', low: 'from-slate-400 to-slate-300' }
  const go = async () => { setBusy(true); try { const { data } = await api.post(`/admin/surveys/${id}/generate-needs`, { items: selected.map((key) => ({ key })), split }); toast(String(t('ss.results.needsCreated', { n: data.created }))); setOpen(false); setSelected([]); onDone() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) } }
  return (
    <Card>
      <CardTitle subtitle={t('ss.results.needsHint')} action={canGenerate && needs.length > 0 && <Button size="sm" variant="gold" icon={<Wand2 className="size-4" />} disabled={!selected.length} onClick={() => setOpen(true)}>{t('ss.results.generate')}{selected.length ? ` · ${selected.length}` : ''}</Button>}><span className="inline-flex items-center gap-2"><Sparkles className="size-5 text-gold-600" />{t('ss.results.needsTitle')}</span></CardTitle>
      {!needs.length ? <p className="text-sm text-slate-500">{t('ss.results.noNeeds')}</p> : (
        <ul className="space-y-2">{needs.map((n, i) => (
          <li key={n.key} className={clsx('flex items-center gap-3 rounded-2xl border p-3', selected.includes(n.key) ? 'border-gold-400 bg-gold-50' : 'border-slate-200 bg-white')}>
            {canGenerate && <input type="checkbox" className="size-4 accent-gold-600" checked={selected.includes(n.key)} onChange={() => setSelected((x) => (x.includes(n.key) ? x.filter((k) => k !== n.key) : [...x, n.key]))} aria-label={n.skill_name} />}
            <span className="w-5 text-center font-display text-sm font-bold text-slate-300">{i + 1}</span>
            <div className="min-w-0 flex-1">
              <div className="flex flex-wrap items-center gap-2"><span className="font-bold text-navy-900">{n.skill_name}</span><Badge color={n.priority === 'critical' ? 'red' : n.priority === 'high' ? 'amber' : 'gray'}>{t(`priority.${n.priority}`, { defaultValue: n.priority })}</Badge>{n.generated && <Badge color="green">✓ {t('ss.results.generated')}</Badge>}</div>
              <div className="mt-1.5 flex items-center gap-3"><div className="h-2 flex-1 overflow-hidden rounded-full bg-slate-100"><div className={clsx('h-full rounded-full bg-gradient-to-l', TONE[n.priority] ?? TONE.low)} style={{ width: `${n.index}%` }} /></div><span className="w-8 text-end font-display font-bold text-navy-900">{n.index}</span></div>
              <div className="mt-0.5 text-xs text-slate-500">{t('ss.results.inNeed', { n: n.in_need, of: n.respondents, share: n.share })}</div>
            </div>
          </li>))}</ul>
      )}
      <Modal open={open} onClose={() => setOpen(false)} title={t('ss.results.generate')}>
        <p className="mb-3 text-sm text-slate-600">{t('ss.results.generateHint', { n: selected.length })}</p>
        <Field label={t('ss.results.split')}><select className="input" value={split} onChange={(e) => setSplit(e.target.value as 'overall' | 'school')}><option value="overall">{t('ss.results.splitOverall')}</option><option value="school">{t('ss.results.splitSchool')}</option></select></Field>
        <div className="mt-4 flex justify-end gap-2"><Button variant="outline" onClick={() => setOpen(false)}>{t('common.cancel')}</Button><Button variant="gold" loading={busy} icon={<ListChecks className="size-4" />} onClick={go}>{t('ss.results.generate')}</Button></div>
      </Modal>
    </Card>
  )
}

function AnswersTable({ id, params, questions, segments }: { id: string; params: Record<string, unknown>; questions: Question[]; segments: Results['segments'] }) {
  const segmentLabels = useMemo(() => Object.fromEntries(Object.entries(segments).map(([f, vs]) => [f, Object.fromEntries(vs.map((v) => [v.value, v.label]))])), [segments])
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const [page, setPage] = useState(1)
  const [open, setOpen] = useState<string | null>(null)
  const res = useGet<{ data: Answers }>(`/admin/surveys/${id}/answers`, { ...params, page, per_page: 25 }, { staleTime: 0, retry: false })
  if (res.error) return <Card><p className="text-sm text-amber-800">{errorMessage(res.error)}</p></Card>
  if (!res.data) return <Spinner />
  const d = res.data.data
  const pages = Math.max(1, Math.ceil(d.total / d.per_page))
  const label = (q: Question, v: unknown): string => {
    if (v == null || v === '') return '—'
    const opts = q.options ?? []
    const name = (x: unknown) => { const o = opts.find((o) => o.id === x); return o ? (en && o.label_en ? o.label_en : o.label) : String(x) }
    if (q.type === 'matrix' && typeof v === 'object') return Object.entries(v as Record<string, unknown>).map(([rid, val]) => `${q.rows?.find((r) => r.id === rid)?.label ?? rid}: ${val}`).join(' · ')
    if (Array.isArray(v)) return q.type === 'file_upload' ? t('ss.results.filesN', { n: v.length }) : v.map(name).join('، ')
    if (q.type === 'yes_no') return v === 'yes' ? t('ss.form.yes', { defaultValue: 'نعم' }) : t('ss.form.no', { defaultValue: 'لا' })
    return name(v)
  }
  return (
    <Card padded={false}>
      <div className="flex items-center justify-between px-4 py-3 text-xs text-slate-500"><span>{t('ss.results.rowsN', { n: d.total })}{!d.identified && ` · ${t('ss.results.noIdentity')}`}</span><span className="flex items-center gap-2"><Button size="sm" variant="ghost" disabled={page <= 1} onClick={() => setPage(page - 1)}>‹</Button>{page} / {pages}<Button size="sm" variant="ghost" disabled={page >= pages} onClick={() => setPage(page + 1)}>›</Button></span></div>
      {!d.rows.length ? <Empty text={String(t('ss.results.noAnswers'))} /> : (
        <table className="w-full text-sm"><thead className="bg-slate-50 text-xs text-slate-500"><tr><th className="px-4 py-2 text-start">{t('ss.results.when')}</th>{d.identified && <th className="px-4 py-2 text-start">{t('ss.results.who')}</th>}<th className="px-4 py-2 text-start">{t('ss.results.source')}</th><th className="px-4 py-2 text-start">{t('ss.list.version')}</th><th className="px-4 py-2 text-start">{t('ss.results.answersCount')}</th><th /></tr></thead>
          <tbody className="divide-y divide-slate-100">{d.rows.map((row) => (
            <RowPair key={row.id} open={open === row.id} onToggle={() => setOpen(open === row.id ? null : row.id)} head={<>
              <td className="px-4 py-2 text-xs">{fmt.dateTime(row.submitted_at)}{row.edit_count > 0 && <span className="ms-1 text-slate-400">({t('ss.results.editedN', { n: row.edit_count })})</span>}</td>
              {d.identified && <td className="px-4 py-2">{row.respondent ? <span>{row.respondent.name}{row.respondent.school ? <span className="block text-xs text-slate-400">{row.respondent.school}</span> : null}</span> : '—'}</td>}
              <td className="px-4 py-2 text-xs">{t(`ss.results.sources.${row.source}`, { defaultValue: row.source })}</td><td className="px-4 py-2 text-xs">{row.version ?? '—'}</td><td className="px-4 py-2 text-xs">{Object.keys(row.answers).length}</td>
            </>}>
              <div className="grid gap-2 sm:grid-cols-2">{questions.filter((q) => !['section', 'instructions', 'page_break'].includes(q.type)).map((q) => <div key={q.id} className="rounded-xl bg-white px-3 py-2 text-xs"><div className="text-slate-400">{en && q.title_en ? q.title_en : q.title}</div><div className="mt-0.5 font-semibold text-navy-900">{q.type === 'file_upload' ? (row.files.filter((f) => f.question_id === q.id).map((f) => f.name).join('، ') || '—') : label(q, row.answers[q.id])}</div></div>)}</div>
              {row.segment && <div className="mt-2 flex flex-wrap gap-1">{Object.entries(row.segment).map(([k, v]) => <Badge key={k} color="gray">{t(`ss.studio.settings.fields.${k}`, { defaultValue: k })}: {segmentLabels[k]?.[String(v)] ?? String(v)}</Badge>)}</div>}
            </RowPair>))}</tbody></table>
      )}
    </Card>
  )
}

function RowPair({ open, onToggle, head, children }: { open: boolean; onToggle: () => void; head: React.ReactNode; children: React.ReactNode }) {
  return (
    <>
      <tr className="cursor-pointer hover:bg-slate-50" onClick={onToggle}>{head}<td className="px-3 py-2 text-slate-400">{open ? <ChevronUp className="size-4" /> : <ChevronDown className="size-4" />}</td></tr>
      {open && <tr><td colSpan={6} className="bg-slate-50 px-4 py-3">{children}</td></tr>}
    </>
  )
}

type Compare = { surveys: { id: string; title: string; kind: string; status: string; submissions: number; participants: number; response_rate: number | null }[]; questions: { id: string; type: string; title: string; by_survey: Record<string, { answered: number; average?: number | null; nps?: number | null; options?: Option[]; rows?: { id: string; label: string; average: number | null }[] } | null> }[] }

function ComparePanel({ id }: { id: string }) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const [picked, setPicked] = useState<string[]>([])
  const list = useGet<{ data: { id: string; title_ar: string; title_en?: string | null; status: string; kind: string; submissions: number }[] }>('/admin/surveys', { per_page: 100, status: 'published,closed,archived' })
  const ids = [id, ...picked]
  const cmp = useGet<{ data: Compare }>(picked.length ? '/admin/surveys/compare' : null, { ids }, { staleTime: 0 })
  const others = (list.data?.data ?? []).filter((s) => s.id !== id && s.submissions > 0)
  const cell = (q: Compare['questions'][number], sid: string) => {
    const v = q.by_survey[sid]
    if (!v) return <span className="text-slate-300">—</span>
    if (v.nps != null) return <span><b>{v.nps}</b> <span className="text-xs text-slate-400">NPS · {v.average}</span></span>
    if (v.average != null) return <b>{v.average}</b>
    if (v.rows) return <span className="text-xs">{v.rows.map((r) => `${r.label}: ${r.average ?? '—'}`).join(' · ')}</span>
    const top = [...(v.options ?? [])].sort((a, b) => (b.percent ?? 0) - (a.percent ?? 0))[0]
    return top ? <span><b>{top.percent}%</b> <span className="text-xs text-slate-400">{top.label}</span></span> : '—'
  }
  return (
    <div className="space-y-4">
      <Card>
        <CardTitle subtitle={t('ss.results.compareHint')}><span className="inline-flex items-center gap-2"><GitCompare className="size-5 text-gold-600" />{t('ss.results.tabs.compare')}</span></CardTitle>
        {!others.length ? <p className="text-sm text-slate-500">{t('ss.results.nothingToCompare')}</p> : (
          <div className="flex flex-wrap gap-1.5">{others.map((s) => { const on = picked.includes(s.id); return <button key={s.id} type="button" disabled={!on && picked.length >= 5} onClick={() => setPicked(on ? picked.filter((x) => x !== s.id) : [...picked, s.id])} className={clsx('rounded-full border px-3 py-1 text-xs transition', on ? 'border-gold-500 bg-gold-50 text-navy-900' : 'border-slate-200 text-slate-600 hover:border-navy-300')}>{en && s.title_en ? s.title_en : s.title_ar} <span className="text-slate-400">({s.submissions})</span></button> })}</div>
        )}
      </Card>
      {picked.length > 0 && (cmp.isLoading || !cmp.data ? <Spinner /> : (
        <Card padded={false}>
          <table className="w-full text-sm"><thead className="bg-slate-50 text-xs text-slate-500"><tr><th className="px-4 py-2 text-start">{t('ss.results.question')}</th>{cmp.data.data.surveys.map((s) => <th key={s.id} className="px-4 py-2 text-start"><div className="font-semibold text-navy-900">{s.title}</div><div className="font-normal">{s.submissions} · {s.response_rate != null ? `${s.response_rate}%` : '—'}</div></th>)}</tr></thead>
            <tbody className="divide-y divide-slate-100">
              {!cmp.data.data.questions.length && <tr><td colSpan={ids.length + 1} className="px-4 py-6 text-center text-sm text-slate-500">{t('ss.results.noShared')}</td></tr>}
              {cmp.data.data.questions.map((q) => <tr key={q.id}><td className="px-4 py-2"><div className="text-xs text-slate-400">{t(`ss.types.${q.type}`, { defaultValue: q.type })}</div>{q.title}</td>{cmp.data!.data.surveys.map((s) => <td key={s.id} className="px-4 py-2">{cell(q, s.id)}</td>)}</tr>)}
            </tbody></table>
        </Card>
      ))}
    </div>
  )
}
