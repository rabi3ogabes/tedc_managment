import clsx from 'clsx'
import { ArrowRight, BookOpenCheck, CalendarDays, ChevronLeft, Clock3, ExternalLink, GraduationCap, MapPin, Search, Users, X } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useParams } from 'react-router-dom'
import { Avatar, Badge, Card, CardTitle, Empty, ErrorState, PageHeader, Spinner, StatCard } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import { Pager } from './shared'

type SchoolInfo = { id: string; code: string; name_ar: string; name_en: string; type: string; stage: string; region: string; gender: string | null; district: string | null; employees_count: number }
type Staff = { id: string; employee_no: string; name: string | null; job_title: string | null; job_title_en: string | null; unit: string | null; status: string; hire_date: string | null; courses: number; hours: number; last_course_on: string | null }
type StaffPage = { data: Staff[]; total: number; last_page: number }
type Course = { id?: string; title: string; provider?: string | null; track: string | null; type: string | null; starts_on: string | null; ends_on: string | null; hours?: number; hours_each?: number; attendees?: number; country?: string | null }
type Timeline = {
  scope: 'school' | 'employee'
  summary: { staff?: number; active_staff?: number; trained?: number; records?: number; courses?: number; hours: number; first_on?: string | null; last_on?: string | null }
  by_year: { year: number; records?: number; people?: number; courses: number; hours: number }[]
  year?: number | null
  courses: Course[]
}

