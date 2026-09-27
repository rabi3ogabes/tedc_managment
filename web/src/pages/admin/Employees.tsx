import { Search } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Avatar, Card, PageHeader } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import type { Employee, Paginated } from '@/lib/types'
import { Pager } from './shared'

export default function Employees() {
  const { t } = useTranslation()
  const [q, setQ] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useGet<Paginated<Employee>>('/admin/employees', { q: q || undefined, page })

  return (
    <>
      <PageHeader title={t('admin.employees.title')} />
      <Card padded={false}>
        <div className="border-b border-navy-100 p-4"><div className="relative max-w-md"><Search className="absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" placeholder={t('common.search')} value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} /></div></div>
        <DataView id="admin.employees" rows={data?.data} total={data?.meta?.total} loading={isLoading} rowKey={(e) => e.id} columns={[
          { key: 'avatar', header: '', role: 'media', hideInTable: true, cell: (e) => <Avatar name={e.name ?? ''} size={44} /> },
          { key: 'name', header: t('common.name'), role: 'title', cell: (e) => <><div className="font-semibold text-navy-900">{e.name}</div><div className="text-xs font-normal text-slate-400" dir="ltr">{e.email}</div></> },
          { key: 'no', header: t('admin.employees.number'), role: 'badge', cell: (e) => <span className="font-mono text-xs text-slate-500" dir="ltr">{e.employee_no}</span> },
          { key: 'job', header: t('admin.employees.jobTitle'), cell: (e) => e.job_title?.name ?? '—' },
          { key: 'school', header: t('admin.employees.school'), cell: (e) => <span className="text-sm">{e.school?.name ?? '—'}</span> },
          { key: 'exp', header: t('admin.employees.experience'), cell: (e) => fmt.number(e.experience_years, 1) },
          { key: 'passport', header: '', role: 'actions', cell: (e) => <Link to={`/admin/employees/${e.id}`} className="text-sm font-bold text-link">{t('admin.employees.passport')}</Link> },
        ]} />
        <Pager page={page} last={data?.meta?.last_page} onChange={setPage} />
      </Card>
    </>
  )
}
