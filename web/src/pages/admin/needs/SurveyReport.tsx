import clsx from 'clsx'
import { BellRing, BookOpen, CheckCircle2, Clock, Download, Filter, Lock, LockOpen, MessageSquareQuote, Send, Sparkles, Target, Users, Wand2 } from 'lucide-react'
import { useMemo, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import { SurveyDialog } from '@/components/surveys/SurveyForm'
import { Button, Card, CardTitle, StatusBadge } from '@/components/ui'
import { Skeleton } from '@/components/ui/Skeleton'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { PRIORITY_TONE, type Survey } from '@/lib/surveys'

type Breakdown = { key: string; label: string; index: number; in_need: number; respondents: number }
type Need = {
  key: string; skill_id: string | null; skill_name: string; category?: string | null; index: number; priority: string; in_need: number; respondents: number; share: number
  by_school: Breakdown[]; by_job_title: Breakdown[]; by_experience: Breakdown[]; generated: boolean
  programs: { id: string; code: string; title: string; status: string; start_date?: string | null }[]
}
type Option = { id: string; label: string; count?: number; percent?: number; score?: number }
type QuestionStat = {
  id: string; type: string; title: string; answered: number; options?: Option[]; average?: number | null; nps?: number | null
  distribution?: { label: string; value: number }[]; rows?: { id: string; label: string; average: number | null }[]; scale?: { min: number; max: number }; mode?: string; samples?: string[]
}
type Report = {
  summary: { recipients: number; responses: number; filtered_responses: number; response_rate: number; avg_duration_seconds: number; last_response_at?: string; timeline: { date: string; value: number }[] }
  questions: QuestionStat[]
  needs: Need[]
  segments: { schools: { id: string; name: string }[]; job_titles: { id: string; name: string }[]; specializations: string[]; nationalities: string[]; experience_bands: string[] }
}

export default function SurveyReport({ survey, onChanged }: { survey: Survey; onChanged: () => void }) {
  const { t } = useTranslation()
  const [filters, setFilters] = useState<Record<string, string>>({})
  const params = Object.fromEntries(Object.entries(filters).filter(([, v]) => v))
  const report = useGet<{ data: Report }>(`/admin/needs-surveys/${survey.id}/report`, params, { refetchInterval: survey.status === 'published' ? 30_000 : false })
  const [selected, setSelected] = useState<string[]>([])
  const [detail, setDetail] = useState<Need | null>(null)
  const [generating, setGenerating] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const r = report.data?.data

  const action = async (name: 'remind' | 'close' | 'reopen') => {
    setBusy(name)
    try {
      const { data } = await api.post(`/admin/needs-surveys/${survey.id}/${name}`)
      if (name === 'remind') setNotice(`${t('surveys.actions.remind')} ✓ ${fmt.number(data.reminded)}`)
      onChanged()
    } catch (e) { setNotice(errorMessage(e)) } finally { setBusy(null) }
  }
  const exportCsv = async () => {
    setBusy('export')
    try {
      const { data } = await api.get(`/admin/needs-surveys/${survey.id}/export`, { responseType: 'blob' })
      const url = URL.createObjectURL(data as Blob)
      Object.assign(document.createElement('a'), { href: url, download: `${survey.title}.csv` }).click()
      setTimeout(() => URL.revokeObjectURL(url), 1000)
    } finally { setBusy(null) }
  }

  if (!r) return <div className="space-y-4"><Skeleton className="h-28 w-full rounded-3xl" /><Skeleton className="h-96 w-full rounded-3xl" /></div>

  const critical = r.needs.filter((n) => n.priority === 'critical' || n.priority === 'high')
  const toggle = (key: string) => setSelected((s) => (s.includes(key) ? s.filter((k) => k !== key) : [...s, key]))

  return (
    <div className="space-y-6">
      <div className="flex flex-wrap items-center gap-2">
        {survey.status === 'published' && <Button size="sm" variant="outline" loading={busy === 'remind'} icon={<BellRing className="size-4" />} onClick={() => action('remind')}>{t('surveys.actions.remind')}</Button>}
        {survey.status === 'published' ? <Button size="sm" variant="outline" loading={busy === 'close'} icon={<Lock className="size-4" />} onClick={() => action('close')}>{t('surveys.actions.close')}</Button>
          : survey.status === 'closed' && <Button size="sm" variant="outline" loading={busy === 'reopen'} icon={<LockOpen className="size-4" />} onClick={() => action('reopen')}>{t('surveys.actions.reopen')}</Button>}
        <Button size="sm" variant="outline" loading={busy === 'export'} icon={<Download className="size-4" />} onClick={exportCsv}>{t('surveys.actions.export')}</Button>
        {notice && <span className="text-sm font-semibold text-emerald-700">{notice}</span>}
      </div>

      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <Kpi icon={<Users className="size-5" />} label={t('surveys.stats.recipients')} value={fmt.number(r.summary.recipients)} />
        <Kpi icon={<CheckCircle2 className="size-5" />} label={t('surveys.stats.responses')} value={fmt.number(r.summary.responses)} hint={<Sparkline data={r.summary.timeline} />} />
        <Kpi dark icon={<Target className="size-5" />} label={t('surveys.stats.rate')} value={`${fmt.number(r.summary.response_rate)}%`} hint={<div className="mt-2 h-1.5 rounded-full bg-white/15"><div className="h-full rounded-full bg-gradient-to-l from-gold-300 to-gold-500" style={{ width: `${r.summary.response_rate}%` }} /></div>} />
        <Kpi icon={<Clock className="size-5" />} label={t('surveys.stats.avgTime')} value={`${fmt.number(Math.max(1, Math.round(r.summary.avg_duration_seconds / 60)))} ${t('surveys.stats.minutes')}`} />
      </div>

      <Card>
        <div className="mb-4 flex items-center gap-2 text-sm font-bold text-navy-900"><Filter className="size-4 text-gold-600" />{t('surveys.report.filters')}{r.summary.filtered_responses !== r.summary.responses && <span className="rounded-full bg-gold-100 px-2 py-0.5 text-xs text-gold-700">{fmt.number(r.summary.filtered_responses)} / {fmt.number(r.summary.responses)}</span>}</div>
        <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-5">
          <FilterSelect value={filters.school_id} onChange={(v) => setFilters({ ...filters, school_id: v })} all={t('surveys.report.allSchools')} options={r.segments.schools.map((s) => ({ value: s.id, label: s.name }))} />
          <FilterSelect value={filters.job_title_id} onChange={(v) => setFilters({ ...filters, job_title_id: v })} all={t('surveys.report.allJobs')} options={r.segments.job_titles.map((s) => ({ value: s.id, label: s.name }))} />
          <FilterSelect value={filters.experience_band} onChange={(v) => setFilters({ ...filters, experience_band: v })} all={t('surveys.report.allBands')} options={r.segments.experience_bands.map((s) => ({ value: s, label: `${s} ${t('surveys.audience.years')}` }))} />
          <FilterSelect value={filters.specialization} onChange={(v) => setFilters({ ...filters, specialization: v })} all={t('surveys.report.allSpecs')} options={r.segments.specializations.map((s) => ({ value: s, label: s }))} />
          <FilterSelect value={filters.nationality} onChange={(v) => setFilters({ ...filters, nationality: v })} all={t('surveys.report.allNationalities')} options={r.segments.nationalities.map((s) => ({ value: s, label: t(`surveys.audience.nationality.${s}`, { defaultValue: s }) }))} />
        </div>
      </Card>

      <Card>
        <CardTitle subtitle={t('surveys.report.needsHint')} action={r.needs.length > 0 && (
          <div className="flex flex-wrap justify-end gap-2">
            {critical.length > 0 && <Button size="sm" variant="ghost" onClick={() => setSelected(critical.map((n) => n.key))}>{t('surveys.report.selectCritical')}</Button>}
            <Button size="sm" variant="gold" icon={<Wand2 className="size-4" />} disabled={!selected.length} onClick={() => setGenerating(true)}>{t('surveys.report.generate')}{selected.length ? ` · ${selected.length}` : ''}</Button>
          </div>
        )}>
          <span className="inline-flex items-center gap-2"><Sparkles className="size-5 text-gold-600" />{t('surveys.report.needsTitle')}</span>
        </CardTitle>
        {!r.needs.length ? <p className="rounded-2xl bg-ivory p-6 text-center text-sm text-slate-500">{t('surveys.report.noNeeds')}</p> : (
          <ul className="space-y-2">
            {r.needs.map((n, i) => (
              <li key={n.key} className={clsx('group flex items-center gap-3 rounded-2xl border p-3 transition sm:gap-4 sm:p-4', selected.includes(n.key) ? 'border-gold-400 bg-gold-100/30' : 'border-navy-100 bg-white hover:border-gold-300')}>
                <input type="checkbox" className="size-4 shrink-0 accent-gold-600" checked={selected.includes(n.key)} onChange={() => toggle(n.key)} aria-label={n.skill_name} />
                <span className="hidden w-6 text-center font-display text-sm font-bold text-slate-300 sm:block">{i + 1}</span>
                <button onClick={() => setDetail(n)} className="min-w-0 flex-1 text-start">
                  <div className="flex flex-wrap items-center gap-2">
                    <span className="font-bold text-navy-900">{n.skill_name}</span>
                    <StatusBadge status={n.priority} label={t(`priority.${n.priority}`)} />
                    {n.generated && <span className="rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">✓ {t('surveys.report.generated')}</span>}
                  </div>
                  <div className="mt-2 flex items-center gap-3">
                    <div className="h-2.5 flex-1 overflow-hidden rounded-full bg-navy-100/60"><div className={clsx('h-full rounded-full bg-gradient-to-l transition-all duration-700', PRIORITY_TONE[n.priority])} style={{ width: `${n.index}%` }} /></div>
                    <span className="w-10 text-end font-display text-lg font-bold text-navy-900">{n.index}</span>
                  </div>
                  <div className="mt-1 text-xs text-slate-500">{fmt.number(n.in_need)} {t('surveys.report.inNeed')} {t('surveys.report.of')} {fmt.number(n.respondents)} {t('surveys.report.respondents')} · {n.share}%{n.programs.length ? ` · ${n.programs.length} ${t('surveys.report.programs')}` : ''}</div>
                </button>
              </li>
            ))}
          </ul>
        )}
      </Card>

      <div>
        <h3 className="mb-4 text-lg font-bold text-navy-900">{t('surveys.report.questionsTitle')}</h3>
        <div className="grid gap-5 xl:grid-cols-2">
          {r.questions.map((q, i) => <QuestionResult key={q.id} q={q} n={i + 1} />)}
        </div>
      </div>

      <NeedDetail need={detail} onClose={() => setDetail(null)} />
      <GenerateDialog open={generating} survey={survey} needs={r.needs.filter((n) => selected.includes(n.key))} onClose={() => setGenerating(false)} onDone={() => { setSelected([]); report.refetch(); onChanged() }} />
    </div>
  )
}

function Kpi({ icon, label, value, hint, dark }: { icon: ReactNode; label: string; value: ReactNode; hint?: ReactNode; dark?: boolean }) {
  return (
    <div className={clsx('rounded-2xl p-5', dark ? 'bg-navy-900 text-white shadow-glass' : 'card')}>
      <div className={clsx('flex items-center gap-2 text-sm', dark ? 'text-white/70' : 'text-slate-500')}><span className={dark ? 'text-gold-300' : 'text-gold-600'}>{icon}</span>{label}</div>
      <div className={clsx('mt-2 font-display text-3xl font-bold', dark ? 'text-gold-300' : 'text-navy-900')}>{value}</div>
      {hint}
    </div>
  )
}

function Sparkline({ data }: { data: { date: string; value: number }[] }) {
  if (data.length < 2) return null
  const max = Math.max(...data.map((d) => d.value))
  return <div className="mt-2 flex h-8 items-end gap-0.5" dir="ltr">{data.slice(-30).map((d) => <span key={d.date} title={`${d.date}: ${d.value}`} className="flex-1 rounded-t bg-gold-400/70" style={{ height: `${Math.max(8, (d.value / max) * 100)}%` }} />)}</div>
}

function FilterSelect({ value, onChange, all, options }: { value?: string; onChange: (v: string) => void; all: string; options: { value: string; label: string }[] }) {
  return <select className={clsx('input py-2 text-sm', value && 'border-gold-400 bg-gold-100/30 font-semibold')} value={value ?? ''} onChange={(e) => onChange(e.target.value)}><option value="">{all}</option>{options.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}</select>
}

function HBar({ label, value, suffix, max = 100, tone = 'from-gold-400 to-gold-600' }: { label: string; value: number; suffix?: string; max?: number; tone?: string }) {
  return (
    <div className="text-sm">
      <div className="mb-1 flex justify-between gap-3"><span className="text-slate-600">{label}</span><span className="shrink-0 font-bold text-navy-900">{suffix ?? fmt.number(value)}</span></div>
      <div className="h-2 overflow-hidden rounded-full bg-navy-100/60"><div className={clsx('h-full rounded-full bg-gradient-to-l transition-all duration-700', tone)} style={{ width: `${Math.min(100, (value / Math.max(1, max)) * 100)}%` }} /></div>
    </div>
  )
}

function QuestionResult({ q, n }: { q: QuestionStat; n: number }) {
  const { t } = useTranslation()
  const maxDist = Math.max(1, ...(q.distribution ?? []).map((d) => d.value))
  return (
    <Card>
      <div className="mb-4 flex items-start justify-between gap-3">
        <div><div className="text-xs font-semibold text-gold-700">{n}. {t(`surveys.types.${q.type}`)}</div><h4 className="mt-1 font-bold leading-7 text-navy-900">{q.title}</h4></div>
        <span className="shrink-0 rounded-full bg-navy-100/60 px-2.5 py-1 text-xs font-semibold text-navy-800">{t('surveys.report.answered', { n: q.answered })}</span>
      </div>
      {!q.answered ? <p className="text-sm text-slate-400">{t('surveys.report.noData')}</p> : (
        <div className="space-y-3">
          {q.options && q.type !== 'ranking' && q.options.map((o) => <HBar key={o.id} label={o.label} value={o.percent ?? 0} suffix={`${o.percent}% · ${fmt.number(o.count ?? 0)}`} />)}
          {q.type === 'ranking' && q.options?.map((o, i) => <HBar key={o.id} label={`${i + 1}. ${o.label}`} value={o.score ?? 0} max={q.options!.length} suffix={`${o.score}`} tone="from-navy-500 to-navy-800" />)}
          {q.rows && q.rows.map((row) => <HBar key={row.id} label={row.label} value={row.average ?? 0} max={q.scale?.max ?? 5} suffix={row.average?.toFixed(2) ?? '—'} tone={q.mode === 'need' ? 'from-rose-400 to-rose-600' : 'from-emerald-400 to-emerald-600'} />)}
          {q.distribution && (
            <div>
              <div className="mb-3 flex items-baseline gap-4">
                {q.average != null && <span className="text-sm text-slate-500">{t('surveys.report.average')} <b className="font-display text-2xl text-navy-900">{q.average}</b></span>}
                {q.nps != null && <span className="text-sm text-slate-500">{t('surveys.report.nps')} <b className={clsx('font-display text-2xl', q.nps >= 0 ? 'text-emerald-600' : 'text-red-600')}>{q.nps > 0 ? '+' : ''}{q.nps}</b></span>}
              </div>
              <div className="flex h-24 items-end gap-1.5" dir="ltr">
                {q.distribution.map((d) => (
                  <div key={d.label} className="flex flex-1 flex-col items-center gap-1">
                    <span className="text-[10px] font-semibold text-slate-400">{d.value || ''}</span>
                    <div className="w-full rounded-t-md bg-gradient-to-t from-gold-600 to-gold-400" style={{ height: `${Math.max(3, (d.value / maxDist) * 64)}px` }} />
                    <span className="text-[11px] text-slate-500">{d.label}</span>
                  </div>
                ))}
              </div>
            </div>
          )}
          {q.type === 'number' && q.average != null && <div className="text-sm text-slate-500">{t('surveys.report.average')} <b className="font-display text-2xl text-navy-900">{q.average}</b></div>}
          {q.samples && (
            <div>
              <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold text-slate-400"><MessageSquareQuote className="size-4" />{t('surveys.report.latest')}</div>
              <ul className="max-h-56 space-y-2 overflow-y-auto pe-1">{q.samples.map((s, i) => <li key={i} className="rounded-xl bg-ivory px-3 py-2 text-sm leading-6 text-navy-900">{s}</li>)}</ul>
            </div>
          )}
        </div>
      )}
    </Card>
  )
}

function NeedDetail({ need, onClose }: { need: Need | null; onClose: () => void }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  return (
    <SurveyDialog open={!!need} onClose={onClose} size="lg" label={need?.skill_name}>
      {need && (
        <div className="overflow-y-auto">
          <div className="relative overflow-hidden bg-gradient-to-br from-navy-950 to-navy-800 p-6 text-white sm:p-8">
            <div className="pattern-bg absolute inset-0 opacity-20" />
            <div className="relative flex items-end justify-between gap-4">
              <div>
                <StatusBadge status={need.priority} label={t(`priority.${need.priority}`)} />
                <h2 className="mt-3 font-display text-2xl font-bold">{need.skill_name}</h2>
                <p className="mt-1 text-sm text-white/60">{fmt.number(need.in_need)} {t('surveys.report.inNeed')} {t('surveys.report.of')} {fmt.number(need.respondents)} {t('surveys.report.respondents')}</p>
              </div>
              <div className="text-center"><div className="font-display text-5xl font-bold text-gold-300">{need.index}</div><div className="text-xs text-white/60">{t('surveys.report.index')}</div></div>
            </div>
          </div>
          <div className="grid gap-6 p-6 sm:p-8 md:grid-cols-2">
            <Breakdowns title={t('surveys.report.bySchool')} rows={need.by_school} />
            <div className="space-y-6">
              <Breakdowns title={t('surveys.report.byJob')} rows={need.by_job_title} />
              <Breakdowns title={t('surveys.report.byExperience')} rows={need.by_experience.map((b) => ({ ...b, label: `${b.label} ${t('surveys.audience.years')}` }))} />
            </div>
            <div className="md:col-span-2">
              <div className="mb-3 flex items-center gap-2 text-sm font-bold text-navy-900"><BookOpen className="size-4 text-gold-600" />{t('surveys.report.programs')}</div>
              {need.programs.length ? (
                <div className="grid gap-2 sm:grid-cols-2">
                  {need.programs.map((p) => (
                    <button key={p.id} onClick={() => navigate(`/admin/programs/${p.id}`)} className="flex items-center justify-between gap-2 rounded-xl border border-navy-100 bg-white p-3 text-start text-sm transition hover:border-gold-400">
                      <span><span className="block text-xs text-gold-700" dir="ltr">{p.code}</span><span className="font-semibold text-navy-900">{p.title}</span></span>
                      <StatusBadge status={p.status} />
                    </button>
                  ))}
                </div>
              ) : <p className="rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{t('surveys.report.noPrograms')}</p>}
            </div>
          </div>
        </div>
      )}
    </SurveyDialog>
  )
}

function Breakdowns({ title, rows }: { title: string; rows: Breakdown[] }) {
  return (
    <div>
      <div className="mb-3 text-sm font-bold text-navy-900">{title}</div>
      <div className="max-h-72 space-y-2.5 overflow-y-auto pe-1">
        {rows.map((b) => <HBar key={b.key} label={`${b.label} (${b.in_need}/${b.respondents})`} value={b.index} suffix={String(b.index)} tone={PRIORITY_TONE[b.index >= 70 ? 'critical' : b.index >= 50 ? 'high' : b.index >= 30 ? 'medium' : 'low']} />)}
      </div>
    </div>
  )
}

function GenerateDialog({ open, survey, needs, onClose, onDone }: { open: boolean; survey: Survey; needs: Need[]; onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [split, setSplit] = useState<'overall' | 'school'>('overall')
  const [minIndex, setMinIndex] = useState(30)
  const [notes, setNotes] = useState('')
  const [overrides, setOverrides] = useState<Record<string, { priority?: string; program_id?: string }>>({})
  const [busy, setBusy] = useState(false)
  const [done, setDone] = useState<number | null>(null)
  const [error, setError] = useState<string | null>(null)
  const items = useMemo(() => needs.map((n) => ({ key: n.key, priority: overrides[n.key]?.priority || undefined, program_id: overrides[n.key]?.program_id || undefined })), [needs, overrides])

  const submit = async () => {
    setBusy(true); setError(null)
    try {
      const { data } = await api.post(`/admin/needs-surveys/${survey.id}/generate-needs`, { items, split, min_index: minIndex, notes: notes || null })
      setDone(data.created)
      onDone()
    } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  const close = () => { setDone(null); setOverrides({}); onClose() }

  return (
    <SurveyDialog open={open} onClose={close} size="lg" label={t('surveys.generateModal.title')}>
      <div className="overflow-y-auto p-6 sm:p-8">
        {done !== null ? (
          <div className="py-8 text-center">
            <div className="mx-auto grid size-16 place-items-center rounded-full bg-emerald-500 text-white shadow-lg"><CheckCircle2 className="size-8" /></div>
            <h2 className="mt-5 font-display text-2xl font-bold text-navy-900">{t('surveys.generateModal.done', { n: done })}</h2>
            <div className="mt-6 flex justify-center gap-2"><Button variant="outline" onClick={close}>{t('common.close')}</Button><Button variant="gold" icon={<Send className="size-4" />} onClick={() => navigate('/admin/needs?tab=requests')}>{t('surveys.generateModal.viewRequests')}</Button></div>
          </div>
        ) : (
          <>
            <div className="flex items-center gap-3"><div className="grid size-12 place-items-center rounded-2xl bg-gradient-to-br from-gold-400 to-gold-600 text-white shadow-lg"><Wand2 className="size-6" /></div><h2 className="font-display text-2xl font-bold text-navy-900">{t('surveys.generateModal.title')}</h2></div>
            <p className="mt-3 text-sm leading-6 text-slate-500">{t('surveys.generateModal.text')}</p>
            <div className="mt-6">
              <span className="label">{t('surveys.generateModal.split')}</span>
              <div className="grid gap-2 sm:grid-cols-2">
                {(['overall', 'school'] as const).map((s) => <button type="button" key={s} onClick={() => setSplit(s)} className={clsx('rounded-xl border px-4 py-3 text-start text-sm transition', split === s ? 'border-gold-500 bg-gold-100/50 font-semibold text-navy-900' : 'border-navy-100 text-slate-500')}>{t(`surveys.generateModal.${s === 'overall' ? 'overall' : 'perSchool'}`)}</button>)}
              </div>
              {split === 'school' && (
                <label className="mt-3 flex items-center gap-3 text-sm text-slate-600">{t('surveys.generateModal.minIndex')}
                  <input type="range" min={0} max={90} step={5} value={minIndex} onChange={(e) => setMinIndex(Number(e.target.value))} className="flex-1 accent-gold-600" /><b className="w-8 text-navy-900">{minIndex}</b>
                </label>
              )}
            </div>
            <div className="mt-6 space-y-2">
              {needs.map((n) => (
                <div key={n.key} className="grid items-center gap-2 rounded-xl border border-navy-100 bg-white p-3 sm:grid-cols-[1fr_140px_220px]">
                  <div><div className="font-semibold text-navy-900">{n.skill_name}</div><div className="text-xs text-slate-500">{t('surveys.report.index')} {n.index} · {fmt.number(n.in_need)} {t('surveys.report.inNeed')}</div></div>
                  <select className="input py-1.5 text-xs" value={overrides[n.key]?.priority ?? ''} onChange={(e) => setOverrides({ ...overrides, [n.key]: { ...overrides[n.key], priority: e.target.value } })}>
                    <option value="">{t(`priority.${n.priority}`)} ✦</option>
                    {['critical', 'high', 'medium', 'low'].map((p) => <option key={p} value={p}>{t(`priority.${p}`)}</option>)}
                  </select>
                  <select className="input py-1.5 text-xs" value={overrides[n.key]?.program_id ?? ''} onChange={(e) => setOverrides({ ...overrides, [n.key]: { ...overrides[n.key], program_id: e.target.value } })}>
                    <option value="">{t('surveys.generateModal.program')}: {t('surveys.generateModal.none')}</option>
                    {n.programs.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.title}</option>)}
                  </select>
                </div>
              ))}
            </div>
            <label className="mt-5 block"><span className="label">{t('surveys.generateModal.notes')}</span><textarea className="input" rows={2} value={notes} onChange={(e) => setNotes(e.target.value)} /></label>
            {error && <p className="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}
            <div className="mt-6 flex justify-end gap-2"><Button variant="outline" onClick={close}>{t('common.cancel')}</Button><Button variant="gold" loading={busy} icon={<Wand2 className="size-4" />} onClick={submit}>{t('surveys.generateModal.confirm')} · {needs.length}</Button></div>
          </>
        )}
      </div>
    </SurveyDialog>
  )
}
