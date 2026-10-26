import clsx from 'clsx'
import { BadgeCheck, ChevronDown, Clock3, FileSpreadsheet, Link2, Search, Users } from 'lucide-react'
import { useMemo, useState, type ComponentType } from 'react'
import { useTranslation } from 'react-i18next'
import { Empty } from '@/components/ui'
import { Skeleton } from '@/components/ui/Skeleton'
import { useGet } from '@/hooks/useApi'
import ImportWizard from './ImportWizard'
import { SurveyLinksDialog, TrainersManagerDialog } from './RosterDialogs'
import type { Overview } from './types'

type Tool = 'import' | 'links' | 'trainers'

/** «أدوات البيانات» (D1–D4): one card per tool, shown to whoever holds its right. */
export function DataTools({ overview, onChanged }: { overview: Overview; onChanged: () => void }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState<Tool | null>(null)
  const can = (p: string) => !!overview.can[p]
  const tools: { id: Tool; icon: ComponentType<{ className?: string }>; allowed: boolean }[] = [
    { id: 'import', icon: FileSpreadsheet, allowed: can('saber.import') },
    { id: 'links', icon: Link2, allowed: can('saber.survey_links.manage') },
    { id: 'trainers', icon: Users, allowed: can('trainers.manage') },
  ]
  const visible = tools.filter((x) => x.allowed)

  return (
    <div className="space-y-3">
      <p className="text-sm text-slate-500">{t('saber.data.hint')}</p>
      {visible.length === 0 ? <div className="rounded-2xl border border-dashed border-navy-100 bg-white py-12"><Empty text="—" /></div> : (
        <div className="grid gap-3 md:grid-cols-2">
          {visible.map(({ id, icon: Icon }) => (
            <button key={id} type="button" onClick={() => setOpen(id)} className="group flex items-start gap-4 rounded-2xl border border-navy-100 bg-white p-5 text-start shadow-sm transition hover:-translate-y-0.5 hover:border-gold-300 hover:shadow-md">
              <span className="grid size-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br from-gold-50 to-ivory text-gold-700 ring-1 ring-inset ring-gold-200 transition group-hover:scale-105"><Icon className="size-6" /></span>
              <span>
                <span className="block text-base font-bold text-navy-900">{id === 'import' ? t('saber.wizard.card') : t(`saber.data.${id}.title`)}</span>
                <span className="mt-1 block text-sm leading-relaxed text-slate-500">{id === 'import' ? t('saber.wizard.cardDesc') : t(`saber.data.${id}.desc`)}</span>
              </span>
            </button>
          ))}
        </div>
      )}
      {open === 'import' && <ImportWizard onClose={() => setOpen(null)} onDone={onChanged} />}
      {open === 'links' && <SurveyLinksDialog onClose={() => setOpen(null)} onChanged={onChanged} />}
      {open === 'trainers' && <TrainersManagerDialog onClose={() => setOpen(null)} onChanged={onChanged} />}
    </div>
  )
}

type Supervisor = { id: string; name: string; email: string | null; programs: { id: string; title: string; code: string; groups: number; validated: number; pending: number }[]; validated: number; pending: number }

