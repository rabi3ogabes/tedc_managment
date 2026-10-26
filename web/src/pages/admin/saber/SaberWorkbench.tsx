import { useQueryClient } from '@tanstack/react-query'
import clsx from 'clsx'
import {
  Ban, BookOpen, CalendarCheck2, CalendarClock, CalendarOff, CalendarRange, CalendarDays, ChevronDown, ChevronsDownUp, ChevronsUpDown, DoorOpen, ExternalLink, FilterX, GraduationCap, Info, LayoutGrid, Map as MapIcon, Plus, Rows3, Search, Settings2, SlidersHorizontal, UserRound,
} from 'lucide-react'
import { useEffect, useMemo, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { Button, Empty, ErrorState, Modal, PageHeader, StatCard } from '@/components/ui'
import { Skeleton } from '@/components/ui/Skeleton'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { SaberActionsContext, type SaberActions } from './actions'
import { OffdayInbox, OffdayRequestDialog, WeeklyHolidays } from './CalendarDialogs'
import CancelDialog from './CancelDialog'
import GroupPanel from './GroupPanel'
import MeetingDialog from './MeetingDialog'
import PlanDialog from './PlanDialog'
import { DetailsModal, SurveyLinks } from './ProgramExtras'
import RoomMap from './RoomMap'
import { isoToday, type MonthData } from './rooms'
import RoomsCalendar from './RoomsCalendar'
import { lightsOf, withGroup, type Group, type Notice, type OffdayTarget, type Overview, type Program } from './types'

type Filters = { q: string; from: string; to: string; term_first: boolean; term_second: boolean; kit_ready: boolean; kit_not_ready: boolean; page: number }
type Tools = { plan: boolean; cancel: boolean; inbox: boolean; weekly: boolean; search: boolean; dates: boolean; term: boolean; kit: boolean; view: boolean; expand: boolean }
type Page = { data: Program[]; meta: { total: number; page: number; per_page: number; last_page: number } }

const NO_FILTERS: Filters = { q: '', from: '', to: '', term_first: true, term_second: true, kit_ready: true, kit_not_ready: true, page: 1 }
const ALL_TOOLS: Tools = { plan: true, cancel: true, inbox: true, weekly: true, search: true, dates: true, term: true, kit: true, view: true, expand: true }
const tell = (notices?: Notice[]) => notices?.forEach((n) => toast(n.text, n.type === 'warning' ? 'error' : n.type === 'info' ? 'info' : 'success'))
const store = {
  get: <T,>(key: string, fallback: T): T => { try { const v = localStorage.getItem(key); return v ? { ...fallback, ...JSON.parse(v) } : fallback } catch { return fallback } },
  set: (key: string, value: unknown) => { try { localStorage.setItem(key, JSON.stringify(value)) } catch { /* private window */ } },
}

/** سابر+ — every programme with its groups and days, edited in place (docs/saber/01-workbench.md). */
export default function SaberWorkbench() {
  const { t } = useTranslation()
  const qc = useQueryClient()
  const [filters, setFilters] = useState<Filters>(NO_FILTERS)
  const [search, setSearch] = useState('')
  const [tools, setTools] = useState<Tools>(() => store.get('saber.tools', ALL_TOOLS))
  const [view, setView] = useState<'rows' | 'cards'>(() => store.get('saber.view', { v: 'rows' as 'rows' | 'cards' }).v)
  const [open, setOpen] = useState<Set<string>>(new Set())
  const [toolsOpen, setToolsOpen] = useState(false)
  const [settingsOpen, setSettingsOpen] = useState(false)
  const [params0, setParams0] = useSearchParams()
  const [plan, setPlan] = useState<{ program: Program; group: Group } | 'new' | null>(null)
  const [cancel, setCancel] = useState<{ program: Program; group: Group } | 'new' | null>(null)
  const [offday, setOffday] = useState<OffdayTarget | null>(null)
  const [inboxOpen, setInboxOpen] = useState(() => params0.get('inbox') === '1')
  const [weeklyOpen, setWeeklyOpen] = useState(false)
  const [detailsId, setDetailsId] = useState<string | null>(null)
  const tab = (['calendar', 'map'] as const).find((x) => x === params0.get('tab')) ?? 'programs'
  const setTab = (next: 'programs' | 'calendar' | 'map') => { if (next === 'programs') params0.delete('tab'); else params0.set('tab', next); setParams0(params0, { replace: true }) }
  const [cursor, setCursor] = useState(() => isoToday().slice(0, 7))
  const [mapDate, setMapDate] = useState(isoToday)
  const [meeting, setMeeting] = useState<{ roomId?: string; date?: string } | null>(null)

  useEffect(() => { const id = setTimeout(() => setFilters((f) => (f.q === search ? f : { ...f, q: search, page: 1 })), 300); return () => clearTimeout(id) }, [search])
  useEffect(() => store.set('saber.tools', tools), [tools])
  useEffect(() => store.set('saber.view', { v: view }), [view])

  const overview = useGet<{ data: Overview }>('/admin/saber/overview', undefined, { staleTime: 60_000 })
  const params = useMemo(() => ({
    q: filters.q || undefined, from: filters.from || undefined, to: filters.to || undefined, page: filters.page,
    term_first: filters.term_first ? undefined : 0, term_second: filters.term_second ? undefined : 0, kit_ready: filters.kit_ready ? undefined : 0, kit_not_ready: filters.kit_not_ready ? undefined : 0,
  }), [filters])
  const list = useGet<Page>('/admin/saber/programs', params, { staleTime: 0, placeholderData: (prev) => prev })
  const month = useGet<{ data: MonthData }>(tab === 'programs' ? null : '/admin/saber/rooms-month', { month: tab === 'map' ? mapDate.slice(0, 7) : cursor }, { staleTime: 0, placeholderData: (prev) => prev })
  const ov = overview.data?.data
  const page = list.data
  const dirty = JSON.stringify({ ...filters, page: 1 }) !== JSON.stringify(NO_FILTERS)

  const set = (patch: Partial<Filters>) => setFilters((f) => ({ ...f, page: 1, ...patch }))
  const replaceProgram = (id: string, fn: (p: Program) => Program) =>
    qc.setQueriesData<Page>({ queryKey: ['/admin/saber/programs'] }, (old) => old && { ...old, data: old.data.map((p) => (p.id === id ? fn(p) : p)) })
  const toggle = (id: string) => setOpen((s) => { const n = new Set(s); if (n.has(id)) n.delete(id); else n.add(id); return n })
  const allOpen = !!page && page.data.length > 0 && page.data.every((p) => open.has(p.id))
  const refresh = () => qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0] ?? '').startsWith('/admin/saber') })
  const actions: SaberActions = {
    plan: (program, group) => setPlan({ program, group }),
    cancelGroup: (program, group) => setCancel({ program, group }),
    requestOffday: setOffday,
    details: setDetailsId,
    refresh,
  }
  const closeInbox = () => { setInboxOpen(false); if (params0.has('inbox')) { params0.delete('inbox'); setParams0(params0, { replace: true }) } }

  if (overview.isError || list.isError) return <ErrorState error={overview.error ?? list.error} onRetry={() => { overview.refetch(); list.refetch() }} />

  return (
    <SaberActionsContext.Provider value={actions}>
    <div className="space-y-5 pb-8">
      <PageHeader title={<span className="flex items-center gap-2">{t('saber.title')}<span className="rounded-full bg-gold-100 px-2 py-0.5 text-[11px] font-bold tracking-wide text-gold-800">PLUS</span></span>} subtitle={t('saber.subtitle')} actions={
        <div className="flex flex-wrap items-center justify-end gap-2">
          {tab !== 'programs' && (ov?.can['rooms.book'] || ov?.can['saber.program.create'] || ov?.can['rooms.manage']) && <Button variant="gold" size="sm" icon={<Plus className="size-4" />} disabled={!month.data} onClick={() => setMeeting({})}>{t('saber.meeting.open')}</Button>}
          {tab === 'programs' && ov?.can['programs.manage'] && <Button variant="outline" size="sm" icon={<Plus className="size-4" />} to="/admin/programs/smart">{t('saber.newProgram')}</Button>}
          {tools.plan && ov?.can['saber.plan.view'] && <Button variant="gold" size="sm" icon={<CalendarRange className="size-4" />} onClick={() => setPlan('new')}>{t('saber.plan.open')}</Button>}
          {tools.cancel && <Button variant="outline" size="sm" icon={<Ban className="size-4" />} onClick={() => setCancel('new')}>{t('saber.cancel.open')}</Button>}
          {tools.inbox && (
            <Button variant="outline" size="sm" icon={<CalendarClock className="size-4" />} onClick={() => setInboxOpen(true)}>
              {t('saber.offday.open')}{!!ov?.offday_pending && <span className="ms-1 grid min-w-5 place-items-center rounded-full bg-rose-600 px-1.5 text-[11px] font-bold leading-5 text-white">{ov.offday_pending}</span>}
            </Button>
          )}
          {tools.weekly && <Button variant="outline" size="sm" icon={<CalendarOff className="size-4" />} onClick={() => setWeeklyOpen(true)}>{t('saber.weekly.open')}</Button>}
          <Button variant="outline" size="sm" icon={<SlidersHorizontal className="size-4" />} onClick={() => setToolsOpen(true)}>{t('saber.tools.title')}</Button>
          {ov?.can['settings.manage'] && <Button variant="outline" size="sm" icon={<Settings2 className="size-4" />} onClick={() => setSettingsOpen(true)}>{t('saber.settings.title')}</Button>}
        </div>} />

      <div className="grid grid-cols-2 gap-3 lg:grid-cols-4">
        {ov ? <>
          <StatCard label={t('saber.stats.programs')} value={fmt.number(ov.stats.programs_month)} icon={<BookOpen className="size-5" />} accent />
          <StatCard label={t('saber.stats.awaiting')} value={fmt.number(ov.stats.awaiting_approval)} icon={<CalendarCheck2 className="size-5" />} />
          <StatCard label={t('saber.stats.rooms')} value={fmt.number(ov.stats.booked_rooms)} icon={<DoorOpen className="size-5" />} />
          <StatCard label={t('saber.stats.trainers')} value={fmt.number(ov.stats.trainers)} icon={<GraduationCap className="size-5" />} />
        </> : Array.from({ length: 4 }, (_, i) => <Skeleton key={i} className="h-24 rounded-2xl" />)}
      </div>

      <div className="flex gap-1 overflow-x-auto rounded-2xl border border-navy-100 bg-white p-1 shadow-sm" role="tablist">
        {([['programs', t('saber.tabs.programs'), BookOpen], ['calendar', t('saber.tabs.calendar'), CalendarDays], ['map', t('saber.tabs.map'), MapIcon]] as const).map(([id, label, Icon]) => (
          <button key={id} type="button" role="tab" aria-selected={tab === id} onClick={() => setTab(id)}
            className={clsx('flex flex-1 items-center justify-center gap-2 whitespace-nowrap rounded-xl px-4 py-2.5 text-sm font-bold transition', tab === id ? 'bg-navy-900 text-white shadow' : 'text-slate-500 hover:bg-ivory hover:text-navy-900')}>
            <Icon className="size-4" />{label}
          </button>
        ))}
      </div>

      {tab === 'calendar' && (month.data && ov
        ? <RoomsCalendar data={month.data.data} overview={ov} cursor={cursor} onCursor={setCursor} onBook={(roomId, date) => setMeeting({ roomId, date })} onChanged={() => month.refetch()} />
        : <Skeleton className="h-[32rem] rounded-2xl" />)}
      {tab === 'map' && (month.data && ov
        ? <RoomMap data={month.data.data} overview={ov} date={mapDate} onDate={setMapDate} onChanged={() => month.refetch()} />
        : <Skeleton className="h-[32rem] rounded-2xl" />)}

      {tab === 'programs' && <>
      <div className="sticky top-0 z-20 -mx-1 rounded-2xl border border-navy-100 bg-white/90 p-3 shadow-sm backdrop-blur">
        <div className="flex flex-wrap items-center gap-2">
          {tools.search && (
            <label className="flex h-10 min-w-56 flex-1 items-center gap-2 rounded-xl border border-navy-100 bg-white px-3 focus-within:border-gold-400">
              <Search className="size-4 text-slate-400" />
              <input value={search} onChange={(e) => setSearch(e.target.value)} placeholder={t('saber.filters.search')} className="w-full bg-transparent text-sm outline-none" />
            </label>
          )}
          {tools.dates && (
            <div className="flex items-center gap-1.5 text-xs font-semibold text-slate-500">
              <span>{t('saber.filters.from')}</span><input type="date" dir="ltr" value={filters.from} onChange={(e) => set({ from: e.target.value })} className="input h-10 w-36 text-sm" />
              <span>{t('saber.filters.to')}</span><input type="date" dir="ltr" value={filters.to} onChange={(e) => set({ to: e.target.value })} className="input h-10 w-36 text-sm" />
            </div>
          )}
          {tools.term && <Segment label={t('saber.filters.term')} items={[['term_first', t('saber.filters.first')], ['term_second', t('saber.filters.second')]]} filters={filters} set={set} />}
          {tools.kit && <Segment label={t('saber.filters.kit')} items={[['kit_ready', t('saber.filters.ready')], ['kit_not_ready', t('saber.filters.notReady')]]} filters={filters} set={set} />}
          <div className="ms-auto flex items-center gap-1.5">
            {dirty && <Button variant="ghost" size="sm" icon={<FilterX className="size-4" />} onClick={() => { setSearch(''); setFilters(NO_FILTERS) }}>{t('saber.filters.clear')}</Button>}
            {tools.expand && page && page.data.length > 0 && (
              <Button variant="ghost" size="sm" icon={allOpen ? <ChevronsDownUp className="size-4" /> : <ChevronsUpDown className="size-4" />} onClick={() => setOpen(allOpen ? new Set() : new Set(page.data.map((p) => p.id)))}>{allOpen ? t('saber.collapseAll') : t('saber.expandAll')}</Button>
            )}
            {tools.view && (
              <div className="flex rounded-xl border border-navy-100 bg-white p-0.5" role="group" aria-label={t('saber.view.label')}>
                {([['rows', Rows3, t('saber.view.rows')], ['cards', LayoutGrid, t('saber.view.cards')]] as const).map(([id, Icon, label]) => (
                  <button key={id} type="button" aria-pressed={view === id} onClick={() => setView(id)} className={clsx('flex items-center gap-1.5 rounded-lg px-2.5 py-1.5 text-xs font-bold transition', view === id ? 'bg-navy-900 text-white' : 'text-slate-500 hover:text-navy-900')}><Icon className="size-4" />{label}</button>
                ))}
              </div>
            )}
          </div>
        </div>
      </div>

      {!page || !ov ? (
        <div className="space-y-3">{Array.from({ length: 5 }, (_, i) => <Skeleton key={i} className="h-20 rounded-2xl" />)}</div>
      ) : page.data.length === 0 ? (
        <div className="rounded-2xl border border-dashed border-navy-100 bg-white py-14"><Empty text={`${t('saber.empty')} ${t('saber.emptyHint')}`} /></div>
      ) : (
        <>
          <div className="flex items-center justify-between px-1 text-xs font-semibold text-slate-500">
            <span>{t('saber.results', { n: fmt.number(page.meta.total) })}</span>
            {list.isFetching && <span className="text-gold-600">•••</span>}
          </div>
          <ul className="space-y-3">
            {page.data.map((p) => (
              <ProgramRow key={p.id} program={p} overview={ov} view={view} open={open.has(p.id)} onToggle={() => toggle(p.id)} onDetails={setDetailsId}
                onProgram={(next) => replaceProgram(p.id, () => next)}
                onGroup={(g) => replaceProgram(p.id, (old) => withGroup(old, g))}
                onAdded={(g) => replaceProgram(p.id, (old) => { const groups = [...old.groups, g]; return { ...old, groups, lights: lightsOf(groups, old.days) } })}
                onRemoved={(id) => replaceProgram(p.id, (old) => { const groups = old.groups.filter((x) => x.id !== id); return { ...old, groups, lights: lightsOf(groups, old.days) } })} />
            ))}
          </ul>
          {page.meta.last_page > 1 && (
            <nav className="flex items-center justify-center gap-3 pt-2" aria-label="pagination">
              <Button variant="outline" size="sm" disabled={page.meta.page <= 1} onClick={() => setFilters((f) => ({ ...f, page: f.page - 1 }))}>{t('saber.pager.prev')}</Button>
              <span className="text-sm font-semibold text-slate-500">{t('saber.pager.page', { page: page.meta.page, last: page.meta.last_page })}</span>
              <Button variant="outline" size="sm" disabled={page.meta.page >= page.meta.last_page} onClick={() => setFilters((f) => ({ ...f, page: f.page + 1 }))}>{t('saber.pager.next')}</Button>
            </nav>
          )}
        </>
      )}
      </>}

      <Modal open={toolsOpen} onClose={() => setToolsOpen(false)} title={t('saber.tools.title')}>
        <p className="mb-4 text-sm text-slate-500">{t('saber.tools.hint')}</p>
        <div className="grid gap-2 sm:grid-cols-2">
          {(Object.keys(ALL_TOOLS) as (keyof Tools)[]).map((k) => (
            <label key={k} className="flex cursor-pointer items-center justify-between gap-3 rounded-xl border border-navy-100 px-3 py-2.5 text-sm font-semibold text-navy-900 hover:border-gold-400">
              {t(`saber.tools.${k}`)}<Switch checked={tools[k]} onChange={(v) => setTools((x) => ({ ...x, [k]: v }))} />
            </label>
          ))}
        </div>
      </Modal>
      {ov && settingsOpen && <SettingsModal onClose={() => setSettingsOpen(false)} settings={ov.settings} onSaved={() => overview.refetch()} />}
      {ov && plan && <PlanDialog overview={ov} initial={plan === 'new' ? null : plan} onClose={() => setPlan(null)} onApplied={(n) => { tell(n); refresh() }} />}
      {ov && cancel && <CancelDialog overview={ov} initial={cancel === 'new' ? null : cancel} onClose={() => setCancel(null)} onChanged={(n) => { tell(n); refresh() }} />}
      {ov && offday && <OffdayRequestDialog target={offday} overview={ov} onClose={() => setOffday(null)} onDone={() => refresh()} />}
      {inboxOpen && <OffdayInbox onClose={closeInbox} onChanged={refresh} />}
      {weeklyOpen && <WeeklyHolidays canManage={!!ov?.can['calendar.manage']} onClose={() => setWeeklyOpen(false)} onChanged={refresh} />}
      {detailsId && <DetailsModal programId={detailsId} onClose={() => setDetailsId(null)} />}
      {ov && meeting && month.data && <MeetingDialog overview={ov} rooms={month.data.data.rooms} initial={meeting} onClose={() => setMeeting(null)} onBooked={() => { month.refetch(); overview.refetch() }} />}
    </div>
    </SaberActionsContext.Provider>
  )
}

