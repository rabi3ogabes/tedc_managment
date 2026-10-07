import { ArrowLeft, CircleHelp, Search, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { createPortal } from 'react-dom'
import { useTranslation } from 'react-i18next'
import { Link, useLocation } from 'react-router-dom'
import { Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import ArticleView from './ArticleView'
import { helpRoute, useLang, type HelpArticle } from './helpApi'

/** The «?» in the top bar: opens a panel with the articles linked to the page the person is on, with search and the whole help centre one step away. */
export default function HelpButton({ portal }: { portal: boolean }) {
  const { t } = useTranslation()
  const { pick } = useLang()
  const { pathname } = useLocation()
  const [open, setOpen] = useState(false)
  const [slug, setSlug] = useState<string | null>(null)
  const [q, setQ] = useState('')
  const route = helpRoute(pathname)
  const here = useGet<{ data: HelpArticle[] }>(open ? '/me/help/articles' : null, { route }, { staleTime: 60_000 })
  const found = useGet<{ data: HelpArticle[] }>(open && q.trim().length > 1 ? '/me/help/articles' : null, { q: q.trim() }, { staleTime: 30_000 })

  useEffect(() => { setSlug(null); setQ('') }, [pathname])
  useEffect(() => {
    if (!open) return
    const onKey = (e: KeyboardEvent) => { if (e.key === 'Escape') setOpen(false) }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [open])

  const list = q.trim().length > 1 ? found.data?.data : here.data?.data
  const loading = q.trim().length > 1 ? found.isLoading : here.isLoading
  return (
    <>
      <button type="button" data-tour="help-button" onClick={() => setOpen(true)} aria-label={t('hlp.button')} title={String(t('hlp.button'))} className="rounded-xl p-2 text-navy-800 hover:bg-navy-100/60">
        <CircleHelp className="size-5" />
      </button>
      {open && createPortal(
        <div className="fixed inset-0 z-50" role="dialog" aria-modal="true" aria-label={String(t('hlp.drawerTitle'))}>
          <div className="absolute inset-0 bg-navy-950/40" onClick={() => setOpen(false)} />
          <aside className="absolute inset-y-0 end-0 flex w-full max-w-md flex-col bg-white shadow-2xl">
            <div className="flex items-center justify-between border-b border-navy-100 px-4 py-3">
              <div className="flex items-center gap-2">
                {slug && <button type="button" onClick={() => setSlug(null)} aria-label={t('hlp.back')} className="rounded-lg p-1.5 hover:bg-navy-100/60"><ArrowLeft className="size-5 rtl:-scale-x-100" /></button>}
                <h2 className="font-bold text-navy-900">{t('hlp.drawerTitle')}</h2>
              </div>
              <button type="button" onClick={() => setOpen(false)} aria-label="close" className="rounded-lg p-1.5 hover:bg-navy-100/60"><X className="size-5" /></button>
            </div>
            <div className="flex-1 space-y-4 overflow-y-auto p-4">
              {slug ? <ArticleView slug={slug} /> : (
                <>
                  <label className="relative block">
                    <Search className="pointer-events-none absolute start-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                    <input className="input ps-9" value={q} onChange={(e) => setQ(e.target.value)} placeholder={String(t('hlp.search'))} aria-label={String(t('hlp.search'))} />
                  </label>
                  <h3 className="text-sm font-semibold text-slate-500">{q.trim().length > 1 ? t('hlp.results') : t('hlp.forPage')}</h3>
                  {loading ? <Spinner /> : (list?.length ?? 0) === 0 ? <p className="rounded-xl bg-ivory p-4 text-sm text-slate-600">{q.trim().length > 1 ? t('hlp.noResults') : t('hlp.none')}</p> : (
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
            <div className="border-t border-navy-100 p-3 text-center">
              <Link to={portal ? '/portal/help' : '/admin/help'} onClick={() => setOpen(false)} className="text-sm font-semibold text-navy-700 underline">{t('hlp.openCentre')}</Link>
            </div>
          </aside>
        </div>,
        document.body,
      )}
    </>
  )
}
