import { Search } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button, Card, EligibilityPanel, Empty, Modal, PageHeader, Progress, Spinner, StatusBadge, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Paginated, Program, Registration } from '@/lib/types'

export default function Registrations() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [filters, setFilters] = useState({ q: '', status: 'pending', program_id: '', source: '' })
  const [page, setPage] = useState(1)
  const [viewing, setViewing] = useState<Registration | null>(null)
  const [error, setError] = useState<string | null>(null)
  const programs = useGet<Paginated<Program>>('/admin/programs', { per_page: 100 })
  const { data, isLoading, refetch } = useGet<Paginated<Registration>>('/admin/registrations', { ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)), page })

  const act = async (id: string, status: string) => {
    setError(null)
    try { await api.patch(`/admin/registrations/${id}/status`, { status }); refetch() } catch (e) { setError(errorMessage(e)) }
  }

  return (
    <>
      <PageHeader title={t('admin.registrations.title')} />
      <Card padded={false}>
        <div className="grid gap-3 border-b border-navy-100 p-4 md:grid-cols-4">
          <div className="relative"><Search className="absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" placeholder={t('common.search')} value={filters.q} onChange={(e) => setFilters({ ...filters, q: e.target.value })} /></div>
          <select className="input" value={filters.program_id} onChange={(e) => setFilters({ ...filters, program_id: e.target.value })}><option value="">{t('admin.registrations.selectProgram')}</option>{programs.data?.data.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}</select>
          <select className="input" value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}><option value="">{t('common.all')}</option>{['pending', 'approved', 'waitlisted', 'rejected', 'cancelled', 'completed'].map((s) => <option key={s} value={s}>{t(`status.${s}`)}</option>)}</select>
          <select className="input" value={filters.source} onChange={(e) => setFilters({ ...filters, source: e.target.value })}><option value="">{t('admin.registrations.source')}</option>{['self', 'school_nomination', 'center_nomination', 'bulk_import'].map((s) => <option key={s} value={s}>{t(`sources.${s}`)}</option>)}</select>
        </div>
        {error && <div className="m-4 rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
        {isLoading ? <Spinner /> : !data?.data.length ? <Empty /> : (
          <Table head={[t('admin.registrations.employee'), t('admin.registrations.program'), t('admin.registrations.source'), t('admin.registrations.eligibility'), t('common.status'), t('admin.registrations.attendance'), t('common.actions')]}>
            {data.data.map((r) => (
              <tr key={r.id} className="hover:bg-ivory/70">
                <Td><div className="font-semibold text-navy-900">{r.employee?.name}</div><div className="text-xs text-slate-400">{r.employee?.school?.name}</div></Td>
                <Td><Link to={`/admin/programs/${r.program_id}`} className="text-sm font-semibold text-navy-800 hover:text-link">{r.program?.title}</Link></Td>
                <Td className="text-xs text-slate-500">{t(`sources.${r.source}`)}</Td>
                <Td><button onClick={() => setViewing(r)}><StatusBadge status={r.eligibility?.eligible === false ? 'not_eligible' : 'eligible'} label={r.eligibility?.label ?? t('status.eligible')} /></button></Td>
                <Td><StatusBadge status={r.status} /></Td>
                <Td><div className="w-24"><Progress value={r.attendance_percent} /><div className="mt-1 text-xs text-slate-500">{fmt.percent(r.attendance_percent)}</div></div></Td>
                <Td>
                  {can('registrations.manage') && ['pending', 'waitlisted'].includes(r.status) && (
                    <div className="flex gap-1"><Button size="sm" variant="outline" onClick={() => act(r.id, 'approved')}>{t('common.approve')}</Button><Button size="sm" variant="ghost" onClick={() => act(r.id, 'rejected')}>{t('common.reject')}</Button></div>
                  )}
                </Td>
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
      <Modal open={!!viewing} onClose={() => setViewing(null)} title={t('admin.registrations.eligibility')}>
        {viewing?.eligibility?.checks ? <EligibilityPanel result={viewing.eligibility} /> : <p className="text-sm text-slate-500">{viewing?.eligibility?.summary ?? t('status.eligible')}</p>}
      </Modal>
    </>
  )
}