function Segment({ label, items, filters, set }: { label: string; items: [keyof Filters, string][]; filters: Filters; set: (p: Partial<Filters>) => void }) {
  return (
    <div className="flex items-center gap-1 rounded-xl border border-navy-100 bg-white p-0.5" role="group" aria-label={label}>
      {items.map(([k, text]) => {
        const on = filters[k] as boolean
        return <button key={k} type="button" aria-pressed={on} onClick={() => set({ [k]: !on } as Partial<Filters>)} className={clsx('rounded-lg px-2.5 py-1.5 text-xs font-bold transition', on ? 'bg-gold-50 text-navy-900 ring-1 ring-inset ring-gold-300' : 'text-slate-400 line-through decoration-slate-300 hover:text-slate-600')}>{text}</button>
      })}
    </div>
  )
}

type RowProps = { program: Program; overview: Overview; view: 'rows' | 'cards'; open: boolean; onToggle: () => void; onDetails: (id: string) => void; onProgram: (p: Program) => void; onGroup: (g: Group) => void; onAdded: (g: Group) => void; onRemoved: (id: string) => void }

function ProgramRow({ program: p, overview, view, open, onToggle, onDetails, onProgram, onGroup, onAdded, onRemoved }: RowProps) {
  const { t } = useTranslation()
  const [adding, setAdding] = useState(false)
  const can = (k: string) => !!overview.can[k]

  const patch = async (body: Record<string, unknown>) => {
    try { onProgram((await api.patch(`/admin/saber/programs/${p.id}`, body)).data.data) } catch (e) { toast(errorMessage(e), 'error') }
  }
  const addGroup = async () => {
    setAdding(true)
    try { onAdded((await api.post(`/admin/saber/programs/${p.id}/groups`)).data.data); toast(t('saber.group.added')) } catch (e) { toast(errorMessage(e), 'error') } finally { setAdding(false) }
  }
  const complete = p.lights.total > 0 && p.lights.dates === p.lights.total && p.lights.rooms === p.lights.total && p.lights.trainers === p.lights.total

  return (
    <li className={clsx('rounded-2xl border bg-white shadow-sm transition', open ? 'border-gold-300 shadow-md' : 'border-navy-100 hover:border-navy-200', p.cancelled && 'opacity-70')}>
      <div className="flex flex-wrap items-center gap-x-4 gap-y-3 p-4">
        <button type="button" onClick={onToggle} aria-expanded={open} className="flex min-w-0 flex-1 items-center gap-3 text-start">
          <span className={clsx('grid size-9 shrink-0 place-items-center rounded-xl transition', open ? 'bg-navy-900 text-white' : 'bg-ivory text-navy-800')}><ChevronDown className={clsx('size-4 transition', open && 'rotate-180')} /></span>
          <span className="min-w-0">
            <span className="flex items-center gap-2">
              <span className="truncate text-[15px] font-bold text-navy-900">{p.title}</span>
              {p.cancelled && <span className="rounded-full bg-rose-50 px-2 py-0.5 text-[11px] font-bold text-rose-700">{t('saber.program.cancelled')}</span>}
            </span>
            <span className="mt-0.5 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs text-slate-500">
              <span className="font-mono" dir="ltr">{p.code}</span>
              <span>{t('saber.program.hours', { n: fmt.number(p.hours, 1) })}</span>
              <span>{t('saber.program.days', { n: p.days })}</span>
              <span>{t('saber.program.groups', { n: p.groups.length })}</span>
              <span>{p.start_date ? `${fmt.date(p.start_date, { day: 'numeric', month: 'short' })} – ${fmt.date(p.end_date, { day: 'numeric', month: 'short', year: 'numeric' })}` : t('saber.program.noDates')}</span>
            </span>
          </span>
        </button>
        <button type="button" onClick={() => onDetails(p.id)} title={t('saber.details.open')} aria-label={t('saber.details.open')} className="grid size-9 place-items-center rounded-xl text-slate-400 transition hover:bg-ivory hover:text-navy-900"><Info className="size-4" /></button>
        <Lights lights={p.lights} complete={complete} />
        <KitToggle program={p} disabled={!can('saber.group.manage')} onChange={(v) => patch({ kit_ready: v })} />
      </div>

      {open && (
        <div className="space-y-3 border-t border-navy-50 bg-ivory/40 p-4">
          <div className="flex flex-wrap items-center gap-3">
            <label className="flex items-center gap-2 text-xs font-semibold text-slate-500">
              <UserRound className="size-4" />{t('saber.program.supervisor')}
              <select value={p.supervisor?.id ?? ''} disabled={!can('saber.supervisor.assign') || !overview.settings.supervisor_edit_enabled} title={overview.settings.supervisor_edit_enabled ? undefined : t('saber.program.supervisorLocked')}
                onChange={(e) => patch({ coordinator_id: e.target.value || null })} className="h-9 min-w-48 rounded-xl border border-navy-100 bg-white px-2 text-sm font-semibold text-navy-900 disabled:bg-slate-50">
                <option value="">{t('saber.program.noSupervisor')}</option>
                {p.supervisor && !overview.supervisors.some((s) => s.id === p.supervisor?.id) && <option value={p.supervisor.id}>{p.supervisor.name}</option>}
                {overview.supervisors.map((s) => <option key={s.id} value={s.id}>{s.name}</option>)}
              </select>
            </label>
            <label className="flex items-center gap-2 text-xs font-semibold text-slate-500">
              {t('saber.program.daysPerGroup')}
              <input type="number" min={1} max={200} defaultValue={p.days} key={p.days} disabled={!can('saber.group.manage')}
                onBlur={(e) => { const n = Number(e.target.value); if (n && n !== p.days) patch({ training_days: n }) }}
                className="h-9 w-16 rounded-xl border border-navy-100 bg-white text-center text-sm font-bold text-navy-900" />
            </label>
            <SurveyLinks program={p} canEdit={can('saber.survey_links.manage')} onSaved={onProgram} />
            <div className="ms-auto flex items-center gap-2">
              <Link to={`/admin/programs/${p.id}`} className="inline-flex items-center gap-1.5 text-xs font-bold text-navy-700 hover:text-gold-700"><ExternalLink className="size-3.5" />{t('saber.program.open')}</Link>
              {can('saber.group.manage') && !p.cancelled && <Button size="sm" variant="gold" icon={<Plus className="size-4" />} loading={adding} onClick={addGroup}>{t('saber.group.add')}</Button>}
            </div>
          </div>
          {p.groups.map((g) => <GroupPanel key={g.id} program={p} group={g} overview={overview} view={view} onGroup={onGroup} onRemoved={onRemoved} />)}
        </div>
      )}
    </li>
  )
}

