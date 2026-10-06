/* eslint-disable @typescript-eslint/no-explicit-any */
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

/** The handlers' queue of data-subject requests, oldest deadline first. */
export default function PrivacyQueue() {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>('/admin/privacy/requests', undefined, { staleTime: 0 })
  const [text, setText] = useState<Record<string, string>>({})
  const act = async (id: string, status: string) => {
    if (status !== 'in_progress' && !(text[id] ?? '').trim()) { toast(String(t('prv.needAnswer')), 'error'); return }
    try { await api.put(`/admin/privacy/requests/${id}`, { status, resolution: text[id] || undefined }); toast(String(t('prv.saved'))); res.refetch() } catch (e) { toast(errorMessage(e), 'error') }
  }
  return (
    <>
      <PageHeader title={t('prv.adminNav')} />
      {res.isLoading ? <Spinner /> : (res.data?.data.length ?? 0) === 0 ? <Empty text={String(t('prv.none'))} /> : (
        <div className="space-y-3">{res.data!.data.map((r) => (
          <Card key={r.id}>
            <div className="flex flex-wrap items-start justify-between gap-2"><div><b>{t(`prv.types.${r.type}`)}</b><div className="text-sm text-slate-500">{r.person} · {r.email}</div>{r.details && <p className="mt-1 text-sm">{r.details}</p>}</div>
              <div className="flex gap-2">{r.overdue && <Badge color="red">{t('prv.overdue')}</Badge>}<Badge color={r.status === 'completed' ? 'green' : r.status === 'rejected' ? 'gray' : 'amber'}>{t(`prv.status.${r.status}`)}</Badge><span className="text-xs text-slate-400">{t('prv.due')}: {fmt.date(r.due_at)}</span></div></div>
            {['received', 'in_progress'].includes(r.status) && <div className="mt-3 space-y-2"><textarea className="input min-h-16" placeholder={String(t('prv.resolution'))} value={text[r.id] ?? ''} onChange={(e) => setText({ ...text, [r.id]: e.target.value })} />
              <div className="flex gap-2">{r.status === 'received' && <Button size="sm" variant="outline" onClick={() => act(r.id, 'in_progress')}>{t('prv.take')}</Button>}<Button size="sm" variant="gold" onClick={() => act(r.id, 'completed')}>{t('prv.complete')}</Button><Button size="sm" variant="ghost" onClick={() => act(r.id, 'rejected')}>{t('prv.reject')}</Button></div></div>}
            {r.resolution && <p className="mt-2 rounded-xl bg-ivory p-3 text-sm">{r.resolution}</p>}
          </Card>))}</div>
      )}
    </>
  )
}
