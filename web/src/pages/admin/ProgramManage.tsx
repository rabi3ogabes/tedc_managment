import { Award, FileSpreadsheet, Pencil } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, useSearchParams } from 'react-router-dom'
import { Badge, Button, ErrorState, PageHeader, Spinner, StatusBadge, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import { fmt } from '@/lib/format'
import type { Program } from '@/lib/types'
import CertificatesTab from './program/CertificatesTab'
import CourseTab from './program/CourseTab'
import ImpactTab from './program/ImpactTab'
import RemoteTab from './program/RemoteTab'
import MaterialsTab from './program/MaterialsTab'
import ParticipantsTab from './program/ParticipantsTab'
import RulesTab from './program/RulesTab'
import SessionsTab from './program/SessionsTab'
import AdmissionTab from './program/AdmissionTab'
import GroupsTab from './program/GroupsTab'
import StaffTab from './program/StaffTab'
import StructureTab from './program/StructureTab'
import SurveyTab from './program/SurveyTab'
import TasksTab from './program/TasksTab'

type Tab = 'participants' | 'sessions' | 'course' | 'remote' | 'rules' | 'tasks' | 'materials' | 'survey' | 'certificates' | 'impact' | 'staff' | 'groups' | 'structure' | 'admission'

export default function ProgramManage() {
  const { id } = useParams()
  const { t } = useTranslation()
  const { can } = useAuth()
  const [search] = useSearchParams()
  const [tab, setTab] = useState<Tab>((search.get('tab') as Tab | null) ?? 'participants')
  const [notice, setNotice] = useState<string | null>(null)
  const { data, isLoading, error, refetch } = useGet<{ data: Program }>(`/admin/programs/${id}`)
  if (isLoading) return <Spinner />
  if (error || !data) return <ErrorState onRetry={refetch} />
  const p = data.data

  const issueAll = async () => {
    try {
      const { data: res } = await api.post(`/admin/programs/${p.id}/certificates`)
      setNotice(`${t('admin.programs.issueAll')}: ${res.data.issued} ✓ — ${res.data.blocked.length} ✗`)
    } catch (e) {
      setNotice(errorMessage(e))
    }
  }

  return (
    <>
      <PageHeader
        title={p.title}
        subtitle={<span className="flex flex-wrap items-center gap-2"><span dir="ltr" className="font-mono">{p.code}</span><StatusBadge status={p.status} /><Badge color="gold">{fmt.number(p.seats_taken ?? 0)} / {fmt.number(p.capacity)} {t('common.seats')}</Badge><span>{fmt.date(p.start_date)} – {fmt.date(p.end_date)}</span></span>}
        actions={<>
          {can('reports.view') && <Button variant="outline" icon={<FileSpreadsheet className="size-4" />} onClick={() => downloadFile(`/admin/reports/programs/${p.id}`, `program-${p.code}.xlsx`)}>{t('admin.programs.exportReport')}</Button>}
          {can('certificates.issue') && <Button variant="outline" icon={<Award className="size-4" />} onClick={issueAll}>{t('admin.programs.issueAll')}</Button>}
          {can('programs.manage') && <Button to={`/admin/programs/${p.id}/edit`} icon={<Pencil className="size-4" />}>{t('common.edit')}</Button>}
        </>}
      />
      {notice && <div className="mb-4 rounded-xl bg-gold-100 p-3 text-sm text-navy-900">{notice}</div>}
      <Tabs<Tab> value={tab} onChange={setTab} tabs={[
        { id: 'participants', label: t('admin.programs.participants') },
        { id: 'groups', label: t('groups.tab') },
        { id: 'structure', label: t('structure.tab') },
        ...(can('registrations.view') ? [{ id: 'admission' as const, label: t('admission.tab') }] : []),
        { id: 'sessions', label: t('admin.programs.sessions') },
        { id: 'course', label: t('course.tab') },
        ...(p.delivery_mode !== 'in_person' ? [{ id: 'remote' as const, label: t('studio.tracking.tab') }] : []),
        { id: 'rules', label: t('admin.programs.rules') },
        { id: 'tasks', label: t('admin.programs.tasks') },
        { id: 'materials', label: t('admin.programs.materials') },
        { id: 'survey', label: t('mgmt.notif.survey.tab') },
        { id: 'certificates', label: t('studio.certs.tab') },
        { id: 'impact', label: t('admin.programs.impact') },
        ...(can('program_grants.manage') ? [{ id: 'staff' as const, label: t('grants.tab') }] : []),
      ]} />
      {tab === 'participants' && <ParticipantsTab program={p} />}
      {tab === 'groups' && <GroupsTab program={p} />}
      {tab === 'structure' && <StructureTab program={p} />}
      {tab === 'admission' && can('registrations.view') && <AdmissionTab program={p} />}
      {tab === 'sessions' && <SessionsTab program={p} />}
      {tab === 'course' && <CourseTab program={p} />}
      {tab === 'remote' && <RemoteTab program={p} />}
      {tab === 'certificates' && <CertificatesTab program={p} />}
      {tab === 'rules' && <RulesTab program={p} />}
      {tab === 'tasks' && <TasksTab program={p} />}
      {tab === 'materials' && <MaterialsTab program={p} />}
      {tab === 'survey' && <SurveyTab program={p} />}
      {tab === 'impact' && <ImpactTab program={p} />}
      {tab === 'staff' && can('program_grants.manage') && <StaffTab programId={p.id} />}
    </>
  )
}
