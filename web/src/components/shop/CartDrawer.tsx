import { ShoppingCart, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useCart, CART_EVENT } from './useCart'
import CartPanel from './CartPanel'

/** The cart button (with the number of lines) and the slide-over cart. Opens by itself after something is added. */
export default function CartDrawer() {
  const { t } = useTranslation()
  const { on, cart, refetch } = useCart()
  const [open, setOpen] = useState(false)
  useEffect(() => {
    const h = () => { void refetch(); setOpen(true) }
    window.addEventListener(CART_EVENT, h)
    return () => window.removeEventListener(CART_EVENT, h)
  }, [refetch])
  if (!on) return null
  const n = cart?.lines.length ?? 0
  return (
    <>
      <button type="button" onClick={() => setOpen(true)} aria-label={String(t('pay.cart.open'))} className="relative rounded-xl p-2 text-navy-800 hover:bg-navy-100/60">
        <ShoppingCart className="size-5" />
        {n > 0 && <span className="absolute -end-0.5 -top-0.5 grid size-4 place-items-center rounded-full bg-gold-500 text-[10px] font-bold text-navy-950">{n}</span>}
      </button>
      {open && (
        <div className="fixed inset-0 z-50 flex justify-end bg-navy-950/40 backdrop-blur-sm" onClick={() => setOpen(false)}>
          <aside role="dialog" aria-label={String(t('pay.cart.title'))} className="flex h-full w-full max-w-md flex-col bg-ivory p-5 shadow-2xl" onClick={(e) => e.stopPropagation()}>
            <header className="mb-4 flex items-center justify-between"><h2 className="font-display text-xl font-bold text-navy-900">{t('pay.cart.title')}</h2><button type="button" aria-label={String(t('pay.common.close'))} onClick={() => setOpen(false)} className="rounded-lg p-2 hover:bg-navy-100/60"><X className="size-5" /></button></header>
            <div className="min-h-0 flex-1"><CartPanel onDone={() => setOpen(false)} /></div>
          </aside>
        </div>
      )}
    </>
  )
}
