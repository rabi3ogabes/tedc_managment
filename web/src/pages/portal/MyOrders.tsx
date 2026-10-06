/* eslint-disable @typescript-eslint/no-explicit-any */
import { FileDown, Ticket } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Badge, Button, Card, Empty, Field, Modal, PageHeader, Spinner, Table, Td } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, downloadFile, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'

const TONE: Record<string, any> = { paid: 'green', pending_payment: 'amber', failed: 'red', cancelled: 'gray', refunded: 'blue', partially_refunded: 'blue' }

/** My orders with invoices and refund requests, and the vouchers given to me. */
export default function MyOrders() {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const orders = useGet<{ data: any[] }>('/me/orders', undefined, { staleTime: 0 })
  const vouchers = useGet<{ data: any[] }>('/me/vouchers', undefined, { staleTime: 0 })
  const [refund, setRefund] = useState<any | null>(null)
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState(false)
  const redeem = async () => {
    setBusy(true)
    try { await api.post('/me/vouchers/redeem', { code }); toast(String(t('pay.vouchers.redeemed'))); setCode(''); vouchers.refetch() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <>
      <PageHeader title={t('pay.orders.title')} />
      <Card className="mb-6 space-y-3">
        <h3 className="flex items-center gap-2 font-bold text-navy-900"><Ticket className="size-5 text-gold-600" />{t('pay.vouchers.redeem')}</h3>
        <div className="flex flex-wrap gap-2"><input className="input max-w-xs font-mono uppercase" dir="ltr" value={code} onChange={(e) => setCode(e.target.value)} placeholder="XXXX-XXXX-XXXX" maxLength={20} /><Button variant="gold" loading={busy} disabled={!code.trim()} onClick={redeem}>{t('pay.vouchers.use')}</Button></div>
        {(vouchers.data?.data.length ?? 0) > 0 && (
          <ul className="divide-y divide-navy-50 text-sm">{vouchers.data!.data.map((v) => <li key={v.id} className="flex flex-wrap items-center justify-between gap-2 py-2"><span><b>{en ? v.program_en : v.program_ar}</b>{v.code && <span className="ms-2 font-mono text-xs text-slate-500">{v.code}</span>}</span><span className="flex items-center gap-2"><Badge color={v.status === 'redeemed' ? 'green' : 'gold'}>{t(`pay.vouchers.st.${v.status}`)}</Badge><span className="text-xs text-slate-400">{t('pay.vouchers.expires', { d: fmt.date(v.expires_at) })}</span></span></li>)}</ul>
        )}
      </Card>
      {orders.isLoading ? <Spinner /> : (orders.data?.data.length ?? 0) === 0 ? <Empty text={String(t('pay.orders.empty'))} /> : (
        <Card padded={false}>
          <Table head={[t('pay.orders.number'), t('pay.orders.date'), t('pay.orders.total'), t('pay.orders.status'), '']}>
            {orders.data!.data.map((o) => (
              <tr key={o.id}>
                <Td><div className="font-mono text-xs" dir="ltr">{o.number}</div><div className="text-xs text-slate-500">{o.items.map((i: any) => (en ? i.title_en : i.title_ar)).join('، ')}</div></Td>
                <Td>{fmt.date(o.created_at)}</Td><Td><b>{fmt.number(o.total, 2)}</b> {o.currency}</Td>
                <Td><Badge color={TONE[o.status] ?? 'gray'}>{t(`pay.orders.st.${o.status}`)}</Badge></Td>
                <Td><div className="flex flex-wrap gap-1.5">
                  {o.has_invoice && <Button size="sm" variant="outline" icon={<FileDown className="size-4" />} onClick={() => downloadFile(`/me/orders/${o.id}/invoice`, `${o.invoice_no}.pdf`)}>{t('pay.orders.invoice')}</Button>}
                  {['paid', 'partially_refunded'].includes(o.status) && o.total > 0 && <Button size="sm" variant="ghost" onClick={() => setRefund(o)}>{t('pay.orders.refund')}</Button>}
                </div></Td>
              </tr>
            ))}
          </Table>
        </Card>
      )}
      {refund && <RefundDialog order={refund} onClose={() => setRefund(null)} onDone={() => { setRefund(null); orders.refetch() }} />}
    </>
  )
}

function RefundDialog({ order, onClose, onDone }: { order: any; onClose: () => void; onDone: () => void }) {
  const { t } = useTranslation()
  const detail = useGet<{ data: any }>(`/me/orders/${order.id}`, undefined, { staleTime: 0 })
  const [reason, setReason] = useState('')
  const [busy, setBusy] = useState(false)
  const q = detail.data?.data.refundable
  const send = async () => {
    setBusy(true)
    try { await api.post(`/me/orders/${order.id}/refund-request`, { reason: reason || undefined }); toast(String(t('pay.orders.sent'))); onDone() } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  return (
    <Modal open onClose={onClose} title={`${t('pay.orders.refundTitle')} · ${order.number}`}>
      {!detail.data ? <Spinner /> : (
        <div className="space-y-4">
          {detail.data.data.refunds?.length > 0 && <ul className="space-y-1 text-sm">{detail.data.data.refunds.map((r: any) => <li key={r.id} className="flex justify-between"><span>{fmt.number(r.amount, 2)} — {t(`pay.orders.rf.${r.status}`)}</span>{r.credit_note_no && <button type="button" className="text-link underline" onClick={() => downloadFile(`/me/refunds/${r.id}/credit-note`, `${r.credit_note_no}.pdf`)}>{r.credit_note_no}</button>}</li>)}</ul>}
          {q?.amount > 0 ? <>
            <p className="rounded-xl bg-gold-50 p-3 text-sm font-semibold text-gold-700">{t('pay.orders.refundable', { a: `${fmt.number(q.amount, 2)} ${order.currency}` })}</p>
            <Field label={t('pay.orders.reason')}><textarea className="input min-h-20" value={reason} onChange={(e) => setReason(e.target.value)} maxLength={250} /></Field>
            <div className="flex justify-end gap-2"><Button variant="ghost" onClick={onClose}>{t('pay.common.cancel')}</Button><Button variant="gold" loading={busy} onClick={send}>{t('pay.orders.send')}</Button></div>
          </> : <p className="text-sm text-slate-500">{t('pay.orders.nothing')}</p>}
        </div>
      )}
    </Modal>
  )
}