/** The school's page: its staff, and the history of the training they attended as a timeline — the whole school, or one person. */
export default function SchoolDetail() {
  const { id } = useParams()
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const [sort, setSort] = useState<'number' | 'hours'>('number')
  const [person, setPerson] = useState<Staff | null>(null)
  const [year, setYear] = useState<number | undefined>(undefined)

  const school = useGet<{ data: SchoolInfo }>(`/admin/schools/${id}`)
  const staff = useGet<StaffPage>(`/admin/schools/${id}/staff`, { q: q || undefined, page, sort: sort === 'hours' ? 'hours' : undefined, per_page: 12 })
  // The school's overview (latest year) loads once; another year or one person's history is fetched only when picked.
  const overall = useGet<{ data: Timeline }>(`/admin/schools/${id}/timeline`, undefined)
  const picked = person !== null || year !== undefined
  const timeline = useGet<{ data: Timeline }>(`/admin/schools/${id}/timeline`, { employee_id: person?.id, year: person ? undefined : year }, { enabled: picked })

  if (school.isLoading) return <Spinner className="mx-auto mt-24" />
  if (school.isError || !school.data) return <ErrorState error={school.error} onRetry={() => void school.refetch()} home="/admin/schools" />
  const s = school.data.data
  const o = overall.data?.data
  const name = i18n.language === 'ar' ? s.name_ar : s.name_en
  const tl = (picked ? timeline : overall).data?.data
  const pick = (p: Staff | null) => { setPerson(p); setYear(undefined) }

  return (
    <>
      <Link to="/admin/schools" className="mb-3 inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 hover:text-navy-900"><ArrowRight className="size-4 rtl:rotate-0 ltr:rotate-180" />{t('schoolTl.back')}</Link>
      <PageHeader
        title={name}
        subtitle={<span className="inline-flex flex-wrap items-center gap-2"><span className="rounded-lg bg-navy-100/70 px-2 py-0.5 font-mono text-[11px] font-bold text-navy-800" dir="ltr">{s.code}</span><span className="inline-flex items-center gap-1"><MapPin className="size-3.5" />{[t(`regions.${s.region}`, { defaultValue: '' }), s.district].filter(Boolean).join(' · ')}</span><Badge color="navy">{t(`stages.${s.stage}`)}</Badge>{s.gender && <Badge color="navy">{t(`schoolsMap.gender.${s.gender}`)}</Badge>}</span>}
      />

      <div className="mb-6 grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <StatCard label={t('schoolTl.staff')} value={o ? fmt.number(o.summary.staff ?? 0) : '…'} icon={<Users className="size-5" />} hint={o ? t('schoolTl.activeStaff', { n: fmt.number(o.summary.active_staff ?? 0) }) : undefined} />
        <StatCard label={t('schoolTl.trained')} value={o ? fmt.number(o.summary.trained ?? 0) : '…'} icon={<GraduationCap className="size-5" />} hint={o && o.summary.staff ? t('schoolTl.trainedShare', { p: fmt.number(Math.round(((o.summary.trained ?? 0) / o.summary.staff) * 100)) }) : undefined} />
        <StatCard label={t('schoolTl.records')} value={o ? fmt.number(o.summary.records ?? 0) : '…'} icon={<BookOpenCheck className="size-5" />} />
        <StatCard label={t('schoolTl.hours')} value={o ? fmt.number(o.summary.hours) : '…'} icon={<Clock3 className="size-5" />} accent />
      </div>

      <div className="grid gap-6 lg:grid-cols-[minmax(0,5fr)_minmax(0,7fr)]">
        <Card padded={false} className="self-start">
          <div className="p-5 pb-3">
            <CardTitle subtitle={t('schoolTl.staffHint')}>{t('schoolTl.staffTitle')}</CardTitle>
            <div className="flex flex-wrap gap-2">
              <div className="relative min-w-40 flex-1"><Search className="absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" placeholder={t('schoolTl.search')} value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} /></div>
              <select className="input w-auto" value={sort} onChange={(e) => { setSort(e.target.value as 'number' | 'hours'); setPage(1) }} aria-label={t('schoolTl.sort')}><option value="number">{t('schoolTl.sortNumber')}</option><option value="hours">{t('schoolTl.sortHours')}</option></select>
            </div>
          </div>
          {staff.isLoading ? <Spinner className="mx-auto my-10" /> : !staff.data?.data.length ? <div className="px-5 pb-6"><Empty text={t('schoolTl.noStaff')} /></div> : (
            <ul className="divide-y divide-navy-100/70 border-t border-navy-100">
              {staff.data.data.map((p) => {
                const active = person?.id === p.id
                return (
                  <li key={p.id}>
                    <button type="button" onClick={() => pick(active ? null : p)} aria-pressed={active} className={clsx('flex w-full items-center gap-3 px-5 py-3 text-start transition', active ? 'bg-gold-100/50' : 'hover:bg-ivory')}>
                      <Avatar name={p.name ?? p.employee_no} size={40} />
                      <span className="min-w-0 flex-1">
                        <span className="block truncate font-bold text-navy-900">{p.name ?? p.employee_no}</span>
                        <span className="block truncate text-xs text-slate-500">{(i18n.language === 'ar' ? p.job_title : p.job_title_en ?? p.job_title) ?? '—'}{p.unit ? ` · ${p.unit}` : ''}</span>
                        <span className="font-mono text-[11px] text-slate-400" dir="ltr">{p.employee_no}</span>
                      </span>
                      <span className="shrink-0 text-end">
                        <span className="block font-display text-lg font-bold leading-none text-navy-900">{fmt.number(p.hours)}<span className="ms-1 text-[10px] font-semibold text-slate-500">{t('schoolTl.h')}</span></span>
                        <span className="mt-1 block text-[11px] text-slate-500">{t('schoolTl.courses', { n: fmt.number(p.courses) })}</span>
                      </span>
                      <ChevronLeft className="size-4 shrink-0 text-slate-300 ltr:rotate-180" />
                    </button>
                  </li>
                )
              })}
            </ul>
          )}
          <Pager page={page} last={staff.data?.last_page} onChange={setPage} />
          {staff.data && <p className="border-t border-navy-100 px-5 py-2.5 text-xs text-slate-400">{t('schoolTl.total', { n: fmt.number(staff.data.total) })}</p>}
        </Card>

        <Card padded={false} className="self-start">
          <div className="flex flex-wrap items-start justify-between gap-3 p-5 pb-3">
            <CardTitle subtitle={person ? [person.employee_no, (i18n.language === 'ar' ? person.job_title : person.job_title_en ?? person.job_title)].filter(Boolean).join(' · ') : t('schoolTl.timelineHint')}>
              <span className="inline-flex items-center gap-2"><CalendarDays className="size-5 text-gold-600" />{person ? person.name : t('schoolTl.timelineTitle')}</span>
            </CardTitle>
            {person && (
              <div className="flex flex-wrap gap-2">
                {can('employees.view') && <Link to={`/admin/employees/${person.id}`} className="inline-flex items-center gap-1.5 rounded-xl border border-navy-100 px-3 py-1.5 text-xs font-bold text-navy-800 hover:border-gold-400"><ExternalLink className="size-3.5" />{t('schoolTl.openProfile')}</Link>}
                <button type="button" onClick={() => pick(null)} className="inline-flex items-center gap-1.5 rounded-xl bg-navy-900 px-3 py-1.5 text-xs font-bold text-white hover:bg-navy-800"><X className="size-3.5" />{t('schoolTl.wholeSchool')}</button>
              </div>
            )}
          </div>
          {(picked ? timeline : overall).isLoading || !tl ? <Spinner className="mx-auto my-12" /> : <TimelineBody tl={tl} onYear={setYear} />}
        </Card>
      </div>
    </>
  )
}

