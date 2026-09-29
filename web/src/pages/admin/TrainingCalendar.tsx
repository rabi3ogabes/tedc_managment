import clsx from 'clsx'
import { AlertTriangle, BadgeCheck, CalendarDays, CalendarRange, ChevronLeft, ChevronRight, Lock, Pencil, Plus, Table2, Trash2, Unlock } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, StatCard } from '@/components/ui'
import { Pager } from './shared'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { CalendarDayInfo, CalendarKind, CalendarSummary } from '@/lib/types'

type View = 'month' | 'year' | 'table'
type Payload = { data: CalendarDayInfo[]; meta: { from: string; to: string; summary: CalendarSummary } }
type Conflict = { id: string; title: string; program?: string | null; starts_at: string }

/** Local-time YYYY-MM-DD (never goes through UTC, so days do not shift). */
const ymd = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`
const parse = (s: string) => { const [y, m, d] = s.split('-').map(Number); return new Date(y, m - 1, d) }
const addDays = (d: Date, n: number) => new Date(d.getFullYear(), d.getMonth(), d.getDate() + n)

const kindStyle: Record<CalendarKind, { cell: string; dot: string; badge: 'gray' | 'red' | 'amber' | 'blue' | 'green' | 'navy' }> = {
  workday: { cell: 'bg-white hover:border-gold-400', dot: 'bg-emerald-500', badge: 'green' },
  weekend: { cell: 'bg-slate-100/80 text-slate-500 hover:border-slate-300', dot: 'bg-slate-400', badge: 'gray' },
  vacation: { cell: 'bg-red-50 text-red-900 hover:border-red-300', dot: 'bg-red-500', badge: 'red' },
  exam: { cell: 'bg-amber-50 text-amber-900 hover:border-amber-300', dot: 'bg-amber-500', badge: 'amber' },
  normal: { cell: 'bg-sky-50 text-sky-900 hover:border-sky-300', dot: 'bg-sky-500', badge: 'blue' },
}
const kinds: CalendarKind[] = ['workday', 'weekend', 'vacation', 'exam', 'normal']

function useRange(from: string, to: string) {
  return useGet<Payload>('/admin/calendar', { from, to })
}

/** One day of the month grid. */
function DayCell({ day, inMonth, onOpen }: { day: CalendarDayInfo; inMonth: boolean; onOpen: (d: CalendarDayInfo) => void }) {
  const { t } = useTranslation()
  const style = kindStyle[day.kind]
  const isToday = day.date === ymd(new Date())
  const number = parse(day.date).getDate()
  return (
    <button
      type="button"
      onClick={() => onOpen(day)}
      className={clsx('relative flex min-h-[4.75rem] flex-col items-stretch rounded-xl border border-navy-100 p-1.5 text-start transition sm:min-h-24 sm:p-2', style.cell, !inMonth && 'opacity-35', isToday && 'ring-2 ring-gold-500')}
      aria-label={`${day.date} ${t(`mgmt.calendar.kinds.${day.kind}`)}`}
    >
      <span className="flex items-center justify-between">
        <span className={clsx('text-sm font-bold', isToday && 'grid size-6 place-items-center rounded-full bg-navy-900 text-xs text-white')}>{fmt.number(number)}</span>
        <span className="flex items-center gap-1">
          {day.approval && <BadgeCheck className="size-4 text-emerald-600" aria-label={t('mgmt.calendar.approvedBadge')} />}
          {!day.training_allowed && <Lock className="size-3.5 opacity-60" aria-label={t('mgmt.calendar.trainingClosed')} />}
        </span>
      </span>
      {day.entry && <span className="mt-1 line-clamp-2 text-[11px] font-semibold leading-tight">{day.entry.title}</span>}
      {!day.entry && day.kind === 'weekend' && <span className="mt-1 hidden text-[11px] opacity-70 sm:block">{t('mgmt.calendar.kinds.weekend')}</span>}
      {day.sessions_count > 0 && <span className="mt-auto inline-flex w-fit items-center gap-1 rounded-full bg-navy-900 px-1.5 py-0.5 text-[10px] font-bold text-white">{fmt.number(day.sessions_count)}</span>}
    </button>
  )
}

function MonthView({ cursor, onOpen }: { cursor: Date; onOpen: (d: CalendarDayInfo) => void }) {
  const { t } = useTranslation()
  const first = new Date(cursor.getFullYear(), cursor.getMonth(), 1)
  const gridStart = addDays(first, -first.getDay())
  const last = new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0)
  const gridEnd = addDays(last, 6 - last.getDay())
  const { data, isLoading } = useRange(ymd(gridStart), ymd(gridEnd))
  const weekdays = t('mgmt.calendar.weekdays', { returnObjects: true }) as unknown as string[]

  if (isLoading || !data) return <Spinner />
  return (
    <div>
      <div className="grid grid-cols-7 gap-1.5 pb-1.5 sm:gap-2">
        {weekdays.map((w, i) => <div key={w} className={clsx('px-1 text-center text-[11px] font-bold uppercase tracking-wide sm:text-xs', i >= 5 ? 'text-slate-400' : 'text-slate-500')}><span className="hidden sm:inline">{w}</span><span className="sm:hidden">{w.slice(0, 2)}</span></div>)}
      </div>
      <div className="grid grid-cols-7 gap-1.5 sm:gap-2">
        {data.data.map((day) => <DayCell key={day.date} day={day} inMonth={parse(day.date).getMonth() === cursor.getMonth()} onOpen={onOpen} />)}
      </div>
    </div>
  )
}

/** Twelve compact months: quickly see holidays, exams and closed days across the year. */
function YearView({ year, onOpen, onMonth }: { year: number; onOpen: (d: CalendarDayInfo) => void; onMonth: (m: number) => void }) {
  const { t, i18n } = useTranslation()
  const { data, isLoading } = useRange(`${year}-01-01`, `${year}-12-31`)
  const weekdays = t('mgmt.calendar.weekdays', { returnObjects: true }) as unknown as string[]
  const byMonth = useMemo(() => {
    const map: CalendarDayInfo[][] = Array.from({ length: 12 }, () => [])
    data?.data.forEach((d) => map[parse(d.date).getMonth()].push(d))
    return map
  }, [data])
  if (isLoading || !data) return <Spinner />
  return (
    <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
      {byMonth.map((days, month) => {
        const offset = parse(days[0].date).getDay()
        return (
          <div key={month} className="rounded-2xl border border-navy-100 bg-white p-3">
            <button type="button" onClick={() => onMonth(month)} className="mb-2 w-full text-start text-sm font-bold text-navy-900 hover:text-link">{new Intl.DateTimeFormat(i18n.language === 'en' ? 'en' : 'ar-QA-u-nu-latn', { month: 'long' }).format(new Date(year, month, 1))}</button>
            <div className="grid grid-cols-7 gap-1 text-center text-[10px] text-slate-400">{weekdays.map((w) => <span key={w}>{w.slice(0, 1)}</span>)}</div>
            <div className="mt-1 grid grid-cols-7 gap-1">
              {Array.from({ length: offset }, (_, i) => <span key={`b${i}`} />)}
              {days.map((d) => (
                <button key={d.date} type="button" onClick={() => onOpen(d)} title={`${d.date} · ${d.entry?.title ?? t(`mgmt.calendar.kinds.${d.kind}`)}`}
                  className={clsx('relative grid aspect-square place-items-center rounded-md text-[10px] font-semibold transition hover:ring-2 hover:ring-gold-400', {
                    'bg-slate-50 text-slate-700': d.kind === 'workday', 'bg-slate-200/70 text-slate-500': d.kind === 'weekend', 'bg-red-200 text-red-900': d.kind === 'vacation',
                    'bg-amber-200 text-amber-900': d.kind === 'exam', 'bg-sky-200 text-sky-900': d.kind === 'normal', 'ring-2 ring-emerald-500': !!d.approval,
                  })}>
                  {parse(d.date).getDate()}
                </button>
              ))}
            </div>
          </div>
        )
      })}
    </div>
  )
}

function TableView({ onOpen }: { onOpen: (d: CalendarDayInfo) => void }) {
  const { t, i18n } = useTranslation()
  const [from, setFrom] = useState(ymd(new Date()))
  const [to, setTo] = useState(ymd(addDays(new Date(), 364)))
  const [kind, setKind] = useState('')
  const [workdays, setWorkdays] = useState(false)
  const [page, setPage] = useState(1)
  const tooLong = (parse(to).getTime() - parse(from).getTime()) / 86_400_000 >= 800
  const { data, isLoading } = useGet<Payload>(!tooLong && from <= to ? '/admin/calendar' : null, { from, to })
  const weekdays = t('mgmt.calendar.weekdays', { returnObjects: true }) as unknown as string[]
  const rows = useMemo(() => (data?.data ?? []).filter((d) => (kind ? d.kind === kind : workdays || d.kind !== 'workday')), [data, kind, workdays])
  const perPage = 15
  const last = Math.max(1, Math.ceil(rows.length / perPage))
  const shown = rows.slice((page - 1) * perPage, page * perPage)
  const reset = () => setPage(1)

  return (
    <Card padded={false}>
      <div className="grid gap-3 border-b border-navy-100 p-4 sm:grid-cols-2 xl:grid-cols-4">
        <Field label={t('mgmt.calendar.tableFrom')}><input type="date" className="input" value={from} onChange={(e) => { setFrom(e.target.value); reset() }} /></Field>
        <Field label={t('mgmt.calendar.tableTo')}><input type="date" className="input" value={to} onChange={(e) => { setTo(e.target.value); reset() }} /></Field>
        <Field label={t('mgmt.calendar.kindFilter')}>
          <select className="input" value={kind} onChange={(e) => { setKind(e.target.value); reset() }}>
            <option value="">{t('mgmt.common.all')}</option>
            {kinds.map((k) => <option key={k} value={k}>{t(`mgmt.calendar.kinds.${k}`)}</option>)}
          </select>
        </Field>
        <label className="flex items-end gap-2 pb-2.5 text-sm text-navy-800"><input type="checkbox" className="size-4 accent-gold-600" checked={workdays} onChange={(e) => { setWorkdays(e.target.checked); reset() }} />{t('mgmt.calendar.includeWorkdays')}</label>
      </div>
      {tooLong && <div className="m-4 rounded-xl bg-amber-50 p-3 text-sm text-amber-800">{t('mgmt.calendar.rangeLimit')}</div>}
      {isLoading ? <Spinner /> : !shown.length ? <Empty text={t('mgmt.calendar.noRows')} /> : (
        <div className="overflow-x-auto">
          <table className="w-full text-sm">
            <thead><tr className="border-b border-navy-100 bg-ivory/60 text-xs font-semibold uppercase tracking-wide text-slate-500">
              {[t('mgmt.calendar.date'), t('mgmt.calendar.kindFilter'), t('mgmt.common.name'), t('mgmt.calendar.trainingStatus'), t('mgmt.calendar.sessions'), t('mgmt.calendar.notes')].map((h) => <th key={h} className="whitespace-nowrap px-3 py-3 text-start">{h}</th>)}
            </tr></thead>
            <tbody className="divide-y divide-navy-100/70">
              {shown.map((d) => (
                <tr key={d.date} onClick={() => onOpen(d)} className="cursor-pointer transition hover:bg-ivory/70">
                  <td className="whitespace-nowrap px-3 py-3"><div className="font-semibold text-navy-900">{fmt.date(d.date, { day: 'numeric', month: 'short', year: 'numeric' })}</div><div className="text-xs text-slate-400">{weekdays[d.weekday]}</div></td>
                  <td className="px-3 py-3"><Badge color={kindStyle[d.kind].badge}>{t(`mgmt.calendar.kinds.${d.kind}`)}</Badge></td>
                  <td className="px-3 py-3 font-medium text-navy-900">{d.entry?.title ?? (d.kind === 'weekend' ? t('mgmt.calendar.weekendNote') : '—')}</td>
                  <td className="px-3 py-3">
                    {d.approval ? <Badge color="green"><BadgeCheck className="size-3" />{t('mgmt.calendar.approvedBadge')}</Badge> : d.training_allowed ? <Badge color="green"><Unlock className="size-3" />{t('mgmt.calendar.trainingOpen')}</Badge> : <Badge color="red"><Lock className="size-3" />{t('mgmt.calendar.trainingClosed')}</Badge>}
                  </td>
                  <td className="px-3 py-3">{d.sessions_count ? <Badge color="navy">{fmt.number(d.sessions_count)}</Badge> : '—'}</td>
                  <td className="max-w-xs truncate px-3 py-3 text-slate-500" title={d.entry?.notes ?? undefined}>{d.entry?.notes ?? '—'}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      )}
      <div className="flex items-center justify-between px-4 py-2 text-xs text-slate-500"><span>{t('mgmt.common.results', { count: rows.length })}</span><span className="hidden">{i18n.language}</span></div>
      <Pager page={page} last={last} onChange={setPage} />
    </Card>
  )
}

/** Add or edit a vacation / exam / restricted day (or a whole period). */
function DayForm({ day, date, onClose, onSaved }: { day: CalendarDayInfo['entry']; date: string; onClose: () => void; onSaved: () => void }) {
  const { t } = useTranslation()
  const [form, setForm] = useState({ type: (day?.type ?? 'vacation') as 'vacation' | 'exam' | 'normal', date, end_date: '', title_ar: day?.title_ar ?? '', title_en: day?.title_en ?? '', notes: day?.notes ?? '' })
  const [saving, setSaving] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [conflicts, setConflicts] = useState<Conflict[] | null>(null)
  const set = <K extends keyof typeof form>(k: K, v: (typeof form)[K]) => setForm((f) => ({ ...f, [k]: v }))

  const save = async () => {
    setSaving(true)
    setError(null)
    try {
      const body = { ...form, end_date: form.end_date || undefined, notes: form.notes || null }
      const res = day ? await api.put(`/admin/calendar/days/${day.id}`, body) : await api.post('/admin/calendar/days', body)
      const found = (res.data?.meta?.conflicting_sessions ?? []) as Conflict[]
      if (found.length) { setConflicts(found); onSaved() } else { onSaved(); onClose() }
    } catch (e) {
      setError(errorMessage(e))
    } finally {
      setSaving(false)
    }
  }

  return (
    <Modal open onClose={onClose} title={day ? t('mgmt.calendar.editTitle') : t('mgmt.calendar.addTitle')}>
      {conflicts ? (
        <div className="space-y-4">
          <div className="flex gap-3 rounded-xl bg-amber-50 p-4 text-amber-900"><AlertTriangle className="size-5 shrink-0" /><div><div className="font-bold">{t('mgmt.calendar.conflicts')}</div><div className="text-sm">{t('mgmt.calendar.conflictHint')}</div></div></div>
          <ul className="space-y-1.5 text-sm">{conflicts.map((c) => <li key={c.id} className="flex justify-between gap-3 rounded-lg border border-navy-100 px-3 py-2"><span className="truncate font-semibold">{c.program ?? c.title}</span><span className="shrink-0 text-xs text-slate-500">{fmt.date(c.starts_at, { day: 'numeric', month: 'short' })} {fmt.time(c.starts_at)}</span></li>)}</ul>
          <div className="flex justify-end"><Button onClick={onClose}>{t('mgmt.common.close')}</Button></div>
        </div>
      ) : (
        <div className="space-y-4">
          <div className="grid gap-2">
            {(['vacation', 'exam', 'normal'] as const).map((type) => (
              <button key={type} type="button" aria-pressed={form.type === type} onClick={() => set('type', type)} className={clsx('flex items-start gap-3 rounded-xl border p-3 text-start transition', form.type === type ? 'border-navy-900 bg-navy-900/5 ring-2 ring-navy-900/10' : 'border-navy-100 hover:border-gold-400')}>
                <span className={clsx('mt-1 size-3 shrink-0 rounded-full', kindStyle[type].dot)} />
                <span><span className="block text-sm font-bold text-navy-900">{t(`mgmt.calendar.types.${type}`)}</span><span className="block text-xs text-slate-500">{t(`mgmt.calendar.typeHelp.${type}`)}</span></span>
              </button>
            ))}
          </div>
          <div className="grid gap-4 sm:grid-cols-2">
            <Field label={t('mgmt.calendar.date')}><input type="date" className="input" value={form.date} disabled={!!day} onChange={(e) => set('date', e.target.value)} /></Field>
            {!day && <Field label={t('mgmt.calendar.endDate')} hint={t('mgmt.calendar.endDateHint')}><input type="date" className="input" min={form.date} value={form.end_date} onChange={(e) => set('end_date', e.target.value)} /></Field>}
            <Field label={t('mgmt.calendar.titleAr')}><input dir="rtl" className="input" value={form.title_ar} onChange={(e) => set('title_ar', e.target.value)} /></Field>
            <Field label={t('mgmt.calendar.titleEn')}><input dir="ltr" className="input" value={form.title_en} onChange={(e) => set('title_en', e.target.value)} /></Field>
          </div>
          <Field label={t('mgmt.calendar.notes')}><textarea rows={2} className="input" value={form.notes} onChange={(e) => set('notes', e.target.value)} /></Field>
          {error && <div className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
          <div className="flex justify-end gap-2">
            <Button variant="ghost" onClick={onClose}>{t('mgmt.common.cancel')}</Button>
            <Button loading={saving} disabled={!form.date || !form.title_ar.trim() || !form.title_en.trim()} onClick={save}>{saving ? t('mgmt.common.saving') : t('mgmt.common.save')}</Button>
          </div>
        </div>
      )}
    </Modal>
  )
}

/** Everything about one day: status, approval, and the actions the user is allowed to take. */
function DayDetails({ day, canManage, canApprove, onClose, onEdit, onMark, onChanged }: { day: CalendarDayInfo; canManage: boolean; canApprove: boolean; onClose: () => void; onEdit: () => void; onMark: () => void; onChanged: () => void }) {
  const { t } = useTranslation()
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const weekdays = t('mgmt.calendar.weekdays', { returnObjects: true }) as unknown as string[]

  const run = async (fn: () => Promise<unknown>) => {
    setBusy(true)
    setError(null)
    try { await fn(); onChanged(); onClose() } catch (e) { setError(errorMessage(e)) } finally { setBusy(false) }
  }
  const approve = () => run(() => api.post('/admin/calendar/approvals', { date: day.date, reason }))
  const revoke = () => run(() => api.delete(`/admin/calendar/approvals/${day.date}`))
  const remove = () => window.confirm(t('mgmt.calendar.confirmDelete')) && run(() => api.delete(`/admin/calendar/days/${day.entry!.id}`))

  return (
    <Modal open onClose={onClose} title={t('mgmt.calendar.dayDetails')}>
      <div className="space-y-4">
        <div className="flex items-center gap-3">
          <span className={clsx('grid size-14 place-items-center rounded-2xl text-xl font-bold', kindStyle[day.kind].cell.split(' ')[0], 'ring-1 ring-navy-100')}>{fmt.number(parse(day.date).getDate())}</span>
          <div>
            <div className="font-bold text-navy-900">{fmt.date(day.date)}</div>
            <div className="text-sm text-slate-500">{weekdays[day.weekday]}</div>
          </div>
          <Badge className="ms-auto" color={kindStyle[day.kind].badge}>{t(`mgmt.calendar.kinds.${day.kind}`)}</Badge>
        </div>

        {day.entry ? (
          <div className="rounded-xl border border-navy-100 p-3">
            <div className="font-semibold text-navy-900">{day.entry.title}</div>
            {day.entry.notes && <p className="mt-1 text-sm text-slate-500">{day.entry.notes}</p>}
          </div>
        ) : <p className="text-sm text-slate-500">{day.kind === 'weekend' ? t('mgmt.calendar.weekendNote') : t('mgmt.calendar.noEntry')}</p>}

        <div className={clsx('flex items-start gap-3 rounded-xl p-3', day.training_allowed ? 'bg-emerald-50 text-emerald-900' : 'bg-red-50 text-red-900')}>
          {day.training_allowed ? <Unlock className="mt-0.5 size-5 shrink-0" /> : <Lock className="mt-0.5 size-5 shrink-0" />}
          <div className="text-sm">
            <div className="font-bold">{day.approval ? t('mgmt.calendar.approvedBadge') : day.training_allowed ? t('mgmt.calendar.trainingOpen') : t('mgmt.calendar.trainingClosed')}</div>
            {day.approval && <div className="mt-1 text-xs opacity-90">{t('mgmt.calendar.reasonLabel')}: {day.approval.reason}{day.approval.approved_by && ` · ${t('mgmt.calendar.approvedBy')} ${day.approval.approved_by}`}{day.approval.approved_at && ` ${t('mgmt.calendar.approvedAt')} ${fmt.date(day.approval.approved_at, { day: 'numeric', month: 'short', year: 'numeric' })}`}</div>}
          </div>
        </div>
        {day.sessions_count > 0 && <div className="text-sm text-navy-800">{t('mgmt.calendar.sessionsCount', { count: day.sessions_count })}</div>}

        {canApprove && day.requires_approval && (
          <div className="space-y-2 rounded-xl border border-dashed border-gold-400 p-3">
            <Field label={t('mgmt.calendar.approveReason')} hint={t('mgmt.calendar.approveHint')}><textarea rows={2} className="input" value={reason} onChange={(e) => setReason(e.target.value)} /></Field>
            <Button variant="gold" size="sm" loading={busy} disabled={reason.trim().length < 3} icon={<BadgeCheck className="size-4" />} onClick={approve}>{t('mgmt.calendar.approveButton')}</Button>
          </div>
        )}
        {error && <div className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
        <div className="flex flex-wrap justify-end gap-2">
          {canApprove && day.approval && <Button variant="outline" size="sm" loading={busy} onClick={revoke}>{t('mgmt.calendar.revoke')}</Button>}
          {canManage && day.entry && <Button variant="ghost" size="sm" icon={<Trash2 className="size-4" />} onClick={remove}>{t('mgmt.common.delete')}</Button>}
          {canManage && day.entry && <Button variant="outline" size="sm" icon={<Pencil className="size-4" />} onClick={onEdit}>{t('mgmt.common.edit')}</Button>}
          {canManage && !day.entry && <Button variant="primary" size="sm" icon={<Plus className="size-4" />} onClick={onMark}>{t('mgmt.calendar.add')}</Button>}
        </div>
      </div>
    </Modal>
  )
}

export default function TrainingCalendar() {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const canManage = can('calendar.manage')
  const canApprove = can('calendar.approve')
  const [view, setView] = useState<View>('month')
  const [cursor, setCursor] = useState(() => new Date(new Date().getFullYear(), new Date().getMonth(), 1))
  const [open, setOpen] = useState<CalendarDayInfo | null>(null)
  const [form, setForm] = useState<{ entry: CalendarDayInfo['entry']; date: string } | null>(null)
  const [version, setVersion] = useState(0)

  const year = cursor.getFullYear()
  const from = view === 'year' ? `${year}-01-01` : ymd(new Date(cursor.getFullYear(), cursor.getMonth(), 1))
  const to = view === 'year' ? `${year}-12-31` : ymd(new Date(cursor.getFullYear(), cursor.getMonth() + 1, 0))
  const summary = useRange(from, to)
  const s = summary.data?.meta.summary
  const refresh = () => { setVersion((v) => v + 1); summary.refetch() }
  const shift = (n: number) => setCursor((c) => (view === 'year' ? new Date(c.getFullYear() + n, c.getMonth(), 1) : new Date(c.getFullYear(), c.getMonth() + n, 1)))
  const title = view === 'year' ? fmt.number(year) : new Intl.DateTimeFormat(i18n.language === 'en' ? 'en' : 'ar-QA-u-nu-latn', { month: 'long', year: 'numeric' }).format(cursor)
  const Prev = i18n.language === 'ar' ? ChevronRight : ChevronLeft
  const Next = i18n.language === 'ar' ? ChevronLeft : ChevronRight

  return (
    <>
      <PageHeader title={t('mgmt.calendar.title')} subtitle={t('mgmt.calendar.subtitle')} actions={canManage && <Button variant="gold" icon={<Plus className="size-4" />} onClick={() => setForm({ entry: null, date: ymd(new Date()) })}>{t('mgmt.calendar.add')}</Button>} />

      {view !== 'table' && (
        <div className="mb-5 grid grid-cols-2 gap-3 lg:grid-cols-5">
          <StatCard label={t('mgmt.calendar.working')} value={fmt.number(s?.working ?? 0)} icon={<CalendarDays className="size-5" />} />
          <StatCard label={t('mgmt.calendar.off')} value={fmt.number(s?.off ?? 0)} icon={<CalendarRange className="size-5" />} />
          <StatCard label={t('mgmt.calendar.open')} value={fmt.number(s?.open_for_training ?? 0)} icon={<Unlock className="size-5" />} accent />
          <StatCard label={t('mgmt.calendar.approved')} value={fmt.number(s?.approved ?? 0)} icon={<BadgeCheck className="size-5" />} />
          <StatCard label={t('mgmt.calendar.sessions')} value={fmt.number(s?.sessions ?? 0)} icon={<Table2 className="size-5" />} />
        </div>
      )}

      <Card>
        <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
          {view !== 'table' ? (
            <div className="flex items-center gap-2">
              <Button variant="outline" size="sm" aria-label={t('mgmt.calendar.prev')} onClick={() => shift(-1)}><Prev className="size-4" /></Button>
              <h2 className="min-w-40 text-center text-lg font-bold text-navy-900">{title}</h2>
              <Button variant="outline" size="sm" aria-label={t('mgmt.calendar.next')} onClick={() => shift(1)}><Next className="size-4" /></Button>
              <Button variant="ghost" size="sm" onClick={() => setCursor(new Date(new Date().getFullYear(), new Date().getMonth(), 1))}>{t('mgmt.calendar.today')}</Button>
            </div>
          ) : <span />}
          <div className="inline-flex rounded-xl border border-navy-100 bg-white p-1 shadow-sm" role="radiogroup">
            {([['month', CalendarDays], ['year', CalendarRange], ['table', Table2]] as const).map(([id, Icon]) => (
              <button key={id} type="button" role="radio" aria-checked={view === id} onClick={() => setView(id)} className={clsx('inline-flex items-center gap-1.5 rounded-lg px-3 py-1.5 text-xs font-bold transition', view === id ? 'bg-navy-900 text-white shadow' : 'text-slate-500 hover:text-navy-900')}>
                <Icon className="size-4" /><span>{t(`mgmt.calendar.views.${id}`)}</span>
              </button>
            ))}
          </div>
        </div>

        <div className="mb-4 flex flex-wrap items-center gap-x-4 gap-y-1.5 text-xs text-slate-500" aria-label={t('mgmt.calendar.legend')}>
          {kinds.map((k) => <span key={k} className="inline-flex items-center gap-1.5"><span className={clsx('size-2.5 rounded-full', kindStyle[k].dot)} />{t(`mgmt.calendar.kinds.${k}`)}</span>)}
          <span className="inline-flex items-center gap-1.5"><BadgeCheck className="size-3.5 text-emerald-600" />{t('mgmt.calendar.approvedBadge')}</span>
          <span className="inline-flex items-center gap-1.5"><Lock className="size-3.5" />{t('mgmt.calendar.trainingClosed')}</span>
        </div>

        <div key={version}>
          {view === 'month' && <MonthView cursor={cursor} onOpen={setOpen} />}
          {view === 'year' && <YearView year={year} onOpen={setOpen} onMonth={(m) => { setCursor(new Date(year, m, 1)); setView('month') }} />}
          {view === 'table' && <TableView onOpen={setOpen} />}
        </div>
        {!canManage && <p className="mt-4 text-xs text-slate-400">{t('mgmt.calendar.permissionNote')}</p>}
      </Card>

      {open && !form && (
        <DayDetails day={open} canManage={canManage} canApprove={canApprove} onClose={() => setOpen(null)} onChanged={refresh}
          onEdit={() => { setForm({ entry: open.entry, date: open.date }); setOpen(null) }} onMark={() => { setForm({ entry: null, date: open.date }); setOpen(null) }} />
      )}
      {form && <DayForm day={form.entry} date={form.date} onClose={() => setForm(null)} onSaved={refresh} />}
    </>
  )
}
