/* eslint-disable @typescript-eslint/no-explicit-any */
import { ShieldCheck, Trash2 } from 'lucide-react'
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Empty, Spinner } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { useCart } from './useCart'

/** The cart: lines with their seat holds, a discount code, totals with VAT, and the way to the gateway. Used for a person and for an entity. */
export default function CartPanel({ entityId, onDone }: { entityId?: string; onDone?: () => void }) {
  const { t, i18n } = useTranslation()
  const { cart, refetch, isLoading } = useCart(entityId)
  const [code, setCode] = useState('')
  const [busy, setBusy] = useState<string | null>(null)
  const base = entityId ? '/entity' : '/me'
  const q = entityId ? { entity_id: entityId } : {}
  const en = i18n.language === 'en'
  if (isLoading || !cart) return <Spinner />
  const call = async (key: string, fn: () => Promise<any>, ok?: string) => {
    setBusy(key)
    try { const r = await fn(); if (ok) toast(ok); await refetch(); return r } catch (e) { toast(errorMessage(e), 'error'); return null } finally { setBusy(null) }
  }
  const remove = (g: string) => call(`rm${g}`, () => (entityId ? api.delete(`/entity/cart/${g}`, { params: q }) : api.delete(`/me/cart/items/${g}`)))
  const apply = () => call('code', () => api.post(`${base}/cart/discount`, { code: code || null, ...q }), code ? String(t('pay.cart.codeApplied', { c: code.toUpperCase() })) : undefined)
  const checkout = async () => {
    const r = await call('pay', () => api.post(`${base}/checkout`, q))
    if (!r) return
    const { redirect_url, order } = r.data.data
    if (redirect_url) { toast(String(t('pay.cart.redirecting')), 'info'); window.location.assign(redirect_url) } else { toast(String(t('pay.cart.paidOk'))); onDone?.() }
    void order
  }
  const money = (n: number) => `${fmt.number(n, 2)} ${t('pay.price.currency')}`

  return (
    <div className="flex h-full flex-col">
      {cart.lines.length === 0 ? <Empty text={String(t('pay.cart.empty'))} /> : (
        <>
          <ul className="flex-1 space-y-3 overflow-y-auto">
            {cart.lines.map((l) => (
              <li key={l.item_id} className="rounded-xl border border-navy-100 bg-white p-3 text-sm">
                <div className="flex items-start justify-between gap-2">
                  <div className="min-w-0"><div className="font-semibold text-navy-900">{en ? l.title_en : l.title_ar}</div>
                    <div className="text-xs text-slate-500">{l.quantity > 1 ? `${l.quantity} × ` : ''}{l.unit_price > 0 ? money(l.unit_price) : t('pay.price.free')}{l.rule ? ` · ${l.rule}` : ''}</div>
                    <div className="text-[11px] text-slate-400">{t('pay.cart.holdUntil', { t: fmt.time(l.expires_at) })}</div></div>
                  <div className="text-end"><div className="font-bold text-navy-900">{money(l.total)}</div><button type="button" aria-label={String(t('pay.cart.remove'))} onClick={() => remove(l.group_id)} className="mt-1 text-slate-400 hover:text-danger"><Trash2 className="size-4" /></button></div>
                </div>
              </li>
            ))}
          </ul>
          <div className="mt-4 space-y-3 border-t border-navy-100 pt-4 text-sm">
            <div className="flex gap-2"><input className="input" value={code} onChange={(e) => setCode(e.target.value)} placeholder={String(t('pay.cart.code'))} maxLength={40} dir="ltr" /><Button variant="outline" loading={busy === 'code'} onClick={apply}>{t('pay.cart.apply')}</Button></div>
            {cart.code && <p className="text-xs font-semibold text-emerald-700">{t('pay.cart.codeApplied', { c: cart.code })}</p>}
            <dl className="space-y-1">
              <div className="flex justify-between text-slate-500"><dt>{t('pay.cart.subtotal')}</dt><dd>{money(cart.subtotal)}</dd></div>
              {cart.discount > 0 && <div className="flex justify-between text-emerald-700"><dt>{t('pay.cart.discount')}</dt><dd>− {money(cart.discount)}</dd></div>}
              {cart.vat > 0 && <div className="flex justify-between text-slate-500"><dt>{t('pay.cart.vat')}</dt><dd>{money(cart.vat)}</dd></div>}
              <div className="flex justify-between border-t border-navy-100 pt-2 text-base font-extrabold text-navy-900"><dt>{t('pay.cart.total')}</dt><dd>{money(cart.total)}</dd></div>
            </dl>
            <Button variant="gold" size="lg" className="w-full" loading={busy === 'pay'} onClick={checkout}>{cart.total > 0 ? t('pay.cart.checkout') : t('pay.cart.free')}</Button>
            {cart.total > 0 && <p className="flex items-start gap-2 text-xs text-slate-500"><ShieldCheck className="mt-0.5 size-4 shrink-0 text-emerald-600" />{t('pay.cart.secure')}</p>}
          </div>
        </>
      )}
    </div>
  )
}
