import { useIsFetching, useIsMutating } from '@tanstack/react-query'
import { useEffect, useState } from 'react'

/** How long a load must last before the line is drawn: a quick answer never shows it. */
const SHOW_AFTER = 300

/**
 * A hairline at the top of the window while a page loads its first data or a change is being saved.
 *
 * It used to follow every request, so the quiet refreshes a page makes by itself (unread notices every minute, badges,
 * the check of the person's rights) drew a bar sweeping across the top again and again, on every page. Now it follows
 * only what the person is waiting for: data a page does not have yet, and saving. It grows once towards the end and
 * finishes, instead of looping.
 */
export function TopProgress() {
  // `pending`: the request has nothing to show yet. A refresh of data already on the screen is not a wait.
  const waiting = useIsFetching({ predicate: (query) => query.state.status === 'pending' }) + useIsMutating() > 0
  const [phase, setPhase] = useState<'idle' | 'running' | 'done'>('idle')

  useEffect(() => {
    if (waiting) {
      const show = setTimeout(() => setPhase('running'), SHOW_AFTER)
      return () => clearTimeout(show)
    }
    if (phase !== 'running') return
    setPhase('done')
    const hide = setTimeout(() => setPhase('idle'), 450)
    return () => clearTimeout(hide)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [waiting])

  if (phase === 'idle') return null
  return (
    <div className="pointer-events-none fixed inset-x-0 top-0 z-[100] h-0.5 print:hidden" role="progressbar" aria-busy={phase === 'running'} aria-label="Loading">
      <div className={phase === 'running' ? 'top-progress h-full' : 'top-progress top-progress-done h-full'} />
    </div>
  )
}
