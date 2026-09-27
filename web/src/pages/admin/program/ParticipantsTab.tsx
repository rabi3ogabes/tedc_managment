import { CheckCheck, Upload, UserPlus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Progress, StatusBadge } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Paginated, Program, Registration } from '@/lib/types'
import { ImportModal, NominateModal } from '../registrationModals'

export default function ParticipantsTab({ program }: { program: Program }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [selected, setSelected] = useState<string[]>([])
  const [modal, setModal] = useState<'nominate' | 'import' | null>(null)
  const [error, setError] = useState<string | null>(null)
  const { data, isLoading, refetch } = useGet<Paginated<Registration>>('/admin/registrations', { program_id: program.id, per_page: 100 })

  const act = async (ids: string[], status: string) => {
    setError(null)
    try {
      if (ids.length === 1) await api.patch(`/admin/registrations/${ids[0]}/status`, { status })
      else await api.post('/admin/registrations/bulk-status', { ids, status })
      setSelected([])
      refetch()
    } catch (e) {
      setError(errorMessage(e))
    }
  }
  const issue = async (id: string) => {
    try { await api.post(`/admin/registrations/${id}/certificate`); refetch() } catch (e) { setError(errorMessage(e)) }
  }

  const rows = data?.data ?? []
  return (
    <Card padded={false}>
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-navy-100 p-4">
        <div className="flex gap-2">
          {(can('nominations.center') || can('nominations.school')) && <Button size="sm" icon={<UserPlus className="size-4" />} onClick={() => setModal('nominate')}>{t('admin.registrations.nominate')}</Button>}
          {can('registrations.import') && <Button size="sm" variant="outline" icon={<Upload className="size-4" />} onClick={() => setModal('import')}>{t('admin.registrations.import')}</Button>}
        </div>
        {can('registrations.manage') && selected.length > 0 && <Button size="sm" variant="gold" icon={<CheckCheck className="size-4" />} onClick={() => act(selected, 'approved')}>{t('admin.registrations.bulkApprove')} ({selected.length})</Button>}
      </div>
      {error && <div className="m-4 rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
      <DataView id="admin.program.participants" rows={rows} total={data?.meta?.total} loading={isLoading} rowKey={(r) => r.id} isSelected={(r) => selected.includes(r.id)} columns={[
        { key: 'select', header: '', role: 'select', cell: (r) => <input type="checkbox" className="accent-gold-600" aria-label={r.employee?.name} checked={selected.includes(r.id)} onChange={(e) => setSelected(e.target.checked ? [...selected, r.id] : selected.filter((x) => x !== r.id))} /> },
        { key: 'employee', header: t('admin.registrations.employee'), role: 'title', cell: (r) => <><div className="font-semibold text-navy-900">{r.employee?.name}</div><div className="text-xs font-normal text-slate-400">{r.employee?.school?.name} · {r.employee?.employee_no}</div></> },
        { key: 'source', header: t('admin.registrations.source'), cell: (r) => <span className="text-xs text-slate-500">{t(`sources.${r.source}`)}</span> },
        { key: 'status', header: t('common.status'), role: 'badge', cell: (r) => <StatusBadge status={r.status} /> },
        { key: 'attendance', header: t('admin.registrations.attendance'), cell: (r) => <div className="w-28"><div className="mb-1 text-xs">{fmt.percent(r.attendance_percent)}</div><Progress value={r.attendance_percent} tone={r.attendance_percent >= program.min_attendance_percent ? 'green' : 'gold'} /></div> },
        { key: 'certificate', header: t('admin.registrations.certificate'), cell: (r) => <StatusBadge status={r.certificate_status} /> },
        { key: 'impact', header: t('admin.impact.score'), cell: (r) => <span className="font-bold text-navy-900">{fmt.number(r.impact_score, 1)}</span> },
        { key: 'actions', header: t('common.actions'), role: 'actions', cell: (r) => (
          <div className="flex gap-1">
            {can('registrations.manage') && ['pending', 'waitlisted'].includes(r.status) && <>
              <Button size="sm" variant="outline" onClick={() => act([r.id], 'approved')}>{t('common.approve')}</Button>
              <Button size="sm" variant="ghost" onClick={() => act([r.id], 'rejected')}>{t('common.reject')}</Button>
            </>}
            {can('certificates.issue') && r.certificate_status === 'eligible' && <Button size="sm" variant="gold" onClick={() => issue(r.id)}>{t('admin.certificates.issue')}</Button>}
          </div>
        ) },
      ]} />
      <NominateModal open={modal === 'nominate'} onClose={() => { setModal(null); refetch() }} programId={program.id} />
      <ImportModal open={modal === 'import'} onClose={() => { setModal(null); refetch() }} programId={program.id} />
    </Card>
  )
}
