import clsx from 'clsx'
import { Award, Check, FileText, Layers, ListChecks, PackageOpen, Play, Presentation, ShieldCheck, Sparkles, Video, Zap } from 'lucide-react'
import type { ComponentType } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Card, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api } from '@/lib/api'
import { fmt } from '@/lib/format'

export type ContentPlan = { blueprint: string | null; watch: 'strict' | 'standard' | 'free'; sequential: boolean; completion: number; autoCert: boolean; kitId: string | null; files: string[] }
export const emptyPlan: ContentPlan = { blueprint: 'classic', watch: 'standard', sequential: true, completion: 100, autoCert: true, kitId: null, files: [] }

type Starters = {
  blueprints: { key: string; title: string; text: string; modules: { title: string; lessons: { type: string; title: string }[] }[] }[]
  watch: string[]
  kits: { id: string; code: string; title: string; status: string; linked: boolean; files: { id: string; name: string; kind: string; category: string; size: number; lesson_type: 'video' | 'presentation' | null }[] }[]
}

const ICON: Record<string, ComponentType<{ className?: string }>> = { video: Video, presentation: Presentation, quiz: ListChecks, survey: FileText, article: FileText }

/** Applies the plan to an existing program: course settings, the chosen structure and the imported kit files. */
export async function applyContentPlan(programId: string, plan: ContentPlan): Promise<{ lessons: number }> {
  await api.put(`/admin/programs/${programId}/course/settings`, { has_course: true, course_sequential: plan.sequential, course_completion_percent: plan.completion, course_auto_certificate: plan.autoCert })
  let lessons = 0
  if (plan.kitId && plan.files.length) lessons += (await api.post<{ data: { created: number } }>(`/admin/programs/${programId}/course/import-kit`, { kit_id: plan.kitId, files: plan.files, watch: plan.watch })).data.data.created
  if (plan.blueprint) lessons += (await api.post<{ data: { created: number } }>(`/admin/programs/${programId}/course/blueprint`, { blueprint: plan.blueprint, watch: plan.watch })).data.data.created
  return { lessons }
}

const fmtSize = (b: number) => (b >= 1024 ** 2 ? `${(b / 1024 ** 2).toFixed(1)} MB` : `${Math.max(1, Math.round(b / 1024))} KB`)