/** «المشرفون» (D5): every supervisor with the programmes in their care, approved and pending groups. */
export function SupervisorsPanel({ onProgram }: { onProgram: (id: string) => void }) {
  const { t } = useTranslation()
  const { data } = useGet<{ data: Supervisor[] }>('/admin/saber/data/supervisors', undefined, { staleTime: 30_000 })
  const [q, setQ] = useState('')
  const [open, setOpen] = useState<Set<string>>(new Set())
  const [page, setPage] = useState(1)
  const per = 15
  const rows = useMemo(() => (data?.data ?? []).filter((s) => !q.trim() || `${s.name} ${s.email ?? ''}`.toLowerCase().includes(q.trim().toLowerCase())), [data, q])
  const last = Math.max(1, Math.ceil(rows.length / per))
  const slice = rows.slice((Math.min(page, last) - 1) * per, Math.min(page, last) * per)

  if (!data) return <Skeleton className="h-96 rounded-2xl" />
  return (
    <div className="space-y-3">
      <label className="flex h-10 max-w-md items-center gap-2 rounded-xl border border-navy-100 bg-white px-3 focus-within:border-gold-400">
        <Search className="size-4 text-slate-400" /><input value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} placeholder={t('saber.data.supervisors.search')} className="w-full bg-transparent text-sm outline-none" />
      </label>
      {rows.length === 0 ? <div className="rounded-2xl border border-dashed border-navy-100 bg-white py-12"><Empty text={t('saber.data.supervisors.none')} /></div> : (
        <ul className="space-y-2">
          {slice.map((s) => {
            const expanded = open.has(s.id)
            return (
              <li key={s.id} className="rounded-2xl border border-navy-100 bg-white shadow-sm">
                <button type="button" aria-expanded={expanded} onClick={() => setOpen((o) => { const n = new Set(o); if (n.has(s.id)) n.delete(s.id); else n.add(s.id); return n })} className="flex w-full flex-wrap items-center gap-3 p-4 text-start">
                  <span className="grid size-10 place-items-center rounded-full bg-navy-900 text-sm font-bold text-white">{s.name.trim().charAt(0)}</span>
                  <span className="min-w-0 flex-1"><span className="block truncate font-bold text-navy-900">{s.name}</span><span className="block truncate text-xs text-slate-400" dir="ltr">{s.email}</span></span>
                  <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-bold text-slate-600">{t('saber.data.supervisors.count')}: {s.programs.length}</span>
                  <span className="inline-flex items-center gap-1 rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-bold text-emerald-700"><BadgeCheck className="size-3.5" />{s.validated}</span>
                  <span className="inline-flex items-center gap-1 rounded-full bg-amber-50 px-2.5 py-1 text-xs font-bold text-amber-700"><Clock3 className="size-3.5" />{s.pending}</span>
                  <ChevronDown className={clsx('size-4 text-slate-400 transition', expanded && 'rotate-180')} />
                </button>
                {expanded && (
                  <div className="border-t border-navy-50 p-3">
                    {s.programs.length === 0 ? <p className="text-sm text-slate-400">{t('saber.data.supervisors.noPrograms')}</p> : (
                      <ul className="divide-y divide-navy-50">
                        {s.programs.map((p) => (
                          <li key={p.id} className="flex flex-wrap items-center gap-2 py-2 text-sm">
                            <button type="button" onClick={() => onProgram(p.id)} className="min-w-0 flex-1 truncate text-start font-semibold text-navy-900 hover:text-gold-700">{p.title}</button>
                            <span className="font-mono text-[11px] text-slate-400" dir="ltr">{p.code}</span>
                            <span className="text-xs text-emerald-700">{t('saber.data.supervisors.validated')}: {p.validated}</span>
                            <span className="text-xs text-amber-700">{t('saber.data.supervisors.pending')}: {p.pending}</span>
                          </li>
                        ))}
                      </ul>
                    )}
                  </div>
                )}
              </li>
            )
          })}
        </ul>
      )}
      {last > 1 && (
        <nav className="flex items-center justify-center gap-3 text-sm font-semibold text-slate-500">
          <button type="button" disabled={page <= 1} onClick={() => setPage(page - 1)} className="rounded-lg border border-navy-100 px-3 py-1.5 disabled:opacity-40">{t('saber.pager.prev')}</button>
          {t('saber.pager.page', { page: Math.min(page, last), last })}
          <button type="button" disabled={page >= last} onClick={() => setPage(page + 1)} className="rounded-lg border border-navy-100 px-3 py-1.5 disabled:opacity-40">{t('saber.pager.next')}</button>
        </nav>
      )}
    </div>
  )
}
