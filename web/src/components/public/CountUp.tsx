import { useEffect, useRef, useState } from 'react'

/** Counts up to a number the first time it is seen (instant when motion is reduced). */
export default function CountUp({ value, format }: { value: number | null | undefined; format: (n: number) => string }) {
  const ref = useRef<HTMLSpanElement>(null)
  const [shown, setShown] = useState(0)
  const [started, setStarted] = useState(false)

  useEffect(() => {
    const el = ref.current
    if (!el || started) return
    const io = new IntersectionObserver(([e]) => { if (e.isIntersecting) { setStarted(true); io.disconnect() } }, { threshold: 0.3 })
    io.observe(el)
    return () => io.disconnect()
  }, [started])

  useEffect(() => {
    if (!started || value == null) return
    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) { setShown(value); return }
    const t0 = performance.now(), duration = 1400
    let raf = 0
    const tick = (now: number) => {
      const p = Math.min(1, (now - t0) / duration)
      setShown(value * (1 - Math.pow(1 - p, 3)))
      if (p < 1) raf = requestAnimationFrame(tick)
    }
    raf = requestAnimationFrame(tick)
    return () => cancelAnimationFrame(raf)
  }, [started, value])

  return <span ref={ref}>{value == null ? '—' : format(started ? shown : 0)}</span>
}