/** Step "Content": how the course is structured, how strictly videos are watched, and which training kit to start from. */
export default function ContentStep({ plan, onChange, programId }: { plan: ContentPlan; onChange: (p: ContentPlan) => void; programId?: string }) {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: Starters }>(programId ? `/admin/programs/${programId}/course/starters` : '/admin/course-starters')
  if (isLoading || !data) return <Spinner />
  const d = data.data
  const set = (p: Partial<ContentPlan>) => onChange({ ...plan, ...p })
  const kit = d.kits.find((k) => k.id === plan.kitId)
  const toggleFile = (id: string) => set({ files: plan.files.includes(id) ? plan.files.filter((x) => x !== id) : [...plan.files, id] })

  return (
    <div className="space-y-6">
      {/* 1 — structure */}
      <section className="space-y-3">
        <div><h3 className="flex items-center gap-2 text-lg font-bold text-navy-900"><Layers className="size-5 text-gold-600" />{t('studio.content.structure')}</h3><p className="text-sm text-slate-500">{t('studio.content.structureHint')}</p></div>
        <div className="grid gap-4 lg:grid-cols-4">
          {d.blueprints.map((b) => {
            const on = plan.blueprint === b.key
            return (
              <button key={b.key} type="button" aria-pressed={on} onClick={() => set({ blueprint: b.key })} className={clsx('flex flex-col rounded-2xl border p-4 text-start transition', on ? 'border-navy-900 bg-navy-900 text-white shadow-glass' : 'border-navy-100 bg-white hover:border-gold-400')}>
                <div className="flex items-center justify-between"><span className="font-bold">{b.title}</span>{on && <Check className="size-4 text-gold-300" />}</div>
                <p className={clsx('mt-1 text-xs', on ? 'text-white/70' : 'text-slate-500')}>{b.text}</p>
                <ul className="mt-3 space-y-2">
                  {b.modules.map((m) => (
                    <li key={m.title}>
                      <div className={clsx('text-[11px] font-bold', on ? 'text-gold-300' : 'text-gold-700')}>{m.title}</div>
                      <div className="mt-1 flex flex-wrap gap-1">{m.lessons.map((l, i) => { const Icon = ICON[l.type] ?? FileText; return <span key={i} title={l.title} className={clsx('grid size-6 place-items-center rounded-md', on ? 'bg-white/15' : 'bg-navy-100/70')}><Icon className="size-3.5" /></span> })}</div>
                    </li>
                  ))}
                </ul>
              </button>
            )
          })}
          <button type="button" aria-pressed={plan.blueprint === null} onClick={() => set({ blueprint: null })} className={clsx('flex flex-col justify-center rounded-2xl border border-dashed p-4 text-start transition', plan.blueprint === null ? 'border-navy-900 bg-navy-900/5' : 'border-navy-200 hover:border-gold-400')}>
            <span className="font-bold text-navy-900">{t('studio.content.empty')}</span><span className="mt-1 text-xs text-slate-500">{t('studio.content.emptyHint')}</span>
          </button>
        </div>
      </section>

      {/* 2 — watch control */}
      <section className="space-y-3">
        <div><h3 className="flex items-center gap-2 text-lg font-bold text-navy-900"><ShieldCheck className="size-5 text-gold-600" />{t('studio.content.watch')}</h3><p className="text-sm text-slate-500">{t('studio.content.watchHint')}</p></div>
        <div className="grid gap-3 sm:grid-cols-3">
          {(['strict', 'standard', 'free'] as const).map((w) => (
            <button key={w} type="button" aria-pressed={plan.watch === w} onClick={() => set({ watch: w })} className={clsx('rounded-2xl border p-4 text-start transition', plan.watch === w ? 'border-gold-500 bg-gold-100/40 ring-2 ring-gold-100' : 'border-navy-100 bg-white hover:border-gold-300')}>
              <div className="flex items-center gap-2 font-bold text-navy-900">{w === 'strict' ? <Zap className="size-4 text-gold-600" /> : <Play className="size-4 text-gold-600" />}{t(`studio.content.watchModes.${w}`)}</div>
              <p className="mt-1 text-xs text-slate-500">{t(`studio.content.watchModes.${w}Hint`)}</p>
            </button>
          ))}
        </div>
      </section>

      {/* 3 — course rules */}
      <Card className="grid gap-4 md:grid-cols-3">
        <button type="button" role="switch" aria-checked={plan.sequential} onClick={() => set({ sequential: !plan.sequential })} className="flex items-start gap-3 text-start">
          <span className={clsx('mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition', plan.sequential ? 'bg-emerald-500' : 'bg-slate-300')}><span className={clsx('size-4 rounded-full bg-white shadow transition', plan.sequential && 'ltr:translate-x-4 rtl:-translate-x-4')} /></span>
          <span><span className="block text-sm font-semibold text-navy-900">{t('course.sequential')}</span><span className="block text-xs text-slate-500">{t('course.sequentialHint')}</span></span>
        </button>
        <button type="button" role="switch" aria-checked={plan.autoCert} onClick={() => set({ autoCert: !plan.autoCert })} className="flex items-start gap-3 text-start">
          <span className={clsx('mt-0.5 inline-flex h-5 w-9 shrink-0 items-center rounded-full p-0.5 transition', plan.autoCert ? 'bg-emerald-500' : 'bg-slate-300')}><span className={clsx('size-4 rounded-full bg-white shadow transition', plan.autoCert && 'ltr:translate-x-4 rtl:-translate-x-4')} /></span>
          <span><span className="flex items-center gap-1.5 text-sm font-semibold text-navy-900"><Award className="size-4 text-gold-600" />{t('course.autoCert')}</span><span className="block text-xs text-slate-500">{t('course.autoCertHint')}</span></span>
        </button>
        <label className="block"><span className="label">{t('course.completionPercent')}</span><div className="flex items-center gap-2"><input type="number" min={1} max={100} dir="ltr" className="input !py-1.5 text-sm" value={plan.completion} onChange={(e) => set({ completion: Math.min(100, Math.max(1, Number(e.target.value) || 100)) })} /><span className="text-xs text-slate-500">%</span></div></label>
      </Card>

      {/* 4 — training kit */}
      <section className="space-y-3">
        <div><h3 className="flex items-center gap-2 text-lg font-bold text-navy-900"><PackageOpen className="size-5 text-gold-600" />{t('studio.content.kit')}</h3><p className="text-sm text-slate-500">{t('studio.content.kitHint')}</p></div>
        {d.kits.length === 0 ? <Card className="text-sm text-slate-500">{t('studio.content.noKits')}</Card> : (
          <div className="grid gap-4 lg:grid-cols-[18rem_minmax(0,1fr)]">
            <ul className="space-y-2">
              <li><button type="button" onClick={() => set({ kitId: null, files: [] })} className={clsx('w-full rounded-xl border px-3 py-2.5 text-start text-sm font-semibold transition', plan.kitId === null ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-navy-800 hover:border-gold-400')}>{t('studio.content.noKit')}</button></li>
              {d.kits.map((k) => (
                <li key={k.id}>
                  <button type="button" onClick={() => set({ kitId: k.id, files: k.files.filter((f) => f.lesson_type).map((f) => f.id) })} className={clsx('w-full rounded-xl border px-3 py-2.5 text-start transition', plan.kitId === k.id ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white hover:border-gold-400')}>
                    <div className="flex items-center gap-2"><span className="min-w-0 flex-1 truncate text-sm font-bold">{k.title}</span>{k.linked && <Badge color="green">{t('studio.content.linked')}</Badge>}</div>
                    <div className={clsx('text-[11px]', plan.kitId === k.id ? 'text-white/70' : 'text-slate-500')} dir="ltr">{k.code} · {k.files.length} {t('studio.content.files')}</div>
                  </button>
                </li>
              ))}
            </ul>
            <div>
              {kit ? (
                <Card padded={false}>
                  <div className="border-b border-navy-100 px-4 py-3 text-sm font-semibold text-navy-900">{t('studio.content.pick', { count: plan.files.length })}</div>
                  <ul className="divide-y divide-navy-100/70">
                    {kit.files.map((f) => {
                      const ok = !!f.lesson_type
                      const Icon = f.lesson_type ? ICON[f.lesson_type] : FileText
                      return (
                        <li key={f.id}>
                          <label className={clsx('flex items-center gap-3 px-4 py-2.5', ok ? 'cursor-pointer hover:bg-ivory' : 'opacity-50')}>
                            <input type="checkbox" className="size-4 accent-gold-600" disabled={!ok} checked={plan.files.includes(f.id)} onChange={() => toggleFile(f.id)} />
                            <span className="grid size-8 shrink-0 place-items-center rounded-lg bg-navy-100/70 text-navy-800"><Icon className="size-4" /></span>
                            <span className="min-w-0 flex-1"><span className="block truncate text-sm font-semibold text-navy-900">{f.name}</span><span className="block text-[11px] text-slate-500">{ok ? t(`course.types.${f.lesson_type}`) : t('studio.content.notImportable')} · {fmtSize(f.size)}</span></span>
                          </label>
                        </li>
                      )
                    })}
                  </ul>
                </Card>
              ) : <Card className="grid min-h-32 place-items-center text-center text-sm text-slate-500"><div><Sparkles className="mx-auto mb-2 size-6 text-gold-500" />{t('studio.content.kitPick')}</div></Card>}
            </div>
          </div>
        )}
      </section>
      <p className="text-xs text-slate-500">{t('studio.content.after', { count: fmt.number(plan.files.length) })}</p>
    </div>
  )
}
