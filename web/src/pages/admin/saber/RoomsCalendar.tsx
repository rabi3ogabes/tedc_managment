import clsx from 'clsx'
import { CalendarOff, ChevronLeft, ChevronRight, DoorClosed, DoorOpen, LayoutGrid, Plus, Rows3 } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui'
import { fmt } from '@/lib/format'
import BookingCard from './BookingCard'
import { roomState, slotsOf, STATE_STYLE, isoToday, type MonthData, type MonthDay, type MonthEvent, type MonthRoom, type RoomState } from './rooms'
import type { Overview } from './types'

type RoomFilter = 'all' | 'free' | 'booked'
type StatusFilter = 'all' | 'available' | 'booked'

/**
 * «تقويم القاعات» (C3): the month of every room — each day shows how many rooms are taken; a day opens its rooms with the hour slots and what
 * holds them (training days and bookings, edited or cancelled in place). Filters by room state and day state, calendar or rows view, legend.
 */
export default function RoomsCalendar({ data, overview, cursor, onCursor, onBook, onChanged }: {
  data: MonthData; overview: Overview; cursor: string; onCursor: (ym: string) => void; onBook: (roomId?: string, date?: string) => void; onChanged: () => void
}) {
  const { t } = useTranslation()
  const [selected, setSelected] = useState<string | null>(() => (isoToday().startsWith(data.month) ? isoToday() : null))
  const [roomFilter, setRoomFilter] = useState<RoomFilter>('all')
  const [statusFilter, setStatusFilter] = useState<StatusFilter>('all')
  const [view, setView] = useState<'calendar' | 'rows'>('calendar')
  const { first, last } = data.hours
  const byDay = useMemo(() => {
    const m = new Map<string, MonthEvent[]>()
    data.events.forEach((e) => m.set(e.date, [...(m.get(e.date) ?? []), e]))
    return m
  }, [data.events])
  const dayOf = useMemo(() => new Map(data.days.map((d) => [d.date, d])), [data.days])
  const bookedRooms = (date: string) => new Set((byDay.get(date) ?? []).map((e) => e.room_id)).size
  const total = data.rooms.length

  const shift = (n: number) => { const [y, m] = cursor.split('-').map(Number); const d = new Date(y, m - 1 + n, 1); onCursor(`${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`) }
  const [y, m] = cursor.split('-').map(Number)
  const lead = new Date(y, m - 1, 1).getDay()   // the week starts on Sunday
  const cells: (MonthDay | null)[] = [...Array.from({ length: lead }, () => null), ...data.days]
  const weekdays = t('saber.weekdays', { returnObjects: true }) as string[]
  const matches = (d: MonthDay) => statusFilter === 'all' || (statusFilter === 'booked' ? bookedRooms(d.date) > 0 : d.events_allowed && bookedRooms(d.date) < total)

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-2 rounded-2xl border border-navy-100 bg-white p-3 shadow-sm">
        <div className="flex items-center gap-1">
          <Button size="sm" variant="outline" icon={<ChevronLeft className="size-4 rtl:rotate-180" />} onClick={() => shift(-1)}>{t('saber.calendar.prev')}</Button>
          <Button size="sm" variant="ghost" onClick={() => { onCursor(isoToday().slice(0, 7)); setSelected(isoToday()) }}>{t('saber.calendar.today')}</Button>
          <Button size="sm" variant="outline" onClick={() => shift(1)}>{t('saber.calendar.next')}<ChevronRight className="size-4 rtl:rotate-180" /></Button>
        </div>
        <h3 className="mx-2 text-lg font-bold text-navy-900">{fmt.date(`${cursor}-01`, { month: 'long', year: 'numeric' })}</h3>
        <div className="ms-auto flex flex-wrap items-center gap-2">
          <Segmented label={t('saber.calendar.roomsHint')} value={roomFilter} onChange={setRoomFilter} items={[['all', t('saber.calendar.rooms.all'), LayoutGrid], ['free', t('saber.calendar.rooms.free'), DoorOpen], ['booked', t('saber.calendar.rooms.booked'), DoorClosed]]} />
          <Segmented label={t('saber.calendar.legend')} value={statusFilter} onChange={setStatusFilter} items={[['all', t('saber.calendar.status.all')], ['available', t('saber.calendar.status.available')], ['booked', t('saber.calendar.status.booked')]]} />
          <Segmented label={t('saber.view.label')} value={view} onChange={setView} items={[['calendar', t('saber.calendar.view.calendar'), LayoutGrid], ['rows', t('saber.calendar.view.rows'), Rows3]]} />
        </div>
      </div>

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
        <div className="rounded-2xl border border-navy-100 bg-white p-3 shadow-sm">
          {view === 'calendar' ? (
            <>
              <div className="mb-1 grid grid-cols-7 gap-1 text-center text-[11px] font-bold text-slate-400">{weekdays.map((d) => <div key={d}>{d}</div>)}</div>
              <div className="grid grid-cols-7 gap-1">
                {cells.map((d, i) => d ? (
                  <DayCell key={d.date} day={d} booked={bookedRooms(d.date)} total={total} active={selected === d.date} dim={!matches(d)} events={byDay.get(d.date) ?? []} onClick={() => setSelected(d.date)} />
                ) : <div key={`x${i}`} />)}
              </div>
            </>
          ) : (
            <ul className="max-h-[38rem] divide-y divide-navy-50 overflow-y-auto">
              {data.days.filter(matches).map((d) => (
                <li key={d.date}>
                  <button type="button" onClick={() => setSelected(d.date)} className={clsx('flex w-full items-center gap-3 px-2 py-2 text-start text-sm transition', selected === d.date ? 'bg-gold-50' : 'hover:bg-ivory')}>
                    <span className="w-40 font-semibold text-navy-900">{fmt.date(d.date, { weekday: 'long', day: 'numeric', month: 'short' })}</span>
                    {!d.events_allowed ? <span className="text-xs text-slate-400">{d.label ?? t(`saber.kinds.${d.kind}`, { defaultValue: d.kind })}</span>
                      : <span className="text-xs text-slate-500">{t('saber.calendar.booked', { n: bookedRooms(d.date), total })}</span>}
                    <span className="ms-auto flex gap-1">{(byDay.get(d.date) ?? []).slice(0, 6).map((e) => <span key={e.id} className={clsx('size-2 rounded-full', e.type === 'booking' ? 'bg-amber-500' : 'bg-navy-700')} style={e.color ? { background: e.color } : undefined} />)}</span>
                  </button>
                </li>
              ))}
            </ul>
          )}
          <Legend />
        </div>

        <DayPanel date={selected} day={selected ? dayOf.get(selected) : undefined} rooms={data.rooms} events={selected ? byDay.get(selected) ?? [] : []} first={first} last={last}
          filter={roomFilter} overview={overview} onBook={onBook} onChanged={onChanged} />
      </div>
    </div>
  )
}

