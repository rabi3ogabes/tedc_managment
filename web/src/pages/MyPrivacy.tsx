/* eslint-disable @typescript-eslint/no-explicit-any */
import { Download, ShieldCheck } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, PageHeader, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

/** The person's own data rights: download everything held about them, and ask for correction, erasure, restriction or objection. */
export default function MyPrivacy() {
  const { t } = useTranslation()
  const res = useGet<{ data: any[] }>('/me/privacy/requests', undefined, { staleTime: 0 })
  const [type, setType] = useState('access')
  const [details, setDetails] = useState('')
  const [busy, setBusy] = useState(false)
  const send = async () => {
    setBusy(true)
    try { await api.post('/me/privacy/requests', { type, details: details || undefined }); toast(String(t('prv.sent'))); setDetails(''); res.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  const download = async () => { try { await downloadFile('/me/privacy/export', 'my-data.json'); toast(String(t('prv.downloaded'))) } catch (e) { toast(errorMessage(e), 'error') } }
  return (
    <>
      <PageHeader title={t('prv.title')} subtitle={t('prv.subtitle')} />
      <div className="grid gap-5 lg:grid-cols-2">
        <Card className="space-y-3"><h3 className="flex items-center gap-2 font-bold text-navy-900"><Download className="size-5 text-gold-600" />{t('prv.download')}</h3><p className="text-sm text-slate-500">{t('prv.downloadHint')}</p><Button variant="gold" onClick={download}>{t('prv.download')}</Button></Card>
        <Card className="space-y-3">
          <h3 className="flex items-center gap-2 font-bold text-navy-900"><ShieldCheck className="size-5 text-gold-600" />{t('prv.newReq')}</h3>
          <Field label={t('prv.type')}><select className="input" value={type} onChange={(e) => setType(e.target.value)}>{['access', 'correction', 'erasure', 'objection', 'restriction'].map((x) => <option key={x} value={x}>{t(`prv.types.${x}`)}</option>)}</select></Field>
          <Field label={t('prv.details')}><textarea className="input min-h-20" value={details} onChange={(e) => setDetails(e.target.value)} maxLength={2000} /></Field>
          <div className="flex justify-end"><Button variant="gold" loading={busy} onClick={send}>{t('prv.send')}</Button></div>
        </Card>
      </div>
      <div className="mt-6 space-y-3">
        {res.isLoading ? <Spinner /> : (res.data?.data.length ?? 0) === 0 ? <Empty text={String(t('prv.none'))} /> : res.data!.data.map((r) => (
          <Card key={r.id}><div className="flex flex-wrap items-center justify-between gap-2"><b>{t(`prv.types.${r.type}`)}</b><div className="flex gap-2">{r.overdue && <Badge color="red">{t('prv.overdue')}</Badge>}<Badge color={r.status === 'completed' ? 'green' : r.status === 'rejected' ? 'gray' : 'amber'}>{t(`prv.status.${r.status}`)}</Badge></div></div>
            <p className="mt-1 text-xs text-slate-500">{t('prv.due')}: {fmt.date(r.due_at)}</p>{r.resolution && <p className="mt-2 rounded-xl bg-ivory p-3 text-sm"><b>{t('prv.answer')}:</b> {r.resolution}</p>}</Card>
        ))}
      </div>
    </>
  )
}
