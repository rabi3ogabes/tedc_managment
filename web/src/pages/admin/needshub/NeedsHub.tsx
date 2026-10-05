import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { PageHeader, Tabs } from '@/components/ui'
import { useAuth } from '@/lib/auth'
import CompetenciesTab from './CompetenciesTab'
import CycleTab from './CycleTab'
import GapsTab from './GapsTab'
import IndividualTab from './IndividualTab'
import PerformanceTab from './PerformanceTab'
import RequestsTab from './RequestsTab'
import RulesTab from './RulesTab'

type Tab = 'cycle' | 'requests' | 'individual' | 'performance' | 'rules' | 'gaps' | 'competencies'

/** The needs cycle and gap-analysis workspace: every tab shows only what the person's permissions allow. */
export default function NeedsHub() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const tabs: { id: Tab; show: boolean }[] = [
    { id: 'cycle', show: can('needs.cycles') || can('needs.propose') },
    { id: 'requests', show: can('needs.cycles') || can('needs.request') },
    { id: 'individual', show: can('needs.cycles') || can('needs.approve_individual') },
    { id: 'performance', show: can('performance.import') },
    { id: 'rules', show: can('needs.cycles') },
    { id: 'gaps', show: can('gaps.view') },
    { id: 'competencies', show: can('competencies.manage') || can('gaps.view') },
  ]
  const visible = tabs.filter((x) => x.show)
  const [tab, setTab] = useState<Tab>(visible[0]?.id ?? 'cycle')
  return (
    <div className="space-y-6 pb-6">
      <PageHeader title={t('needsHub.title')} subtitle={t('needsHub.subtitle')} />
      <Tabs<Tab> value={tab} onChange={setTab} tabs={visible.map((x) => ({ id: x.id, label: t(`needsHub.tabs.${x.id}`) }))} />
      {tab === 'cycle' && <CycleTab />}
      {tab === 'requests' && <RequestsTab />}
      {tab === 'individual' && <IndividualTab />}
      {tab === 'performance' && <PerformanceTab />}
      {tab === 'rules' && <RulesTab />}
      {tab === 'gaps' && <GapsTab />}
      {tab === 'competencies' && <CompetenciesTab />}
    </div>
  )
}
