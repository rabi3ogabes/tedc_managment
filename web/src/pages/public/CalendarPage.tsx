import { CalendarPlus, Clock, MapPin, Users } from 'lucide-react'
import { useMemo, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import CalendarGrid from '@/components/public/CalendarGrid'
import { PageHero } from '@/components/public/Section'
import { Badge, Card, Empty, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import type { Session } from '@/lib/types'

type CalendarResponse = { data: Session[]; programs: { id: string; title: string; seats_available: number; registration_open: boolean }[] }

/** Builds a Google Calendar "add event" link for a single session. */
export function googleCalendarUrl(s: Session) {
  const f = (d: string) => new Date(d).toISOString().replace(/[-:]/g, '').replace(/\.\d{3}/, '')
  const params = new URLSearchParams({ action: 'TEMPLATE', text: `${s.program?.title ?? ''} — ${s.title}`, dates: `${f(s.starts_at)}/${f(s.ends_at)}`, location: s.location ?? '' })
  return `https://calendar.google.com/calendar/render?${params}`
}

export default function CalendarPage() {
  const { t } = useTranslation()
  const [month, setMonth] = useState(() => new Date(new Date().getFullYear(), new Date().getMonth(), 1))
  const [selected, setSelected] = useState<Date | null>(null)
  const from = month.toISOString().slice(0, 10)
  const to = new Date(month.getFullYear(), month.getMonth() + 1, 0, 23, 59).toISOString().slice(0, 10)
  const { data, isLoading } = useGet<CalendarResponse>('/public/calendar', { from, to })

  const seats = useMemo(() => new Map(data?.programs.map((p) => [p.id, p]) ?? []), [data])
  const list = (data?.data ?? []).filter((s) => !selected || new Date(s.starts_at).toDateString() === selected.toDateString())

  return (
    <>
      <PageHero title={t('calendar.title')} subtitle={t('calendar.subtitle')} />
      <section className="py-12">
        <div className="container-x grid gap-8 lg:grid-cols-5">
          <div className="lg:col-span-3">{isLoading ? <Spinner /> : <CalendarGrid month={month} onMonth={(m) => { setMonth(m); setSelected(null) }} sessions={data?.data ?? []} selected={selected} onSelect={(d) => setSelected(selected?.toDateString() === d.toDateString() ? null : d)} />}</div>
          <div className="space-y-3 lg:col-span-2">
            {!list.length ? <Card><Empty text={t('calendar.noSessions')} /></Card> : list.map((s) => {
              const p = seats.get(s.program_id)
              return (
                <Card key={s.id} className="!p-4">
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <Link to={`/programs/${s.program?.code}`} className="font-bold text-navy-900 hover:text-link">{s.program?.title}</Link>
                      <div className="text-sm text-slate-500">{s.title}</div>
                    </div>
                    {p && <Badge color={p.registration_open ? 'green' : 'gray'}><Users className="size-3" />{fmt.number(p.seats_available)}</Badge>}
                  </div>
                  <div className="mt-3 flex flex-wrap gap-x-4 gap-y-1 text-xs text-slate-500">
                    <span className="flex items-center gap-1"><Clock className="size-3.5" />{fmt.dateTime(s.starts_at)}</span>
                    {s.location && <span className="flex items-center gap-1"><MapPin className="size-3.5" />{s.location}</span>}
                  </div>
                  <a href={googleCalendarUrl(s)} target="_blank" rel="noreferrer" className="mt-3 inline-flex items-center gap-1.5 text-xs font-bold text-link hover:opacity-80"><CalendarPlus className="size-4" />{t('calendar.addToCalendar')}</a>
                </Card>
              )
            })}
          </div>
        </div>
      </section>
    </>
  )
}
