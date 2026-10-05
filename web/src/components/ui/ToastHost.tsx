import clsx from 'clsx'
import { CheckCircle2, Info, TriangleAlert } from 'lucide-react'
import { useEffect, useState } from 'react'
import type { ToastTone } from '@/lib/toast'

type Item = { id: number; text: string; tone: ToastTone }

/** Mounted once at the root: shows the messages sent with `toast()`, each for a few seconds. */
export default function ToastHost() {
  const [items, setItems] = useState<Item[]>([])

  useEffect(() => {
    let next = 1
    const onToast = (e: Event) => {
      const { text, tone } = (e as CustomEvent<{ text: string; tone: ToastTone }>).detail
      const id = next++
      setItems((list) => [...list.slice(-2), { id, text, tone }])
      window.setTimeout(() => setItems((list) => list.filter((i) => i.id !== id)), 4000)
    }
    window.addEventListener('tedc:toast', onToast)
    return () => window.removeEventListener('tedc:toast', onToast)
  }, [])

  if (!items.length) return null
  return (
    <div className="pointer-events-none fixed inset-x-0 bottom-6 z-[70] flex flex-col items-center gap-2 px-4" role="status" aria-live="polite">
      {items.map((i) => {
        const Icon = i.tone === 'success' ? CheckCircle2 : i.tone === 'error' ? TriangleAlert : Info
        return (
          <div key={i.id} className={clsx('pointer-events-auto flex max-w-md animate-fade-up items-center gap-2.5 rounded-2xl px-4 py-3 text-sm font-semibold shadow-xl',
            i.tone === 'success' && 'bg-navy-900 text-white', i.tone === 'error' && 'bg-red-600 text-white', i.tone === 'info' && 'bg-white text-navy-900 ring-1 ring-navy-100')}>
            <Icon className={clsx('size-4 shrink-0', i.tone === 'success' && 'text-gold-300')} />{i.text}
          </div>
        )
      })}
    </div>
  )
}
