import { CheckCheck, Upload, UserPlus } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Progress, StatusBadge } from '@/components/ui'
import { DataView } from '@/components/ui/DataView'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Paginated, Program, Registration } from '@/lib/types'
import { ImportModal, NominateModal } from '../registrationModals'
import PassStatusModal from './PassStatusModal'

export default function ParticipantsTab({ program }: { program: Program }) {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [selected, setSelected] = useState<string[]>([])
  const [modal, setModal] = useState<'nominate' | 'import' | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [passFor, setPassFor] = useState<Registration | null>(null)
  const [groupFilter, setGroupFilter] = useState('')
  const [moveTo, setMoveTo] = useState('')
  const groupsRes = useGet<{ data: { id: string; sequence: number; title: string; seats_available: number }[] }>(`/admin/programs/${program.id}/groups`, undefined, { staleTime: 0 })
  const groups = [...(groupsRes.data?.data ?? [])].sort((a, b) => a.sequence - b.sequence)
  const { data, isLoading, refetch } = useGet<Paginated<Registration>>('/admin/registrations', { program_id: program.id, per_page: 100, status: '', ...(groupFilter ? { group_id: groupFilter } : {}) })

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
  // Every trainee belongs to a group: moving one (or the selected ones) to another group of the programme.
  const move = async (ids: string[], groupId: string, override = false) => {
    setError(null)
    try {
      const { data: res } = await api.post('/admin/registrations/assign-group', { registration_ids: ids, group_id: groupId, override })
      const failed = (res.data as { ok: boolean; message?: string }[]).filter((x) => !x.ok)
      if (failed.length) setError(failed.map((f) => f.message).join(' · '))
      setSelected([]); setMoveTo(''); refetch(); void groupsRes.refetch()
    } catch (e) { setError(errorMessage(e)) }
  }
  const issue = async (id: string, type?: 'attendance' | 'pass') => {
    try { await api.post(`/admin/registrations/${id}/certificate`, type ? { type } : {}); refetch() } catch (e) { setError(errorMessage(e)) }
  }

  const rows = data?.data ?? []
  return (
    <Card padded={false}>
      <div className="flex flex-wrap items-center justify-between gap-2 border-b border-navy-100 p-4">
        <div className="flex gap-2">
          {(can('nominations.center') || can('nominations.school')) && <Button size="sm" icon={<UserPlus className="size-4" />} onClick={() => setModal('nominate')}>{t('admin.registrations.nominate')}</Button>}
          {can('registrations.import') && <Button size="sm" variant="outline" icon={<Upload className="size-4" />} onClick={() => setModal('import')}>{t('admin.registrations.import')}</Button>}
        </div>
        {groups.length > 1 && (
          <select className="input w-auto py-1.5 text-sm" value={groupFilter} onChange={(e) => setGroupFilter(e.target.value)} aria-label={t('admin.programs.groups.column')}>
            <option value="">{t('admin.programs.groups.all')}</option>
            {groups.map((g) => <option key={g.id} value={g.id}>{g.title}</option>)}
          </select>
        )}
        {can('registrations.manage') && selected.length > 0 && groups.length > 1 && (
          <span className="flex items-center gap-1.5">
            <select className="input w-auto py-1.5 text-sm" value={moveTo} onChange={(e) => setMoveTo(e.target.value)} aria-label={t('admin.programs.groups.moveTo')}>
              <option value="">{t('admin.programs.groups.moveTo')}</option>
              {groups.map((g) => <option key={g.id} value={g.id}>{g.title} · {t('admin.programs.groups.free', { n: g.seats_available })}</option>)}
            </select>
            <Button size="sm" variant="outline" disabled={!moveTo} onClick={() => void move(selected, moveTo)}>{t('admin.programs.groups.move')} ({selected.length})</Button>
          </span>
        )}
        {can('registrations.manage') && selected.length > 0 && <Button size="sm" variant="gold" icon={<CheckCheck className="size-4" />} onClick={() => act(selected, 'approved')}>{t('admin.registrations.bulkApprove')} ({selected.length})</Button>}
      </div>
      {error && <div className="m-4 rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}
      <DataView id="admin.program.participants" rows={rows} total={data?.meta?.total} loading={isLoading} rowKey={(r) => r.id} isSelected={(r) => selected.includes(r.id)} columns={[
        { key: 'select', header: '', role: 'select', cell: (r) => <input type="checkbox" className="accent-gold-600" aria-label={r.employee?.name} checked={selected.includes(r.id)} onChange={(e) => setSelected(e.target.checked ? [...selected, r.id] : selected.filter((x) => x !== r.id))} /> },
        { key: 'employee', header: t('admin.registrations.employee'), role: 'title', cell: (r) => <><div className="font-semibold text-navy-900">{r.employee?.name}</div><div className="text-xs font-normal text-slate-400">{r.employee?.school?.name} · {r.employee?.employee_no}</div></> },
        { key: 'group', header: t('admin.programs.groups.column'), cell: (r) => (can('registrations.manage') && groups.length > 1
          ? <select className="input w-auto min-w-28 py-1 text-xs" value={r.group?.id ?? ''} onChange={(e) => e.target.value && void move([r.id], e.target.value)} aria-label={t('admin.programs.groups.column')}>{!r.group && <option value="">—</option>}{groups.map((g) => <option key={g.id} value={g.id}>{g.title}</option>)}</select>
          : <span className="text-xs font-semibold text-navy-800">{r.group?.title ?? '—'}</span>) },
        { key: 'source', header: t('admin.registrations.source'), cell: (r) => <span className="text-xs text-slate-500">{t(`sources.${r.source}`)}</span> },
        { key: 'status', header: t('common.status'), role: 'badge', cell: (r) => <StatusBadge status={r.status} /> },
        { key: 'attendance', header: t('admin.registrations.attendance'), cell: (r) => <div className="w-28"><div className="mb-1 text-xs">{fmt.percent(r.attendance_percent)}</div><Progress value={r.attendance_percent} tone={r.attendance_percent >= program.min_attendance_percent ? 'green' : 'gold'} /></div> },
        { key: 'pass', header: t('passing.column'), cell: (r) => <button type="button" onClick={() => setPassFor(r)} className="text-start" aria-label={t('passing.details')}><Badge color={r.pass_status === 'passed' || r.pass_status === 'exempted' ? 'green' : r.pass_status === 'failed' ? 'red' : 'gray'}>{t(`passing.status.${r.pass_status ?? 'pending'}`)}</Badge>{r.weighted_score != null && <div className="mt-0.5 text-[11px] text-slate-400">{fmt.number(r.weighted_score, 1)}%</div>}</button> },
        { key: 'certificate', header: t('admin.registrations.certificate'), cell: (r) => { const a = r.certificates?.find((c) => c.type === 'attendance'); const p = r.certificates?.find((c) => c.type !== 'attendance'); return (<div className="space-y-1 text-xs">{a && <div><Badge color="gold">{t('passing.certAttendance')}</Badge></div>}{p ? <div><Badge color="green">{t('passing.certPass')}</Badge></div> : !a && <StatusBadge status={r.certificate_status} />}</div>) } },
        { key: 'impact', header: t('admin.impact.score'), cell: (r) => <span className="font-bold text-navy-900">{fmt.number(r.impact_score, 1)}</span> },
        { key: 'actions', header: t('common.actions'), role: 'actions', cell: (r) => (
          <div className="flex gap-1">
            {can('registrations.manage') && ['pending', 'waitlisted'].includes(r.status) && <>
              <Button size="sm" variant="outline" onClick={() => act([r.id], 'approved')}>{t('common.approve')}</Button>
              <Button size="sm" variant="ghost" onClick={() => act([r.id], 'rejected')}>{t('common.reject')}</Button>
            </>}
            {can('certificates.issue') && r.certificate_status === 'eligible' && <Button size="sm" variant="gold" onClick={() => issue(r.id)}>{t('admin.certificates.issue')}</Button>}
            {can('certificates.issue') && !r.certificates?.some((c) => c.type === 'attendance') && r.status === 'approved' && <Button size="sm" variant="ghost" onClick={() => issue(r.id, 'attendance')}>{t('passing.certAttendance')}</Button>}
          </div>
        ) },
      ]} />
      {passFor && <PassStatusModal registrationId={passFor.id} name={passFor.employee?.name} onClose={() => setPassFor(null)} onChanged={refetch} />}
      <NominateModal open={modal === 'nominate'} onClose={() => { setModal(null); refetch() }} programId={program.id} />
      <ImportModal open={modal === 'import'} onClose={() => { setModal(null); refetch() }} programId={program.id} />
    </Card>
  )
}
