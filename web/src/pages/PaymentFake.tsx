import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useParams, useSearchParams } from 'react-router-dom'
import { Button, Card } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'

/** The training gateway's "hosted page": choose how the payment ends. Exists only while no real gateway is connected. */
export default function PaymentFake() {
  const { t } = useTranslation()
  const { ref } = useParams()
  const [params] = useSearchParams()
  const [busy, setBusy] = useState<string | null>(null)
  const done = async (outcome: 'captured' | 'failed' | 'cancelled') => {
    setBusy(outcome)
    try {
      await api.post(`/payments/fake/${ref}/complete`, { outcome })
      const back = params.get('return')
      window.location.assign(back ?? `/payments/return?order=${params.get('order')}`)
    } catch (e) { toast(errorMessage(e), 'error'); setBusy(null) }
  }
  return (
    <div className="mx-auto grid min-h-[70vh] max-w-md place-items-center px-4 py-16">
      <Card className="w-full space-y-5 text-center">
        <h1 className="font-display text-xl font-bold text-navy-900">{t('pay.fake.title')}</h1>
        <p className="text-sm text-slate-500">{t('pay.fake.hint')}</p>
        <div className="rounded-xl bg-ivory p-4"><div className="text-xs text-slate-400" dir="ltr">{params.get('order')}</div><div className="font-display text-3xl font-extrabold text-navy-900">{params.get('amount')} QAR</div></div>
        <div className="grid gap-2"><Button variant="gold" size="lg" loading={busy === 'captured'} onClick={() => done('captured')}>{t('pay.fake.pay')}</Button>
          <Button variant="outline" loading={busy === 'failed'} onClick={() => done('failed')}>{t('pay.fake.fail')}</Button><Button variant="ghost" loading={busy === 'cancelled'} onClick={() => done('cancelled')}>{t('pay.fake.cancel')}</Button></div>
      </Card>
    </div>
  )
}
