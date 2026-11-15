import clsx from 'clsx'
import { ArrowLeft, ArrowRight, BookOpen, CalendarRange, CheckCircle2, Clock, DoorOpen, GraduationCap, Lightbulb, LightbulbOff, Lock, PackageOpen, QrCode, Search, ShieldCheck, Star, Target, Users } from 'lucide-react'
import { useMemo, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Avatar, Button, Progress } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { StateChip } from './StateChip'
import { trainedText } from './text'
import type { BoardRoom, Card as CardData, Counts, RosterRow, SessionBrief, TrainerBrief } from './types'

function Section({ icon, title, children, aside }: { icon: ReactNode; title: string; children: ReactNode; aside?: ReactNode }) {
  return (
    <section className="border-t border-navy-100/70 px-5 py-4">
      <div className="mb-3 flex items-center justify-between gap-3">
        <h3 className="flex items-center gap-2 text-sm font-bold text-navy-900"><span className="text-gold-600">{icon}</span>{title}</h3>
        {aside}
      </div>
      {children}
    </section>
  )
}

function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <div className="min-w-0">
      <dt className="text-[11px] text-slate-500">{label}</dt>
      <dd className="truncate text-sm font-semibold text-ink">{children}</dd>
    </div>
  )
}

function AttendanceDial({ counts, inRoom }: { counts: Counts; inRoom: number }) {
  const { t } = useTranslation()
  const r = 34
  const c = 2 * Math.PI * r
  const share = counts.expected > 0 ? Math.min(1, counts.present / counts.expected) : 0
  return (
    <div className="flex items-center gap-4">
      <div className="relative size-[88px] shrink-0">
        <svg viewBox="0 0 88 88" className="size-full -rotate-90">
          <circle cx="44" cy="44" r={r} fill="none" strokeWidth="9" className="stroke-navy-100" />
          <circle cx="44" cy="44" r={r} fill="none" strokeWidth="9" strokeLinecap="round" className="stroke-navy-900 transition-[stroke-dashoffset] duration-700" strokeDasharray={c} strokeDashoffset={c * (1 - share)} />
        </svg>
        <div className="absolute inset-0 grid place-items-center text-center"><span className="font-display text-xl font-bold text-navy-900 tabular-nums">{fmt.percent(counts.percent)}</span></div>
      </div>
      <dl className="grid flex-1 grid-cols-3 gap-x-3 gap-y-2">
        <Fact label={String(t('liveT.card.expected'))}><span className="tabular-nums">{counts.expected}</span></Fact>
        <Fact label={String(t('liveT.card.present'))}><span className="tabular-nums text-emerald-700">{counts.present}</span></Fact>
        <Fact label={String(t('liveT.card.inRoom'))}><span className="tabular-nums">{inRoom}</span></Fact>
        <Fact label={String(t('liveT.card.late'))}><span className="tabular-nums text-amber-700">{counts.late}</span></Fact>
        <Fact label={String(t('liveT.card.left'))}><span className="tabular-nums">{counts.left}</span></Fact>
        <Fact label={String(t('liveT.card.absent'))}><span className="tabular-nums text-red-700">{counts.absent}</span></Fact>
      </dl>
    </div>
  )
}

