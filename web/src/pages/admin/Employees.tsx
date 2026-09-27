import { Search } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button, Card, Empty, PageHeader, Spinner, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { fmt } from '@/lib/format'
import type { Employee, Paginated } from '@/lib/types'

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
        {isLoading ? <Spinner /> : !data?.data.length ? <Empty /> : (
          <Table head={[t('admin.employees.number'), t('common.name'), t('admin.employees.jobTitle'), t('admin.employees.school'), t('admin.employees.experience'), '']}>
            {data.data.map((e) => (
              <tr key={e.id} className="hover:bg-ivory/70">
                <Td><span className="font-mono text-xs" dir="ltr">{e.employee_no}</span></Td>
                <Td><div className="font-semibold text-navy-900">{e.name}</div><div className="text-xs text-slate-400" dir="ltr">{e.email}</div></Td>
                <Td>{e.job_title?.name}</Td>
                <Td className="text-sm">{e.school?.name}</Td>
                <Td>{fmt.number(e.experience_years, 1)}</Td>
                <Td><Link to={`/admin/employees/${e.id}`} className="text-sm font-bold text-link">{t('admin.employees.passport')}</Link></Td>
              </tr>
            ))}
          </Table>
        )}
        {data?.meta && data.meta.last_page > 1 && (
          <div className="flex justify-center gap-2 border-t border-navy-100 p-4">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</Button>
            <span className="px-2 py-1.5 text-sm text-slate-500">{page} / {data.meta.last_page}</span>
            <Button variant="outline" size="sm" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</Button>
          </div>
        )}
      </Card>
    </>
  )
}
