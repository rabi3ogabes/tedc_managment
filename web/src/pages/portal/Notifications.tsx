import clsx from 'clsx'
import { Bell, CheckCheck } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Empty, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api } from '@/lib/api'
import { fmt } from '@/lib/format'
import type { NotificationItem, Paginated } from '@/lib/types'

export default function Notifications() {
  const { t } = useTranslation()
  const { data, isLoading, refetch } = useGet<Paginated<NotificationItem>>('/me/notifications')
  const readAll = async () => { await api.post('/me/notifications/read-all'); refetch() }
  const read = async (n: NotificationItem) => { if (!n.read) { await api.post(`/me/notifications/${n.id}/read`); refetch() } }
  return (
    <>
      <PageHeader title={t('portal.notifications')} actions={<Button variant="outline" icon={<CheckCheck className="size-4" />} onClick={readAll}>{t('portal.markAllRead')}</Button>} />
      <Card padded={false}>
        {isLoading ? <Spinner /> : !data?.data.length ? <Empty icon={<Bell className="size-6" />} /> : (
          <ul className="divide-y divide-navy-100">
            {data.data.map((n) => (
              <li key={n.id}>
                <button onClick={() => read(n)} className={clsx('flex w-full gap-4 p-4 text-start hover:bg-ivory', !n.read && 'bg-gold-100/30')}>
                  <span className={clsx('mt-1.5 size-2.5 shrink-0 rounded-full', n.read ? 'bg-slate-200' : 'bg-gold-500')} />
                  <div className="min-w-0 flex-1">
                    <div className="flex justify-between gap-3"><span className="font-bold text-navy-900">{n.title}</span><time className="shrink-0 text-xs text-slate-400">{fmt.dateTime(n.created_at)}</time></div>
                    {n.body && <p className="mt-1 text-sm text-slate-600">{n.body}</p>}
                  </div>
                </button>
              </li>
            ))}
          </ul>
        )}
      </Card>
    </>
  )
}