function Lights({ lights, complete }: { lights: Program['lights']; complete: boolean }) {
  const { t } = useTranslation()
  const items: [keyof Program['lights'], string][] = [['dates', t('saber.lights.dates')], ['rooms', t('saber.lights.rooms')], ['trainers', t('saber.lights.trainers')]]
  return (
    <div className="flex items-center gap-3">
      {items.map(([k, label]) => {
        const n = lights[k] as number
        const share = lights.total ? n / lights.total : 0
        return (
          <div key={k} className="w-20" title={`${label}: ${t('saber.lights.of', { n, total: lights.total })}`}>
            <div className="mb-1 flex items-baseline justify-between text-[10px] font-bold text-slate-400"><span className="truncate">{label}</span><span className="tabular-nums text-navy-800">{n}/{lights.total}</span></div>
            <div className="h-1.5 overflow-hidden rounded-full bg-navy-50">
              <div className={clsx('h-full rounded-full transition-[width] duration-500', complete ? 'bg-emerald-500' : share >= 1 ? 'bg-emerald-500' : share > 0 ? 'bg-gold-500' : 'bg-transparent')} style={{ width: `${Math.round(share * 100)}%` }} />
            </div>
          </div>
        )
      })}
    </div>
  )
}

function KitToggle({ program, disabled, onChange }: { program: Program; disabled: boolean; onChange: (v: boolean) => void }) {
  const { t } = useTranslation()
  const ready = program.kit_ready
  return (
    <button type="button" disabled={disabled} onClick={() => onChange(!ready)} aria-pressed={ready} title={program.kit_ready_set ? undefined : t('saber.program.kitAuto')}
      className={clsx('inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs font-bold ring-1 ring-inset transition disabled:cursor-default',
        ready ? 'bg-emerald-50 text-emerald-700 ring-emerald-600/20' : 'bg-amber-50 text-amber-700 ring-amber-600/20', !disabled && 'hover:ring-gold-400')}>
      <span className={clsx('size-1.5 rounded-full', ready ? 'bg-emerald-500' : 'bg-amber-500')} />
      {ready ? t('saber.program.kitReady') : t('saber.program.kitNotReady')}
    </button>
  )
}

