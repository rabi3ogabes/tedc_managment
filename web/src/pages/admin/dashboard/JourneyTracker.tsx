import clsx from 'clsx'
import { Award, BadgeCheck, Bell, CalendarRange, ChevronLeft, ClipboardList, PackageCheck, Presentation, Star, UserPlus } from 'lucide-react'
import { useEffect, useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import { relativeTime } from '../kits/comments/api'

type Note = { id: string; type: string; title: string; body: string | null; to: string | null; at: string }
type Step = {
  key: string; order: number; icon: string; link: string; title: string; text: string; value: number; unit: string; alert: string | null
  events: number; recent: Note[]; split?: Record<'in_person' | 'online' | 'hybrid', number>
}

const ICONS: Record<string, typeof Bell> = { ClipboardList, CalendarRange, PackageCheck, UserPlus, BadgeCheck, Presentation, Star, Award }

/** The training journey on the main dashboard: eight steps from the need to the certificate, each with live numbers and its latest notifications. */
export default function JourneyTracker() {
  const { t, i18n } = useTranslation()
  const { data, isLoading } = useGet<{ data: { steps: Step[] } }>('/admin/process', undefined, { refetchInterval: 15_000, staleTime: 0 })
  const steps = data?.data.steps ?? []
  const [picked, setPicked] = useState<string | null>(null)
  // Open on the step with something that needs attention, otherwise the first one.
  const fallback = useMemo(() => steps.find((s) => s.alert && s.recent.length)?.key ?? steps.find((s) => s.recent.length)?.key ?? steps[0]?.key ?? null, [steps])
  const active = steps.find((s) => s.key === (picked ?? fallback)) ?? null
  useEffect(() => { if (picked && !steps.some((s) => s.key === picked)) setPicked(null) }, [steps, picked])

  if (isLoading) return <div className="mb-6 grid h-48 place-items-center rounded-3xl bg-navy-900"><Spinner /></div>
  if (steps.length === 0) return null

  return (
    <section aria-label={t('journey.title')} className="relative mb-6 min-w-0 max-w-full overflow-hidden rounded-3xl bg-gradient-to-br from-navy-950 via-navy-900 to-navy-800 p-5 text-white shadow-glass sm:p-7">
      <div className="pointer-events-none absolute -end-24 -top-24 size-72 rounded-full bg-gold-500/15 blur-3xl" />
      <div className="relative">
        <div className="flex flex-wrap items-end justify-between gap-3">
          <div>
            <h2 className="text-xl font-extrabold sm:text-2xl">{t('journey.title')}</h2>
            <p className="mt-1 max-w-2xl text-sm text-white/65">{t('journey.subtitle')}</p>
          </div>
          <span className="inline-flex items-center gap-2 rounded-full bg-white/10 px-3 py-1 text-xs font-semibold text-emerald-300"><span className="size-2 animate-pulse rounded-full bg-emerald-400" />{t('journey.live')}</span>
        </div>

        {/* The eight steps */}
        <ol className="mt-6 grid grid-cols-[repeat(2,minmax(0,1fr))] gap-3 sm:grid-cols-[repeat(4,minmax(0,1fr))] xl:grid-cols-[repeat(8,minmax(0,1fr))]">
          {steps.map((s) => {
            const Icon = ICONS[s.icon] ?? Bell
            const on = active?.key === s.key
            return (
              <li key={s.key} className="relative min-w-0">
                <button type="button" onClick={() => setPicked(s.key)} aria-pressed={on} className={clsx('group h-full w-full rounded-2xl p-3.5 text-start transition', on ? 'bg-white text-navy-900 shadow-lg ring-2 ring-gold-400' : 'bg-white/8 ring-1 ring-white/10 hover:bg-white/14')}>
                  <div className="flex items-center justify-between">
                    <span className={clsx('grid size-9 place-items-center rounded-xl', on ? 'bg-navy-900 text-gold-300' : 'bg-white/12 text-gold-300')}><Icon className="size-[18px]" /></span>
                    <span className={clsx('text-xs font-black tabular-nums', on ? 'text-gold-600' : 'text-white/40')}>{s.order}</span>
                  </div>
                  <div className="mt-3 text-sm font-extrabold">{s.title}</div>
                  <div className="mt-0.5 flex items-baseline gap-1.5"><span className="text-2xl font-black tabular-nums leading-none">{fmt.number(s.value)}</span><span className={clsx('truncate text-[11px]', on ? 'text-slate-500' : 'text-white/55')}>{s.unit}</span></div>
                  {s.alert ? <div className={clsx('mt-2 inline-block max-w-full truncate rounded-full px-2 py-0.5 text-[10px] font-bold', on ? 'bg-gold-100 text-gold-700' : 'bg-gold-500/20 text-gold-300')}>{s.alert}</div> : <div className="mt-2 h-[18px]" />}
                </button>
                {s.order < steps.length && <ChevronLeft aria-hidden className="pointer-events-none absolute -end-2.5 top-1/2 z-10 hidden size-4 -translate-y-1/2 text-white/30 ltr:rotate-180 xl:block" />}
              </li>
            )
          })}
        </ol>

        {/* What this step is, and its latest notifications */}
        {active && (
          <div className="mt-5 rounded-2xl bg-white/8 p-4 ring-1 ring-white/10 sm:p-5">
            <div className="flex flex-wrap items-center justify-between gap-3">
              <div>
                <div className="flex items-center gap-2 font-extrabold"><span className="grid size-6 place-items-center rounded-full bg-gold-500 text-xs font-black text-navy-950">{active.order}</span>{active.title}</div>
                <p className="mt-1 text-sm text-white/65">{active.text}</p>
                {active.split && (
                  <div className="mt-2 flex flex-wrap gap-2 text-xs">
                    {(['in_person', 'online', 'hybrid'] as const).map((m) => <span key={m} className="rounded-full bg-white/10 px-2.5 py-1 font-semibold">{t(`journey.modes.${m}`)} <b className="tabular-nums text-gold-300">{active.split![m]}</b></span>)}
                  </div>
                )}
              </div>
              <Link to={active.link} className="inline-flex items-center gap-2 rounded-xl bg-gold-500 px-4 py-2 text-sm font-bold text-navy-950 transition hover:bg-gold-400">{t('journey.open')}</Link>
            </div>
            <h3 className="mb-2 mt-4 flex items-center gap-2 text-xs font-bold text-white/70"><Bell className="size-3.5" />{t('journey.latest')}</h3>
            {active.recent.length === 0 ? <p className="rounded-xl border border-dashed border-white/15 p-4 text-center text-sm text-white/50">{t('journey.empty')}</p> : (
              <ul className="grid gap-2 lg:grid-cols-3">
                {active.recent.map((n) => (
                  <li key={n.id} className="rounded-xl bg-white p-3 text-navy-900 shadow-sm">
                    <div className="flex items-start justify-between gap-2"><span className="text-sm font-extrabold leading-snug">{n.title}</span><span className="shrink-0 text-[10px] font-semibold text-slate-400">{relativeTime(n.at, i18n.language)}</span></div>
                    {n.body && <p className="mt-1 line-clamp-2 text-xs text-slate-600">{n.body}</p>}
                    {n.to && <p className="mt-1.5 text-[11px] font-semibold text-gold-700">{t('journey.to')}: {n.to}</p>}
                  </li>
                ))}
              </ul>
            )}
          </div>
        )}
      </div>
    </section>
  )
}
