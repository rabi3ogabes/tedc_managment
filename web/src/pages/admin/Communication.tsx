import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { PageHeader, Tabs } from '@/components/ui'
import { useAuth } from '@/lib/auth'
import AnnouncementsBoard from './communication/AnnouncementsBoard'
import DeliveriesTab from './communication/DeliveriesTab'
import RulesTab from './communication/RulesTab'
import ScheduledTab from './communication/ScheduledTab'
import SendTab from './communication/SendTab'
import CampaignTracking from './notifications/CampaignTracking'
import TemplatesManager from './notifications/TemplatesManager'
import UpcomingTimeline from './notifications/UpcomingTimeline'

type Tab = 'send' | 'scheduled' | 'rules' | 'templates' | 'deliveries' | 'announcements' | 'events' | 'campaigns' | 'upcoming'

/** The communication centre: send now or later, rules, templates, delivery tracking, announcements and events in one place. */
export default function Communication() {
  const { t } = useTranslation()
  const { can } = useAuth()
  const [params] = useSearchParams()
  const manage = can('announcements.manage') || can('announcements.publish')
  const all: { id: Tab; ok: boolean }[] = [
    { id: 'announcements', ok: manage }, { id: 'events', ok: manage }, { id: 'send', ok: manage || can('notifications.schedule') }, { id: 'scheduled', ok: can('notifications.schedule') },
    { id: 'rules', ok: can('notifications.rules') }, { id: 'templates', ok: can('announcements.manage') }, { id: 'deliveries', ok: can('notifications.reports') },
    { id: 'campaigns', ok: can('announcements.manage') }, { id: 'upcoming', ok: can('announcements.manage') },
  ]
  const tabs = all.filter((x) => x.ok).map((x) => ({ id: x.id, label: t(`comm.tabs.${x.id}`) }))
  const wanted = params.get('tab') as Tab | null
  const [tab, setTab] = useState<Tab>(wanted && tabs.some((x) => x.id === wanted) ? wanted : (tabs[0]?.id ?? 'announcements'))
  const [refresh, setRefresh] = useState(0)

  return (
    <>
      <PageHeader title={t('comm.title')} />
      <Tabs<Tab> value={tab} onChange={setTab} tabs={tabs} />
      <div className="mt-4">
        {tab === 'send' && <SendTab onSent={() => { setRefresh((n) => n + 1); if (can('notifications.schedule')) setTab('scheduled') }} />}
        {tab === 'scheduled' && <ScheduledTab refreshKey={refresh} />}
        {tab === 'rules' && <RulesTab />}
        {tab === 'templates' && <TemplatesManager />}
        {tab === 'deliveries' && <DeliveriesTab initial={{ channel: params.get('channel') ?? undefined, status: params.get('status') ?? undefined, campaign_id: params.get('campaign') ?? undefined }} />}
        {tab === 'announcements' && <AnnouncementsBoard kind="announcements" />}
        {tab === 'events' && <AnnouncementsBoard kind="events" />}
        {tab === 'campaigns' && <CampaignTracking />}
        {tab === 'upcoming' && <UpcomingTimeline />}
      </div>
    </>
  )
}
