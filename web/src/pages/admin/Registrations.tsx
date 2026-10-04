import { Search } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link } from 'react-router-dom'
import { Button, Card, EligibilityPanel, Modal, PageHeader, Progress, StatusBadge } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import ChannelPicker, { type Channel } from './notifications/ChannelPicker'
import { fmt } from '@/lib/format'
import type { Paginated, Program, Registration } from '@/lib/types'
import { Pager } from './shared'

export default function Registrations() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [filters, setFilters] = useState({ q: '', status: 'pending', program_id: '', source: '' })
  const [page, setPage] = useState(1)
  const [viewing, setViewing] = useState<Registration | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [channels, setChannels] = useState<Channel[] | null>(null)
  const programs = useGet<Paginated<Program>>('/admin/programs', { per_page: 100 })
  const { data, isLoading, refetch } = useGet<Paginated<Registration>>('/admin/registrations', { ...Object.fromEntries(Object.entries(filters).filter(([, v]) => v)), page })

  const act = async (id: string, status: string) => {
    setError(null)
    try { await api.patch(`/admin/registrations/${id}/status`, { status, notify_channels: channels ?? undefined }); refetch() } catch (e) { setError(errorMessage(e)) }
  }

  return (
    <>
      <PageHeader title={t('admin.registrations.title')} />
      {can('registrations.manage') && <div className="mb-4 max-w-xl"><ChannelPicker compact value={channels} onChange={setChannels} /></div>}
      <Card padded={false}>
        <div className="grid gap-3 border-b border-navy-100 p-4 md:grid-cols-4">
          <div className="relative"><Search className="absolute start-3 top-3 size-4 text-slate-400" /><input className="input ps-9" placeholder={t('common.search')} value={filters.q} onChange={(e) => setFilters({ ...filters, q: e.target.value })} /></div>
          <select className="input" value={filters.program_id} onChange={(e) => setFilters({ ...filters, program_id: e.target.value })}><option value="">{t('admin.registrations.selectProgram')}</option>{programs.data?.data.map((p) => <option key={p.id} value={p.id}>{p.title}</option>)}</select>
          <select className="input" value={filters.status} onChange={(e) => setFilters({ ...filters, status: e.target.value })}><option value="">{t('common.all')}</option>{['pending', 'approved', 'waitlisted', 'rejected', 'cancelled', 'completed'].map((s) => <option key={s} value={s}>{t(`status.${s}`)}</option>)}</select>
          <select className="input" value={filters.source} onChange={(e) => setFilters({ ...filters, source: e.target.value })}><option value="">{t('admin.registrations.source')}</option>{['self', 'school_nomination', 'center_nomination', 'bulk_import'].map((s) => <option key={s} value={s}>{t(`sources.${s}`)}</option>)}</select>
        </div>
        {error && <div className="m-4 rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
        <DataView id="admin.registrations" rows={data?.data} total={data?.meta?.total} loading={isLoading} rowKey={(r) => r.id} columns={[
          { key: 'employee', header: t('admin.registrations.employee'), role: 'title', cell: (r) => <><div className="font-semibold text-navy-900">{r.employee?.name}</div><div className="text-xs font-normal text-slate-400">{r.employee?.school?.name}</div></> },
          { key: 'program', header: t('admin.registrations.program'), cell: (r) => <Link to={`/admin/programs/${r.program_id}`} className="text-sm font-semibold text-navy-800 hover:text-link">{r.program?.title}</Link> },
          { key: 'source', header: t('admin.registrations.source'), cell: (r) => <span className="text-xs text-slate-500">{t(`sources.${r.source}`)}</span> },
          { key: 'eligibility', header: t('admin.registrations.eligibility'), cell: (r) => <button onClick={() => setViewing(r)}><StatusBadge status={r.eligibility?.eligible === false ? 'not_eligible' : 'eligible'} label={r.eligibility?.label ?? t('status.eligible')} /></button> },
          { key: 'status', header: t('common.status'), role: 'badge', cell: (r) => <StatusBadge status={r.status} /> },
          { key: 'attendance', header: t('admin.registrations.attendance'), cell: (r) => <div className="w-24"><Progress value={r.attendance_percent} /><div className="mt-1 text-xs text-slate-500">{fmt.percent(r.attendance_percent)}</div></div> },
          { key: 'actions', header: t('common.actions'), role: 'actions', cell: (r) => can('registrations.manage') && ['pending', 'waitlisted'].includes(r.status) ? (
            <div className="flex gap-1"><Button size="sm" variant="outline" onClick={() => act(r.id, 'approved')}>{t('common.approve')}</Button><Button size="sm" variant="ghost" onClick={() => act(r.id, 'rejected')}>{t('common.reject')}</Button></div>
          ) : null },
        ]} />
        <Pager page={page} last={data?.meta?.last_page} onChange={setPage} />
      </Card>
      <Modal open={!!viewing} onClose={() => setViewing(null)} title={t('admin.registrations.eligibility')}>
        {viewing?.eligibility?.checks ? <EligibilityPanel result={viewing.eligibility} /> : <p className="text-sm text-slate-500">{viewing?.eligibility?.summary ?? t('status.eligible')}</p>}
      </Modal>
    </>
  )
}
