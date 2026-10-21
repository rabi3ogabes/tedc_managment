import { Clock3, Hash } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import PassportView, { type PassportData } from '@/components/PassportView'
import { Avatar, Badge, Card, CardTitle, PageHeader, Spinner, StatusBadge } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import type { Employee, Registration } from '@/lib/types'

type History = {
  totals: { courses: number; hours: number; by_year: { year: number; courses: number; hours: number }[] }
  rows: { id: string; title: string; provider: string | null; track: string | null; type: string | null; starts_on: string | null; ends_on: string | null; hours: number; country: string | null; source: string | null }[]
}

export default function EmployeeProfile() {
  const { id } = useParams()
  return <EmployeeProfileBody id={id!} />
}

/** The employee's training passport and profile — as its own page, or (`embedded`) inside the side panel opened from the employees list, where the panel shows the name. */
export function EmployeeProfileBody({ id, embedded = false }: { id: string; embedded?: boolean }) {
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: { employee: Employee; passport: PassportData; registrations: Registration[]; training_history: History } }>(`/admin/employees/${id}`)
  if (isLoading || !data) return <Spinner />
  const { employee: e, passport, registrations, training_history: h } = data.data
  const facts: [string, string | null | undefined][] = [
    [t('trn.jobClass'), e.job_class], [t('trn.employmentClass'), e.employment_class], [t('trn.kind'), e.employee_kind], [t('trn.education'), e.education_level], [t('trn.certificate'), e.certificate_name],
    [t('trn.specialization'), e.specialization], [t('trn.subUnit'), e.sub_unit], [t('trn.hireDate'), e.hire_date ? fmt.date(e.hire_date) : null], [t('trn.nationality'), e.nationality],
  ]
  const hasFacts = facts.some(([, v]) => v)
  return (
    <>
      {!embedded && <PageHeader title={<span className="flex items-center gap-4"><Avatar name={e.name ?? ''} size={56} />{e.name}{e.status === 'inactive' && <Badge color="gray">{t('status.inactive', { defaultValue: 'inactive' })}</Badge>}</span>}
        subtitle={`${e.job_title?.name ?? ''} · ${e.school?.name ?? ''} · ${e.employee_no}`} />}
      <PassportView data={passport} />

      {(hasFacts || (e.employee_numbers?.length ?? 0) > 1) && (
        <Card className="mt-6">
          <CardTitle>{t('trn.jobData')}</CardTitle>
          <dl className="grid gap-x-8 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
            {facts.filter(([, v]) => v).map(([k, v]) => <div key={k}><dt className="text-xs font-semibold text-slate-500">{k}</dt><dd className="font-semibold text-navy-900" dir="auto">{v}</dd></div>)}
          </dl>
          {(e.employee_numbers?.length ?? 0) > 1 && (
            <div className="mt-4 border-t border-navy-100 pt-3">
              <div className="mb-2 flex items-center gap-1.5 text-xs font-semibold text-slate-500"><Hash className="size-3.5" />{t('trn.numbers')}</div>
              <ul className="flex flex-wrap gap-2">
                {e.employee_numbers!.map((n) => <li key={n.employee_no} className="rounded-xl bg-ivory px-3 py-1.5 text-xs"><span className="font-mono font-bold" dir="ltr">{n.employee_no}</span>{n.is_current && <Badge color="gold" className="ms-1.5">{t('trn.current')}</Badge>}<span className="ms-2 text-slate-500">{[n.appointed_on ? fmt.date(n.appointed_on) : null, n.employment_class, n.org_name].filter(Boolean).join(' · ')}</span></li>)}
              </ul>
            </div>
          )}
        </Card>
      )}

      <Card className="mt-6" padded={false}>
        <div className="p-5 pb-3">
          <CardTitle subtitle={t('trn.historyHint')}>{t('trn.history')}</CardTitle>
          <div className="flex flex-wrap items-center gap-2">
            <Badge color="navy">{t('trn.courses', { n: fmt.number(h.totals.courses) })}</Badge>
            <Badge color="gold"><Clock3 className="size-3" />{t('trn.hoursTotal', { n: fmt.number(h.totals.hours) })}</Badge>
            {h.totals.by_year.slice(0, 8).map((y) => <span key={y.year} className="rounded-full bg-ivory px-2.5 py-0.5 text-xs text-slate-600" title={t('trn.courses', { n: y.courses })}><b className="text-navy-900">{y.year}</b> · {y.hours} {t('common.hours')}</span>)}
          </div>
        </div>
        {h.rows.length === 0 ? <p className="px-5 pb-5 text-sm text-slate-500">{t('trn.none')}</p> : (
          <>
            <DataView id="admin.employee.history" rows={h.rows} rowKey={(r) => r.id} columns={[
              { key: 'title', header: t('trn.course'), role: 'title', cell: (r) => <span dir="auto">{r.title}</span> },
              { key: 'track', header: t('trn.track'), role: 'subtitle', cell: (r) => <span className="text-xs text-slate-500" dir="auto">{r.track ?? t('trn.noValue')}</span> },
              { key: 'period', header: t('trn.period'), cell: (r) => <span className="text-xs">{r.starts_on ? fmt.date(r.starts_on) : ''}{r.ends_on && r.ends_on !== r.starts_on ? ` – ${fmt.date(r.ends_on)}` : ''}</span> },
              { key: 'hours', header: t('trn.hours'), cell: (r) => <b className="text-navy-900">{r.hours}</b> },
            ]} />
            {h.totals.courses > h.rows.length && <p className="px-5 py-3 text-xs text-slate-400">{t('trn.showing', { n: h.rows.length, total: h.totals.courses })}</p>}
          </>
        )}
      </Card>

      <Card className="mt-6" padded={false}>
        <div className="p-5 pb-0"><CardTitle>{t('portal.myTraining')}</CardTitle></div>
        <DataView id="admin.employee.registrations" rows={registrations} rowKey={(r) => r.id} columns={[
          { key: 'program', header: t('admin.registrations.program'), role: 'title', cell: (r) => r.program?.title },
          { key: 'source', header: t('admin.registrations.source'), role: 'subtitle', cell: (r) => <span className="text-xs">{t(`sources.${r.source}`)}</span> },
          { key: 'status', header: t('common.status'), role: 'badge', cell: (r) => <StatusBadge status={r.status} /> },
          { key: 'attendance', header: t('admin.registrations.attendance'), cell: (r) => fmt.percent(r.attendance_percent) },
          { key: 'certificate', header: t('admin.registrations.certificate'), cell: (r) => <StatusBadge status={r.certificate_status} /> },
          { key: 'impact', header: t('admin.impact.score'), cell: (r) => fmt.number(r.impact_score, 1) },
        ]} />
      </Card>
    </>
  )
}
