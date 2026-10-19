import { Award, BadgeCheck, BookOpenCheck, CalendarDays, ClipboardList, FileSpreadsheet, FolderOpen, Layers, LayoutGrid, Library, ListChecks, MessageSquareHeart, Network, Pencil, Rows3, ScrollText, Settings2, ShieldCheck, Star, TrendingUp, Users, UserCog, Video } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, useSearchParams } from 'react-router-dom'
import clsx from 'clsx'
import { Badge, Button, ErrorState, PageHeader, Spinner, StatusBadge, Tabs } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { useFeature } from '@/hooks/useFeature'
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
import AssessmentsTab from './program/AssessmentsTab'
import EvaluationTab from './program/EvaluationTab'
import PassingTab from './program/PassingTab'
import GroupsTab from './program/GroupsTab'
import StaffTab from './program/StaffTab'
import StructureTab from './program/StructureTab'
import SurveyTab from './program/SurveyTab'
import TasksTab from './program/TasksTab'
import ProgramEditor from './ProgramEditor'
import ProgramWidgets, { type SectionDef } from './program/ProgramWidgets'

type Tab = 'participants' | 'sessions' | 'course' | 'remote' | 'rules' | 'tasks' | 'materials' | 'survey' | 'certificates' | 'impact' | 'staff' | 'groups' | 'structure' | 'admission' | 'assessments' | 'passing' | 'evaluation'

