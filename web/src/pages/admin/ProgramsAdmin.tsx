import { Plus, Search } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button, Card, Empty, PageHeader, Progress, Spinner, StatusBadge, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Paginated, Program } from '@/lib/types'

export default function ProgramsAdmin() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [q, setQ] = useState('')
  const [status, setStatus] = useState('')
  const [page, setPage] = useState(1)
  const { data, isLoading } = useGet<Paginated<Program>>('/admin/programs', { q: q || undefined, status: status || undefined, page })

  return (
    <>
      <PageHeader title={t('admin.menu.programs')} actions={can('programs.manage') && <Button to="/admin/programs/new" variant="gold" icon={<Plus className="size-4" />}>{t('admin.programs.new')}</Button>} />
      <Card padded={false}>
        <div className="flex flex-wrap gap-3 border-b border-navy-100 p-4">
          <div className="relative min-w-60 flex-1"><Search className="absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" placeholder={t('common.search')} value={q} onChange={(e) => { setQ(e.target.value); setPage(1) }} /></div>
          <select className="input w-auto" value={status} onChange={(e) => setStatus(e.target.value)}>
            <option value="">{t('common.all')}</option>
            {['draft', 'published', 'registration_open', 'in_progress', 'completed', 'archived'].map((s) => <option key={s} value={s}>{t(`status.${s}`)}</option>)}
          </select>
        </div>
        {isLoading ? <Spinner /> : !data?.data.length ? <Empty /> : (
          <Table head={[t('admin.programs.code'), t('programs.title'), t('programs.category'), t('programs.startsOn'), t('programs.capacity'), t('common.status')]}>
            {data.data.map((p) => (
              <tr key={p.id} className="hover:bg-ivory/70">
                <Td><span className="font-mono text-xs text-slate-500" dir="ltr">{p.code}</span></Td>
                <Td><Link to={`/admin/programs/${p.id}`} className="font-bold text-navy-900 hover:text-link">{p.title}</Link><div className="text-xs text-slate-400">{t(`modes.${p.delivery_mode}`)} · {fmt.number(p.total_hours)} {t('common.hours')}</div></Td>
                <Td className="text-slate-600">{p.category?.name}</Td>
                <Td className="whitespace-nowrap text-slate-600">{fmt.date(p.start_date, { day: 'numeric', month: 'short', year: 'numeric' })}</Td>
                <Td><div className="w-32"><div className="mb-1 text-xs text-slate-500">{fmt.number(p.seats_taken ?? 0)} / {fmt.number(p.capacity)}</div><Progress value={((p.seats_taken ?? 0) / p.capacity) * 100} /></div></Td>
                <Td><StatusBadge status={p.status} /></Td>
              </tr>
            ))}
          </Table>
        )}
        {data?.meta && data.meta.last_page > 1 && (
          <div className="flex justify-center gap-2 border-t border-navy-100 p-4">
            <Button variant="outline" size="sm" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('common.previous')}</Button>
            <Button variant="outline" size="sm" disabled={page >= data.meta.last_page} onClick={() => setPage(page + 1)}>{t('common.next')}</Button>
          </div>
        )}
      </Card>
    </>
  )
}
