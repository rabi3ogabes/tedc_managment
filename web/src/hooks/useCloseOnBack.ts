import { useEffect, useRef } from 'react'

type Marker = { __overlay?: number } | null

/**
 * The phone's Back button (and the browser's) closes the top overlay instead of leaving the page: one history entry is
 * pushed while an overlay is open and removed when it closes by its own ✕, Esc or scrim, so nothing goes stale.
 * Nested overlays each add one entry; Back pops the top-most only. Safe under React StrictMode.
 */
export function useCloseOnBack(open: boolean, close: () => void) {
  const closeRef = useRef(close)
  useEffect(() => { closeRef.current = close })
  useEffect(() => {
    if (!open) return
    let pushed = false
    let popped = false
    const depth = (((window.history.state as Marker)?.__overlay) ?? 0) + 1
    const timer = window.setTimeout(() => { window.history.pushState({ ...(window.history.state ?? {}), __overlay: depth }, ''); pushed = true }, 0)
    const onPop = () => { if (pushed && (((window.history.state as Marker)?.__overlay) ?? 0) < depth) { popped = true; closeRef.current() } }
    window.addEventListener('popstate', onPop)
    return () => {
      window.clearTimeout(timer)
      window.removeEventListener('popstate', onPop)
      if (pushed && !popped && (window.history.state as Marker)?.__overlay === depth) window.history.back()
    }
  }, [open])
}
