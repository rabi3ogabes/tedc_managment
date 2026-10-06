/* eslint-disable @typescript-eslint/no-explicit-any */
import { useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Field } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import type { Program } from '@/lib/types'
import { CART_EVENT } from './useCart'

/** On a programme page when the person has to pay: pick the group, see the price for them, add it to the cart. */
export default function AddToCart({ program }: { program: Program }) {
  const { t } = useTranslation()
  const groups: any[] = ((program as any).groups?.data ?? (program as any).groups ?? []).filter((g: any) => g.registration_open !== false)
  const [gid, setGid] = useState<string>(groups[0]?.id ?? '')
  const [busy, setBusy] = useState(false)
  const price = program.pricing?.groups?.[gid]
  const add = async () => {
    setBusy(true)
    try { await api.post('/me/cart/items', { group_id: gid }); toast(String(t('pay.add.added'))); window.dispatchEvent(new Event(CART_EVENT)) } catch (e) { toast(errorMessage(e), 'error') } finally { setBusy(false) }
  }
  if (!groups.length) return <p className="text-sm text-slate-500">{t('pay.add.noGroups')}</p>
  return (
    <div className="space-y-3">
      {groups.length > 1 && (
        <Field label={t('pay.add.group')}><select className="input" value={gid} onChange={(e) => setGid(e.target.value)}>{groups.map((g) => <option key={g.id} value={g.id}>{g.title ?? g.code} · {fmt.date(g.start_date)}{program.pricing?.groups?.[g.id] ? ` · ${program.pricing.groups[g.id].free_for_you ? t('pay.price.freeForYou') : `${fmt.number(program.pricing.groups[g.id].price, 2)} ${t('pay.price.currency')}`}` : ''}</option>)}</select></Field>
      )}
      <div className="flex items-baseline justify-between"><span className="text-sm text-slate-500">{t('pay.price.vat')}</span><span className="font-display text-2xl font-extrabold text-navy-900">{price ? `${fmt.number(price.price, 2)} ${t('pay.price.currency')}` : program.pricing ? `${fmt.number(program.pricing.price, 2)} ${t('pay.price.currency')}` : ''}</span></div>
      <Button variant="gold" size="lg" className="w-full" loading={busy} disabled={!gid} onClick={add}>{t('pay.add.button')}</Button>
    </div>
  )
}
