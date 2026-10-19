import { useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import { ArrowUpRight, ExternalLink, X } from 'lucide-react'
import { useEffect, useRef, useState, type ComponentType, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'

export type Overview = {
  participants: { total: number; approved: number; pending: number; waitlisted: number }
  groups: { count: number; capacity: number; seats_taken: number }
  structure: { units: number; sub_programs: number }
  admission: { capacity: number; seats_taken: number; waitlisted: number }
  assessments: { count: number; published: number }
  passing: { passed: number; total: number }
  evaluation: { assignments: number; interviews: number }
  sessions: { count: number; completed: number; next: string | null }
  course: { modules: number; lessons: number }
  rules: { count: number }
  tasks: { count: number }
  materials: { count: number }
  survey: { mode: string | null; auto_hours: number | null }
  certificates: { issued: number }
  impact: { average: number | null }
  staff: { grants: number }
}

/** One section of the program page: shown as a tab, or as a widget that opens in a popup. */
export type SectionDef = { id: string; label: string; icon: ComponentType<{ className?: string }>; render: () => ReactNode; widgetOnly?: boolean }

/** Up to two live numbers for a widget, read from the overview. */
function metrics(id: string, o: Overview | undefined, t: (k: string, p?: Record<string, unknown>) => string): { value: ReactNode; label: string }[] {
  if (!o) return []
  switch (id) {
    case 'participants': return [{ value: fmt.number(o.participants.approved), label: t('admin.programs.widgets.m.approved') }, { value: fmt.number(o.participants.pending), label: t('admin.programs.widgets.m.pending') }]
    case 'groups': return [{ value: fmt.number(o.groups.count), label: t('admin.programs.widgets.m.groups') }, { value: `${fmt.number(o.groups.seats_taken)}/${fmt.number(o.groups.capacity)}`, label: t('admin.programs.widgets.m.seats') }]
    case 'structure': return [{ value: fmt.number(o.structure.units), label: t('admin.programs.widgets.m.units') }, { value: fmt.number(o.structure.sub_programs), label: t('admin.programs.widgets.m.subPrograms') }]
    case 'admission': return [{ value: `${fmt.number(o.admission.seats_taken)}/${fmt.number(o.admission.capacity)}`, label: t('admin.programs.widgets.m.seats') }, { value: fmt.number(o.admission.waitlisted), label: t('admin.programs.widgets.m.waiting') }]
    case 'assessments': return [{ value: fmt.number(o.assessments.count), label: t('admin.programs.widgets.m.tests') }, { value: fmt.number(o.assessments.published), label: t('admin.programs.widgets.m.published') }]
    case 'passing': return [{ value: fmt.number(o.passing.passed), label: t('admin.programs.widgets.m.passed') }, { value: fmt.number(o.passing.total), label: t('admin.programs.widgets.m.trainees') }]
    case 'evaluation': return [{ value: fmt.number(o.evaluation.assignments), label: t('admin.programs.widgets.m.forms') }, { value: fmt.number(o.evaluation.interviews), label: t('admin.programs.widgets.m.interviews') }]
    case 'sessions': return [{ value: `${fmt.number(o.sessions.completed)}/${fmt.number(o.sessions.count)}`, label: t('admin.programs.widgets.m.sessionsDone') }, ...(o.sessions.next ? [{ value: fmt.date(o.sessions.next), label: t('admin.programs.widgets.m.next') }] : [])]
    case 'course': return [{ value: fmt.number(o.course.modules), label: t('admin.programs.widgets.m.modules') }, { value: fmt.number(o.course.lessons), label: t('admin.programs.widgets.m.lessons') }]
    case 'rules': return [{ value: fmt.number(o.rules.count), label: t('admin.programs.widgets.m.rules') }]
    case 'tasks': return [{ value: fmt.number(o.tasks.count), label: t('admin.programs.widgets.m.tasks') }]
    case 'materials': return [{ value: fmt.number(o.materials.count), label: t('admin.programs.widgets.m.files') }]
    case 'survey': return [{ value: o.survey.mode ? t(`admin.programs.widgets.m.mode.${o.survey.mode}`) : '—', label: t('admin.programs.widgets.m.surveyMode') }]
    case 'certificates': return [{ value: fmt.number(o.certificates.issued), label: t('admin.programs.widgets.m.issued') }]
    case 'impact': return [{ value: o.impact.average !== null ? fmt.number(o.impact.average, 1) : '—', label: t('admin.programs.widgets.m.impact') }]
    case 'staff': return [{ value: fmt.number(o.staff.grants), label: t('admin.programs.widgets.m.grants') }]
    default: return []
  }
}

/** The program page as a board: every section a widget with its live numbers; opening one shows it in a popup, ready to edit. */
export default function ProgramWidgets({ programId, sections, openId, onOpen, onClose, onOpenAsTab }: {
  programId: string
  sections: SectionDef[]
  openId: string | null
  onOpen: (id: string) => void
  onClose: () => void
  onOpenAsTab: (id: string) => void
}) {
  const { t } = useTranslation()
  const overview = useGet<{ data: Overview }>(`/admin/programs/${programId}/overview`, undefined, { staleTime: 0 })
  const current = sections.find((s) => s.id === openId) ?? null
  return (
    <>
      <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3" role="list">
        {sections.map((s) => {
          const m = metrics(s.id, overview.data?.data, t)
          const Icon = s.icon
          return (
            <button key={s.id} type="button" role="listitem" onClick={() => onOpen(s.id)} aria-haspopup="dialog" className={clsx('group relative flex min-h-40 flex-col overflow-hidden rounded-3xl border bg-white p-5 text-start shadow-sm transition-all duration-200 hover:-translate-y-0.5 hover:border-gold-400 hover:shadow-md focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-navy-900', s.widgetOnly ? 'border-gold-300/80 bg-gradient-to-br from-gold-50/70 to-white' : 'border-navy-100')}>
              <div className="pointer-events-none absolute -end-8 -top-8 size-28 rounded-full bg-gold-500/10 transition group-hover:scale-125" />
              <div className="flex items-start gap-3">
                <span className="grid size-11 shrink-0 place-items-center rounded-2xl bg-navy-900 text-gold-300 shadow-sm"><Icon className="size-5" /></span>
                <div className="min-w-0 flex-1"><div className="font-bold leading-6 text-navy-900">{s.label}</div><p className="mt-0.5 line-clamp-2 text-xs leading-5 text-slate-500">{t(`admin.programs.widgets.hint.${s.id}`)}</p></div>
                <ArrowUpRight className="size-4 shrink-0 text-slate-300 transition group-hover:text-gold-600 rtl:-scale-x-100" />
              </div>
              <div className="mt-auto flex flex-wrap items-end gap-x-6 gap-y-1 pt-4">
                {overview.isLoading ? <span className="h-8 w-16 animate-pulse rounded-lg bg-slate-100" /> : m.map((x, i) => (
                  <div key={i}><div className="font-display text-2xl font-bold leading-none text-navy-900" dir="ltr">{x.value}</div><div className="mt-1 text-[11px] font-semibold text-slate-500">{x.label}</div></div>
                ))}
              </div>
            </button>
          )
        })}
      </div>
      {current && <WidgetPopup programId={programId} def={current} onClose={onClose} onOpenAsTab={() => onOpenAsTab(current.id)} />}
    </>
  )
}

/**
 * A section opened over the board. No blur or transform on the way down, so windows the section itself opens (add trainees, dialogs) sit
 * over it correctly; Escape closes only this popup, and a click outside does not (a form may be half filled).
 */
function WidgetPopup({ programId, def, onClose, onOpenAsTab }: { programId: string; def: SectionDef; onClose: () => void; onOpenAsTab: () => void }) {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [shown, setShown] = useState(false)
  const box = useRef<HTMLDivElement>(null)
  const Icon = def.icon
  const close = () => {
    // What was changed inside shows on the board at once.
    void qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0] ?? '').startsWith(`/admin/programs/${programId}`) || String(q.queryKey[0] ?? '').includes(`/groups`) })
    onClose()
  }
  const closeRef = useRef(close)
  useEffect(() => { closeRef.current = close })
  useEffect(() => {
    const raf = requestAnimationFrame(() => setShown(true))
    const before = document.activeElement as HTMLElement | null
    box.current?.focus()
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape' && document.querySelectorAll('[role="dialog"]').length <= 1) closeRef.current() }
    window.addEventListener('keydown', onKey)
    const prev = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => { cancelAnimationFrame(raf); window.removeEventListener('keydown', onKey); document.body.style.overflow = prev; before?.focus?.() }
  }, [])
  return (
    <div className={clsx('fixed inset-0 z-50 flex items-start justify-center bg-navy-950/55 p-3 transition-opacity duration-200 sm:p-6', shown ? 'opacity-100' : 'opacity-0')}>
      <div ref={box} role="dialog" aria-modal="true" aria-label={def.label} tabIndex={-1} className="flex max-h-[94vh] w-full max-w-6xl flex-col overflow-hidden rounded-3xl bg-white shadow-2xl outline-none ring-1 ring-black/5">
        <header className="flex shrink-0 items-center gap-3 border-b border-navy-100 bg-gradient-to-l from-navy-900 to-navy-800 px-5 py-3.5 text-white">
          <span className="grid size-10 place-items-center rounded-xl bg-gold-500 text-navy-950"><Icon className="size-5" /></span>
          <h2 className="min-w-0 flex-1 truncate text-lg font-bold">{def.label}</h2>
          <button type="button" onClick={onOpenAsTab} className="hidden items-center gap-1.5 rounded-xl bg-white/10 px-3 py-1.5 text-xs font-bold hover:bg-white/20 sm:inline-flex" title={t('admin.programs.widgets.openAsTab') as string}><ExternalLink className="size-3.5" />{t('admin.programs.widgets.openAsTab')}</button>
          <button type="button" onClick={close} aria-label={t('common.close') as string} className="grid size-9 place-items-center rounded-xl bg-white/10 hover:bg-white/20"><X className="size-5" /></button>
        </header>
        <div className="min-h-0 flex-1 overflow-y-auto bg-ivory p-4 sm:p-6">
          {def.render()}
        </div>
      </div>
    </div>
  )
}
