/* eslint-disable @typescript-eslint/no-explicit-any */
import { useGet } from '@/hooks/useApi'
import { useFeature } from '@/hooks/useFeature'

export type CartLine = { item_id: string; group_id: string; program_id: string; title_ar: string; title_en: string; quantity: number; unit_price: number; vat_rate: number; gross: number; discount: number; vat: number; total: number; expires_at: string; category: string; rule: string | null }
export type CartTotals = { lines: CartLine[]; subtotal: number; discount: number; vat: number; total: number; currency: string; code: string | null }

export const CART_EVENT = 'tedc:cart-open'

/** The person's cart (or an entity's); null while payments are off. */
export function useCart(entityId?: string) {
  const on = useFeature('payments')
  const res = useGet<{ data: CartTotals }>(on ? (entityId ? '/entity/cart' : '/me/cart') : null, entityId ? { entity_id: entityId } : undefined, { staleTime: 0, retry: false })
  return { on, cart: res.data?.data ?? null, refetch: res.refetch, isLoading: res.isLoading }
}
