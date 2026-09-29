import clsx from 'clsx'
import { Command, RefreshCcw, X } from 'lucide-react'
import { Suspense, useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useSearchParams } from 'react-router-dom'
import { Spinner } from '@/components/ui'
import { useAuth } from '@/lib/auth'
import CommandPalette from './CommandPalette'
import Launcher from './Launcher'
import { HOME, SETTINGS_SECTIONS, type SettingsSection } from './registry'
import { SettingsTabContext } from './tabContext'
import TabErrorBoundary from './TabErrorBoundary'
import TabStrip from './TabStrip'

const STORAGE = 'tedc.settings.workspace.v1'
const RECENT = 'tedc.settings.recent.v1'

type Workspace = { open: string[]; active: string }

const read = <T,>(key: string, fallback: T): T => {
  try {
    const raw = localStorage.getItem(key)
    return raw ? (JSON.parse(raw) as T) : fallback
  } catch {
    return fallback
  }
}
const write = (key: string, value: unknown) => {
  try { localStorage.setItem(key, JSON.stringify(value)) } catch { /* storage unavailable */ }
}

type Menu = { id: string; x: number; y: number } | null

/** A multi-tab settings workspace: every settings page opens as a closable, draggable, keep-alive tab. */
export default function SettingsWorkspace() {
  const { t, i18n } = useTranslation()
  const { can } = useAuth()
  const [params, setParams] = useSearchParams()
  const sections = useMemo(() => SETTINGS_SECTIONS.filter((s) => s.permission.some((p) => can(p))), [can])
  const byId = useMemo(() => new Map(sections.map((s) => [s.id, s])), [sections])
  const rtl = i18n.dir() === 'rtl'

  const [ws, setWs] = useState<Workspace>(() => {
    const saved = read<Workspace>(STORAGE, { open: [], active: HOME })
    const open = saved.open.filter((id) => sections.some((s) => s.id === id))
    const wanted = new URLSearchParams(window.location.search).get('tab')
    if (wanted && sections.some((s) => s.id === wanted)) return { open: open.includes(wanted) ? open : [...open, wanted], active: wanted }
    return { open, active: open.includes(saved.active) ? saved.active : HOME }
  })
  const [recent, setRecent] = useState<string[]>(() => read<string[]>(RECENT, []))
  const [dirty, setDirty] = useState<Record<string, boolean>>({})
  const [reloads, setReloads] = useState<Record<string, number>>({})
  const [palette, setPalette] = useState(false)
  const [menu, setMenu] = useState<Menu>(null)
  const wsRef = useRef(ws)
  wsRef.current = ws

  const label = useCallback((id: string) => t(`mgmt.settings.sections.${id}.title`), [t])

  const openTab = useCallback((id: string) => {
    if (!byId.has(id)) return
    setWs((cur) => ({ open: cur.open.includes(id) ? cur.open : [...cur.open, id], active: id }))
    setRecent((cur) => { const next = [id, ...cur.filter((x) => x !== id)].slice(0, 6); write(RECENT, next); return next })
    setPalette(false)
  }, [byId])

  const closeTabs = useCallback((ids: string[]) => {
    const current = wsRef.current
    const targets = ids.filter((id) => current.open.includes(id))
    if (!targets.length) return
    const unsaved = targets.find((id) => dirty[id])
    if (unsaved && !window.confirm(t('mgmt.settings.dirtyConfirm', { name: label(unsaved) }))) return
    setWs((cur) => {
      const open = cur.open.filter((id) => !targets.includes(id))
      let active = cur.active
      if (targets.includes(cur.active)) {
        const at = cur.open.indexOf(cur.active)
        active = cur.open.slice(at + 1).find((id) => open.includes(id)) ?? [...cur.open.slice(0, at)].reverse().find((id) => open.includes(id)) ?? HOME
      }
      return { open, active }
    })
    setDirty((cur) => Object.fromEntries(Object.entries(cur).filter(([id]) => !targets.includes(id))))
  }, [dirty, label, t])

  const reorder = useCallback((from: string, to: string) => {
    setWs((cur) => {
      const open = cur.open.filter((id) => id !== from)
      const at = open.indexOf(to)
      const original = cur.open.indexOf(from) < cur.open.indexOf(to)
      open.splice(original ? at + 1 : at, 0, from)
      return { ...cur, open }
    })
  }, [])

  // Persist the workspace and mirror the active tab in the URL (deep links + reload).
  useEffect(() => {
    write(STORAGE, ws)
    const next = new URLSearchParams(params)
    if (ws.active === HOME) next.delete('tab'); else next.set('tab', ws.active)
    if (next.toString() !== params.toString()) setParams(next, { replace: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ws])

  // A link such as /admin/settings?tab=users opens that tab even when the workspace is already mounted.
  const wanted = params.get('tab')
  useEffect(() => {
    if (wanted === HOME && wsRef.current.active !== HOME) setWs((cur) => ({ ...cur, active: HOME }))
    else if (wanted && wanted !== wsRef.current.active && byId.has(wanted)) openTab(wanted)
  }, [wanted, byId, openTab])

  // Keyboard: Ctrl/⌘+K palette, Alt+W close, Alt+←/→ switch.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k') { e.preventDefault(); setPalette((v) => !v); return }
      if (!e.altKey || e.ctrlKey || e.metaKey) return
      const cur = wsRef.current
      if (e.code === 'KeyW' && cur.active !== HOME) { e.preventDefault(); closeTabs([cur.active]) }
      if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        e.preventDefault()
        const order = [HOME, ...cur.open]
        const visualStep = e.key === 'ArrowRight' ? 1 : -1
        const step = document.documentElement.dir === 'rtl' ? -visualStep : visualStep
        const at = order.indexOf(cur.active)
        const target = order[(at + step + order.length) % order.length]
        setWs({ ...cur, active: target })
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [closeTabs])

  // Close the context menu on any outside interaction.
  useEffect(() => {
    if (!menu) return
    const close = () => setMenu(null)
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && close()
    window.addEventListener('click', close)
    window.addEventListener('scroll', close, true)
    window.addEventListener('keydown', onKey)
    return () => { window.removeEventListener('click', close); window.removeEventListener('scroll', close, true); window.removeEventListener('keydown', onKey) }
  }, [menu])

  const stripTabs = ws.open.filter((id) => byId.has(id)).map((id) => ({ id, label: label(id), icon: byId.get(id)!.icon, dirty: !!dirty[id] }))
  const openSet = useMemo(() => new Set(ws.open), [ws.open])
  const menuIndex = menu ? ws.open.indexOf(menu.id) : -1

  const items = menu ? [
    { label: t('mgmt.settings.menu.close'), run: () => closeTabs([menu.id]) },
    { label: t('mgmt.settings.menu.closeOthers'), run: () => closeTabs(ws.open.filter((id) => id !== menu.id)), disabled: ws.open.length < 2 },
    { label: t('mgmt.settings.menu.closeRight'), run: () => closeTabs(ws.open.slice(menuIndex + 1)), disabled: menuIndex === ws.open.length - 1 },
    { label: t('mgmt.settings.menu.moveStart'), run: () => setWs((cur) => ({ ...cur, open: [menu.id, ...cur.open.filter((id) => id !== menu.id)] })), disabled: menuIndex === 0 },
    { label: t('mgmt.settings.menu.reload'), run: () => setReloads((cur) => ({ ...cur, [menu.id]: (cur[menu.id] ?? 0) + 1 })), icon: RefreshCcw },
    { label: t('mgmt.settings.menu.closeAll'), run: () => closeTabs(ws.open), danger: true },
  ] : []

  return (
    <div>
      <div className="mb-4 flex flex-wrap items-center justify-between gap-3">
        <div>
          <h1 className="text-2xl font-bold text-navy-900 sm:text-3xl">{t('mgmt.settings.title')}</h1>
          <p className="mt-1 hidden text-sm text-slate-500 sm:block">{t('mgmt.settings.subtitle')}</p>
        </div>
        <button type="button" onClick={() => setPalette(true)} className="inline-flex items-center gap-3 rounded-xl border border-navy-100 bg-white px-4 py-2.5 text-sm font-semibold text-slate-500 shadow-sm transition hover:border-gold-400 hover:text-navy-900">
          <Command className="size-4" />{t('mgmt.settings.openSetting')}<kbd className="rounded-md bg-slate-100 px-1.5 py-0.5 font-mono text-[11px] text-slate-500" dir="ltr">Ctrl K</kbd>
        </button>
      </div>

      <div className="overflow-hidden rounded-2xl border border-navy-100 bg-white shadow-glass">
        <TabStrip tabs={stripTabs} active={ws.active} onSelect={(id) => setWs((cur) => ({ ...cur, active: id }))} onClose={(id) => closeTabs([id])} onReorder={reorder}
          onContextMenu={(id, x, y) => setMenu({ id, x, y })} />

        <div className="bg-ivory p-4 sm:p-6">
          <div hidden={ws.active !== HOME} role="tabpanel">
            {ws.active === HOME && <Launcher sections={sections} open={openSet} recent={recent} onOpen={openTab} />}
          </div>
          {ws.open.map((id) => {
            const section = byId.get(id) as SettingsSection | undefined
            if (!section) return null
            const Page = section.component
            const active = ws.active === id
            return (
              <div key={`${id}-${reloads[id] ?? 0}`} hidden={!active} role="tabpanel" aria-label={label(id)}>
                <SettingsTabContext.Provider value={{ active, setDirty: (v) => setDirty((cur) => (!!cur[id] === v ? cur : { ...cur, [id]: v })) }}>
                  <TabErrorBoundary title={t('mgmt.settings.failed')} hint={t('mgmt.settings.failedHint')} retry={t('mgmt.settings.retry')}>
                    <Suspense fallback={<Spinner />}><Page /></Suspense>
                  </TabErrorBoundary>
                </SettingsTabContext.Provider>
              </div>
            )
          })}
        </div>
      </div>

      {menu && (
        <ul role="menu" className="fixed z-[70] min-w-52 overflow-hidden rounded-xl border border-navy-100 bg-white py-1.5 text-sm shadow-glass" style={{ top: Math.min(menu.y, window.innerHeight - 260), [rtl ? 'right' : 'left']: Math.max(8, Math.min(rtl ? window.innerWidth - menu.x : menu.x, window.innerWidth - 230)) }}>
          {items.map((item) => (
            <li key={item.label} role="none">
              <button role="menuitem" type="button" disabled={item.disabled} onClick={() => { setMenu(null); item.run() }} className={clsx('flex w-full items-center gap-2 px-4 py-2 text-start transition disabled:opacity-40', item.danger ? 'text-danger hover:bg-red-50' : 'text-navy-900 hover:bg-ivory')}>
                {item.icon ? <item.icon className="size-4" /> : item.danger ? <X className="size-4" /> : <span className="size-4" />}{item.label}
              </button>
            </li>
          ))}
        </ul>
      )}

      {palette && <CommandPalette sections={sections} open={openSet} onPick={openTab} onClose={() => setPalette(false)} />}
    </div>
  )
}
