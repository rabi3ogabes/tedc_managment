import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Spinner, Tabs } from '@/components/ui'
import { useFeature } from '@/hooks/useFeature'
import { useGet } from '@/hooks/useApi'
import RatingSummary from './RatingSummary'
import SpacePanel from './SpacePanel'
import SubjectNotes from './SubjectNotes'

type Tab = 'discuss' | 'notes' | 'rate'

/** Beside a training material: talk about it with the group and the trainer, keep private notes, and rate it (COL-02, COL-03). */
export default function MaterialSocial({ materialId }: { materialId: string }) {
  const { t } = useTranslation()
  const forums = useFeature('forums')
  const tabs: { id: Tab; label: string }[] = [...(forums ? [{ id: 'discuss' as Tab, label: t('soc.material.discuss') }] : []), { id: 'notes', label: t('soc.lesson.notes') }, { id: 'rate', label: t('soc.material.rate') }]
  const [tab, setTab] = useState<Tab>(tabs[0].id)
  return (
    <div>
      <Tabs<Tab> value={tab} onChange={setTab} tabs={tabs} />
      {tab === 'discuss' && <Discussion materialId={materialId} />}
      {tab === 'notes' && <SubjectNotes type="material" id={materialId} />}
      {tab === 'rate' && <RatingSummary type="material" id={materialId} />}
    </div>
  )
}

function Discussion({ materialId }: { materialId: string }) {
  const { t } = useTranslation()
  // eslint-disable-next-line @typescript-eslint/no-explicit-any
  const res = useGet<{ data: any }>(`/social/materials/${materialId}/thread`, undefined, { staleTime: 0, retry: false })
  if (res.isLoading) return <Spinner />
  if (!res.data) return <p className="text-sm text-slate-500">{t('soc.material.noDiscussion')}</p>
  return <SpacePanel space={res.data.data} onChanged={() => res.refetch()} compact />
}
