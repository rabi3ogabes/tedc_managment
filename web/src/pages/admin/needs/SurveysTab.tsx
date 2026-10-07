import clsx from 'clsx'
import {
  BarChart3, CalendarClock, Copy, Cpu, Crown, Download, Eye, FilePlus2, FileSpreadsheet, FileText, GraduationCap, HeartHandshake, LayoutTemplate,
  Loader2, PenLine, Plus, Search, Sparkles, Trash2, UploadCloud, Users, Wand2, Zap,
} from 'lucide-react'
import { useMemo, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate } from 'react-router-dom'
import SurveyForm, { SurveyDialog } from '@/components/surveys/SurveyForm'
import { Button, Card, Empty, StatusBadge } from '@/components/ui'
import { ProgramGridSkeleton } from '@/components/ui/Skeleton'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { autoLinkSkills, downloadText, ImportError, importQuestions, importTemplateCsv, newQuestion, type Question, type Skill, type SurveySummary, type Template } from '@/lib/surveys'
import type { LaravelPage } from '@/lib/types'
import { dialogs } from '@/lib/dialogs'

const TEMPLATE_ICONS: Record<string, typeof Crown> = { graduation: GraduationCap, crown: Crown, cpu: Cpu, heart: HeartHandshake, zap: Zap }

export default function SurveysTab() {
  const { t } = useTranslation()
  const navigate = useNavigate()
  const [status, setStatus] = useState<'all' | 'draft' | 'published' | 'closed'>('all')
  const [q, setQ] = useState('')
  const [creating, setCreating] = useState(false)
  const list = useGet<LaravelPage<SurveySummary>>('/admin/needs-surveys', { per_page: 60, status: status === 'all' ? undefined : status, q: q || undefined })

  const duplicate = async (s: SurveySummary) => { const { data } = await api.post(`/admin/needs-surveys/${s.id}/duplicate`); navigate(`/admin/needs/surveys/${data.data.id}`) }
  const remove = async (s: SurveySummary) => { if (await dialogs.confirm(t('surveys.actions.confirmDelete'))) { await api.delete(`/admin/needs-surveys/${s.id}`); list.refetch() } }

  return (
    <div className="space-y-6">
      <div className="relative overflow-hidden rounded-3xl bg-gradient-to-br from-navy-950 via-navy-900 to-navy-800 p-6 text-white shadow-glass sm:p-8">
        <div className="pattern-bg absolute inset-0 opacity-20" />
        <div className="absolute -end-16 -top-16 size-64 rounded-full bg-gold-400/20 blur-3xl" />
        <div className="relative flex flex-col gap-6 lg:flex-row lg:items-center lg:justify-between">
          <div className="max-w-2xl">
            <div className="mb-3 inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-gold-300 backdrop-blur"><Sparkles className="size-3.5" />{t('surveys.tab')}</div>
            <h2 className="font-display text-2xl font-bold sm:text-3xl">{t('surveys.heroTitle')}</h2>
            <p className="mt-3 text-sm leading-7 text-white/70">{t('surveys.heroText')}</p>
          </div>
          <Button variant="gold" size="lg" icon={<Plus className="size-5" />} onClick={() => setCreating(true)}>{t('surveys.create')}</Button>
        </div>
      </div>

      <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div className="flex gap-1 overflow-x-auto rounded-2xl border border-navy-100 bg-white p-1">
          {(['all', 'published', 'draft', 'closed'] as const).map((s) => (
            <button key={s} onClick={() => setStatus(s)} className={clsx('whitespace-nowrap rounded-xl px-4 py-1.5 text-sm font-semibold transition', status === s ? 'bg-navy-900 text-white shadow' : 'text-slate-500 hover:text-navy-900')}>{t(`surveys.status.${s}`)}</button>
          ))}
        </div>
        <label className="relative sm:w-72">
          <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
          <input className="input ps-9" placeholder={t('surveys.search')} value={q} onChange={(e) => setQ(e.target.value)} />
        </label>
      </div>

      {list.isLoading ? <ProgramGridSkeleton count={3} /> : !list.data?.data.length ? (
        <Card><Empty text={t('surveys.empty')} icon={<LayoutTemplate className="size-6" />} /></Card>
      ) : (
        <div className="grid gap-5 md:grid-cols-2 2xl:grid-cols-3">
          {list.data.data.map((s) => <SurveyCard key={s.id} survey={s} onOpen={() => navigate(`/admin/needs/surveys/${s.id}`)} onResults={() => navigate(`/admin/needs/surveys/${s.id}?view=results`)} onDuplicate={() => duplicate(s)} onDelete={() => remove(s)} />)}
        </div>
      )}

      <CreateSurveyDialog open={creating} onClose={() => setCreating(false)} onCreated={(id) => navigate(`/admin/needs/surveys/${id}`)} />
    </div>
  )
}

