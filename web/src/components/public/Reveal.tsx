import clsx from 'clsx'
import { useEffect, useRef, useState, type ReactNode } from 'react'

/** Fades a block in once when it scrolls into view. Respects "reduce motion" (the block is simply visible). */
export default function Reveal({ children, delay = 0, className, as: Tag = 'div' }: { children: ReactNode; delay?: number; className?: string; as?: 'div' | 'section' | 'li' }) {
  const ref = useRef<HTMLElement | null>(null)
  const [shown, setShown] = useState(() => typeof window === 'undefined' || !('IntersectionObserver' in window) || window.matchMedia('(prefers-reduced-motion: reduce)').matches)

  useEffect(() => {
    const el = ref.current
    if (shown || !el) return
    const io = new IntersectionObserver(([entry]) => { if (entry.isIntersecting) { setShown(true); io.disconnect() } }, { rootMargin: '0px 0px -8% 0px', threshold: 0.08 })
    io.observe(el)
    return () => io.disconnect()
  }, [shown])

  return (
    <Tag ref={ref as never} style={{ transitionDelay: shown ? `${delay}ms` : undefined }} className={clsx('transition-[opacity,transform] duration-700 ease-out', shown ? 'translate-y-0 opacity-100' : 'translate-y-6 opacity-0', className)}>
      {children}
    </Tag>
  )
}
