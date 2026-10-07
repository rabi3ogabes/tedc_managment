/* eslint-disable @typescript-eslint/no-explicit-any */
import { RefreshCw } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

/** Problems reported from the portal and the app, with their Saaed number and status; a ticket that could not be sent can be sent again. */
export default function SupportTickets() {
  const { t } = useTranslation()
  const [status, setStatus] = useState('')
  const [q, setQ] = useState('')
  const [busy, setBusy] = useState<string | null>(null)
  const res = useGet<{ data: any[] }>('/admin/tickets', { status: status || undefined, q: q.trim() || undefined, per_page: 50 }, { staleTime: 0 })
  const resend = async (id: string) => {
    setBusy(id)
    try { await api.post(`/admin/tickets/${id}/resend`); toast(String(t('hlp.tk.resent'))); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(null) }
  }
  return (
    <>
      <PageHeader title={t('hlp.tk.title')} subtitle={t('hlp.tk.subtitle')} />
      <div className="mb-4 flex flex-wrap gap-3">
        <input className="input max-w-xs" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('hlp.tk.search'))} aria-label={String(t('hlp.tk.search'))} />
        <select className="input max-w-[12rem]" value={status} onChange={(e) => setStatus(e.target.value)} aria-label={String(t('hlp.tk.status'))}>
          <option value="">{t('hlp.admin.all')}</option>
          {['queued', 'open', 'closed', 'failed'].map((s) => <option key={s} value={s}>{t(`hlp.tk.states.${s}`)}</option>)}
        </select>
      </div>
      {res.isLoading ? <Spinner /> : (res.data?.data.length ?? 0) === 0 ? <Empty text={String(t('hlp.tk.none'))} /> : (
        <div className="space-y-3">{res.data!.data.map((k) => (
          <Card key={k.id}>
            <div className="flex flex-wrap items-start justify-between gap-2">
              <div><b className="text-navy-900">{k.subject}</b><p className="text-xs text-slate-500" dir="ltr">{k.saaed_ticket_no ?? '—'} · {fmt.date(k.created_at)} · {k.category} · {k.priority}</p></div>
              <div className="flex items-center gap-2">
                <Badge color={k.status === 'open' || k.status === 'closed' ? 'green' : k.status === 'failed' ? 'red' : 'gold'}>{t(`hlp.tk.states.${k.status}`, { defaultValue: k.status })}</Badge>
                {k.saaed_status && <Badge color="navy">{k.saaed_status}</Badge>}
                {(k.status === 'queued' || k.status === 'failed') && <Button size="sm" variant="outline" loading={busy === k.id} icon={<RefreshCw className="size-4" />} onClick={() => resend(k.id)}>{t('hlp.tk.resend')}</Button>}
              </div>
            </div>
            <p className="mt-2 text-xs text-slate-500">{k.user ? `${k.user.name_ar ?? k.user.name} · ${k.user.email}` : ''}</p>
          </Card>
        ))}</div>
      )}
    </>
  )
}
