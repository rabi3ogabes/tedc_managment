import { useTranslation } from 'react-i18next'
import { Badge } from '@/components/ui'
import { fmt } from '@/lib/format'
import type { Pricing } from '@/lib/types'

/** What the catalogue shows about price: "Free for you", the price, or the range for a visitor. Nothing when payments are off. */
export default function PriceTag({ pricing, signedIn = false }: { pricing?: Pricing | null; signedIn?: boolean }) {
  const { t } = useTranslation()
  if (!pricing || (!pricing.paid && !pricing.free_for_you && pricing.to <= 0)) return null
  if (pricing.free_for_you) return <Badge color="green">{t('pay.price.freeForYou')}</Badge>
  if (!signedIn && pricing.from !== pricing.to) return <Badge color="gold">{pricing.from <= 0 ? t('pay.price.free') + ' – ' : ''}{fmt.number(pricing.to, 2)} {t('pay.price.currency')}</Badge>
  return <Badge color="gold">{fmt.number(pricing.price, 2)} {t('pay.price.currency')}</Badge>
}
