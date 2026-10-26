import clsx from 'clsx'
import { Check, ChevronDown, Search, X } from 'lucide-react'
import { useEffect, useLayoutEffect, useMemo, useRef, useState, type CSSProperties, type ReactNode } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { useGet } from '@/hooks/useApi'
import type { Availability, Day, Option, RoomOption } from './types'

const normalise = (s: string) => s.toLowerCase().replace(/[أإآ]/g, 'ا').replace(/ى/g, 'ي').replace(/ة/g, 'ه').replace(/[ً-ٟـ]/g, '')

/** The availability of a day's time slot, fetched only while a picker is open. */
export type Scope = { programId: string; groupId: string }

/** Who holds rooms and trainers in this day's slot — live bookings of other programmes and the open drafts (this one's other days included). */
function useAvailability(day: Day, open: boolean, scope: Scope) {
  return useGet<{ data: Availability }>(open && day.date ? '/admin/saber/availability' : null,
    { date: day.date, start_time: day.start_time ?? undefined, end_time: day.end_time ?? undefined, program_id: scope.programId, group: scope.groupId, idx: day.idx }, { staleTime: 10_000 })
}

/**
 * A searchable list in a popover: rooms show who holds them in that slot, trainers show when they are busy. Taken entries stay
 * selectable only to explain why — the server refuses a real clash, so they are marked and sorted last.
 */
