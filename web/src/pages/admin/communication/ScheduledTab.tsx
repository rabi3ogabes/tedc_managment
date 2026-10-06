/* eslint-disable @typescript-eslint/no-explicit-any */
import { Repeat } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Spinner, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { statusColor } from './shared'

export default function ScheduledTab({ refreshKey }: { refreshKey: number }) {
  const { t, i18n } = useTranslation()
  const { data, isLoading, refetch } = useGet<{ data: any[] }>('/admin/scheduled-notifications', { per_page: 50, k: refreshKey }, { staleTime: 0 })
  const cancel = async (id: string) => {
    try { await api.delete(`/admin/scheduled-notifications/${id}`); toast(String(t('comm.scheduled.cancelled'))); refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  if (isLoading) return <Spinner />
  if (!data?.data.length) return <Card><Empty text={String(t('comm.scheduled.empty'))} /></Card>
  return (
    <Card padded={false}>
      <Table head={[t('comm.send.titleAr'), t('comm.scheduled.nextAt'), t('comm.send.channels'), t('comm.send.repeat'), t('comm.scheduled.runs'), '', '']}>
        {data.data.map((s) => (
          <tr key={s.id}>
            <Td><div className="font-semibold text-navy-900">{i18n.language === 'ar' ? s.title_ar : s.title_en}</div>{s.last_error && <div className="text-xs text-danger">{t('comm.scheduled.failed')}: {s.last_error}</div>}</Td>
            <Td>{fmt.date(s.send_at, { dateStyle: 'medium', timeStyle: 'short' } as any)}</Td>
            <Td><div className="flex gap-1">{(s.channels ?? ['push']).map((c: string) => <Badge key={c}>{t(`comm.channels.${c}`)}</Badge>)}</div></Td>
            <Td>{s.repeat !== 'none' ? <span className="inline-flex items-center gap-1 text-xs"><Repeat className="size-3" />{t(`comm.send.repeats.${s.repeat}`)}</span> : '—'}</Td>
            <Td>{s.runs}</Td>
            <Td><Badge color={statusColor(s.status)}>{t(`comm.status.${s.status}`)}</Badge></Td>
            <Td>{['scheduled', 'failed'].includes(s.status) && <Button size="sm" variant="outline" onClick={() => cancel(s.id)}>{t('comm.scheduled.cancel')}</Button>}</Td>
          </tr>
        ))}
      </Table>
    </Card>
  )
}
