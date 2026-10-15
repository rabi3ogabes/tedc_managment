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
            {['access', 'erasure'].includes(r.type) && ['received', 'in_progress'].includes(r.status) && <SurveyData userId={r.user_id} erasure={r.type === 'erasure'} />}
            {r.resolution && <p className="mt-2 rounded-xl bg-ivory p-3 text-sm">{r.resolution}</p>}
          </Card>))}</div>
      )}
    </>
  )
}

/** The person's survey data for an access or erasure request: counts, and the erasure itself (the answers stay, anonymous). */
function SurveyData({ userId, erasure }: { userId: string; erasure: boolean }) {
  const { t } = useTranslation()
  const [open, setOpen] = useState(false)
  const res = useGet<{ data: { identified_answers: number; files: number; participations: number; drafts: number } }>(open ? `/admin/privacy/subjects/${userId}/surveys` : null, undefined, { staleTime: 0 })
  const erase = async () => { if (!window.confirm(String(t('prv.surveys.confirm')))) return; try { await api.post(`/admin/privacy/subjects/${userId}/surveys/erase`); toast(String(t('prv.surveys.erased'))); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } }
  const d = res.data?.data
  return (
    <div className="mt-3 rounded-xl border border-slate-200 p-3 text-sm">
      <button type="button" className="font-semibold text-navy-900 underline" onClick={() => setOpen(!open)}>{t('prv.surveys.title')}</button>
      {open && (d ? <div className="mt-2 flex flex-wrap items-center gap-2 text-xs"><Badge color="gray">{t('prv.surveys.answers', { n: d.identified_answers })}</Badge><Badge color="gray">{t('prv.surveys.files', { n: d.files })}</Badge><Badge color="gray">{t('prv.surveys.participations', { n: d.participations })}</Badge><span className="text-slate-500">{t('prv.surveys.note')}</span>
        {erasure && (d.identified_answers + d.files + d.participations + d.drafts > 0) && <Button size="sm" variant="ghost" onClick={erase}>{t('prv.surveys.erase')}</Button>}</div> : <Spinner />)}
    </div>
  )
}
