import { useQueries } from '@tanstack/react-query'
import clsx from 'clsx'
import { X } from 'lucide-react'
import { lazy, Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useLocation, useNavigate } from 'react-router-dom'
import { Spinner } from '@/components/ui'
import { api } from '@/lib/api'
import TabErrorBoundary from '../settings/TabErrorBoundary'
import KitDocTabs, { type DocTab } from './KitDocTabs'
import type { KitDelivery, KitDetail } from './types'

const KitsHome = lazy(() => import('./KitsHome'))
const KitWorkspace = lazy(() => import('./KitWorkspace'))

const STORAGE = 'tedc.kits.open'
const MAX_OPEN = 10
const read = (): string[] => { try { const v = JSON.parse(localStorage.getItem(STORAGE) ?? '[]'); return Array.isArray(v) ? v.filter((x): x is string => typeof x === 'string').slice(0, MAX_OPEN) : [] } catch { return [] } }

/**
 * Every kit opens in its own tab, next to the studio, so the administrator can work on several at once — in person,
 * online and hybrid alike. Opened kits stay alive in the background (unsaved slide edits, the tab inside the kit and
 * the scroll position are kept) and are restored after a reload.
 */
export default function KitsWorkbench() {
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const { pathname } = useLocation()
  const routeKit = pathname.match(/^\/admin\/kits\/([^/]+)/)?.[1] ?? null
  const [open, setOpen] = useState<string[]>(read)
  const [visited, setVisited] = useState<Set<string>>(new Set())
  const [menu, setMenu] = useState<{ id: string; x: number; y: number } | null>(null)
  const [notice, setNotice] = useState<string | null>(null)
  const active = routeKit ?? 'home'
  const rtl = i18n.dir() === 'rtl'
  const activeRef = useRef(active)
  activeRef.current = active

  // A kit that is opened (a link, the address bar, the back button) gets a tab; too many at once drop the oldest idle one.
  useEffect(() => {
    if (!routeKit) return
    setOpen((cur) => {
      if (cur.includes(routeKit)) return cur
      const next = [...cur, routeKit]
      if (next.length <= MAX_OPEN) return next
      const drop = next.find((id) => id !== routeKit)
      setNotice(t('kits.workbench.limit', { n: MAX_OPEN }))
      return next.filter((id) => id !== drop)
    })
  }, [routeKit, t])
  useEffect(() => { setVisited((cur) => (cur.has(active) ? cur : new Set(cur).add(active))) }, [active])
  useEffect(() => { try { localStorage.setItem(STORAGE, JSON.stringify(open)) } catch { /* storage unavailable */ } }, [open])
  useEffect(() => { if (!notice) return; const id = setTimeout(() => setNotice(null), 6000); return () => clearTimeout(id) }, [notice])

  // The label of each tab: the same request the kit page makes, so it is fetched once.
  const details = useQueries({
    queries: open.map((id) => ({ queryKey: [`/admin/kits/${id}`, undefined, i18n.language], queryFn: async () => (await api.get(`/admin/kits/${id}`)).data as { data: KitDetail }, retry: false, staleTime: 30_000 })),
  })
  const tabs: DocTab[] = useMemo(() => open.map((id, i) => {
    const kit = details[i]?.data?.data
    return { id, code: kit?.code, title: kit?.title ?? '', delivery: (kit?.delivery ?? null) as KitDelivery | null, loading: details[i]?.isLoading }
  }), [open, details])

  const go = useCallback((id: string) => navigate(id === 'home' ? '/admin/kits' : `/admin/kits/${id}`), [navigate])
  const close = useCallback((ids: string[]) => {
    const cur = open
    const remaining = cur.filter((id) => !ids.includes(id))
    if (ids.includes(activeRef.current)) {
      const at = cur.indexOf(activeRef.current)
      go(cur.slice(at + 1).find((id) => remaining.includes(id)) ?? [...cur.slice(0, at)].reverse().find((id) => remaining.includes(id)) ?? 'home')
    }
    setOpen(remaining)
    setVisited((v) => { const n = new Set(v); ids.forEach((id) => n.delete(id)); return n })
  }, [open, go])
  const reorder = useCallback((from: string, to: string) => setOpen((cur) => {
    const next = cur.filter((id) => id !== from)
    const at = next.indexOf(to)
    next.splice(cur.indexOf(from) < cur.indexOf(to) ? at + 1 : at, 0, from)
    return next
  }), [])

  // A kit that no longer exists loses its tab.
  useEffect(() => {
    const gone = open.filter((_, i) => (details[i]?.error as { response?: { status?: number } } | null)?.response?.status === 404)
    if (gone.length) close(gone)
  }, [details, open, close])

  useEffect(() => {
    if (!menu) return
    const hide = () => setMenu(null)
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && hide()
    window.addEventListener('click', hide); window.addEventListener('scroll', hide, true); window.addEventListener('keydown', onKey)
    return () => { window.removeEventListener('click', hide); window.removeEventListener('scroll', hide, true); window.removeEventListener('keydown', onKey) }
  }, [menu])

  const index = menu ? open.indexOf(menu.id) : -1
  const items = menu ? [
    { label: t('kits.workbench.close'), run: () => close([menu.id]) },
    { label: t('kits.workbench.closeOthers'), run: () => close(open.filter((id) => id !== menu.id)), disabled: open.length < 2 },
    { label: t('kits.workbench.closeRight'), run: () => close(open.slice(index + 1)), disabled: index === open.length - 1 },
    { label: t('kits.workbench.moveStart'), run: () => setOpen((cur) => [menu.id, ...cur.filter((id) => id !== menu.id)]), disabled: index === 0 },
    { label: t('kits.workbench.closeAll'), run: () => close(open), danger: true },
  ] : []

  return (
    <div>
      <KitDocTabs tabs={tabs} active={active} onSelect={go} onClose={(id) => close([id])} onReorder={reorder} onContextMenu={(id, x, y) => setMenu({ id, x, y })} />
      {notice && <div role="status" className="mb-4 flex items-center justify-between gap-3 rounded-xl bg-amber-50 px-4 py-2.5 text-sm font-semibold text-amber-800">{notice}<button type="button" aria-label={t('kits.workbench.close')} onClick={() => setNotice(null)}><X className="size-4" /></button></div>}

      <div hidden={active !== 'home'}><Suspense fallback={<Spinner />}><KitsHome /></Suspense></div>
      {open.filter((id) => visited.has(id) || id === routeKit).map((id) => (
        <div key={id} hidden={active !== id} role="tabpanel">
          <TabErrorBoundary title={t('kits.tabs.failed')} hint={t('kits.tabs.failedHint')} retry={t('kits.common.retry')}>
            <Suspense fallback={<Spinner />}><KitWorkspace kitId={id} active={active === id} /></Suspense>
          </TabErrorBoundary>
        </div>
      ))}

      {menu && (
        <ul role="menu" className="fixed z-[70] min-w-52 overflow-hidden rounded-xl border border-navy-100 bg-white py-1.5 text-sm shadow-glass" style={{ top: Math.min(menu.y, window.innerHeight - 240), [rtl ? 'right' : 'left']: Math.max(8, Math.min(rtl ? window.innerWidth - menu.x : menu.x, window.innerWidth - 230)) }}>
          {items.map((item) => (
            <li key={item.label} role="none">
              <button role="menuitem" type="button" disabled={item.disabled} onClick={() => { setMenu(null); item.run() }} className={clsx('flex w-full items-center gap-2 px-4 py-2 text-start transition disabled:opacity-40', item.danger ? 'text-danger hover:bg-red-50' : 'text-navy-900 hover:bg-ivory')}>{item.label}</button>
            </li>
          ))}
        </ul>
      )}
    </div>
  )
}
