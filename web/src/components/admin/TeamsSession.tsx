/* eslint-disable @typescript-eslint/no-explicit-any */
import { Video } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Modal, Spinner, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

/** The Teams meeting of one session: its link, creating or cancelling it, and attendance by time spent in the call. */
export function TeamsSessionButton({ sessionId }: { sessionId: string }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  return <><Button size="sm" variant="outline" icon={<Video className="size-4" />} onClick={() => setOpen(true)}>Teams</Button>{open && <TeamsSessionDialog sessionId={sessionId} onClose={() => setOpen(false)} />}<span className="sr-only">{t('idn.teams.title')}</span></>
}

function TeamsSessionDialog({ sessionId, onClose }: { sessionId: string; onClose: () => void }) {
  const { t } = useTranslation()
  const res = useGet<{ data: any }>(`/admin/sessions/${sessionId}/teams`, undefined, { staleTime: 0 })
  const [busy, setBusy] = useState<string | null>(null)
  const d = res.data?.data
  const act = async (name: string, fn: () => Promise<unknown>) => { setBusy(name); try { await fn(); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) } }
  return (
    <Modal open onClose={onClose} title={t('idn.teams.title')} wide>
      {!d ? <Spinner /> : !d.ready ? <p className="text-sm text-slate-500">{t('idn.teams.notReady')}</p> : (
        <div className="space-y-4">
          <div className="flex flex-wrap items-center gap-2">
            {d.meeting ? <><Badge color={d.meeting.status === 'cancelled' ? 'red' : 'green'}>{d.meeting.status}</Badge><a className="text-sm font-semibold text-link" href={d.meeting.join_url} target="_blank" rel="noreferrer noopener">{t('idn.teams.join')}</a></> : null}
            {(!d.meeting || d.meeting.status === 'cancelled') && <Button size="sm" variant="gold" loading={busy === 'c'} onClick={() => act('c', () => api.post(`/admin/sessions/${sessionId}/teams`))}>{t('idn.teams.create')}</Button>}
            {d.meeting && d.meeting.status !== 'cancelled' && <><Button size="sm" variant="outline" loading={busy === 'p'} onClick={() => act('p', () => api.post(`/admin/sessions/${sessionId}/teams/attendance`))}>{t('idn.teams.pull')}</Button><Button size="sm" variant="outline" onClick={() => act('x', () => api.delete(`/admin/sessions/${sessionId}/teams`))}>{t('idn.teams.cancel')}</Button></>}
          </div>
          {d.meeting?.last_error && <p className="text-xs text-danger">{d.meeting.last_error}</p>}
          {d.meeting?.attendance_synced_at && <p className="text-xs text-slate-500">{t('idn.teams.synced')}: {fmt.date(d.meeting.attendance_synced_at, { dateStyle: 'medium', timeStyle: 'short' } as any)}</p>}
          {!!d.attendance.length && (
            <Table head={[t('idn.teams.attendance'), t('idn.teams.minutes'), t('idn.teams.percent'), '']}>
              {d.attendance.map((a: any, i: number) => <tr key={i}><Td>{a.display_name || a.email}<div className="text-xs text-slate-400">{a.email}</div></Td><Td>{a.minutes}</Td><Td>{a.percent}%</Td><Td><Badge color={a.registration_id ? 'green' : 'gray'}>{a.registration_id ? t('idn.teams.matched') : t('idn.teams.unmatched')}</Badge></Td></tr>)}
            </Table>
          )}
        </div>
      )}
    </Modal>
  )
}
