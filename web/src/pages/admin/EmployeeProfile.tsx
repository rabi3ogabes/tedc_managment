import { useTranslation } from 'react-i18next'
import { useParams } from 'react-router-dom'
import PassportView, { type PassportData } from '@/components/PassportView'
import { Avatar, Card, CardTitle, PageHeader, Spinner, StatusBadge, Table, Td } from '@/components/ui'
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
        <Table head={[t('admin.registrations.program'), t('admin.registrations.source'), t('common.status'), t('admin.registrations.attendance'), t('admin.registrations.certificate'), t('admin.impact.score')]}>
          {registrations.map((r) => (
            <tr key={r.id}>
              <Td className="font-semibold">{r.program?.title}</Td>
              <Td className="text-xs">{t(`sources.${r.source}`)}</Td>
              <Td><StatusBadge status={r.status} /></Td>
              <Td>{fmt.percent(r.attendance_percent)}</Td>
              <Td><StatusBadge status={r.certificate_status} /></Td>
              <Td>{fmt.number(r.impact_score, 1)}</Td>
            </tr>
          ))}
        </Table>
      </Card>
    </>
  )
}
