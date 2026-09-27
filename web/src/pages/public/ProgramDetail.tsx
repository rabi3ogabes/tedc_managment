import { CalendarDays, CheckCircle2, Clock, GraduationCap, MapPin, Target, Users } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import { ProgramCover } from '@/components/public/ProgramCard'
import { Avatar, Badge, Button, Card, EligibilityPanel, ErrorState, Progress, Spinner, StatusBadge } from '@/components/ui'
import { useGet, useSend } from '@/hooks/useApi'
import { errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Eligibility, Program } from '@/lib/types'

export default function ProgramDetail() {
  const { code } = useParams()
  const { t } = useTranslation()
  const { user } = useAuth()
  const { data, isLoading, error } = useGet<{ data: Program }>(`/public/programs/${code}`)
  const program = data?.data
  const eligibility = useGet<{ data: Eligibility }>(user?.employee && program ? `/me/programs/${program.id}/eligibility` : null)
  const register = useSend('post', () => `/me/programs/${program!.id}/register`, ['/me', '/public'])
  const [message, setMessage] = useState<string | null>(null)

  if (isLoading) return <div className="pt-32"><Spinner /></div>
  if (error || !program) return <div className="pt-32"><ErrorState /></div>

  const seatsPct = program.capacity ? ((program.seats_taken ?? 0) / program.capacity) * 100 : 0
  const e = eligibility.data?.data

  return (
    <>
      <section className="relative pt-20">
        <ProgramCover program={program} className="h-[380px]" labels={false} />
        <div className="absolute inset-x-0 bottom-0">
          <div className="container-x pb-10 text-white">
            <div className="flex flex-wrap gap-2"><StatusBadge status={program.status} /><Badge color="gold">{t(`levels.${program.level}`)}</Badge>{program.category && <Badge color="navy">{program.category.name}</Badge>}<span className="font-mono text-sm text-gold-300" dir="ltr">{program.code}</span></div>
            <h1 className="mt-4 max-w-4xl text-3xl font-bold sm:text-5xl">{program.title}</h1>
            {program.summary && <p className="mt-3 max-w-3xl text-white/80">{program.summary}</p>}
          </div>
        </div>
      </section>

      <section className="py-12">
        <div className="container-x grid gap-8 lg:grid-cols-3">
          <div className="space-y-8 lg:col-span-2">
            <Card>
              <p className="leading-loose text-slate-700">{program.description}</p>
              {!!program.objectives.length && (
                <>
                  <h3 className="mt-8 flex items-center gap-2 text-lg font-bold text-navy-900"><Target className="size-5 text-gold-600" />{t('programs.objectives')}</h3>
                  <ul className="mt-4 space-y-2">{program.objectives.map((o) => <li key={o} className="flex items-start gap-2 text-slate-600"><CheckCircle2 className="mt-0.5 size-5 shrink-0 text-emerald-600" />{o}</li>)}</ul>
                </>
              )}
              {!!program.skills?.length && (
                <>
                  <h3 className="mt-8 text-lg font-bold text-navy-900">{t('programs.skills')}</h3>
                  <div className="mt-3 flex flex-wrap gap-2">{program.skills.map((s) => <Badge key={s.id} color="navy">{s.name}</Badge>)}</div>
                </>
              )}
            </Card>

            {!!program.sessions?.length && (
              <Card>
                <h3 className="mb-5 text-lg font-bold text-navy-900">{t('programs.sessionsTitle')}</h3>
                <ol className="relative space-y-5 border-s-2 border-gold-300/60 ps-6">
                  {program.sessions.map((s) => (
                    <li key={s.id} className="relative">
                      <span className="absolute -start-[33px] top-1 grid size-4 place-items-center rounded-full bg-gold-500 ring-4 ring-gold-100" />
                      <div className="font-bold text-navy-900">{s.title}</div>
                      <div className="mt-1 flex flex-wrap gap-x-4 gap-y-1 text-sm text-slate-500">
                        <span className="flex items-center gap-1"><CalendarDays className="size-4" />{fmt.date(s.starts_at, { weekday: 'long', day: 'numeric', month: 'long' })}</span>
                        <span className="flex items-center gap-1"><Clock className="size-4" />{fmt.time(s.starts_at)} – {fmt.time(s.ends_at)}</span>
                        {s.location && <span className="flex items-center gap-1"><MapPin className="size-4" />{s.location}</span>}
                      </div>
                    </li>
                  ))}
                </ol>
              </Card>
            )}

            {!!program.trainers?.length && (
              <Card>
                <h3 className="mb-5 text-lg font-bold text-navy-900">{t('programs.trainersTitle')}</h3>
                <div className="grid gap-4 sm:grid-cols-2">
                  {program.trainers.map((tr) => (
                    <div key={tr.id} className="flex items-center gap-4 rounded-2xl bg-ivory p-4">
                      <Avatar name={tr.name} src={tr.photo_url} size={56} />
                      <div><div className="font-bold text-navy-900">{tr.name}</div><div className="text-sm text-slate-500">{tr.title}</div></div>
                    </div>
                  ))}
                </div>
              </Card>
            )}
          </div>

          <aside className="space-y-6 lg:sticky lg:top-28 lg:self-start">
            <Card className="!p-0 overflow-hidden">
              <div className="grid grid-cols-2 divide-x divide-navy-100 border-b border-navy-100 rtl:divide-x-reverse">
                <Info icon={<CalendarDays className="size-5" />} label={t('programs.startsOn')} value={fmt.date(program.start_date, { day: 'numeric', month: 'short', year: 'numeric' })} />
                <Info icon={<Clock className="size-5" />} label={t('programs.duration')} value={`${fmt.number(program.total_hours)} ${t('common.hours')}`} />
                <Info icon={<MapPin className="size-5" />} label={t('programs.mode')} value={t(`modes.${program.delivery_mode}`)} />
                <Info icon={<GraduationCap className="size-5" />} label={t('programs.minAttendance')} value={fmt.percent(program.min_attendance_percent)} />
              </div>
              <div className="p-6">
                <div className="mb-2 flex items-center justify-between text-sm">
                  <span className="flex items-center gap-1.5 text-slate-500"><Users className="size-4" />{t('programs.capacity')}</span>
                  <span className="font-bold text-navy-900">{fmt.number(program.seats_available ?? 0)} {t('common.seatsAvailable')}</span>
                </div>
                <Progress value={seatsPct} />

                <div className="mt-6 space-y-4">
                  {!user && <Button to="/login" variant="gold" className="w-full" size="lg">{t('programs.loginToRegister')}</Button>}
                  {user?.employee && e && (
                    <>
                      <EligibilityPanel result={e} />
                      {e.registration ? (
                        <div className="rounded-xl bg-navy-100/60 p-3 text-center text-sm font-semibold text-navy-800">{t('programs.registered')} — <StatusBadge status={e.registration.status} /></div>
                      ) : !program.registration_open ? (
                        <Button variant="outline" disabled className="w-full">{t('programs.registrationClosed')}</Button>
                      ) : (
                        <Button variant="gold" size="lg" className="w-full" disabled={!e.eligible} loading={register.isPending}
                          onClick={() => register.mutate(undefined, { onSuccess: () => { setMessage(null); eligibility.refetch() }, onError: (err) => setMessage(errorMessage(err)) })}>
                          {t('programs.register')}
                        </Button>
                      )}
                      {message && <p className="text-sm text-danger">{message}</p>}
                    </>
                  )}
                </div>
              </div>
            </Card>
            {!!program.target_groups?.length && (
              <Card>
                <h4 className="font-bold text-navy-900">{t('programs.targetGroups')}</h4>
                <ul className="mt-3 space-y-1 text-sm text-slate-600">{program.target_groups.map((g) => <li key={g.id}>• {g.description ?? g.job_title}</li>)}</ul>
              </Card>
            )}
          </aside>
        </div>
      </section>
    </>
  )
}

function Info({ icon, label, value }: { icon: React.ReactNode; label: string; value: string }) {
  return (
    <div className="border-b border-navy-100 p-4 [&:nth-last-child(-n+2)]:border-b-0">
      <div className="flex items-center gap-1.5 text-xs text-slate-400"><span className="text-gold-600">{icon}</span>{label}</div>
      <div className="mt-1 font-bold text-navy-900">{value}</div>
    </div>
  )
}
