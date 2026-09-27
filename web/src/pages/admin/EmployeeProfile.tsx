import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import PassportView, { type PassportData } from '@/components/PassportView'
import { Avatar, Card, CardTitle, PageHeader, Spinner, StatusBadge } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import type { Employee, Registration } from '@/lib/types'

export default function EmployeeProfile() {
  const { id } = useParams()
  const { t } = useTranslation()
  const { data, isLoading } = useGet<{ data: { employee: Employee; passport: PassportData; registrations: Registration[] } }>(`/admin/employees/${id}`)
  if (isLoading || !data) return <Spinner />
  const { employee, passport, registrations } = data.data
  return (
    <>
      <PageHeader title={<span className="flex items-center gap-4"><Avatar name={employee.name ?? ''} size={56} />{employee.name}</span>}
        subtitle={`${employee.job_title?.name ?? ''} · ${employee.school?.name ?? ''} · ${employee.employee_no}`} />
      <PassportView data={passport} />
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