function SurveyCard({ survey: s, onOpen, onResults, onDuplicate, onDelete }: { survey: SurveySummary; onOpen: () => void; onResults: () => void; onDuplicate: () => void; onDelete: () => void }) {
  const { t } = useTranslation()
  const accent = s.accent ?? '#8A1538'
  const rate = s.recipients_count ? Math.round((s.responses_count / s.recipients_count) * 100) : 0
  const SourceIcon = s.source === 'import' ? UploadCloud : s.source === 'template' ? LayoutTemplate : PenLine
  return (
    <div className="group card flex flex-col overflow-hidden transition duration-300 hover:-translate-y-1 hover:shadow-glass">
      <button onClick={onOpen} className="relative h-28 overflow-hidden text-start" style={{ background: `linear-gradient(135deg, ${accent}, color-mix(in srgb, ${accent} 50%, #12040a))` }}>
        <div className="pattern-bg absolute inset-0 opacity-30" />
        <svg className="absolute -bottom-8 -end-6 size-36 text-white/10 transition duration-500 group-hover:rotate-12" viewBox="0 0 100 100" aria-hidden><path d="M50 2 L61 39 L98 50 L61 61 L50 98 L39 61 L2 50 L39 39 Z" fill="currentColor" /></svg>
        <div className="absolute inset-x-5 top-4 flex items-center justify-between">
          <span className="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-1 text-[11px] font-semibold text-white backdrop-blur"><SourceIcon className="size-3.5" />{t(`surveys.source.${s.source}`)}</span>
          <StatusBadge status={s.status} label={t(`surveys.status.${s.status}`)} />
        </div>
        <div className="absolute inset-x-5 bottom-3 line-clamp-1 font-display text-lg font-bold text-white">{s.title}</div>
      </button>
      <div className="flex flex-1 flex-col p-5">
        <p className="line-clamp-1 text-xs text-slate-500"><Users className="me-1 inline size-3.5" />{s.audience_summary}</p>
        <div className="mt-4 grid grid-cols-3 gap-2 text-center">
          <Metric value={fmt.number(s.questions_count)} label={t('surveys.stats.questions')} />
          <Metric value={fmt.number(s.recipients_count)} label={t('surveys.stats.recipients')} />
          <Metric value={fmt.number(s.responses_count)} label={t('surveys.stats.responses')} />
        </div>
        {s.status !== 'draft' && (
          <div className="mt-4">
            <div className="mb-1.5 flex justify-between text-xs"><span className="text-slate-500">{t('surveys.stats.rate')}</span><span className="font-bold text-navy-900">{rate}%</span></div>
            <div className="h-2 overflow-hidden rounded-full bg-navy-100/70"><div className="h-full rounded-full transition-all duration-700" style={{ width: `${rate}%`, background: accent }} /></div>
          </div>
        )}
        <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-400">
          {s.closes_at && <span><CalendarClock className="me-1 inline size-3.5" />{t('surveys.stats.closes')} {fmt.date(s.closes_at)}</span>}
          {s.needs_count > 0 && <span className="font-semibold text-emerald-600"><Wand2 className="me-1 inline size-3.5" />{fmt.number(s.needs_count)} {t('surveys.stats.needs')}</span>}
        </div>
        <div className="mt-auto flex items-center gap-2 pt-5">
          <Button size="sm" variant="primary" onClick={onOpen} icon={<PenLine className="size-3.5" />}>{t('surveys.actions.open')}</Button>
          {s.status !== 'draft' && <Button size="sm" variant="outline" onClick={onResults} icon={<BarChart3 className="size-3.5" />}>{t('surveys.actions.results')}</Button>}
          <div className="ms-auto flex">
            <IconButton label={t('surveys.actions.duplicate')} onClick={onDuplicate}><Copy className="size-4" /></IconButton>
            <IconButton label={t('surveys.actions.delete')} onClick={onDelete} danger><Trash2 className="size-4" /></IconButton>
          </div>
        </div>
      </div>
    </div>
  )
}