function TimelineBody({ tl, onYear }: { tl: Timeline; onYear: (y: number) => void }) {
  const { t } = useTranslation()
  const isPerson = tl.scope === 'employee'
  const max = Math.max(1, ...tl.by_year.map((y) => y.hours))
  if (tl.by_year.length === 0) return <div className="px-5 pb-6"><Empty text={t('schoolTl.noHistory')} /></div>
  // A person's history is one long line grouped by year; the school's is one year at a time, picked from the rail.
  const groups: { year: number; courses: Course[] }[] = isPerson
    ? tl.by_year.map((y) => ({ year: y.year, courses: tl.courses.filter((c) => c.starts_on && new Date(c.starts_on).getFullYear() === y.year) }))
    : [{ year: tl.year ?? tl.by_year[0].year, courses: tl.courses }]
  return (
    <div>
      {!isPerson && (
        <div className="flex gap-2 overflow-x-auto border-y border-navy-100 bg-ivory/60 px-5 py-3" role="tablist" aria-label={t('schoolTl.years')}>
          {tl.by_year.map((y) => (
            <button key={y.year} type="button" role="tab" aria-selected={(tl.year ?? tl.by_year[0].year) === y.year} onClick={() => onYear(y.year)} className={clsx('shrink-0 rounded-2xl border px-3.5 py-2 text-start transition', (tl.year ?? tl.by_year[0].year) === y.year ? 'border-navy-900 bg-navy-900 text-white shadow-sm' : 'border-navy-100 bg-white text-navy-900 hover:border-gold-400')}>
              <span className="block font-display text-base font-bold leading-none">{y.year}</span>
              <span className={clsx('mt-1 block text-[11px]', (tl.year ?? tl.by_year[0].year) === y.year ? 'text-white/70' : 'text-slate-500')}>{fmt.number(y.hours)} {t('schoolTl.h')} · {t('schoolTl.people', { n: fmt.number(y.people ?? 0) })}</span>
            </button>
          ))}
        </div>
      )}
      {isPerson && (
        <div className="flex flex-wrap items-center gap-2 border-y border-navy-100 bg-ivory/60 px-5 py-3">
          <Badge color="navy">{t('schoolTl.courses', { n: fmt.number(tl.summary.courses ?? 0) })}</Badge>
          <Badge color="gold"><Clock3 className="size-3" />{fmt.number(tl.summary.hours)} {t('schoolTl.h')}</Badge>
          {tl.by_year.slice(0, 6).map((y) => (
            <span key={y.year} className="inline-flex items-center gap-1.5 rounded-full bg-white px-2.5 py-0.5 text-xs text-slate-600 ring-1 ring-navy-100"><b className="text-navy-900">{y.year}</b>
              <span className="h-1.5 w-10 overflow-hidden rounded-full bg-navy-100"><span className="block h-full rounded-full bg-gold-500" style={{ width: `${Math.max(6, (y.hours / max) * 100)}%` }} /></span>{fmt.number(y.hours)}</span>
          ))}
        </div>
      )}
      <ol className="max-h-[75vh] overflow-y-auto px-5 py-5">
        {groups.map((g) => (
          <li key={g.year} className="mb-2 last:mb-0">
            <div className="sticky top-0 z-10 -mx-5 mb-3 flex items-center gap-3 bg-white/95 px-5 py-1"><span className="font-display text-xl font-bold text-navy-900">{g.year}</span><span className="h-px flex-1 bg-navy-100" /><span className="text-xs text-slate-400">{t('schoolTl.courses', { n: fmt.number(g.courses.length) })}</span></div>
            <ol className="relative ms-3 border-s-2 border-gold-300/70 ps-6">
              {g.courses.map((c, i) => (
                <li key={`${c.id ?? c.title}-${c.starts_on}-${i}`} className="relative pb-5 last:pb-1">
                  <span className="absolute -start-[34px] top-1.5 grid size-4 place-items-center rounded-full bg-white ring-2 ring-gold-500"><span className="size-1.5 rounded-full bg-gold-500" /></span>
                  <div className="text-[11px] font-bold uppercase tracking-wide text-gold-700">{c.starts_on ? fmt.date(c.starts_on) : ''}{c.ends_on && c.ends_on !== c.starts_on ? ` – ${fmt.date(c.ends_on)}` : ''}</div>
                  <div className="mt-0.5 rounded-2xl border border-navy-100 bg-white p-3.5 shadow-sm transition hover:border-gold-400 hover:shadow-md">
                    <div className="flex flex-wrap items-start justify-between gap-2">
                      <h4 className="min-w-0 flex-1 font-bold leading-6 text-navy-900" dir="auto">{c.title}</h4>
                      <span className="inline-flex shrink-0 items-center gap-1 rounded-full bg-navy-900 px-2.5 py-0.5 text-xs font-bold text-white"><Clock3 className="size-3" />{fmt.number(c.hours_each ?? c.hours ?? 0)} {t('schoolTl.h')}</span>
                    </div>
                    <div className="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1 text-xs text-slate-500">
                      {c.track && <span dir="auto">{c.track}</span>}
                      {c.type && <span className="rounded-full bg-ivory px-2 py-0.5" dir="auto">{c.type}</span>}
                      {c.provider && <span dir="auto">{c.provider}</span>}
                      {c.attendees !== undefined && <span className="ms-auto inline-flex items-center gap-1 font-bold text-navy-900"><Users className="size-3.5 text-gold-600" />{t('schoolTl.attendees', { n: fmt.number(c.attendees) })}</span>}
                    </div>
                  </div>
                </li>
              ))}
              {g.courses.length === 0 && <li className="pb-2 text-sm text-slate-400">{t('schoolTl.noHistory')}</li>}
            </ol>
          </li>
        ))}
      </ol>
    </div>
  )
}
