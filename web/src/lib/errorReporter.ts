/**
 * Reports the website's own errors to the administrator's error log: uncaught errors, unhandled promise
 * rejections, crashed pages and failed server calls. Reports are batched, de-duplicated and rate-limited, and
 * the reporter itself can never throw or loop.
 */
import { API_URL, sessionStore } from './api'

type Report = { source: 'web'; level: 'warning' | 'error' | 'critical'; message: string; stack?: string; route: string; url: string; status_code?: number; app_version?: string; device: Record<string, unknown>; context?: Record<string, unknown> }

const queue: Report[] = []
const seen = new Map<string, number>()
let timer: number | undefined
let sentThisMinute = 0
let windowStart = Date.now()

const device = () => ({ platform: 'web', language: navigator.language, screen: `${window.screen.width}x${window.screen.height}`, viewport: `${window.innerWidth}x${window.innerHeight}`, online: navigator.onLine })

async function flush() {
  timer = undefined
  if (!queue.length) return
  const batch = queue.splice(0, 10)
  const session = sessionStore.get()
  try {
    await fetch(`${API_URL}/client-errors`, {
      method: 'POST', keepalive: true,
      headers: { 'Content-Type': 'application/json', Accept: 'application/json', ...(session ? { Authorization: `Bearer ${session.access_token}` } : {}) },
      body: JSON.stringify({ errors: batch }),
    })
  } catch { /* offline: the reports are dropped, nothing else breaks */ }
}

export function reportError(error: unknown, extra: { level?: Report['level']; status_code?: number; context?: Record<string, unknown> } = {}) {
  try {
    const err = error as { message?: string; stack?: string } | string | undefined
    const message = String(typeof err === 'string' ? err : err?.message ?? 'Unknown error').slice(0, 1500)
    // Noise that is not a bug: extensions, cancelled requests, resize observers.
    if (/ResizeObserver loop|Script error\.?$|AbortError|Network Error|canceled|Load failed|extension:\/\//i.test(message)) return
    const now = Date.now()
    if (now - windowStart > 60_000) { windowStart = now; sentThisMinute = 0 }
    if (sentThisMinute >= 20) return
    const key = `${message}|${location.pathname}`
    if (now - (seen.get(key) ?? 0) < 30_000) return
    seen.set(key, now)
    sentThisMinute++
    queue.push({
      source: 'web', level: extra.level ?? 'error', message, stack: typeof err === 'object' ? String(err?.stack ?? '').slice(0, 8000) : undefined,
      route: location.pathname, url: location.href.replace(/([?&](token|code|password|key)=)[^&]*/gi, '$1***'), status_code: extra.status_code, app_version: import.meta.env.VITE_APP_VERSION as string | undefined,
      device: device(), context: extra.context,
    })
    timer ??= window.setTimeout(flush, 2500)
  } catch { /* never throw from the reporter */ }
}

/** Call once at start. */
export function installErrorReporting() {
  window.addEventListener('error', (e) => reportError(e.error ?? e.message))
  window.addEventListener('unhandledrejection', (e) => reportError(e.reason))
  window.addEventListener('pagehide', () => { if (queue.length) void flush() })
}
