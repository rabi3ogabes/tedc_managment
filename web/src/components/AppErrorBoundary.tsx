import { RotateCw, TriangleAlert } from 'lucide-react'
import { Component, type ErrorInfo, type ReactNode } from 'react'
import i18n from '@/i18n'
import { reportError } from '@/lib/errorReporter'

const STALE = /Failed to fetch dynamically imported module|Importing a module script failed|error loading dynamically imported module|ChunkLoadError|Loading chunk .* failed|Unable to preload CSS/i

/**
 * A new deployment replaces the hashed files of the site, so a tab that was opened before it can fail to load a
 * page it has not visited yet — that used to leave a blank white screen until a manual refresh. We reload once
 * automatically (guarded against loops); any other crash shows a clear screen instead of a white page.
 */
export function reloadOnce(): boolean {
  try {
    const last = Number(sessionStorage.getItem('tedc.reloaded') ?? 0)
    if (Date.now() - last < 30_000) return false
    sessionStorage.setItem('tedc.reloaded', String(Date.now()))
  } catch { /* storage unavailable: still reload, the browser stops loops by itself */ }
  window.location.reload()
  return true
}

export function isStaleCodeError(error: unknown): boolean {
  return STALE.test(String((error as Error | undefined)?.message ?? error ?? ''))
}

/** Call once at start: stale code detected outside React (route preloads, rejected promises). */
export function installStaleCodeRecovery() {
  window.addEventListener('vite:preloadError', (e) => { e.preventDefault(); reloadOnce() })
  window.addEventListener('unhandledrejection', (e) => { if (isStaleCodeError(e.reason)) { e.preventDefault(); reloadOnce() } })
}

type Props = { children: ReactNode; /** Changing this (e.g. the current page) clears a previous error. */ resetKey?: string }

export default class AppErrorBoundary extends Component<Props, { error: Error | null }> {
  state = { error: null as Error | null }

  static getDerivedStateFromError(error: Error) {
    return { error }
  }

  componentDidCatch(error: Error, info: ErrorInfo) {
    console.error('The page crashed', error, info.componentStack)
    reportError(error, { level: 'critical', context: { componentStack: String(info.componentStack ?? '').slice(0, 1500) } })
    if (isStaleCodeError(error)) reloadOnce()
  }

  componentDidUpdate(prev: Props) {
    if (this.state.error && prev.resetKey !== this.props.resetKey) this.setState({ error: null })
  }

  render() {
    const { error } = this.state
    if (!error) return this.props.children
    const t = (k: string) => i18n.t(`errorPage.${k}`)
    return (
      <div className="grid min-h-[70vh] place-items-center bg-ivory px-6 py-16 text-center" role="alert" dir={i18n.dir()}>
        <div className="max-w-md">
          <span className="mx-auto grid size-16 place-items-center rounded-2xl bg-gold-100 text-gold-700"><TriangleAlert className="size-8" /></span>
          <h1 className="mt-5 text-2xl font-bold text-navy-900">{t(isStaleCodeError(error) ? 'updatedTitle' : 'title')}</h1>
          <p className="mt-2 text-sm leading-relaxed text-slate-500">{t(isStaleCodeError(error) ? 'updatedText' : 'text')}</p>
          <div className="mt-6 flex flex-wrap justify-center gap-3">
            <button type="button" onClick={() => window.location.reload()} className="inline-flex items-center gap-2 rounded-xl bg-navy-900 px-5 py-2.5 text-sm font-bold text-white transition hover:bg-navy-800"><RotateCw className="size-4" />{t('reload')}</button>
            <button type="button" onClick={() => this.setState({ error: null })} className="rounded-xl border border-navy-100 bg-white px-5 py-2.5 text-sm font-bold text-navy-900 transition hover:border-gold-400">{t('retry')}</button>
          </div>
          <details className="mt-6 text-start text-xs text-slate-400"><summary className="cursor-pointer text-center">{t('details')}</summary><pre className="mt-2 overflow-auto rounded-xl bg-white p-3 text-[11px] text-slate-500" dir="ltr">{String(error.message).slice(0, 400)}</pre></details>
        </div>
      </div>
    )
  }
}