function Switch({ checked, onChange }: { checked: boolean; onChange: (v: boolean) => void }) {
  return (
    <button type="button" role="switch" aria-checked={checked} onClick={(e) => { e.preventDefault(); onChange(!checked) }}
      className={clsx('relative h-6 w-11 shrink-0 rounded-full transition', checked ? 'bg-navy-900' : 'bg-slate-200')}>
      <span className={clsx('absolute top-0.5 size-5 rounded-full bg-white shadow transition-all', checked ? 'start-[1.375rem]' : 'start-0.5')} />
    </button>
  )
}

function SettingsModal({ onClose, settings, onSaved }: { onClose: () => void; settings: Overview['settings']; onSaved: () => void }) {
  const { t } = useTranslation()
  const [value, setValue] = useState(settings)
  const [saving, setSaving] = useState(false)
  const save = async () => {
    setSaving(true)
    try { await api.put('/admin/saber/settings', value); toast(t('saber.settings.saved')); onSaved(); onClose() } catch (e) { toast(errorMessage(e), 'error') } finally { setSaving(false) }
  }
  const row = (k: keyof Overview['settings'], label: string, hint: string): ReactNode => (
    <label className="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-navy-100 p-3 hover:border-gold-400">
      <span><span className="block text-sm font-bold text-navy-900">{label}</span><span className="mt-0.5 block text-xs text-slate-500">{hint}</span></span>
      <Switch checked={value[k]} onChange={(v) => setValue((x) => ({ ...x, [k]: v }))} />
    </label>
  )
  return (
    <Modal open onClose={onClose} title={t('saber.settings.title')}>
      <div className="space-y-2.5">
        {row('supervisor_edit_enabled', t('saber.settings.supervisor'), t('saber.settings.supervisorHint'))}
        {row('group_delete_enabled', t('saber.settings.delete'), t('saber.settings.deleteHint'))}
      </div>
      <div className="mt-5 flex justify-end gap-2">
        <Button variant="ghost" onClick={onClose}>{t('common.cancel', { defaultValue: 'إلغاء' })}</Button>
        <Button loading={saving} onClick={save}>{t('common.save', { defaultValue: 'حفظ' })}</Button>
      </div>
    </Modal>
  )
}
