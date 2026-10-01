import { useQueryClient } from '@tanstack/react-query'
import { CalendarDays, Clock, MapPin, Users } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { StatusBadge } from '@/components/ui'
import { api } from '@/lib/api'
import { fmt } from '@/lib/format'
import type { Program } from '@/lib/types'

const palettes: Record<string, [string, string]> = {
  leadership: ['#5a0e24', '#8a1538'],
  pedagogy: ['#3d0a1c', '#756b54'],
  digital: ['#0b2a3a', '#0e7490'],
  assessment: ['#1e1b4b', '#5b21b6'],
  wellbeing: ['#3b0d24', '#9d174d'],
  professional: ['#2a1a05', '#a16207'],
}

export function ProgramCover({ program, className = 'h-44', labels = true }: { program: Program; className?: string; labels?: boolean }) {
  const [from, to] = palettes[program.category?.slug ?? ''] ?? palettes.pedagogy
  return (
    <div className={`relative overflow-hidden ${className}`} style={{ background: `linear-gradient(135deg, ${from}, ${to})` }}>
      <div className="pattern-bg absolute inset-0 opacity-40" />
      <svg className="absolute -bottom-6 -end-6 size-40 text-gold-400/25" viewBox="0 0 100 100" aria-hidden>
        <path d="M50 2 L61 39 L98 50 L61 61 L50 98 L39 61 L2 50 L39 39 Z" fill="currentColor" />
      </svg>
      {program.cover_url && (
        <img src={program.cover_url} alt="" loading="lazy" onError={(e) => (e.currentTarget.style.display = 'none')} className="absolute inset-0 h-full w-full object-cover transition duration-700 group-hover:scale-105" />
      )}
      <div className="absolute inset-0 bg-gradient-to-t from-navy-950/70 to-transparent" />
      {labels && <div className="absolute inset-x-4 bottom-3 flex items-end justify-between">
        <span className="font-display text-sm font-semibold tracking-wider text-gold-300" dir="ltr">{program.code}</span>
        {program.category && <span className="rounded-full bg-white/15 px-2.5 py-0.5 text-xs text-white backdrop-blur">{program.category.name}</span>}
      </div>}
    </div>
  )
}

export default function ProgramCard({ program }: { program: Program }) {
  const { t, i18n } = useTranslation()
  const qc = useQueryClient()
  // Warm the detail page while the pointer is on its way, so the click feels instant.
  const prefetch = () => {
    const url = `/public/programs/${program.code}`
    void qc.prefetchQuery({ queryKey: [url, undefined, i18n.language], queryFn: async () => (await api.get(url)).data, staleTime: 60_000 })
  }
  return (
    <Link to={`/programs/${program.code}`} onMouseEnter={prefetch} onFocus={prefetch} onTouchStart={prefetch} className="group card flex flex-col overflow-hidden transition duration-300 hover:-translate-y-1 hover:shadow-glass">
      <ProgramCover program={program} />
      <div className="flex flex-1 flex-col p-5">
        <div className="mb-2 flex items-center gap-2">
          <StatusBadge status={program.status} />
          <span className="text-xs text-slate-400">{t(`levels.${program.level}`)}</span>
        </div>
        <h3 className="text-lg font-bold leading-snug text-navy-900 transition group-hover:text-link">{program.title}</h3>
        {program.summary && <p className="mt-2 line-clamp-2 text-sm text-slate-500">{program.summary}</p>}
        {program.capacity > 0 && program.seats_available !== null && program.seats_available !== undefined && (() => {
          const taken = Math.min(1, Math.max(0, (program.capacity - program.seats_available) / program.capacity))
          const low = program.seats_available > 0 && program.seats_available / program.capacity <= 0.2
          return (
            <div className="mt-4" aria-label={`${program.seats_available} / ${program.capacity}`}>
              <div className="h-1.5 overflow-hidden rounded-full bg-navy-100/70"><div className={`h-full rounded-full transition-all duration-700 ${low ? 'bg-amber-500' : 'bg-gradient-to-l from-gold-400 to-gold-600'}`} style={{ width: `${taken * 100}%` }} /></div>
              {low && <div className="mt-1 text-[11px] font-bold text-amber-700">{t('common.fewSeats', { count: program.seats_available })}</div>}
            </div>
          )
        })()}
        <div className="mt-auto grid grid-cols-2 gap-2 pt-5 text-xs text-slate-500">
          <span className="flex items-center gap-1.5"><CalendarDays className="size-4 text-gold-600" />{fmt.date(program.start_date, { day: 'numeric', month: 'short' })}</span>
          <span className="flex items-center gap-1.5"><Clock className="size-4 text-gold-600" />{fmt.number(program.total_hours)} {t('common.hours')}</span>
          <span className="flex items-center gap-1.5"><MapPin className="size-4 text-gold-600" />{t(`modes.${program.delivery_mode}`)}</span>
          {program.seats_available !== null && program.seats_available !== undefined && (
            <span className="flex items-center gap-1.5"><Users className="size-4 text-gold-600" />{fmt.number(program.seats_available)} {t('common.seatsAvailable')}</span>
          )}
        </div>
      </div>
    </Link>
  )
}
