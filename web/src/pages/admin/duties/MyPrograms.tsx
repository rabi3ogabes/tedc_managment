import clsx from 'clsx'
import { BookOpen, CalendarClock, ChevronDown, ClipboardCheck, ClipboardList, Copy, DoorOpen, ExternalLink, GraduationCap, Library, NotebookPen, QrCode, Radio, UserCog, Users } from 'lucide-react'
import { QRCodeSVG } from 'qrcode.react'
import { useEffect, useState, type ComponentType, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Badge, Button, Card, Empty, ErrorState, Modal, PageHeader, Progress, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { toast } from '@/lib/toast'
import { AttendanceModal } from '../program/SessionsTab'
import { StatusPill, type ReflectionForm, type TraineeForm } from '../saber/FormLinks'
import { fmtClock, fmtDateTime, pickBi } from '../teaching/api'
import { attendancePercent, filterGroups, focusOf, meFirst, showsQr, todayKey, type Capacity, type Duties, type DutyGroup, type DutySession, type Filter, type Phase } from './model'

/**
 * «برامجي»: the programme groups the person supervises or teaches, whichever screen assigned them («البرامج» or «سابر+»).
 * Each group shows when and where it runs and with whom, the session to act on with its attendance, the attendance code
 * to put on the screen, the full attendance sheet, and the programme's two forms. One page for the supervisor and the
 * trainer: what differs is said on the card (the capacity), not hidden in another screen.
 */
export default function MyPrograms() {
  const { t } = useTranslation()
  const { data, isLoading, error, refetch } = useGet<{ data: Duties }>('/me/duties', undefined, { staleTime: 0, refetchInterval: 60_000 })
  const [filter, setFilter] = useState<Filter>({ capacity: 'all', phase: 'all' })
  if (isLoading) return <Spinner />
  if (error || !data) return <ErrorState error={error} onRetry={() => void refetch()} />
  const { groups, summary } = data.data
  const shown = filterGroups(groups, filter)
  const both = summary.supervising > 0 && summary.teaching > 0
  const phases = (['running', 'upcoming', 'ended'] as const).filter((p) => groups.some((g) => g.phase === p))

  return (
    <div className="mx-auto max-w-6xl">
      <PageHeader title={t('duties.title')} subtitle={t('duties.subtitle')} />
      {!groups.length ? (
        <Card><Empty text={t('duties.none')} icon={<ClipboardCheck className="size-6" />} /><p className="mx-auto -mt-2 max-w-md pb-4 text-center text-sm leading-7 text-slate-500">{t('duties.noneHint')}</p></Card>
      ) : (
        <>
          <div className="mb-5 flex flex-wrap items-center gap-x-6 gap-y-3 rounded-2xl border border-navy-100 bg-white px-5 py-3.5">
            <p className="flex items-center gap-2 font-bold text-navy-900"><span className={clsx('size-2.5 rounded-full', summary.today ? 'animate-pulse bg-emerald-500 motion-reduce:animate-none' : 'bg-slate-300')} aria-hidden />{t(`duties.summary.${todayKey(summary.today)}`, { n: summary.today })}</p>
            <p className="text-sm text-slate-500">{summary.running} {t('duties.summary.running')}، {summary.upcoming} {t('duties.summary.upcoming')}</p>
            <div className="ms-auto flex flex-wrap items-center gap-2">
              {both && <Chips label={t('duties.capacity.all')} value={filter.capacity} onChange={(capacity) => setFilter({ ...filter, capacity })}
                options={[['all', t('duties.capacity.all')], ['supervisor', `${t('duties.capacity.supervising')} (${summary.supervising})`], ['trainer', `${t('duties.capacity.teaching')} (${summary.teaching})`]]} />}
              {phases.length > 1 && <Chips label={t('duties.phase.all')} value={filter.phase} onChange={(phase) => setFilter({ ...filter, phase })}
                options={[['all', t('duties.phase.all')], ...phases.map((p) => [p, t(`duties.phase.${p}`)] as [Phase, string])]} />}
            </div>
          </div>
          {!shown.length ? (
            <Card><Empty text={t('duties.noneFiltered')} /><div className="pb-4 text-center"><Button variant="outline" size="sm" onClick={() => setFilter({ capacity: 'all', phase: 'all' })}>{t('duties.showAll')}</Button></div></Card>
          ) : (
            <div className="space-y-5">{shown.map((g) => <GroupCard key={g.id} g={g} onChanged={() => void refetch()} />)}</div>
          )}
          <p className="mt-4 text-center text-xs text-slate-400">{t('duties.updated')}</p>
        </>
      )}
    </div>
  )
}

function Chips<T extends string>({ label, value, options, onChange }: { label: string; value: T; options: [T, string][]; onChange: (v: T) => void }) {
  return (
    <div role="radiogroup" aria-label={label} className="inline-flex rounded-xl bg-ivory p-1 ring-1 ring-navy-100">
      {options.map(([id, text]) => (
        <button key={id} type="button" role="radio" aria-checked={value === id} onClick={() => onChange(id)}
          className={clsx('rounded-lg px-3 py-1.5 text-xs font-bold transition-colors', value === id ? 'bg-navy-900 text-white shadow-sm' : 'text-slate-600 hover:text-navy-900')}>{text}</button>
      ))}
    </div>
  )
}

const phaseTone: Record<Phase, string> = { running: 'bg-emerald-50 text-emerald-800 ring-emerald-200', upcoming: 'bg-sky-50 text-sky-800 ring-sky-200', ended: 'bg-slate-100 text-slate-600 ring-slate-200' }
const capacityIcon: Record<Capacity, ComponentType<{ className?: string }>> = { supervisor: UserCog, trainer: GraduationCap }

function GroupCard({ g, onChanged }: { g: DutyGroup; onChanged: () => void }) {
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  const { can } = useAuth()
  const [open, setOpen] = useState(false)
  const [sheet, setSheet] = useState<DutySession | null>(null)
  const [showSurvey, setShowSurvey] = useState(false)
  const focus = focusOf(g)
  const held = g.sessions.filter((s) => s.when === 'past').length
  const title = lang === 'en' ? g.program.title_en : g.program.title_ar
  const mayAttend = can('attendance.manage')
  const closeSheet = () => { setSheet(null); onChanged() }
  const sessionTitle = (s: DutySession) => (lang === 'en' ? s.title_en : s.title_ar) || t('duties.session.n', { n: s.sequence ?? '' })
  const people = (list: { ar: string; en: string; me: boolean }[]) => meFirst(list).map((p, i) => <span key={i}>{i > 0 && '، '}{pickBi(p, lang)}{p.me && <span className="ms-1 rounded-full bg-gold-100 px-1.5 py-0.5 text-[10px] font-bold text-gold-800">{t('duties.you')}</span>}</span>)

  return (
    <Card className="overflow-hidden !p-0">
      <div className="flex flex-wrap items-start gap-5 p-5">
        <div className="min-w-0 flex-1">
          <div className="flex flex-wrap items-center gap-2">
            <Badge color="gold" className="font-mono">{g.program.code}</Badge>
            <span className={clsx('inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 text-[11px] font-bold ring-1 ring-inset', phaseTone[g.phase])}><span className="size-1.5 rounded-full bg-current" />{t(`duties.phase.${g.phase}`)}</span>
            {g.capacities.map((c) => { const Icon = capacityIcon[c]; return <span key={c} className="inline-flex items-center gap-1.5 rounded-full bg-navy-900 px-2.5 py-1 text-[11px] font-bold text-white"><Icon className="size-3.5 text-gold-300" />{t(`duties.capacity.${c}`)}</span> })}
          </div>
          <h2 className="mt-2.5 text-xl font-bold text-navy-900" dir="auto">{title}</h2>
          {!g.group.only && <p className="mt-0.5 text-sm font-semibold text-gold-800" dir="auto">{lang === 'en' ? g.group.title_en : g.group.title_ar}</p>}
          <dl className="mt-4 grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2">
            <Fact icon={CalendarClock} label={t('duties.when')}>{g.from ? <>{fmtDateTime(g.from, lang)}{g.to && <> ← {fmtDateTime(g.to, lang)}</>}</> : '—'}</Fact>
            <Fact icon={DoorOpen} label={t('duties.place')}>{g.program.delivery_mode === 'online' ? t('duties.online') : g.rooms.map((r) => pickBi(r, lang)).join('، ') || t('duties.placeTbd')}</Fact>
            <Fact icon={Users} label={t('duties.trainees')}>{t('duties.traineesN', { n: g.trainees })}</Fact>
            <Fact icon={GraduationCap} label={t('duties.trainers')}>{g.trainers.length ? people(g.trainers) : t('duties.noTrainers')}</Fact>
            <Fact icon={UserCog} label={t('duties.supervisor')}>{g.supervisor ? people([g.supervisor]) : t('duties.noSupervisor')}</Fact>
          </dl>
        </div>
        <FocusSession g={g} session={focus} title={focus ? sessionTitle(focus) : ''} mayAttend={mayAttend} onSheet={() => focus && setSheet(focus)} />
      </div>

      {g.forms && (
        <div className="grid gap-px border-t border-navy-100 bg-navy-100 sm:grid-cols-2">
          <FormRow icon={ClipboardList} title={t('duties.forms.trainee')} pill={<StatusPill kind="trainee" form={g.forms.trainee as TraineeForm} />} line={t('duties.forms.answered', { answered: g.forms.trainee.answered, total: g.forms.trainee.expected ?? 0 })}>
            <Button size="sm" variant="outline" icon={<QrCode className="size-4" />} onClick={() => setShowSurvey(true)}>{t('duties.forms.show')}</Button>
          </FormRow>
          <FormRow icon={NotebookPen} title={t('duties.forms.reflection')} pill={<StatusPill kind="reflection" form={g.forms.reflection as ReflectionForm} />} line={t('duties.forms.answered', { answered: g.forms.reflection.answered, total: g.forms.reflection.assigned ?? 0 })}>
            {g.capacities.includes('trainer') && <a href={g.forms.reflection.url} className="inline-flex items-center gap-1.5 rounded-xl border border-navy-100 bg-white px-3 py-1.5 text-xs font-bold text-navy-900 transition-colors hover:border-gold-400"><ExternalLink className="size-3.5" />{t('duties.forms.mine')}</a>}
          </FormRow>
        </div>
      )}

      {g.sessions.length > 0 && (
        <>
          <button type="button" onClick={() => setOpen((v) => !v)} aria-expanded={open} className="flex w-full items-center justify-between border-t border-navy-100 bg-ivory px-5 py-3 text-sm font-bold text-navy-900 transition-colors hover:bg-gold-50">
            <span className="inline-flex items-center gap-2"><CalendarClock className="size-4 text-gold-600" />{t('duties.session.all')}<span className="font-semibold text-slate-500">· {t('duties.session.count', { done: held, total: g.sessions.length })}</span></span>
            <ChevronDown className={clsx('size-4 transition-transform', open && 'rotate-180')} />
          </button>
          {open && (
            <ul className="divide-y divide-navy-100/70">
              {g.sessions.map((s) => (
                <li key={s.id} className={clsx('flex flex-wrap items-center gap-x-4 gap-y-2 px-5 py-3', s.when === 'now' && 'bg-emerald-50/50')}>
                  <div className="min-w-0 flex-1">
                    <div className="flex flex-wrap items-center gap-2 font-semibold text-navy-900" dir="auto">{sessionTitle(s)}<WhenPill when={s.when} /></div>
                    <div className="text-xs text-slate-500">{fmtDateTime(s.starts_at, lang)}{s.place ? ` · ${pickBi(s.place, lang)}` : ''}</div>
                  </div>
                  {s.when !== 'later' && s.expected > 0 && <div className="w-40"><div className="mb-1 text-xs font-semibold text-slate-600">{t('duties.attendance.of', { present: s.present, expected: s.expected })}</div><Progress value={attendancePercent(s.present, s.expected)} tone={s.when === 'now' ? 'green' : 'gold'} /></div>}
                  {mayAttend && (
                    <div className="flex gap-1.5">
                      {showsQr(s) && <Button size="sm" variant="gold" icon={<QrCode className="size-4" />} onClick={() => window.open(`/admin/sessions/${s.id}/qr`, '_blank', 'noopener')}>{t('duties.attendance.qr')}</Button>}
                      <Button size="sm" variant="outline" icon={<ClipboardCheck className="size-4" />} onClick={() => setSheet(s)}>{t('duties.attendance.sheet')}</Button>
                    </div>
                  )}
                </li>
              ))}
            </ul>
          )}
        </>
      )}

      <div className="flex flex-wrap items-center gap-x-5 gap-y-2 border-t border-navy-100 px-5 py-3 text-sm">
        {g.capacities.includes('trainer') && can('trainers.respond') && <Link to={`/admin/teaching?program=${g.program.id}`} className="inline-flex items-center gap-1.5 font-bold text-link"><Library className="size-4" />{t('duties.more.teaching')}</Link>}
        {can('programs.view') && <Link to={`/admin/programs/${g.program.id}`} className="inline-flex items-center gap-1.5 font-bold text-link"><BookOpen className="size-4" />{t('duties.more.program')}</Link>}
        {!mayAttend && <span className="text-xs text-slate-500">{t('duties.attendance.noRight')}</span>}
      </div>

      {sheet && <AttendanceModal session={{ id: sheet.id, title: `${sessionTitle(sheet)} — ${title}` }} onClose={closeSheet} />}
      {showSurvey && g.forms && <SurveyOnScreen title={title} url={g.forms.trainee.url} open={g.forms.trainee.status === 'open'} onClose={() => setShowSurvey(false)} />}
    </Card>
  )
}

function Fact({ icon: Icon, label, children }: { icon: ComponentType<{ className?: string }>; label: string; children: ReactNode }) {
  return <div className="flex items-start gap-2"><Icon className="mt-0.5 size-4 shrink-0 text-gold-600" /><div className="min-w-0"><dt className="text-[11px] font-semibold text-slate-500">{label}</dt><dd className="text-navy-900" dir="auto">{children}</dd></div></div>
}

function WhenPill({ when }: { when: DutySession['when'] }) {
  const { t } = useTranslation()
  const tone = { past: 'bg-slate-100 text-slate-600', now: 'bg-emerald-100 text-emerald-800', today: 'bg-gold-100 text-gold-800', later: 'bg-sky-50 text-sky-800' }[when]
  return <span className={clsx('inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-bold', tone)}>{when === 'now' && <Radio className="size-3 animate-pulse motion-reduce:animate-none" />}{t(`duties.session.status.${when}`)}</span>
}

/** The session to act on: what it is, when, the count of who came, and the two things to do with it. */
function FocusSession({ g, session: s, title, mayAttend, onSheet }: { g: DutyGroup; session: DutySession | null; title: string; mayAttend: boolean; onSheet: () => void }) {
  const { t, i18n } = useTranslation()
  const lang = i18n.language === 'en' ? 'en' : 'ar'
  // The countdown ticks only while there is something to count down to.
  const counting = s?.when === 'today'
  const [now, setNow] = useState(() => Date.now())
  useEffect(() => { if (!counting) return; const id = window.setInterval(() => setNow(Date.now()), 1000); return () => window.clearInterval(id) }, [counting])
  if (!s) return <div className="w-full shrink-0 rounded-2xl bg-ivory p-4 text-sm text-slate-500 ring-1 ring-navy-100 sm:w-80">{t('duties.session.none')}</div>
  const label = s.when === 'now' ? 'now' : s.when === 'today' ? 'today' : s.when === 'later' ? 'next' : 'last'
  const left = new Date(s.starts_at).getTime() - now

  return (
    <div className={clsx('w-full shrink-0 rounded-2xl p-4 text-white sm:w-80', s.when === 'now' ? 'bg-gradient-to-br from-navy-900 to-navy-700 ring-2 ring-gold-400' : 'bg-navy-900')}>
      <div className="flex items-center gap-2 text-xs font-bold text-gold-300">{s.when === 'now' && <Radio className="size-3.5 animate-pulse motion-reduce:animate-none" />}{t(`duties.session.${label}`)}</div>
      <div className="mt-1 font-bold" dir="auto">{title}</div>
      <div className="mt-1 text-xs text-white/70">{fmtDateTime(s.starts_at, lang)}{s.place ? ` · ${pickBi(s.place, lang)}` : ''}</div>
      {counting && left > 0 && <div className="mt-2 text-xs text-white/70">{t('duties.session.startsIn')} <span className="font-display text-xl font-bold tabular-nums text-gold-200" dir="ltr">{fmtClock(left)}</span></div>}
      {s.when !== 'later' && (
        <div className="mt-3">
          <div className="mb-1.5 flex items-baseline justify-between text-xs"><span className="font-semibold text-white/80">{t('duties.attendance.title')}</span><span className="font-display text-lg font-bold tabular-nums text-gold-200">{s.expected > 0 ? t('duties.attendance.of', { present: s.present, expected: s.expected }) : '—'}</span></div>
          {s.expected > 0 ? <div className="h-2 overflow-hidden rounded-full bg-white/15"><div className="h-full rounded-full bg-gradient-to-l from-gold-300 to-gold-500 transition-[width] duration-500 motion-reduce:transition-none" style={{ width: `${attendancePercent(s.present, s.expected)}%` }} /></div> : <p className="text-xs text-white/60">{t('duties.attendance.nobody')}</p>}
        </div>
      )}
      {mayAttend && (
        <div className="mt-4 flex flex-wrap gap-2">
          {showsQr(s) && <Button size="sm" variant="gold" icon={<QrCode className="size-4" />} title={t('duties.attendance.qrHint')} onClick={() => window.open(`/admin/sessions/${s.id}/qr`, '_blank', 'noopener')}>{t('duties.attendance.qr')}</Button>}
          <button type="button" onClick={onSheet} className="inline-flex items-center gap-1.5 rounded-xl bg-white/10 px-3 py-2 text-xs font-bold text-white transition-colors hover:bg-white/20"><ClipboardCheck className="size-4" />{t('duties.attendance.sheet')}</button>
        </div>
      )}
      {mayAttend && !showsQr(s) && s.when === 'later' && g.program.delivery_mode !== 'online' && <p className="mt-2 text-[11px] text-white/55">{t('duties.attendance.qrLater')}</p>}
    </div>
  )
}

function FormRow({ icon: Icon, title, pill, line, children }: { icon: ComponentType<{ className?: string }>; title: string; pill: ReactNode; line: string; children?: ReactNode }) {
  return (
    <div className="flex flex-wrap items-center gap-3 bg-white px-5 py-3.5">
      <span className="grid size-9 shrink-0 place-items-center rounded-xl bg-ivory text-gold-700 ring-1 ring-navy-100"><Icon className="size-4" /></span>
      <div className="min-w-0 flex-1"><div className="flex flex-wrap items-center gap-2 text-sm font-bold text-navy-900">{title}{pill}</div><div className="text-xs text-slate-500">{line}</div></div>
      {children}
    </div>
  )
}

/** The trainee survey of a programme as a code large enough for a room: scanned from the screen, it opens each trainee's own survey. */
function SurveyOnScreen({ title, url, open, onClose }: { title: string; url: string; open: boolean; onClose: () => void }) {
  const { t } = useTranslation()
  const copy = async () => { try { await navigator.clipboard.writeText(url); toast(t('duties.forms.copied')) } catch { toast(url, 'info') } }
  return (
    <Modal open onClose={onClose} title={t('duties.forms.showTitle', { title })} wide>
      <div className="flex flex-col items-center gap-5 text-center">
        <div className="rounded-3xl bg-white p-5 shadow-glass ring-1 ring-navy-100"><QRCodeSVG value={url} size={Math.min(360, typeof window === 'undefined' ? 360 : window.innerWidth - 120)} level="M" fgColor="#3d0a1c" /></div>
        <p className="max-w-lg text-sm leading-7 text-slate-600">{t('duties.forms.showHint')}</p>
        {!open && <p role="note" className="rounded-xl bg-amber-50 px-4 py-2 text-xs leading-6 text-amber-900">{t('duties.forms.notOpen')}</p>}
        <div className="max-w-full truncate rounded-xl bg-ivory px-4 py-2 font-mono text-xs text-navy-900 ring-1 ring-navy-100" dir="ltr">{url}</div>
        <div className="flex flex-wrap justify-center gap-2">
          <Button variant="outline" icon={<Copy className="size-4" />} onClick={() => void copy()}>{t('duties.forms.copy')}</Button>
          <a href={url} target="_blank" rel="noopener noreferrer" className="inline-flex items-center gap-2 rounded-xl bg-navy-900 px-4 py-2.5 text-sm font-bold text-white transition-colors hover:bg-navy-800"><ExternalLink className="size-4" />{t('duties.forms.open')}</a>
        </div>
      </div>
    </Modal>
  )
}
