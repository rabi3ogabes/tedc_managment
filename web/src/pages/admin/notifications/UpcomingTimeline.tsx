import clsx from 'clsx'
import { Award, BookOpen, CalendarClock, ClipboardCheck, Clock, Pencil, Presentation, Star, Users } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Card, Empty, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import type { Paginated, Program } from '@/lib/types'
import { CHANNEL_ICON, type Channel } from './ChannelPicker'
import { relativeTime } from '../kits/comments/api'

type Item = {
  id: string; type: string; group: string | null; at: string; recipients: number; title_ar: string; title_en: string; body_ar: string | null; body_en: string | null
  enabled: boolean; channels: Record<Channel, 'on' | 'setup' | 'off'>; program: { id: string; code: string; title: string } | null
  session?: { id: string; title: string; mode: string; starts_at: string }; note?: { ar: string; en: string }
}
type Upcoming = { summary: { total: number; recipients: number; next_24h: number; stopped: number }; types: { type: string; name_ar: string; name_en: string; count: number }[]; items: Item[] }

const GROUP: Record<string, { icon: typeof Clock; tone: string }> = {
  session: { icon: Presentation, tone: 'bg-sky-100 text-sky-700' }, survey: { icon: Star, tone: 'bg-emerald-100 text-emerald-700' }, certificate: { icon: Award, tone: 'bg-gold-100 text-gold-700' },
  course: { icon: BookOpen, tone: 'bg-violet-100 text-violet-700' }, program: { icon: CalendarClock, tone: 'bg-navy-100 text-navy-800' },
}
const CHANNEL_TONE = { on: 'bg-emerald-50 text-emerald-700 ring-emerald-200', setup: 'bg-amber-50 text-amber-700 ring-amber-200', off: 'bg-slate-100 text-slate-400 ring-slate-200 line-through' }

