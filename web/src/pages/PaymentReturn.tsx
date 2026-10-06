import { CheckCircle2, Clock, XCircle } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useSearchParams } from 'react-router-dom'
import { Button, Card } from '@/components/ui'
import { api } from '@/lib/api'
import { fmt } from '@/lib/format'

type Status = { number: string; status: string; total: number; currency: string }

/** Where the gateway sends the buyer back. It only shows what the server knows; the money is confirmed by the server's own message. */
export default function PaymentReturn() {
  const { t } = useTranslation()
  const [params] = useSearchParams()
  const order = params.get('order') ?? ''
  const [s, setS] = useState<Status | null>(null)
  const [tries, setTries] = useState(0)
  useEffect(() => {
    if (!order) return
    let stop = false
    const poll = async (n: number) => {
      try {
        const { data } = await api.get<{ data: Status }>('/payments/return', { params: { order } })
        if (stop) return
        setS(data.data); setTries(n)
        if (data.data.status === 'pending_payment' && n < 15) window.setTimeout(() => void poll(n + 1), 2000)
      } catch { /* the page keeps its last state */ }
    }
    void poll(1)
    return () => { stop = true }
  }, [order])

  const paid = s?.status === 'paid' || s?.status === 'partially_refunded' || s?.status === 'refunded'
  const pending = !s || s.status === 'pending_payment'
  return (
    <div className="mx-auto grid min-h-[70vh] max-w-lg place-items-center px-4 py-16">
      <Card className="w-full space-y-4 text-center">
        {pending ? <Clock className="mx-auto size-12 text-gold-600" /> : paid ? <CheckCircle2 className="mx-auto size-12 text-emerald-600" /> : <XCircle className="mx-auto size-12 text-danger" />}
        <h1 className="font-display text-2xl font-extrabold text-navy-900">{pending ? t('pay.ret.checking') : paid ? t('pay.ret.paid') : s?.status === 'cancelled' ? t('pay.ret.cancelled') : t('pay.ret.failed')}</h1>
        <p className="text-sm text-slate-600">{pending ? (tries >= 15 ? t('pay.ret.pending') : '') : paid ? t('pay.ret.paidHint') : t('pay.ret.failedHint')}</p>
        {s && <p className="text-sm text-slate-500">{t('pay.ret.order')}: <b dir="ltr">{s.number}</b> · {fmt.number(s.total, 2)} {s.currency}</p>}
        <div className="flex justify-center gap-2"><Button to="/portal/orders" variant="gold">{t('pay.ret.myOrders')}</Button><Link to="/" className="self-center text-sm font-semibold text-link">{t('pay.ret.home')}</Link></div>
      </Card>
    </div>
  )
}
