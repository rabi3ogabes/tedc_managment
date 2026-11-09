import { ArrowLeft, Pencil, Search, X } from 'lucide-react'
import { useEffect, useId, useRef, useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { Link, useLocation } from 'react-router-dom'
import { useGet } from '@/hooks/useApi'
import { useCloseOnBack } from '@/hooks/useCloseOnBack'
import { useAuth } from '@/lib/auth'
import ArticleView from './ArticleView'
import { helpRoute, useLang, type HelpArticle } from './helpApi'

export type HelpTarget = { slug?: string | null; page?: boolean }

/**
 * The help window: slides in from the end side and keeps the page in view (the "drawer" of the overlays-and-controls rules).
 * It opens on a given article, on the one article written for the page, or on the list of articles for the page with
 * a search box. Esc, the scrim, ✕ and the Back button close it; focus is kept inside while it is open and returns to
 * the button that opened it. The system administrator is offered to write the help for a page that has none.
 */
export default function HelpDrawer({ open, target, onClose, portal }: { open: boolean; target: HelpTarget; onClose: () => void; portal: boolean }) {
  const { t } = useTranslation()
  const { pick } = useLang()
  const { can } = useAuth()
  const { pathname } = useLocation()
  const route = helpRoute(pathname)
  const titleId = useId()
  const panel = useRef<HTMLElement>(null)
  const [slug, setSlug] = useState<string | null>(null)
  const [q, setQ] = useState('')
  const searching = q.trim().length > 1
  const here = useGet<{ data: HelpArticle[] }>(open ? '/me/help/articles' : null, { route }, { staleTime: 60_000 })
  const found = useGet<{ data: HelpArticle[] }>(open && searching ? '/me/help/articles' : null, { q: q.trim() }, { staleTime: 30_000 })
  const manager = can('help.manage')

  useCloseOnBack(open, onClose)

  // Each time it opens it starts where it was asked to: a named article, the page's single article, or the list.
  useEffect(() => {
    if (!open) return
    setQ('')
    setSlug(target.slug ?? null)
  }, [open, target.slug])
  useEffect(() => {
    if (open && target.page && !target.slug && here.data?.data.length === 1) setSlug(here.data.data[0].slug)
  }, [open, target.page, target.slug, here.data])

  useEffect(() => {
    if (!open) return
    const before = document.activeElement as HTMLElement | null
    const focusables = () => Array.from(panel.current?.querySelectorAll<HTMLElement>('a[href], button:not([disabled]), input:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])') ?? [])
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') { e.stopPropagation(); onClose(); return }
      if (e.key !== 'Tab') return
      const items = focusables()
      if (!items.length) return
      const first = items[0]
      const last = items[items.length - 1]
      if (e.shiftKey && (document.activeElement === first || document.activeElement === panel.current)) { e.preventDefault(); last.focus() }
      else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus() }
    }
    const scroll = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    window.addEventListener('keydown', onKey, true)
    panel.current?.focus()
    return () => {
      window.removeEventListener('keydown', onKey, true)
      document.body.style.overflow = scroll
      // Focus goes back to the button that opened the window. The Back-button entry closing re-renders the page, which can replace that button, so it is found again by its tour key.
      window.setTimeout(() => {
        const key = before?.getAttribute('data-tour')
        const target = before?.isConnected ? before : key ? document.querySelector<HTMLElement>(`[data-tour="${key}"]`) : null
        target?.focus?.()
      }, 80)
    }
  }, [open, onClose])

  if (!open) return null
  const list = searching ? found.data?.data : here.data?.data
  const loading = searching ? found.isLoading : here.isLoading
  const writeHref = `/admin/help-articles?new=1&route=${encodeURIComponent(route)}`

  return createPortal(
    <div className="fixed inset-0 z-50">
      <div className="help-scrim absolute inset-0 bg-navy-950/35" onClick={onClose} aria-hidden />
      <aside ref={panel} tabIndex={-1} role="dialog" aria-modal="true" aria-labelledby={titleId} className="help-drawer absolute inset-y-0 end-0 flex w-full max-w-md flex-col rounded-s-3xl bg-white shadow-2xl outline-none">
        <header className="flex items-center justify-between gap-2 border-b border-navy-100 px-4 py-3">
          <div className="flex min-w-0 items-center gap-2">
            {slug && <button type="button" onClick={() => setSlug(null)} aria-label={t('hlp.back')} className="grid size-10 place-items-center rounded-full hover:bg-navy-100/60"><ArrowLeft className="size-5 rtl:-scale-x-100" /></button>}
            <h2 id={titleId} className="truncate text-lg font-extrabold text-navy-900">{t('hlp.drawerTitle')}</h2>
          </div>
          <button type="button" onClick={onClose} aria-label={t('common.close', { defaultValue: 'Close' })} className="grid size-10 place-items-center rounded-full hover:bg-navy-100/60"><X className="size-5" /></button>
        </header>
        <div className="flex-1 space-y-4 overflow-y-auto overscroll-contain p-4">
          {slug ? <ArticleView slug={slug} /> : (
            <>
              <label className="relative block">
                <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                <input className="input ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('hlp.search'))} aria-label={String(t('hlp.search'))} />
              </label>
              <h3 className="text-sm font-semibold text-slate-500">{searching ? t('hlp.results') : t('hlp.forPage')}</h3>
              {loading ? <p className="text-sm text-slate-500" role="status">…</p> : (list?.length ?? 0) === 0 ? (
                <div className="space-y-3 rounded-2xl bg-ivory p-4 text-sm text-slate-600">
                  <p>{searching ? t('hlp.noResults') : t('hlp.none')}</p>
                  {manager && !searching && (
                    <>
                      <p className="text-xs text-slate-500">{t('hlp.writeHint')}</p>
                      <Link to={writeHref} onClick={onClose} className="inline-flex min-h-10 items-center gap-2 rounded-xl bg-navy-900 px-4 text-sm font-bold text-white"><Pencil className="size-4" />{t('hlp.writeCta')}</Link>
                    </>
                  )}
                </div>
              ) : (
                <ul className="space-y-2">
                  {list!.map((a) => (
                    <li key={a.id}>
                      <button type="button" onClick={() => setSlug(a.slug)} className="w-full rounded-xl border border-navy-100 p-3 text-start transition hover:border-gold-400">
                        <b className="block text-navy-900">{pick(a, 'title')}</b>
                        <span className="mt-1 block text-xs text-slate-500">{pick(a, 'excerpt')}</span>
                      </button>
                    </li>
                  ))}
                </ul>
              )}
            </>
          )}
        </div>
        <footer className="flex items-center justify-between gap-3 border-t border-navy-100 px-4 pb-[calc(0.75rem+env(safe-area-inset-bottom))] pt-3">
          <Link to={portal ? '/portal/help' : '/admin/help'} onClick={onClose} className="text-sm font-semibold text-navy-700 underline">{t('hlp.openCentre')}</Link>
          {manager && <Link to={slug ? `/admin/help-articles?edit=${slug}` : writeHref} onClick={onClose} className="inline-flex items-center gap-1.5 text-sm font-semibold text-gold-700"><Pencil className="size-3.5" />{slug ? t('hlp.editHere') : t('hlp.writeCta')}</Link>}
        </footer>
      </aside>
    </div>,
    document.body,
  )
}