function DayCell({ day, booked, total, active, dim, events, onClick }: { day: MonthDay; booked: number; total: number; active: boolean; dim: boolean; events: MonthEvent[]; onClick: () => void }) {
  const { t } = useTranslation()
  const closed = !day.events_allowed
  const today = day.date === isoToday()
  const share = total ? booked / total : 0
  return (
    <button type="button" onClick={onClick} aria-pressed={active}
      className={clsx('relative flex min-h-20 flex-col rounded-xl border p-1.5 text-start transition', active ? 'border-gold-400 ring-2 ring-gold-300' : 'border-navy-50 hover:border-gold-300',
        closed ? (day.kind === 'weekend' ? 'bg-slate-50' : 'bg-slate-100') : 'bg-white', dim && 'opacity-35')}>
      <span className={clsx('grid size-6 place-items-center rounded-full text-xs font-bold tabular-nums', today ? 'bg-navy-900 text-white' : 'text-navy-900')}>{Number(day.date.slice(8))}</span>
      {closed ? (
        <span className="mt-auto line-clamp-2 text-[10px] font-semibold text-slate-400"><CalendarOff className="me-0.5 inline size-3" />{day.label ?? t(`saber.kinds.${day.kind}`, { defaultValue: day.kind })}</span>
      ) : (
        <>
          {day.label && day.kind !== 'workday' && <span className="truncate text-[10px] text-emerald-700">{day.label}</span>}
          <span className="mt-auto flex items-center gap-1">
            <span className="h-1.5 flex-1 overflow-hidden rounded-full bg-navy-50"><span className={clsx('block h-full rounded-full', share >= 1 ? 'bg-rose-500' : share > 0 ? 'bg-amber-500' : 'bg-emerald-400')} style={{ width: `${Math.max(share * 100, booked ? 8 : 0)}%` }} /></span>
            <span className="text-[10px] font-bold tabular-nums text-slate-500">{booked}/{total}</span>
          </span>
          <span className="mt-1 flex gap-0.5">{events.slice(0, 5).map((e) => <span key={e.id} className={clsx('h-1 flex-1 rounded-full', e.type === 'booking' ? 'bg-amber-400' : 'bg-navy-600')} style={e.color ? { background: e.color } : undefined} />)}</span>
        </>
      )}
    </button>
  )
}