/** The automatic notifications still to come, as a timeline grouped by day. */
export default function UpcomingTimeline() {
  const { t, i18n } = useTranslation()
  const ar = i18n.language === 'ar'
  const [days, setDays] = useState(14)
  const [type, setType] = useState('')
  const [program, setProgram] = useState('')
  const programs = useGet<Paginated<Program>>('/admin/programs', { per_page: 100 })
  const { data, isLoading } = useGet<{ data: Upcoming }>('/admin/notifications/upcoming', { days, type: type || undefined, program_id: program || undefined }, { refetchInterval: 60_000, staleTime: 0 })

  const groups = useMemo(() => {
    const map = new Map<string, Item[]>()
    for (const i of data?.data.items ?? []) {
      const key = new Date(i.at).toLocaleDateString('en-CA')   // local calendar day, sortable
      map.set(key, [...(map.get(key) ?? []), i])
    }
    return [...map.entries()]
  }, [data])

  const dayLabel = (key: string) => {
    const today = new Date().toLocaleDateString('en-CA')
    const tomorrow = new Date(Date.now() + 86_400_000).toLocaleDateString('en-CA')
    return key === today ? t('upcoming.today') : key === tomorrow ? t('upcoming.tomorrow') : fmt.date(`${key}T12:00:00`, { weekday: 'long', day: 'numeric', month: 'long' })
  }
  const time = (iso: string) => new Date(iso).toLocaleTimeString(ar ? 'ar-u-nu-latn' : 'en-GB', { hour: '2-digit', minute: '2-digit', hour12: false })
  const s = data?.data.summary

  return (
    <div>
      <div className="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div><h2 className="text-xl font-extrabold text-navy-900">{t('upcoming.title')}</h2><p className="mt-1 max-w-2xl text-sm text-slate-500">{t('upcoming.subtitle')}</p></div>
        <span className="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-xs font-semibold text-emerald-700"><span className="size-2 animate-pulse rounded-full bg-emerald-500" />{t('upcoming.refreshed')}</span>
      </div>

      <div className="mb-5 grid gap-3 sm:grid-cols-4">
        {[
          { label: t('upcoming.total'), value: s?.total ?? 0, icon: CalendarClock }, { label: t('upcoming.next24'), value: s?.next_24h ?? 0, icon: Clock },
          { label: t('upcoming.recipients'), value: s?.recipients ?? 0, icon: Users }, { label: t('upcoming.stopped'), value: s?.stopped ?? 0, icon: ClipboardCheck },
        ].map((k) => (
          <div key={k.label} className="flex items-center gap-3 rounded-2xl border border-navy-100 bg-white p-4 shadow-sm">
            <span className="grid size-11 place-items-center rounded-xl bg-navy-900 text-gold-300"><k.icon className="size-5" /></span>
            <div><div className="text-2xl font-extrabold tabular-nums text-navy-900">{fmt.number(k.value)}</div><div className="text-xs text-slate-500">{k.label}</div></div>
          </div>
        ))}
      </div>

      <div className="mb-6 flex flex-wrap items-center gap-3">
        <div role="tablist" aria-label={t('upcoming.range')} className="inline-flex rounded-xl border border-navy-100 bg-white p-1">
          {[3, 7, 14, 30].map((n) => <button key={n} type="button" role="tab" aria-selected={days === n} onClick={() => setDays(n)} className={clsx('rounded-lg px-3.5 py-1.5 text-xs font-bold transition', days === n ? 'bg-navy-900 text-white' : 'text-slate-500 hover:text-navy-900')}>{t('upcoming.days', { n })}</button>)}
        </div>
        <select className="input w-auto" value={type} onChange={(e) => setType(e.target.value)} aria-label={t('upcoming.allTypes')}>
          <option value="">{t('upcoming.allTypes')}</option>{data?.data.types.map((x) => <option key={x.type} value={x.type}>{ar ? x.name_ar : x.name_en} ({x.count})</option>)}
        </select>
        <select className="input w-auto" value={program} onChange={(e) => setProgram(e.target.value)} aria-label={t('upcoming.allPrograms')}>
          <option value="">{t('upcoming.allPrograms')}</option>{programs.data?.data.map((p) => <option key={p.id} value={p.id}>{p.code} · {p.title}</option>)}
        </select>
      </div>

      {isLoading ? <Spinner /> : groups.length === 0 ? <Card><Empty text={t('upcoming.empty')} /></Card> : (
        <ol className="space-y-8">
          {groups.map(([day, items]) => (
            <li key={day}>
              <h3 className="sticky top-0 z-10 mb-3 inline-flex items-center gap-2 rounded-full bg-navy-900 px-4 py-1.5 text-sm font-extrabold text-white shadow">{dayLabel(day)}<span className="text-xs font-semibold text-gold-300">{items.length}</span></h3>
              <ul className="relative space-y-3 border-s-2 border-dashed border-navy-100 ps-5">
                {items.map((i) => {
                  const g = GROUP[i.group ?? ''] ?? GROUP.program
                  const Icon = g.icon
                  const soon = new Date(i.at).getTime() - Date.now() < 15 * 60_000
                  return (
                    <li key={i.id} className={clsx('relative rounded-2xl border bg-white p-4 shadow-sm', i.enabled ? 'border-navy-100' : 'border-slate-200 bg-slate-50/70')}>
                      <span className={clsx('absolute -start-[1.72rem] top-5 size-3 rounded-full ring-4 ring-white', i.enabled ? 'bg-gold-500' : 'bg-slate-300')} />
                      <div className="flex flex-wrap items-start gap-4">
                        <div className="w-16 shrink-0 text-center">
                          <div className="text-xl font-black tabular-nums text-navy-900">{time(i.at)}</div>
                          <div className={clsx('text-[11px] font-semibold', soon ? 'text-gold-700' : 'text-slate-400')}>{soon ? t('upcoming.soon') : relativeTime(i.at, i18n.language)}</div>
                        </div>
                        <span className={clsx('grid size-11 shrink-0 place-items-center rounded-xl', g.tone)}><Icon className="size-5" /></span>
                        <div className="min-w-0 flex-1 basis-60">
                          <div className="flex flex-wrap items-center gap-2">
                            <span className={clsx('font-bold', i.enabled ? 'text-navy-900' : 'text-slate-500')}>{ar ? i.title_ar : i.title_en}</span>
                            {!i.enabled && <span className="rounded-full bg-slate-200 px-2 py-0.5 text-[11px] font-bold text-slate-600">{t('upcoming.stoppedTag')}</span>}
                            {i.note && <span className="rounded-full bg-navy-100/70 px-2 py-0.5 text-[11px] font-semibold text-navy-800">{ar ? i.note.ar : i.note.en}</span>}
                          </div>
                          {(ar ? i.body_ar : i.body_en) && <p className="mt-1 line-clamp-2 text-sm text-slate-500">{ar ? i.body_ar : i.body_en}</p>}
                          <div className="mt-2 flex flex-wrap items-center gap-x-4 gap-y-1 text-xs text-slate-500">
                            {i.program && <span className="font-semibold text-navy-800"><span className="me-1 rounded bg-navy-900 px-1.5 py-0.5 font-mono text-[10px] text-gold-300" dir="ltr">{i.program.code}</span>{i.program.title}</span>}
                            {i.session && <span>{i.session.title}</span>}
                            <span className="inline-flex items-center gap-1 font-semibold"><Users className="size-3.5" />{t('upcoming.toPeople', { count: i.recipients })}</span>
                          </div>
                        </div>
                        <div className="flex flex-col items-end gap-2">
                          <div className="flex gap-1.5" aria-label="channels">
                            {(['push', 'email', 'sms'] as Channel[]).map((c) => { const C = CHANNEL_ICON[c]; return <span key={c} title={`${t(`channels.${c}`)}: ${t(`channels.${i.channels[c]}`)}`} className={clsx('grid size-8 place-items-center rounded-lg ring-1', CHANNEL_TONE[i.channels[c]])}><C className="size-4" /></span> })}
                          </div>
                          <Link to="/admin/settings?tab=templates" className="inline-flex items-center gap-1 text-[11px] font-bold text-gold-700 hover:underline"><Pencil className="size-3" />{t('upcoming.edit')}</Link>
                        </div>
                      </div>
                    </li>
                  )
                })}
              </ul>
            </li>
          ))}
        </ol>
      )}
    </div>
  )
}