export function Picker({ kind, day, scope, value, options, onPick, disabled, placeholder }: {
  kind: 'room' | 'trainer'; day: Day; scope: Scope; value: string | null; options: (Option | RoomOption)[]; onPick: (id: string | null) => void; disabled?: boolean; placeholder: string
}) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const [q, setQ] = useState('')
  const box = useRef<HTMLDivElement>(null)
  const pop = useRef<HTMLDivElement>(null)
  const [place, setPlace] = useState<CSSProperties>({ position: 'fixed', visibility: 'hidden' })
  const input = useRef<HTMLInputElement>(null)
  const { data, isFetching } = useAvailability(day, open, scope)
  const busy = data?.data
  const current = options.find((o) => o.id === value)
  const close = () => { setOpen(false); setQ(''); setPlace({ position: 'fixed', visibility: 'hidden' }) }

  useEffect(() => {
    if (!open) return
    const away = (e: MouseEvent) => { if (!box.current?.contains(e.target as Node) && !pop.current?.contains(e.target as Node)) close() }
    const key = (e: KeyboardEvent) => { if (e.key === 'Escape') close() }
    document.addEventListener('mousedown', away)
    document.addEventListener('keydown', key)
    return () => { document.removeEventListener('mousedown', away); document.removeEventListener('keydown', key) }
  }, [open])

  // The list floats above the page (the days table scrolls sideways and would clip it): under the button, or above it near the bottom edge.
  useLayoutEffect(() => {
    if (!open) return
    const measure = () => {
      const r = box.current?.getBoundingClientRect()
      if (!r) return
      const width = Math.max(r.width, 288)
      const below = window.innerHeight - r.bottom > 340
      const inline = document.documentElement.dir === 'rtl' ? { right: Math.max(8, window.innerWidth - r.right) } : { left: Math.max(8, r.left) }
      setPlace({ position: 'fixed', width, ...inline, ...(below ? { top: r.bottom + 6 } : { bottom: window.innerHeight - r.top + 6 }) })
    }
    measure()
    input.current?.focus({ preventScroll: true })
    window.addEventListener('scroll', measure, true)
    window.addEventListener('resize', measure)
    return () => { window.removeEventListener('scroll', measure, true); window.removeEventListener('resize', measure) }
  }, [open])

  const takenBy = (id: string): string | null => {
    if (!busy || id === value) return null
    if (kind === 'room') return busy.busy_rooms[id] ? t('saber.day.taken', { by: busy.busy_rooms[id] }) : null
    return busy.busy_trainers.includes(id) ? (busy.trainer_holders?.[id] ? t('saber.day.taken', { by: busy.trainer_holders[id] }) : t('saber.day.busy')) : null
  }

  const list = useMemo(() => {
    const n = normalise(q.trim())
    return options.filter((o) => !n || normalise(o.name).includes(n))
  }, [options, q])
  const sorted = [...list].sort((a, b) => Number(!!takenBy(a.id)) - Number(!!takenBy(b.id)))

  const pick = (id: string | null) => { onPick(id); close() }

  return (
    <div ref={box} className="relative min-w-0">
      <button type="button" disabled={disabled} onClick={() => (open ? close() : setOpen(true))} aria-haspopup="listbox" aria-expanded={open}
        className={clsx('flex h-9 w-full items-center justify-between gap-2 rounded-xl border bg-white px-3 text-start text-sm transition',
          disabled ? 'cursor-not-allowed border-slate-100 text-slate-300' : 'border-navy-100 hover:border-gold-400', current ? 'font-semibold text-navy-900' : 'text-slate-400')}>
        <span className="truncate">{current?.name ?? placeholder}</span>
        <ChevronDown className={clsx('size-4 shrink-0 text-slate-400 transition', open && 'rotate-180')} />
      </button>
      {open && createPortal(
        <div ref={pop} style={place} className="z-[60] overflow-hidden rounded-2xl border border-navy-100 bg-white shadow-xl" role="listbox">
          <label className="flex items-center gap-2 border-b border-navy-50 px-3 py-2">
            <Search className="size-4 text-slate-400" />
            <input ref={input} value={q} onChange={(e) => setQ(e.target.value)} placeholder={t('saber.day.search')} className="w-full bg-transparent text-sm outline-none" />
          </label>
          {isFetching && <div className="px-3 py-1.5 text-[11px] font-semibold text-gold-600">{t('saber.day.checking')}</div>}
          <ul className="max-h-72 overflow-y-auto p-1.5">
            {value && <Row onClick={() => pick(null)} icon={<X className="size-4 text-slate-400" />}><span className="text-slate-500">{t('saber.day.none')}</span></Row>}
            {sorted.length === 0 && <li className="px-3 py-4 text-center text-sm text-slate-400">{t('saber.day.noMatches')}</li>}
            {sorted.map((o) => {
              const taken = takenBy(o.id)
              const room = o as RoomOption
              return (
                <Row key={o.id} onClick={() => pick(o.id)} active={o.id === value} muted={!!taken} icon={o.id === value ? <Check className="size-4 text-gold-600" /> : <span className={clsx('size-2 rounded-full', taken ? 'bg-rose-400' : busy ? 'bg-emerald-500' : 'bg-slate-200')} />}>
                  <span className="block truncate font-semibold">{o.name}{o.pending && <span className="ms-1.5 rounded-full bg-amber-50 px-1.5 text-[10px] font-bold text-amber-700">{t('saber.data.trainers.pending')}</span>}</span>
                  {(taken || (kind === 'room' && (room.capacity || room.tag))) && (
                    <span className={clsx('block truncate text-[11px]', taken ? 'text-rose-600' : 'text-slate-400')}>
                      {taken ?? [room.capacity ? t('saber.day.capacity', { n: room.capacity }) : null, room.tag, room.building].filter(Boolean).join(' · ')}
                    </span>
                  )}
                </Row>
              )
            })}
          </ul>
        </div>,
        document.body,
      )}
    </div>
  )
}

function Row({ children, onClick, icon, active, muted }: { children: ReactNode; onClick: () => void; icon: ReactNode; active?: boolean; muted?: boolean }) {
  return (
    <li>
      <button type="button" onClick={onClick} className={clsx('flex w-full items-center gap-2.5 rounded-xl px-2.5 py-2 text-start text-sm transition', active ? 'bg-gold-50 text-navy-900' : 'hover:bg-ivory', muted && 'opacity-70')}>
        <span className="grid size-5 shrink-0 place-items-center">{icon}</span>
        <span className="min-w-0 flex-1">{children}</span>
      </button>
    </li>
  )
}
