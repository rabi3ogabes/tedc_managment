/* eslint-disable @typescript-eslint/no-explicit-any */
import { CalendarDays, CalendarPlus, Headphones, MapPin, Video } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { PageHero } from '@/components/public/Section'
import { Badge, Button, Card, Empty, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

const dt = { dateStyle: 'full', timeStyle: 'short' } as Intl.DateTimeFormatOptions
const API = (import.meta.env.VITE_API_URL as string | undefined)?.replace(/\/$/, '') ?? ''

export default function Events() {
  const { t } = useTranslation()
  const [past, setPast] = useState(false)
  const { data, isLoading } = useGet<{ data: any[] }>('/public/events', { past: past ? 1 : undefined })
  return (
    <>
      <PageHero title={t('comm.events.title')} subtitle={t('comm.events.subtitle')} />
      <section className="py-16">
        <div className="container-x">
          <div className="mb-6 flex flex-wrap items-center gap-2">
            <Button size="sm" variant={!past ? 'primary' : 'outline'} onClick={() => setPast(false)}>{t('comm.events.upcoming')}</Button>
            <Button size="sm" variant={past ? 'primary' : 'outline'} onClick={() => setPast(true)}>{t('comm.events.past')}</Button>
            <a className="ms-auto text-sm font-semibold text-link" href={`${API}/api/v1/public/calendar.ics`}>{t('comm.events.calendarAll')}</a>
          </div>
          {isLoading ? <Spinner /> : !data?.data.length ? <Empty text={String(t('comm.events.empty'))} /> : (
            <div className="grid gap-5 md:grid-cols-2">
              {data.data.map((e) => (
                <Link key={e.id} to={`/events/${e.id}`} className="card group flex gap-4 overflow-hidden p-4 transition hover:-translate-y-0.5 hover:shadow-glass">
                  <div className="grid size-20 shrink-0 place-items-center rounded-2xl bg-navy-900 text-center text-white">
                    <div><div className="font-display text-2xl font-bold text-gold-300">{e.event.starts_at ? new Date(e.event.starts_at).getDate() : '—'}</div><div className="text-xs text-white/70">{e.event.starts_at ? fmt.date(e.event.starts_at, { month: 'short' }) : ''}</div></div>
                  </div>
                  <div className="min-w-0 flex-1">
                    <div className="flex items-center gap-2"><Badge color="gold">{t(`comm.ann.types.${e.type}`)}</Badge>{e.is_pinned && <span aria-hidden>📌</span>}</div>
                    <h3 className="mt-1 font-bold text-navy-900 group-hover:text-link">{e.title}</h3>
                    {e.event.venue && <p className="mt-1 flex items-center gap-1 text-sm text-slate-500"><MapPin className="size-3.5" />{e.event.venue}</p>}
                    <p className="mt-1 line-clamp-2 text-sm text-slate-500">{e.excerpt}</p>
                  </div>
                </Link>
              ))}
            </div>
          )}
        </div>
      </section>
    </>
  )
}

export function EventDetail() {
  const { t } = useTranslation()
  const { id } = useParams()
  const { user } = useAuth()
  const { data, isLoading } = useGet<{ data: any }>(`/public/events/${id}`)
  const mine = useGet<{ data: any[] }>(user ? '/me/events' : null, undefined, { staleTime: 0 })
  const [status, setStatus] = useState<string | null | undefined>(undefined)
  const [busy, setBusy] = useState(false)
  const e = data?.data
  if (isLoading || !e) return <div className="pt-32"><Spinner /></div>
  const my = status !== undefined ? status : mine.data?.data.find((x) => x.id === id)?.my_rsvp
  const rsvp = async (going: boolean) => {
    setBusy(true)
    try { const { data: r } = await api.post(`/me/events/${id}/rsvp`, { going }); setStatus(r.data.status === 'cancelled' ? null : r.data.status) } catch (er) { toast(errorMessage(er), 'error') } finally { setBusy(false) }
  }
  const m = e.media ?? {}

  return (
    <>
      <PageHero title={e.title} subtitle={e.event.starts_at ? fmt.date(e.event.starts_at, dt) : ''} />
      <section className="py-12">
        <div className="container-x max-w-3xl space-y-5">
          <Card>
            <div className="flex flex-wrap gap-4 text-sm text-slate-600">
              {e.event.venue && <span className="flex items-center gap-1.5"><MapPin className="size-4 text-gold-600" />{e.event.venue}</span>}
              {e.event.online_url && <a className="flex items-center gap-1.5 text-link" href={e.event.online_url} target="_blank" rel="noreferrer"><Video className="size-4" />{t('comm.events.online')}</a>}
              <a className="flex items-center gap-1.5 text-link" href={e.ics_url}><CalendarPlus className="size-4" />{t('comm.events.addToCalendar')}</a>
              {e.event.capacity && <span className="flex items-center gap-1.5"><CalendarDays className="size-4 text-gold-600" />{t('comm.events.seats')}: {e.event.capacity}</span>}
            </div>
            <p className="mt-6 whitespace-pre-line text-lg leading-loose text-slate-700">{e.body}</p>
            {!!m.images?.length && <div className="mt-6 grid grid-cols-2 gap-3 sm:grid-cols-3">{m.images.map((i: any) => <img key={i.url} src={i.url} alt={i.title ?? ''} loading="lazy" className="aspect-video w-full rounded-xl object-cover" />)}</div>}
            {!!m.audio?.length && <div className="mt-6 space-y-2">{m.audio.map((a: any) => <div key={a.url}><div className="mb-1 flex items-center gap-1.5 text-sm font-semibold text-navy-900"><Headphones className="size-4 text-gold-600" />{a.title || t('comm.events.listen')}</div><audio controls preload="none" src={a.url} className="w-full" /></div>)}</div>}
            {!!m.video?.length && <div className="mt-6 space-y-3">{m.video.map((v: any) => /\.(mp4|webm)(\?|$)/i.test(v.url) ? <video key={v.url} controls preload="metadata" src={v.url} className="w-full rounded-xl" /> : <a key={v.url} href={v.url} target="_blank" rel="noreferrer" className="flex items-center gap-2 text-link"><Video className="size-4" />{v.title || t('comm.events.watch')}</a>)}</div>}
          </Card>
          <Card>
            {e.event.rsvp ? (user ? (
              <div className="flex flex-wrap items-center justify-between gap-3">
                <span className="font-semibold text-navy-900">{my === 'going' ? t('comm.events.going') : my === 'waitlisted' ? t('comm.events.waitlisted') : ''}</span>
                {my === 'going' || my === 'waitlisted' ? <Button variant="outline" loading={busy} onClick={() => rsvp(false)}>{t('comm.events.cancel')}</Button> : <Button variant="gold" loading={busy} onClick={() => rsvp(true)}>{t('comm.events.register')}</Button>}
              </div>
            ) : <div className="flex items-center justify-between gap-3"><span className="text-slate-600">{t('comm.events.loginToRegister')}</span><Button to="/login" variant="gold">{t('nav.login')}</Button></div>)
              : e.event.registration_url ? <Button variant="gold" to={e.event.registration_url}>{t('comm.events.externalRegister')}</Button> : null}
          </Card>
        </div>
      </section>
    </>
  )
}