function DayPanel({ date, day, rooms, events, first, last, filter, overview, onBook, onChanged }: {
  date: string | null; day: MonthDay | undefined; rooms: MonthRoom[]; events: MonthEvent[]; first: number; last: number; filter: RoomFilter; overview: Overview
  onBook: (roomId?: string, date?: string) => void; onChanged: () => void
}) {
  const { t } = useTranslation()
  if (!date) return <div className="grid place-items-center rounded-2xl border border-dashed border-navy-100 bg-white p-8 text-sm text-slate-400">{t('saber.calendar.pickDay')}</div>
  const list = rooms.map((r) => ({ room: r, events: events.filter((e) => e.room_id === r.id) }))
    .filter(({ events: ev }) => filter === 'all' || (filter === 'booked' ? ev.length > 0 : ev.length === 0))
  const canBook = !!(overview.can['rooms.book'] || overview.can['saber.program.create'] || overview.can['rooms.manage'])
  return (
    <div className="flex max-h-[44rem] flex-col rounded-2xl border border-navy-100 bg-white shadow-sm">
      <header className="flex items-center gap-2 border-b border-navy-50 px-4 py-3">
        <div className="min-w-0 flex-1">
          <h4 className="truncate font-bold text-navy-900">{t('saber.calendar.dayTitle', { date: fmt.date(date, { weekday: 'long', day: 'numeric', month: 'long' }) })}</h4>
          {day && !day.events_allowed && <p className="text-xs text-slate-500">{t('saber.calendar.closed')} · {day.label ?? t(`saber.kinds.${day.kind}`, { defaultValue: day.kind })}</p>}
          {day && day.events_allowed && !day.training_allowed && <p className="text-xs text-emerald-700">{day.label} · {t('saber.calendar.allowEvents')}</p>}
        </div>
        {canBook && day?.events_allowed && <Button size="sm" variant="gold" icon={<Plus className="size-4" />} onClick={() => onBook(undefined, date)}>{t('saber.meeting.open')}</Button>}
      </header>
      <ul className="flex-1 space-y-2 overflow-y-auto p-3">
        {list.length === 0 && <li className="py-6 text-center text-sm text-slate-400">{t('saber.calendar.noRooms')}</li>}
        {list.map(({ room, events: ev }) => {
          const slots = slotsOf(ev, first, last)
          const state: RoomState = roomState(day, ev, first, last)
          return (
            <li key={room.id} className="rounded-xl border border-navy-100 p-2.5">
              <div className="mb-2 flex items-center gap-2">
                <span className="min-w-0 flex-1">
                  <span className="block truncate text-sm font-bold text-navy-900">{room.name}</span>
                  <span className="block truncate text-[11px] text-slate-400">{[t(`saber.rooms.types.${room.type}`), room.tag, room.capacity ? t('saber.rooms.capacity', { n: room.capacity }) : null, room.building].filter(Boolean).join(' · ')}</span>
                </span>
                <span className={clsx('rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset', STATE_STYLE[state])}>{t(`saber.calendar.legendItems.${state === 'closed' ? 'off' : state}`)}</span>
                {canBook && day?.events_allowed && !slots.every(Boolean) && <button type="button" onClick={() => onBook(room.id, date)} aria-label={t('saber.meeting.open')} title={t('saber.meeting.open')} className="grid size-7 place-items-center rounded-lg text-slate-400 hover:bg-gold-50 hover:text-navy-900"><Plus className="size-4" /></button>}
              </div>
              <div className="mb-2 grid gap-0.5" style={{ gridTemplateColumns: `repeat(${slots.length}, minmax(0, 1fr))` }} title={t('saber.calendar.hoursBooked', { n: slots.filter(Boolean).length, total: slots.length })}>
                {slots.map((busy, i) => <span key={i} className={clsx('flex h-5 items-center justify-center rounded text-[9px] font-bold tabular-nums', busy ? 'bg-amber-400/80 text-white' : 'bg-emerald-50 text-emerald-700')} dir="ltr">{first + i}</span>)}
              </div>
              {ev.length === 0 ? <p className="text-[11px] text-slate-400">{t('saber.calendar.free')}</p> : <div className="space-y-1.5">{ev.map((e) => <BookingCard key={e.id} event={e} overview={overview} onChanged={onChanged} />)}</div>}
            </li>
          )
        })}
      </ul>
    </div>
  )
}

function Legend() {
  const { t } = useTranslation()
  const items: [string, string][] = [['free', 'bg-emerald-400'], ['partial', 'bg-amber-500'], ['full', 'bg-rose-500'], ['off', 'bg-slate-300'], ['events', 'bg-emerald-700']]
  return (
    <div className="mt-3 flex flex-wrap items-center gap-3 border-t border-navy-50 pt-3 text-[11px] text-slate-500">
      <span className="font-bold">{t('saber.calendar.legend')}:</span>
      {items.map(([k, c]) => <span key={k} className="inline-flex items-center gap-1.5"><span className={clsx('size-2.5 rounded-full', c)} />{t(`saber.calendar.legendItems.${k}`)}</span>)}
    </div>
  )
}

function Segmented<T extends string>({ label, value, onChange, items }: { label: string; value: T; onChange: (v: T) => void; items: [T, string, React.ComponentType<{ className?: string }>?][] }) {
  return (
    <div className="flex rounded-xl border border-navy-100 bg-white p-0.5" role="group" aria-label={label} title={label}>
      {items.map(([id, text, Icon]) => (
        <button key={id} type="button" aria-pressed={value === id} onClick={() => onChange(id)} className={clsx('flex items-center gap-1 rounded-lg px-2.5 py-1.5 text-xs font-bold transition', value === id ? 'bg-navy-900 text-white' : 'text-slate-500 hover:text-navy-900')}>
          {Icon && <Icon className="size-3.5" />}{text}
        </button>
      ))}
    </div>
  )
}