function TrainerRow({ tr }: { tr: TrainerBrief }) {
  const { t } = useTranslation()
  return (
    <li className="flex items-start gap-3">
      <Avatar name={tr.name} src={tr.photo} size={44} />
      <div className="min-w-0 flex-1">
        <p className="truncate text-sm font-bold text-ink">{tr.name}</p>
        <p className="text-xs text-slate-500">{tr.role === 'lead' ? t('liveT.card.trainer') : t('liveT.card.coTrainer')}{tr.source ? ` — ${tr.source}` : ''}</p>
        <p className="mt-1 text-xs font-semibold text-navy-800">{trainedText(tr.programs_count, t)}</p>
        <div className="mt-1.5 flex flex-wrap items-center gap-2 text-[11px]">
          <span className={clsx('inline-flex items-center gap-1 rounded-full px-2 py-0.5 font-semibold', tr.checked_in ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500')}>
            {tr.checked_in && <CheckCircle2 className="size-3" />}{tr.checked_in ? t('liveT.card.checkedIn') : t('liveT.card.notYet')}
          </span>
          {tr.rating !== null && tr.rating > 0 && <span className="inline-flex items-center gap-1 text-slate-600"><Star className="size-3 fill-gold-500 text-gold-500" />{t('liveT.card.rating', { n: fmt.number(tr.rating, 1) })}</span>}
        </div>
      </div>
    </li>
  )
}

const ROSTER_TONE: Record<RosterRow['status'], string> = {
  present: 'bg-emerald-50 text-emerald-700', late: 'bg-amber-50 text-amber-700', left: 'bg-sky-50 text-sky-700', excused: 'bg-slate-100 text-slate-600', expected: 'bg-navy-50 text-navy-700', absent: 'bg-red-50 text-red-700',
}

function Roster({ rows }: { rows: RosterRow[] }) {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const shown = useMemo(() => rows.filter((r) => !q.trim() || r.name.toLowerCase().includes(q.trim().toLowerCase())), [rows, q])
  if (rows.length === 0) return <p className="text-sm text-slate-500">{t('liveT.card.noRoster')}</p>
  return (
    <div>
      {rows.length > 8 && (
        <label className="relative mb-3 block">
          <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
          <input type="search" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('liveT.card.search'))} aria-label={String(t('liveT.card.search'))} className="input !py-2 ps-9" />
        </label>
      )}
      {shown.length === 0 ? <p className="text-sm text-slate-500">{t('liveT.card.noMatch')}</p> : (
        <ul className="divide-y divide-navy-100/60">
          {shown.map((r) => (
            <li key={r.id} className="flex items-center gap-3 py-2">
              <div className="min-w-0 flex-1">
                <p className="truncate text-sm font-semibold text-ink">{r.name}</p>
                {r.school && <p className="truncate text-[11px] text-slate-500">{r.school}</p>}
              </div>
              {r.check_in_at && <span className="shrink-0 text-xs tabular-nums text-slate-500">{fmt.time(r.check_in_at)}</span>}
              <span className={clsx('shrink-0 rounded-full px-2 py-0.5 text-[11px] font-bold', ROSTER_TONE[r.status])}>{t(`liveT.roster.${r.status}`)}</span>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}

/**
 * The training card of one classroom: opened by pressing a room. The top is drawn at once from what the board already knows;
 * the programme, the days and the roster arrive a moment later.
 */
export default function TrainingCard({ room, session, date, now, onBack, onPick }: {
  room: BoardRoom; session: SessionBrief | null; date: string; now: Date; onBack: () => void; onPick: (sessionId: string) => void
}) {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const Back = i18n.dir() === 'rtl' ? ArrowRight : ArrowLeft
  const { data, error, refetch } = useGet<{ data: CardData }>(session ? `/admin/live-training/sessions/${session.id}` : null, undefined, { refetchInterval: session?.state === 'live' ? 10_000 : false, staleTime: 0 })
  const card = data?.data && data.data.session.id === session?.id ? data.data : null

  const header = (
    <div className="flex items-center justify-between gap-3 px-5 pb-3 pt-4">
      <button type="button" onClick={onBack} className="inline-flex items-center gap-1.5 rounded-lg px-2 py-1.5 text-sm font-bold text-navy-900 hover:bg-navy-50 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-gold-500"><Back className="size-4" />{t('liveT.card.back')}</button>
      <span className="text-xs font-semibold text-slate-500">{t('liveT.card.title')}</span>
    </div>
  )

  if (!session) {
    return (
      <article className="card overflow-hidden !p-0" aria-label={String(t('liveT.card.title'))}>
        {header}
        <div className="px-5 pb-6 text-center">
          <span className="mx-auto grid size-14 place-items-center rounded-2xl bg-slate-100 text-slate-400"><LightbulbOff className="size-7" /></span>
          <h2 className="mt-3 text-xl font-bold text-navy-900">{room.name}</h2>
          <p className="mt-1 text-sm font-semibold text-slate-500">{t('liveT.card.darkTitle')}</p>
          <p className="mx-auto mt-2 max-w-xs text-sm leading-7 text-slate-600">{t('liveT.card.darkText', { date: fmt.date(`${date}T12:00:00`) })}</p>
          <dl className="mx-auto mt-4 grid max-w-xs grid-cols-2 gap-3 text-start">
            {room.floor && <Fact label={String(t('liveT.card.floor'))}>{room.floor}</Fact>}
            <Fact label={String(t('liveT.card.capacity'))}>{t('liveT.card.seats', { n: room.capacity })}</Fact>
          </dl>
          {can('programs.view') && <Button to="/admin/room-ops" variant="outline" size="sm" className="mt-5" icon={<CalendarRange className="size-4" />}>{t('liveT.card.bookRoom')}</Button>}
        </div>
      </article>
    )
  }

  const startsIn = Math.round((new Date(session.starts_at).getTime() - now.getTime()) / 60_000)
  const remaining = Math.round((new Date(session.ends_at).getTime() - now.getTime()) / 60_000)
  const span = new Date(session.ends_at).getTime() - new Date(session.starts_at).getTime()
  const elapsed = span > 0 ? Math.max(0, Math.min(100, ((now.getTime() - new Date(session.starts_at).getTime()) / span) * 100)) : 0
  const counts = card?.counts ?? session.counts
  const trainers = card?.trainers ?? session.trainers
  const supervisor = card?.supervisor ?? session.supervisor
  const kit = card?.kit ?? session.kit
  const stateLabel = session.state === 'live' ? t('liveT.state.live') : session.state === 'ended' ? t('liveT.state.ended') : t('liveT.state.later')

  return (
    <article className="card overflow-hidden !p-0" aria-label={String(t('liveT.card.title'))}>
      {header}

      <div className="px-5 pb-4">
        <div className="flex flex-wrap items-center gap-2">
          <StateChip state={session.state} label={String(stateLabel)} />
          <span className="inline-flex items-center gap-1 text-xs font-semibold text-slate-500"><DoorOpen className="size-3.5" />{room.name}{room.floor ? ` — ${room.floor}` : ''}</span>
        </div>
        <h2 className="mt-2 text-xl font-bold leading-snug text-navy-900">{session.program.title}</h2>
        <p className="mt-1 text-sm text-slate-600">
          {[session.program.code, card?.program.type, session.group?.title].filter(Boolean).join(' — ')}
        </p>
        <div className="mt-3 rounded-2xl bg-ivory px-4 py-3">
          <div className="flex items-center justify-between gap-3 text-sm">
            <span className="inline-flex items-center gap-2 font-bold text-ink"><Clock className="size-4 text-gold-600" /><span className="tabular-nums">{fmt.time(session.starts_at)} – {fmt.time(session.ends_at)}</span></span>
            <span className="text-xs font-semibold text-slate-600">
              {session.state === 'live' ? t('liveT.card.remaining', { m: Math.max(remaining, 0) }) : session.state === 'ended' ? t('liveT.card.endedAt', { time: fmt.time(session.ends_at) }) : startsIn <= 600 && startsIn > 0 ? t('liveT.card.startsIn', { m: startsIn }) : fmt.date(session.starts_at, { weekday: 'long', day: 'numeric', month: 'long' })}
            </span>
          </div>
          {session.state === 'live' && <Progress value={elapsed} tone="navy" className="mt-2.5 !h-1.5" />}
          <p className="mt-2 text-xs text-slate-600">
            {[session.title, card?.session.number ? t('liveT.card.dayOf', { n: card.session.number, of: card.session.of }) : null, card ? t('liveT.card.minutes', { n: card.session.minutes }) : null].filter(Boolean).join(' — ')}
            {card?.session.attendance_locked && <span className="ms-2 inline-flex items-center gap-1 font-semibold text-slate-500"><Lock className="size-3" />{t('liveT.card.locked')}</span>}
          </p>
        </div>

        {room.sessions.length > 1 && (
          <div className="mt-3">
            <p className="mb-1.5 text-[11px] font-semibold text-slate-500">{t('liveT.card.sessionsToday')}</p>
            <div className="flex flex-wrap gap-1.5">
              {room.sessions.map((s) => (
                <button key={s.id} type="button" aria-pressed={s.id === session.id} onClick={() => onPick(s.id)} className={clsx('rounded-lg border px-2.5 py-1 text-xs font-bold tabular-nums transition', s.id === session.id ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-navy-800 hover:border-gold-400')}>
                  <span dir="ltr">{fmt.time(s.starts_at)}</span>
                </button>
              ))}
            </div>
          </div>
        )}
      </div>

      <Section icon={<Users className="size-4" />} title={String(t('liveT.card.attendance'))}>
        <AttendanceDial counts={counts} inRoom={session.present.length} />
      </Section>

      <Section icon={<GraduationCap className="size-4" />} title={String(t('liveT.card.people'))}>
        {trainers.length === 0 ? <p className="text-sm text-slate-500">{t('liveT.card.noTrainer')}</p> : <ul className="space-y-3">{trainers.map((tr) => <TrainerRow key={tr.id} tr={tr} />)}</ul>}
        <div className="mt-3 flex items-center gap-3 rounded-xl bg-gold-50 px-3 py-2.5">
          <span className="grid size-9 shrink-0 place-items-center rounded-full bg-gold-500 text-white"><ShieldCheck className="size-4" /></span>
          {supervisor ? (
            <div className="min-w-0">
              <p className="text-[11px] text-slate-500">{t('liveT.card.supervisor')}</p>
              <p className="truncate text-sm font-bold text-ink">{supervisor.name}</p>
              {supervisor.email && <p className="truncate text-[11px] text-slate-500" dir="ltr">{supervisor.email}</p>}
            </div>
          ) : <p className="text-sm text-slate-500">{t('liveT.card.noSupervisor')}</p>}
        </div>
      </Section>

      <Section icon={<PackageOpen className="size-4" />} title={String(t('liveT.card.kit'))}>
        {kit ? (
          <div className="flex items-center justify-between gap-3">
            <div className="min-w-0">
              <p className="truncate text-sm font-bold text-ink">{kit.title}</p>
              <p className="text-xs text-slate-500">{[kit.code, t('liveT.card.version', { n: kit.version })].filter(Boolean).join(' — ')}</p>
            </div>
            {can('kits.view') && <Button to={`/admin/kits/${kit.id}`} variant="outline" size="sm">{t('liveT.card.openKit')}</Button>}
          </div>
        ) : <p className="text-sm text-slate-500">{t('liveT.card.noKit')}</p>}
      </Section>

      {error ? (
        <div className="border-t border-navy-100/70 px-5 py-5 text-center">
          <p className="text-sm text-slate-600">{t('liveT.card.loadFailed')}</p>
          <Button variant="outline" size="sm" className="mt-3" onClick={() => void refetch()}>{t('liveT.card.retry')}</Button>
        </div>
      ) : !card ? (
        <div className="space-y-3 border-t border-navy-100/70 px-5 py-5" aria-hidden>
          {[70, 92, 55, 80].map((w) => <div key={w} className="h-3 animate-pulse rounded-full bg-navy-100/70" style={{ width: `${w}%` }} />)}
        </div>
      ) : (
        <>
          <Section icon={<BookOpen className="size-4" />} title={String(t('liveT.card.program'))}>
            {card.program.summary && <p className="mb-3 text-sm leading-7 text-slate-600">{card.program.summary}</p>}
            <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
              {card.program.type && <Fact label={String(t('liveT.card.type'))}>{card.program.type}</Fact>}
              {card.program.category && <Fact label={String(t('liveT.card.category'))}>{card.program.category}</Fact>}
              {card.program.level && <Fact label={String(t('liveT.card.level'))}>{t(`levels.${card.program.level}`, { defaultValue: card.program.level })}</Fact>}
              {card.program.total_hours !== null && <Fact label={String(t('liveT.card.hours'))}>{t('liveT.card.hoursN', { n: fmt.number(card.program.total_hours, 1) })}</Fact>}
              {card.program.delivery_mode && <Fact label={String(t('liveT.card.mode'))}>{t(`modes.${card.program.delivery_mode}`, { defaultValue: card.program.delivery_mode })}</Fact>}
              {card.group && <Fact label={String(t('liveT.card.group'))}>{card.group.title}{card.group.capacity ? ` (${card.group.capacity})` : ''}</Fact>}
              {(card.group?.start_date ?? card.program.start_date) && (
                <div className="col-span-2"><Fact label={String(t('liveT.card.dates'))}>{fmt.date(card.group?.start_date ?? card.program.start_date, { day: 'numeric', month: 'short' })} — {fmt.date(card.group?.end_date ?? card.program.end_date, { day: 'numeric', month: 'short', year: 'numeric' })}</Fact></div>
              )}
            </dl>
          </Section>

          {card.program.objectives.length > 0 && (
            <Section icon={<Target className="size-4" />} title={String(t('liveT.card.objectives'))}>
              <ul className="list-disc space-y-1.5 ps-5 text-sm leading-7 text-slate-700 marker:text-gold-500">{card.program.objectives.map((o, i) => <li key={i}>{o}</li>)}</ul>
            </Section>
          )}

          {card.program.units.length > 0 && (
            <Section icon={<BookOpen className="size-4" />} title={String(t('liveT.card.units'))}>
              <ol className="space-y-1.5 text-sm text-slate-700">{card.program.units.map((u, i) => <li key={i} className="flex items-baseline justify-between gap-3"><span>{u.title}</span>{u.hours !== null && <span className="shrink-0 text-xs tabular-nums text-slate-500">{t('liveT.card.hoursN', { n: fmt.number(u.hours, 1) })}</span>}</li>)}</ol>
            </Section>
          )}

          {card.room && (
            <Section icon={<Lightbulb className="size-4" />} title={String(t('liveT.card.room'))}>
              <dl className="grid grid-cols-2 gap-x-4 gap-y-3">
                <Fact label={String(t('liveT.card.room'))}>{card.room.name}{card.room.code ? ` (${card.room.code})` : ''}</Fact>
                {card.room.floor && <Fact label={String(t('liveT.card.floor'))}>{card.room.floor}</Fact>}
                <Fact label={String(t('liveT.card.capacity'))}>{t('liveT.card.seats', { n: card.room.capacity })}</Fact>
              </dl>
              {card.room.equipment.length > 0 && <div className="mt-3 flex flex-wrap gap-1.5">{card.room.equipment.map((e) => <span key={e.key} className="rounded-full bg-navy-50 px-2.5 py-0.5 text-[11px] font-semibold text-navy-800">{e.label}{e.qty > 1 ? ` × ${e.qty}` : ''}</span>)}</div>}
            </Section>
          )}

          {card.schedule.length > 1 && (
            <Section icon={<CalendarRange className="size-4" />} title={String(t('liveT.card.schedule'))}>
              <ol className="space-y-1">
                {card.schedule.map((s, i) => (
                  <li key={s.id} className={clsx('flex items-center gap-3 rounded-lg px-2 py-1.5 text-sm', s.current && 'bg-navy-50 font-bold text-navy-900')}>
                    <span className={clsx('grid size-6 shrink-0 place-items-center rounded-full text-[11px] font-bold tabular-nums', s.state === 'ended' ? 'bg-emerald-100 text-emerald-700' : s.state === 'live' ? 'bg-navy-900 text-white' : 'bg-slate-100 text-slate-500')}>{i + 1}</span>
                    <span className="min-w-0 flex-1 truncate">{fmt.date(s.starts_at, { weekday: 'short', day: 'numeric', month: 'short' })}</span>
                    <span className="shrink-0 text-xs tabular-nums text-slate-500">{fmt.time(s.starts_at)} – {fmt.time(s.ends_at)}</span>
                  </li>
                ))}
              </ol>
            </Section>
          )}

          <Section icon={<Users className="size-4" />} title={String(t('liveT.card.roster'))} aside={<span className="text-xs tabular-nums text-slate-500">{card.roster.length}</span>}>
            <Roster rows={card.roster} />
          </Section>
        </>
      )}

      <div className="flex flex-wrap gap-2 border-t border-navy-100/70 bg-ivory/60 px-5 py-4">
        {can('programs.view') && <Link to={`/admin/programs/${session.program.id}`} className="btn inline-flex items-center gap-2 bg-navy-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-navy-800"><BookOpen className="size-4" />{t('liveT.card.openProgram')}</Link>}
        {can('attendance.manage') && session.state !== 'ended' && <Link to={`/admin/sessions/${session.id}/qr`} target="_blank" rel="noreferrer" className="btn inline-flex items-center gap-2 border border-navy-100 bg-white px-4 py-2.5 text-sm font-semibold text-navy-900 hover:border-gold-400"><QrCode className="size-4" />{t('liveT.card.openQr')}</Link>}
      </div>
    </article>
  )
}
