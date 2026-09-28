import { useIsFetching, useIsMutating } from '@tanstack/react-query'
import { useEffect, useState } from 'react'

/**
 * A slim progress line in the brand colors at the top of the window while data loads or saves.
 * It appears only after a short delay, so instant (cached) responses never flash it.
 */
export function TopProgress() {
  const busy = useIsFetching() + useIsMutating() > 0
  const [phase, setPhase] = useState<'idle' | 'running' | 'done'>('idle')

  useEffect(() => {
    if (busy) {
      const show = setTimeout(() => setPhase('running'), 150)
      return () => clearTimeout(show)
    }
    if (phase !== 'running') return
    setPhase('done')
    const hide = setTimeout(() => setPhase('idle'), 400)
    return () => clearTimeout(hide)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [busy])

  if (phase === 'idle') return null
  return (
    <div className="pointer-events-none fixed inset-x-0 top-0 z-[100] h-[3px] overflow-hidden" role="progressbar" aria-busy={phase === 'running'} aria-label="Loading">
      <div
        className={phase === 'running' ? 'top-progress-run h-full' : 'h-full w-full opacity-0 transition-opacity duration-300'}
        style={{ background: 'linear-gradient(90deg, var(--color-gold-400), var(--color-navy-900), var(--color-gold-400))', boxShadow: '0 0 12px var(--color-gold-400)' }}
      />
    </div>
  )
}
