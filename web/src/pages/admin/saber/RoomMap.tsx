import clsx from 'clsx'
import { Building2, CalendarOff, DoorOpen } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Modal } from '@/components/ui'
import { fmt } from '@/lib/format'
import BookingCard from './BookingCard'
import { roomState, slotsOf, STATE_STYLE, type MonthData, type MonthRoom, type RoomState } from './rooms'
import type { Overview } from './types'

const TONE: Record<RoomState, string> = { free: 'border-emerald-200 bg-emerald-50/50', partial: 'border-amber-200 bg-amber-50/50', full: 'border-rose-200 bg-rose-50/50', closed: 'border-slate-200 bg-slate-50' }

/** «خريطة القاعات» (C4): every room on a chosen day by building and floor — free, partly booked, booked or closed — with what holds it. */
export default function RoomMap({ data, overview, date, onDate, onChanged }: { data: MonthData; overview: Overview; date: string; onDate: (d: string) => void; onChanged: () => void }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState<MonthRoom | null>(null)
  const { first, last } = data.hours
  const day = data.days.find((d) => d.date === date)
  const events = data.events.filter((e) => e.date === date)
  const states = new Map(data.rooms.map((r) => [r.id, roomState(day, events.filter((e) => e.room_id === r.id), first, last)]))
  const count = (s: RoomState) => [...states.values()].filter((x) => x === s).length
  const buildings = [...new Set(data.rooms.map((r) => r.building ?? ''))].sort((a, b) => (a === '' ? 1 : b === '' ? -1 : a.localeCompare(b, 'ar')))

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3 rounded-2xl border border-navy-100 bg-white p-3 shadow-sm">
        <label className="flex items-center gap-2 text-xs font-bold text-slate-500">{t('saber.map.date')}
          <input type="date" dir="ltr" value={date} onChange={(e) => e.target.value && onDate(e.target.value)} className="h-10 rounded-xl border border-navy-100 px-2 text-sm" />
        </label>
        <span className="text-sm font-bold text-navy-900">{fmt.date(date, { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' })}</span>
        {day && !day.events_allowed && <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600"><CalendarOff className="size-3.5" />{day.label ?? t(`saber.kinds.${day.kind}`, { defaultValue: day.kind })}</span>}
        <span className="ms-auto flex flex-wrap gap-1.5 text-xs font-bold">
          {(['free', 'partial', 'full'] as const).map((s) => <span key={s} className={clsx('rounded-full px-2.5 py-1 ring-1 ring-inset', STATE_STYLE[s])}>{t(`saber.map.${s}`)} {count(s)}</span>)}
        </span>
      </div>

      {buildings.map((b) => {
        const inB = data.rooms.filter((r) => (r.building ?? '') === b)
        const floors = [...new Set(inB.map((r) => r.floor ?? ''))].sort()
        return (
          <section key={b || 'none'} className="rounded-2xl border border-navy-100 bg-white p-4 shadow-sm">
            <h4 className="mb-3 flex items-center gap-2 text-sm font-bold text-navy-900"><Building2 className="size-4 text-gold-600" />{b || t('saber.map.noBuilding')}</h4>
            <div className="space-y-3">
              {floors.map((f) => (
                <div key={f || 'none'}>
                  {f && <div className="mb-1.5 text-[11px] font-bold text-slate-400">{t('saber.rooms.floor', { v: f })}</div>}
                  <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                    {inB.filter((r) => (r.floor ?? '') === f).map((r) => {
                      const ev = events.filter((e) => e.room_id === r.id)
                      const state = states.get(r.id) ?? 'free'
                      const slots = slotsOf(ev, first, last)
                      return (
                        <button key={r.id} type="button" onClick={() => setOpen(r)} className={clsx('rounded-xl border p-3 text-start transition hover:-translate-y-0.5 hover:shadow-md', TONE[state])}>
                          <div className="flex items-start gap-2">
                            <DoorOpen className="mt-0.5 size-4 shrink-0 text-navy-700" />
                            <div className="min-w-0 flex-1">
                              <div className="truncate text-sm font-bold text-navy-900">{r.name}</div>
                              <div className="truncate text-[11px] text-slate-500">{[t(`saber.rooms.types.${r.type}`), r.tag, r.capacity ? t('saber.rooms.capacity', { n: r.capacity }) : null].filter(Boolean).join(' · ')}</div>
                            </div>
                            <span className={clsx('rounded-full px-2 py-0.5 text-[10px] font-bold ring-1 ring-inset', STATE_STYLE[state])}>{t(`saber.map.${state}`)}</span>
                          </div>
                          <div className="mt-2 flex gap-0.5">{slots.map((busy, i) => <span key={i} className={clsx('h-1.5 flex-1 rounded-full', busy ? 'bg-amber-500' : state === 'closed' ? 'bg-slate-200' : 'bg-emerald-300')} />)}</div>
                          {ev[0] && <div className="mt-1.5 truncate text-[11px] text-slate-600">{ev[0].title}{ev.length > 1 ? ` +${ev.length - 1}` : ''}</div>}
                        </button>
                      )
                    })}
                  </div>
                </div>
              ))}
            </div>
          </section>
        )
      })}

      {open && (
        <Modal open onClose={() => setOpen(null)} title={<span className="flex items-center gap-2"><DoorOpen className="size-5 text-gold-600" />{open.name}</span>}>
          <div className="mb-3 flex flex-wrap gap-x-3 text-xs text-slate-500">
            <span>{t(`saber.rooms.types.${open.type}`)}</span>{open.tag && <span>{open.tag}</span>}{open.capacity && <span>{t('saber.rooms.capacity', { n: open.capacity })}</span>}
            {open.building && <span>{t('saber.rooms.building', { v: open.building })}</span>}{open.floor && <span>{t('saber.rooms.floor', { v: open.floor })}</span>}
          </div>
          <div className="mb-3 grid gap-0.5" style={{ gridTemplateColumns: `repeat(${last - first}, minmax(0, 1fr))` }}>
            {slotsOf(events.filter((e) => e.room_id === open.id), first, last).map((busy, i) => <span key={i} className={clsx('flex h-6 items-center justify-center rounded text-[10px] font-bold tabular-nums', busy ? 'bg-amber-400/80 text-white' : 'bg-emerald-50 text-emerald-700')} dir="ltr">{first + i}</span>)}
          </div>
          <div className="space-y-1.5">
            {events.filter((e) => e.room_id === open.id).length === 0 ? <p className="text-sm text-slate-400">{t('saber.calendar.free')}</p>
              : events.filter((e) => e.room_id === open.id).map((e) => <BookingCard key={e.id} event={e} overview={overview} onChanged={onChanged} />)}
          </div>
        </Modal>
      )}
    </div>
  )
}
