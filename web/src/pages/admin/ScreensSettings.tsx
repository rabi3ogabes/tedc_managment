import { DoorOpen, Tv } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import RoomScreenSettings from './RoomScreenSettings'
import LobbyScreenSettings from './LobbyScreenSettings'
import { Tabs } from '@/components/ui'

/** Settings → Screens: every display of the center in one place, so they can be checked side by side. */
export default function ScreensSettings() {
  const { t } = useTranslation()
  const [tab, setTab] = useState<'room' | 'lobby'>('room')
  return (
    <div className="space-y-5">
      <Tabs value={tab} onChange={setTab} tabs={[
        { id: 'room', label: <span className="inline-flex items-center gap-2"><DoorOpen className="size-4" />{t('mgmt.settings.sections.roomscreen.title')} · 1920×1080</span> },
        { id: 'lobby', label: <span className="inline-flex items-center gap-2"><Tv className="size-4" />{t('mgmt.settings.sections.lobbyscreen.title')} · 1080×1920</span> },
      ]} />
      {tab === 'room' ? <RoomScreenSettings /> : <LobbyScreenSettings />}
    </div>
  )
}