const Metric = ({ value, label }: { value: ReactNode; label: string }) => (
  <div className="rounded-xl bg-ivory px-2 py-2.5"><div className="font-display text-lg font-bold text-navy-900">{value}</div><div className="text-[11px] text-slate-500">{label}</div></div>
)

export const IconButton = ({ label, onClick, children, danger }: { label: string; onClick: () => void; children: ReactNode; danger?: boolean }) => (
  <button title={label} aria-label={label} onClick={onClick} className={clsx('rounded-lg p-2 text-slate-400 transition', danger ? 'hover:bg-red-50 hover:text-red-600' : 'hover:bg-navy-100/60 hover:text-navy-900')}>{children}</button>
)

type Mode = 'choose' | 'template' | 'import'

function CreateSurveyDialog({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (id: string) => void }) {
  const { t } = useTranslation()
  const [mode, setMode] = useState<Mode>('choose')
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [preview, setPreview] = useState<Template | null>(null)
  const templates = useGet<{ data: Template[] }>(open ? '/admin/needs-surveys/templates' : null, undefined, { staleTime: 10 * 60_000 })
  const lookups = useGet<{ data: { skills: Skill[] } }>(open ? '/admin/lookups' : null, undefined, { staleTime: 10 * 60_000 })

  const close = () => { setMode('choose'); setError(null); onClose() }
  const create = async (key: string, body: Record<string, unknown>) => {
    setBusy(key); setError(null)
    try {
      const { data } = await api.post('/admin/needs-surveys', body)
      close()
      onCreated(data.data.id)
    } catch (e) { setError(errorMessage(e)) } finally { setBusy(null) }
  }

  return (
    <>
      <SurveyDialog open={open} onClose={close} size="xl" label={t('surveys.createModal.title')}>
        <div className="overflow-y-auto p-6 sm:p-10">
          <h2 className="font-display text-2xl font-bold text-navy-900">{t('surveys.createModal.title')}</h2>
          {mode !== 'choose' && <button onClick={() => setMode('choose')} className="mt-2 text-sm font-semibold text-gold-700 hover:underline">← {t('surveys.createModal.back')}</button>}
          {error && <p className="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}

          {mode === 'choose' && (
            <div className="mt-8 grid gap-5 md:grid-cols-3">
              <WayCard icon={<LayoutTemplate className="size-7" />} title={t('surveys.createModal.template')} text={t('surveys.createModal.templateText')} onClick={() => setMode('template')} tone="from-[#8A1538] to-[#5a0e24]" />
              <WayCard icon={<FileSpreadsheet className="size-7" />} title={t('surveys.createModal.import')} text={t('surveys.createModal.importText')} onClick={() => setMode('import')} tone="from-[#0e7490] to-[#0b2a3a]" />
              <WayCard
                icon={busy === 'blank' ? <Loader2 className="size-7 animate-spin" /> : <FilePlus2 className="size-7" />}
                title={t('surveys.createModal.builder')}
                text={t('surveys.createModal.builderText')}
                tone="from-[#a29475] to-[#6b5f45]"
                onClick={() => create('blank', { title: t('surveys.builder.titlePlaceholder'), source: 'builder', questions: [newQuestion('matrix', t), newQuestion('long_text', t)] })}
              />
            </div>
          )}

          {mode === 'template' && (
            <div className="mt-8 grid gap-5 md:grid-cols-2 xl:grid-cols-3">
              {templates.isLoading && <Loader2 className="size-6 animate-spin text-gold-600" />}
              {templates.data?.data.map((tpl) => {
                const Icon = TEMPLATE_ICONS[tpl.icon] ?? LayoutTemplate
                return (
                  <div key={tpl.key} className="card flex flex-col overflow-hidden">
                    <div className="relative h-24 p-5 text-white" style={{ background: `linear-gradient(135deg, ${tpl.accent}, color-mix(in srgb, ${tpl.accent} 45%, #12040a))` }}>
                      <div className="pattern-bg absolute inset-0 opacity-30" />
                      <div className="relative grid size-12 place-items-center rounded-2xl bg-white/15 backdrop-blur"><Icon className="size-6" /></div>
                      <span className="absolute end-4 top-4 rounded-full bg-white/15 px-2.5 py-1 text-[11px] font-semibold backdrop-blur">{t('surveys.createModal.questions', { n: tpl.count })}</span>
                    </div>
                    <div className="flex flex-1 flex-col p-5">
                      <h3 className="font-bold text-navy-900">{tpl.title}</h3>
                      <p className="mt-1 flex-1 text-sm leading-6 text-slate-500">{tpl.description}</p>
                      <div className="mt-4 flex gap-2">
                        <Button size="sm" variant="gold" loading={busy === tpl.key} onClick={() => create(tpl.key, { source: 'template', template_key: tpl.key })}>{t('surveys.createModal.useTemplate')}</Button>
                        <Button size="sm" variant="outline" icon={<Eye className="size-3.5" />} onClick={() => setPreview(tpl)}>{t('surveys.actions.preview')}</Button>
                      </div>
                    </div>
                  </div>
                )
              })}
            </div>
          )}

          {mode === 'import' && <Importer skills={lookups.data?.data.skills ?? []} busy={busy === 'import'} onCreate={(title, questions) => create('import', { title, source: 'import', questions })} />}
        </div>
      </SurveyDialog>

      <SurveyDialog open={!!preview} onClose={() => setPreview(null)} label={preview?.title}>
        {preview && <SurveyForm preview survey={{ title: preview.title, description: preview.description, questions: preview.questions, accent: preview.accent }} onClose={() => setPreview(null)} />}
      </SurveyDialog>
    </>
  )
}

function WayCard({ icon, title, text, onClick, tone }: { icon: ReactNode; title: string; text: string; onClick: () => void; tone: string }) {
  return (
    <button onClick={onClick} className="group relative isolate overflow-hidden rounded-3xl border border-navy-100 bg-white p-6 text-start shadow-sm transition duration-300 hover:-translate-y-1 hover:border-gold-400 hover:shadow-glass">
      <div className="pointer-events-none absolute -bottom-12 -end-12 -z-10 size-32 rounded-full bg-gold-100/60 transition duration-500 group-hover:scale-150" />
      <div className={clsx('grid size-14 place-items-center rounded-2xl bg-gradient-to-br text-white shadow-lg transition duration-300 group-hover:scale-110', tone)}>{icon}</div>
      <h3 className="mt-5 text-lg font-bold text-navy-900">{title}</h3>
      <p className="mt-2 text-sm leading-6 text-slate-500">{text}</p>
    </button>
  )
}

function Importer({ skills, busy, onCreate }: { skills: Skill[]; busy: boolean; onCreate: (title: string, questions: Question[]) => void }) {
  const { t } = useTranslation()
  const input = useRef<HTMLInputElement>(null)
  const [drag, setDrag] = useState(false)
  const [reading, setReading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [result, setResult] = useState<{ title: string; questions: Question[]; linked: number } | null>(null)

  const read = async (file?: File) => {
    if (!file) return
    setReading(true); setError(null); setResult(null)
    try {
      const questions = await importQuestions(file)
      const linked = autoLinkSkills(questions, skills)
      setResult({ title: file.name.replace(/\.[^.]+$/, '').replace(/[_-]+/g, ' '), questions: linked.questions, linked: linked.linked })
    } catch (e) {
      setError(e instanceof ImportError ? t(e.message === 'format' ? 'surveys.importer.errorFormat' : 'surveys.importer.errorEmpty') : t('surveys.importer.errorRead'))
    } finally { setReading(false) }
  }

  const counts = useMemo(() => {
    const c: Record<string, number> = {}
    result?.questions.forEach((q) => { c[q.type] = (c[q.type] ?? 0) + 1 })
    return c
  }, [result])

  return (
    <div className="mt-8 grid gap-6 lg:grid-cols-5">
      <div className="lg:col-span-2">
        <button
          onClick={() => input.current?.click()}
          onDragOver={(e) => { e.preventDefault(); setDrag(true) }}
          onDragLeave={() => setDrag(false)}
          onDrop={(e) => { e.preventDefault(); setDrag(false); read(e.dataTransfer.files[0]) }}
          className={clsx('flex w-full flex-col items-center justify-center gap-3 rounded-3xl border-2 border-dashed p-10 text-center transition', drag ? 'border-gold-500 bg-gold-100/40' : 'border-navy-100 bg-white hover:border-gold-400')}
        >
          {reading ? <Loader2 className="size-10 animate-spin text-gold-600" /> : <UploadCloud className="size-10 text-gold-600" />}
          <span className="font-bold text-navy-900">{t('surveys.importer.drop')}</span>
          <span className="text-xs text-slate-400">{t('surveys.importer.formats')}</span>
        </button>
        <input ref={input} type="file" hidden accept=".docx,.xlsx,.csv,.tsv,.txt" onChange={(e) => { read(e.target.files?.[0]); e.target.value = '' }} />
        <p className="mt-4 text-xs leading-6 text-slate-500">{t('surveys.importer.tips')}</p>
        <button onClick={() => downloadText('survey-import-template.csv', importTemplateCsv())} className="mt-3 inline-flex items-center gap-2 text-sm font-semibold text-gold-700 hover:underline"><Download className="size-4" />{t('surveys.importer.downloadTemplate')}</button>
        {error && <p className="mt-4 rounded-xl bg-red-50 p-3 text-sm text-red-700">{error}</p>}
      </div>
      <div className="lg:col-span-3">
        {result ? (
          <div className="card p-5">
            <div className="flex flex-wrap items-center gap-2 text-sm">
              <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-3 py-1 font-semibold text-emerald-700"><FileText className="size-4" />{t('surveys.importer.detected', { n: result.questions.filter((q) => q.type !== 'section').length })}</span>
              {result.linked > 0 && <span className="inline-flex items-center gap-1.5 rounded-full bg-gold-100 px-3 py-1 font-semibold text-gold-700"><Wand2 className="size-4" />{t('surveys.importer.linked', { n: result.linked })}</span>}
            </div>
            <div className="mt-3 flex flex-wrap gap-1.5">{Object.entries(counts).map(([type, n]) => <span key={type} className="rounded-full bg-navy-100/60 px-2.5 py-0.5 text-[11px] font-semibold text-navy-800">{t(`surveys.types.${type}`)} · {n}</span>)}</div>
            <ol className="mt-4 max-h-72 space-y-2 overflow-y-auto pe-1">
              {result.questions.map((q, i) => (
                <li key={q.id} className={clsx('rounded-xl px-3 py-2 text-sm', q.type === 'section' ? 'bg-navy-900 font-bold text-white' : 'bg-ivory text-navy-900')}>
                  {q.type !== 'section' && <span className="me-2 text-xs text-slate-400">{result.questions.slice(0, i + 1).filter((x) => x.type !== 'section').length}.</span>}{q.title}
                  {(q.options?.length || q.rows?.length) ? <span className="ms-2 text-xs text-slate-400">({(q.options ?? q.rows)!.map((o) => o.label).slice(0, 4).join('، ')}{((q.options ?? q.rows)!.length > 4) ? '…' : ''})</span> : null}
                </li>
              ))}
            </ol>
            <label className="mt-4 block"><span className="label">{t('surveys.importer.title')}</span><input className="input" value={result.title} onChange={(e) => setResult({ ...result, title: e.target.value })} /></label>
            <div className="mt-4 flex justify-end"><Button variant="gold" loading={busy} onClick={() => onCreate(result.title || t('surveys.builder.titlePlaceholder'), result.questions)}>{t('surveys.importer.create')}</Button></div>
          </div>
        ) : (
          <div className="grid h-full place-items-center rounded-3xl bg-ivory p-8 text-center text-sm text-slate-400">
            <div><FileSpreadsheet className="mx-auto mb-3 size-10 text-navy-100" />{t('surveys.importer.tips')}</div>
          </div>
        )}
      </div>
    </div>
  )
}