export default function ProgramManage() {
  const { id } = useParams()
  const { t } = useTranslation()
  const { can } = useAuth()
  const payments = useFeature('payments')
  const [search, setSearch] = useSearchParams()
  const [tab, setTab] = useState<Tab>((search.get('tab') as Tab | null) ?? 'groups')
  // Two ways to work on a program: one tab at a time, or the whole program as a board of widgets that open in a popup. The choice is remembered.
  const saved = (() => { try { return localStorage.getItem('tedc.program.view') } catch { return null } })()
  const [view, setViewState] = useState<'tabs' | 'widgets'>((search.get('view') as 'tabs' | 'widgets' | null) ?? (saved === 'widgets' ? 'widgets' : 'tabs'))
  const open = search.get('open')
  const setView = (v: 'tabs' | 'widgets') => {
    setViewState(v)
    try { localStorage.setItem('tedc.program.view', v) } catch { /* storage unavailable */ }
    setSearch((q) => { q.set('view', v); q.delete('open'); return q }, { replace: true })
  }
  const setOpen = (id: string | null) => setSearch((q) => { if (id) q.set('open', id); else q.delete('open'); return q }, { replace: true })
  const [notice, setNotice] = useState<string | null>(null)
  const { data, isLoading, error, refetch } = useGet<{ data: Program }>(`/admin/programs/${id}`)
  if (isLoading) return <Spinner />
  if (error || !data) return <ErrorState error={error} onRetry={refetch} />
  const p = data.data
  const sections: SectionDef[] = [
    { id: 'details', label: t('admin.programs.widgets.details'), icon: Settings2, widgetOnly: true, render: () => can('programs.manage') ? <ProgramEditor programId={p.id} embedded onSaved={() => { setOpen(null); void refetch() }} /> : null },
    { id: 'groups', label: t('groups.tab'), icon: Layers, render: () => <GroupsTab program={p} /> },
    { id: 'participants', label: t('admin.programs.participants'), icon: Users, render: () => <ParticipantsTab program={p} /> },
    { id: 'structure', label: t('structure.tab'), icon: Network, render: () => <StructureTab program={p} /> },
    ...(can('registrations.view') ? [{ id: 'admission', label: t('admission.tab'), icon: ClipboardList, render: () => <AdmissionTab program={p} /> }] : []),
    ...(can('assessments.manage') || can('assessments.grade') || can('assessments.invigilate') ? [{ id: 'assessments', label: t('assess.builder.tab'), icon: BookOpenCheck, render: () => <AssessmentsTab program={p} /> }] : []),
    ...(can('passing.manage') ? [{ id: 'passing', label: t('passing.tab'), icon: BadgeCheck, render: () => <PassingTab program={p} /> }] : []),
    ...(can('evaluations.manage') || can('evaluation_reports.prepare') || can('impact.view') ? [{ id: 'evaluation', label: t('evalc.tab'), icon: Star, render: () => <EvaluationTab program={p} /> }] : []),
    { id: 'sessions', label: t('admin.programs.sessions'), icon: CalendarDays, render: () => <SessionsTab program={p} /> },
    { id: 'course', label: t('course.tab'), icon: Library, render: () => <CourseTab program={p} /> },
    ...(p.delivery_mode !== 'in_person' ? [{ id: 'remote', label: t('studio.tracking.tab'), icon: Video, render: () => <RemoteTab program={p} /> }] : []),
    { id: 'rules', label: t('admin.programs.rules'), icon: ShieldCheck, render: () => <RulesTab program={p} /> },
    { id: 'tasks', label: t('admin.programs.tasks'), icon: ListChecks, render: () => <TasksTab program={p} /> },
    { id: 'materials', label: t('admin.programs.materials'), icon: FolderOpen, render: () => <MaterialsTab program={p} /> },
    { id: 'survey', label: t('mgmt.notif.survey.tab'), icon: MessageSquareHeart, render: () => <SurveyTab program={p} /> },
    { id: 'certificates', label: t('studio.certs.tab'), icon: ScrollText, render: () => <CertificatesTab program={p} /> },
    { id: 'impact', label: t('admin.programs.impact'), icon: TrendingUp, render: () => <ImpactTab program={p} /> },
    ...(can('program_grants.manage') ? [{ id: 'staff', label: t('grants.tab'), icon: UserCog, render: () => <StaffTab programId={p.id} /> }] : []),
  ]
  const activeTab = (sections.find((x) => x.id === tab && !x.widgetOnly) ?? sections.find((x) => !x.widgetOnly))!.id as Tab

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
          {can('pricing.manage') && payments && <Button variant="outline" to={`/admin/programs/${p.id}/pricing`}>{t('pay.nav.pricing')}</Button>}
          {can('programs.manage') && <Button to={`/admin/programs/${p.id}/edit`} icon={<Pencil className="size-4" />}>{t('common.edit')}</Button>}
        </>}
      />
      {notice && <div className="mb-4 rounded-xl bg-gold-100 p-3 text-sm text-navy-900">{notice}</div>}
      <div className="mb-5 flex flex-wrap items-center justify-between gap-3">
        <div role="group" aria-label={t('admin.programs.widgets.view') as string} className="inline-flex rounded-2xl border border-navy-100 bg-white p-1">
          {([['tabs', Rows3, t('admin.programs.widgets.viewTabs')], ['widgets', LayoutGrid, t('admin.programs.widgets.viewWidgets')]] as const).map(([id, Icon, label]) => (
            <button key={id} type="button" aria-pressed={view === id} onClick={() => setView(id)} className={clsx('inline-flex items-center gap-2 rounded-xl px-4 py-2 text-sm font-semibold transition', view === id ? 'bg-navy-900 text-white shadow' : 'text-slate-500 hover:text-navy-900')}><Icon className="size-4" />{label}</button>
          ))}
        </div>
        {view === 'widgets' && <p className="text-xs text-slate-500">{t('admin.programs.widgets.intro')}</p>}
      </div>
      {view === 'tabs' ? (
        <>
          <Tabs<Tab> value={activeTab} onChange={setTab} tabs={sections.filter((x) => !x.widgetOnly).map((x) => ({ id: x.id as Tab, label: x.label }))} />
          {sections.find((x) => x.id === activeTab)?.render()}
        </>
      ) : (
        <ProgramWidgets programId={p.id} sections={sections} openId={open} onOpen={setOpen} onClose={() => { setOpen(null); void refetch() }} onOpenAsTab={(id) => { setTab(id as Tab); setView('tabs') }} />
      )}
    </>
  )
}
