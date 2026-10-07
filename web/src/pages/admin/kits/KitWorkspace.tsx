import clsx from 'clsx'
import { AlertTriangle, ArrowLeft, ChevronDown, ArrowRight, CalendarClock, Check, CheckCircle2, Clapperboard, Download, FilePlus2, FileUp, Files as Files2, History, Image as ImageIcon, LayoutDashboard, Loader2, MessageSquare, Pencil, Presentation, RefreshCcw, Sparkles, Trash2, UploadCloud, X } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Link, useNavigate, useParams, useSearchParams } from 'react-router-dom'
import { Avatar, Badge, Button, Card, Empty, ErrorState, Progress, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import GenerateDeckDialog from './ai/GenerateDeckDialog'
import VideoStudio from './ai/VideoStudio'
import CommentsPanel from './comments/CommentsPanel'
import { relativeTime, useKitComments } from './comments/api'
import { DeliveryBadge, KitStatusBadge, categoryIcon, fmtSize, kindIcon, kindTint } from './common'
import ImagePickerDialog from './deck/ImagePickerDialog'
import { FileWorkbench } from './FileStudio'
import KitActions from './KitActions'
import KitTabStrip, { type KitStripTab } from './KitTabStrip'
import KitForm from './KitForm'
import TabErrorBoundary from '../settings/TabErrorBoundary'
import { FILE_CATEGORIES, type Activity, type Anchor, type FileCategory, type KitDetail, type KitFile, type KitRole, type Suggestion } from './types'
import { dialogs } from '@/lib/dialogs'

type Dialog = { type: 'deck' | 'video' | 'image'; seed?: Record<string, unknown> } | null

function ProgressRing({ value }: { value: number }) {
  const r = 26, c = 2 * Math.PI * r
  return (
    <div className="relative grid size-16 shrink-0 place-items-center" role="img" aria-label={`${value}%`}>
      <svg viewBox="0 0 64 64" className="-rotate-90 absolute inset-0"><circle cx="32" cy="32" r={r} fill="none" stroke="rgba(255,255,255,.18)" strokeWidth="6" /><circle cx="32" cy="32" r={r} fill="none" stroke="#C9B98D" strokeWidth="6" strokeLinecap="round" strokeDasharray={c} strokeDashoffset={c * (1 - value / 100)} style={{ transition: 'stroke-dashoffset .8s ease' }} /></svg>
      <span className="text-sm font-bold text-white">{fmt.percent(value)}</span>
    </div>
  )
}

const SECTIONS = ['overview', 'files', 'review', 'activity'] as const
type Section = (typeof SECTIONS)[number]
type Workspace = { open: string[]; active: string }
const fileTab = (id: string) => `f:${id}`
const isFileTab = (id: string) => id.startsWith('f:')
const readWorkspace = (kitId: string): Workspace => {
  try {
    const raw = JSON.parse(localStorage.getItem(`tedc.kit.tabs.${kitId}`) ?? 'null') as Partial<Workspace> | null
    return { open: Array.isArray(raw?.open) ? raw.open.filter((x) => typeof x === 'string') : [], active: typeof raw?.active === 'string' ? raw.active : 'overview' }
  } catch { return { open: [], active: 'overview' } }
}

/** One kit. Several can be open at once (see KitsWorkbench): only the pane on screen reads or writes the address bar and listens to the keyboard. */
export default function KitWorkspace({ kitId: kitProp, active: paneActive = true }: { kitId?: string; active?: boolean } = {}) {
  const { kitId: routeKit = '' } = useParams()
  const kitId = kitProp ?? routeKit
  const { t, i18n } = useTranslation()
  const navigate = useNavigate()
  const kitQuery = useGet<{ data: KitDetail }>(`/admin/kits/${kitId}`)
  const kit = kitQuery.data?.data
  const [params, setParams] = useSearchParams()
  const [ws, setWs] = useState<Workspace>(() => {
    const saved = readWorkspace(kitId)
    const wanted = paneActive ? new URLSearchParams(window.location.search).get('tab') : null
    if (wanted && (SECTIONS as readonly string[]).includes(wanted)) return { ...saved, active: wanted }
    if (wanted && isFileTab(wanted)) return { open: saved.open.includes(wanted) ? saved.open : [...saved.open, wanted], active: wanted }
    return saved
  })
  const [visited, setVisited] = useState<Set<string>>(() => new Set(['overview']))
  const [reloads, setReloads] = useState<Record<string, number>>({})
  const [focus, setFocus] = useState(false)
  const [menu, setMenu] = useState<{ id: string; x: number; y: number } | null>(null)
  const [editing, setEditing] = useState(false)
  const [dialog, setDialog] = useState<Dialog>(null)
  const wsRef = useRef(ws)
  wsRef.current = ws
  const asked = useRef<string | null>(null)
  const shell = useRef<HTMLDivElement>(null)
  const setParamsRef = useRef(setParams)
  setParamsRef.current = setParams
  const Back = i18n.dir() === 'rtl' ? ArrowRight : ArrowLeft
  const rtl = i18n.dir() === 'rtl'

  const refresh = useCallback(() => kitQuery.refetch(), [kitQuery])
  const fileById = useMemo(() => new Map((kit?.files ?? []).map((f) => [f.id, f])), [kit?.files])
  // Only files that still exist keep a tab (a deleted file silently drops out).
  const openFiles = useMemo(() => ws.open.filter((id) => !kit || fileById.has(id.slice(2))), [ws.open, fileById, kit])
  const activeId = isFileTab(ws.active) && kit && !fileById.has(ws.active.slice(2)) ? 'files' : ws.active
  const [fileOverride, setFileOverride] = useState<Record<string, KitFile>>({})

  const openTab = useCallback((id: string, comment?: string) => {
    // A comment deep link goes through the URL so the editor that opens can read (and clear) it.
    if (comment) { setParams({ tab: id, comment }, { replace: true }); return }
    setWs((cur) => ({ open: isFileTab(id) && !cur.open.includes(id) ? [...cur.open, id] : cur.open, active: id }))
  }, [setParams])
  const openFile = useCallback((fileId: string, comment?: string) => openTab(fileTab(fileId), comment), [openTab])

  const closeTabs = useCallback((ids: string[]) => {
    const targets = ids.filter((id) => isFileTab(id) && wsRef.current.open.includes(id))
    if (!targets.length) return
    setWs((cur) => {
      const open = cur.open.filter((id) => !targets.includes(id))
      let active = cur.active
      if (targets.includes(cur.active)) {
        const at = cur.open.indexOf(cur.active)
        active = cur.open.slice(at + 1).find((id) => open.includes(id)) ?? [...cur.open.slice(0, at)].reverse().find((id) => open.includes(id)) ?? 'files'
      }
      return { open, active }
    })
  }, [])

  const reorder = useCallback((from: string, to: string) => {
    setWs((cur) => {
      const open = cur.open.filter((id) => id !== from)
      const at = open.indexOf(to)
      open.splice(cur.open.indexOf(from) < cur.open.indexOf(to) ? at + 1 : at, 0, from)
      return { ...cur, open }
    })
  }, [])

  // Persist the tabs of this kit and mirror the active tab in the URL (deep links + reload).
  useEffect(() => {
    try { localStorage.setItem(`tedc.kit.tabs.${kitId}`, JSON.stringify(ws)) } catch { /* storage unavailable */ }
    setVisited((cur) => (cur.has(ws.active) ? cur : new Set(cur).add(ws.active)))
    if (isFileTab(ws.active) && window.innerWidth < 1024) shell.current?.scrollIntoView({ block: 'start', behavior: 'smooth' })
    if (!paneActive) return
    const n = new URLSearchParams(window.location.search)
    if (ws.active === 'overview') n.delete('tab'); else n.set('tab', ws.active)
    if (n.toString() !== window.location.search.replace(/^\?/, '')) setParamsRef.current(n, { replace: true })
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [ws, kitId, paneActive])

  // A link such as /admin/kits/:id?tab=f:<file> opens that tab even when the workspace is already mounted.
  const wanted = params.get('tab')
  const pendingComment = params.get('comment')
  const lastWanted = useRef<string | null>(null)
  const refreshRef = useRef(refresh)
  refreshRef.current = refresh
  useEffect(() => {
    if (!paneActive) return
    if (!wanted) { lastWanted.current = null; return }
    if (wanted === wsRef.current.active) { lastWanted.current = wanted; return }
    if (lastWanted.current === wanted && !pendingComment && !(isFileTab(wanted) && kit && asked.current === wanted)) return
    if ((SECTIONS as readonly string[]).includes(wanted)) { lastWanted.current = wanted; setWs((cur) => ({ ...cur, active: wanted })) }
    else if (isFileTab(wanted)) {
      if (!kit) return
      if (fileById.has(wanted.slice(2))) { lastWanted.current = wanted; openTab(wanted) }
      else if (asked.current !== wanted) { asked.current = wanted; void refreshRef.current() } // a file created a moment ago
    }
  }, [wanted, pendingComment, kit, fileById, openTab, paneActive])

  // Keyboard: Alt+W closes the file tab, Alt+←/→ switches.
  useEffect(() => {
    if (!paneActive) return
    const onKey = (e: KeyboardEvent) => {
      if (!e.altKey || e.ctrlKey || e.metaKey) return
      const cur = wsRef.current
      if (e.code === 'KeyW' && isFileTab(cur.active)) { e.preventDefault(); closeTabs([cur.active]) }
      if (e.key === 'ArrowLeft' || e.key === 'ArrowRight') {
        e.preventDefault()
        const order = [...SECTIONS, ...cur.open]
        const step = (e.key === 'ArrowRight' ? 1 : -1) * (rtl ? -1 : 1)
        setWs({ ...cur, active: order[(order.indexOf(cur.active) + step + order.length) % order.length] })
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [closeTabs, rtl, paneActive])

  useEffect(() => {
    if (!menu) return
    const close = () => setMenu(null)
    const onKey = (e: KeyboardEvent) => e.key === 'Escape' && close()
    window.addEventListener('click', close)
    window.addEventListener('scroll', close, true)
    window.addEventListener('keydown', onKey)
    return () => { window.removeEventListener('click', close); window.removeEventListener('scroll', close, true); window.removeEventListener('keydown', onKey) }
  }, [menu])

  if (kitQuery.isLoading) return <Spinner />
  if (!kit) return <ErrorState message={kitQuery.error ? errorMessage(kitQuery.error) : t('kits.file.notFound')} onRetry={refresh} />

  const people = [{ user_id: kit.owner_id, name: kit.owner?.name ?? '', role: 'developer' as KitRole }, ...(kit.members ?? []).map((m) => ({ ...m, name: m.name ?? '' }))]
  const overdue = kit.due_at && new Date(kit.due_at) < new Date() && !['approved', 'published', 'archived'].includes(kit.status)

  const sectionIcon: Record<Section, KitStripTab['icon']> = { overview: LayoutDashboard, files: Files2, review: MessageSquare, activity: History }
  const strip: KitStripTab[] = [
    ...SECTIONS.map((id) => ({ id, pinned: true, icon: sectionIcon[id], label: t(`kits.workspace.tabs.${id}`), badge: id === 'review' ? kit.open_comments || undefined : undefined })),
    ...openFiles.map((id) => {
      const f = (fileOverride[id.slice(2)] ?? fileById.get(id.slice(2)))!
      return { id, label: f.name, icon: kindIcon[f.kind], tint: undefined as string | undefined, badge: f.open_comments || undefined }
    }),
  ]
  const menuIndex = menu ? openFiles.indexOf(menu.id) : -1
  const items = menu ? [
    { label: t('kits.tabs.close'), run: () => closeTabs([menu.id]) },
    { label: t('kits.tabs.closeOthers'), run: () => closeTabs(openFiles.filter((id) => id !== menu.id)), disabled: openFiles.length < 2 },
    { label: t('kits.tabs.closeRight'), run: () => closeTabs(openFiles.slice(menuIndex + 1)), disabled: menuIndex === openFiles.length - 1 },
    { label: t('kits.tabs.moveStart'), run: () => setWs((cur) => ({ ...cur, open: [menu.id, ...cur.open.filter((id) => id !== menu.id)] })), disabled: menuIndex === 0 },
    { label: t('kits.tabs.reload'), run: () => setReloads((cur) => ({ ...cur, [menu.id]: (cur[menu.id] ?? 0) + 1 })), icon: RefreshCcw },
    { label: t('kits.tabs.closeAll'), run: () => closeTabs(openFiles), danger: true },
  ] : []

  const panelHeight = focus ? 'h-[calc(100vh-6.5rem)]' : 'h-[calc(100vh-11rem)]'

  return (
    <>
      {!focus && <Link to="/admin/kits" className="mb-4 inline-flex items-center gap-1.5 text-sm font-semibold text-slate-500 hover:text-navy-900"><Back className="size-4" />{t('kits.workspace.back')}</Link>}

      {!focus && (
      <section className="relative mb-6 overflow-hidden rounded-3xl bg-gradient-to-br from-navy-950 via-navy-900 to-navy-800 p-6 text-white shadow-glass sm:p-8">
        <div className="pointer-events-none absolute -end-20 -top-24 size-72 rounded-full bg-gold-500/15 blur-3xl" />
        <div className="relative flex flex-wrap items-start justify-between gap-6">
          <div className="min-w-0 max-w-3xl">
            <div className="flex flex-wrap items-center gap-2"><span className="rounded-md bg-white/10 px-2 py-0.5 font-mono text-xs font-bold text-gold-300" dir="ltr">{kit.code} · v{kit.version}</span><KitStatusBadge status={kit.status} className="!bg-white !text-navy-900" /><DeliveryBadge delivery={kit.delivery} onDark />
              {kit.status === 'in_review' && <Badge color="gold">{t('kits.home.round', { n: kit.review_round })}</Badge>}
              {overdue && <span className="inline-flex items-center gap-1 rounded-full bg-red-500/90 px-2.5 py-0.5 text-xs font-bold"><AlertTriangle className="size-3" />{t('kits.workspace.overdue')}</span>}</div>
            <h1 className="mt-3 text-2xl font-bold leading-tight sm:text-3xl" dir="auto">{kit.title}</h1>
            {kit.program && <p className="mt-1 text-sm text-white/70" dir="auto">{kit.program.code} · {kit.program.title}</p>}
            <div className="mt-4 flex flex-wrap items-center gap-x-5 gap-y-2 text-sm text-white/80">
              {kit.audience && <span dir="auto">{kit.audience}</span>}
              {kit.duration_hours > 0 && <span>{fmt.number(kit.duration_hours, 1)} {t('kits.common.hours')}</span>}
              {kit.due_at && <span className="inline-flex items-center gap-1.5"><CalendarClock className="size-4" />{fmt.date(kit.due_at, { day: 'numeric', month: 'long', year: 'numeric' })}</span>}
            </div>
            <div className="mt-5 flex items-center gap-3">
              <div className="flex -space-x-2 rtl:space-x-reverse">{people.map((p) => <span key={p.user_id} title={`${p.name} · ${t(`kits.roles.${p.role}`)}`} className={clsx('rounded-full ring-2', p.role === 'qa' ? 'ring-gold-400' : 'ring-navy-900')}><Avatar name={p.name} size={34} /></span>)}</div>
              <div className="text-xs text-white/60">{people.map((p) => p.name).slice(0, 3).join('، ')}{people.length > 3 && ` +${people.length - 3}`}</div>
            </div>
          </div>
          <div className="flex flex-col items-end gap-4">
            <div className="flex items-center gap-3"><div className="text-end text-xs text-white/70"><div className="font-bold text-white">{t('kits.workspace.readiness')}</div><div>{t('kits.workspace.readinessHint')}</div></div><ProgressRing value={kit.completeness.percent} /></div>
            <div className="flex flex-wrap justify-end gap-2">
              <KitActions kit={kit} onDone={refresh} onDark />
              {kit.can?.manage && <Button variant="light" icon={<Pencil className="size-4" />} onClick={() => setEditing(true)}>{t('kits.common.edit')}</Button>}
            </div>
          </div>
        </div>
      </section>
      )}

      {!focus && <NextUp kit={kit} onDialog={setDialog} onUpload={() => openTab('files')} />}

      <div ref={shell} className="scroll-mt-20 overflow-hidden rounded-2xl border border-navy-100 bg-white shadow-glass">
        <KitTabStrip tabs={strip} active={activeId} focus={focus} onSelect={(id) => setWs((cur) => ({ ...cur, active: id }))} onClose={(id) => closeTabs([id])} onReorder={reorder}
          onContextMenu={(id, x, y) => setMenu({ id, x, y })} onToggleFocus={() => setFocus((v) => !v)} />

        <div className="bg-ivory">
          {SECTIONS.filter((id) => visited.has(id) || activeId === id).map((id) => (
            <div key={id} hidden={activeId !== id} role="tabpanel" aria-label={t(`kits.workspace.tabs.${id}`)} className="p-4 sm:p-6">
              {id === 'overview' && <Overview kit={kit} onGo={(x) => openTab(x)} />}
              {id === 'files' && <Files kit={kit} onChanged={refresh} onDialog={setDialog} onOpen={openFile} />}
              {id === 'review' && <ReviewTab kit={kit} onOpenFile={openFile} />}
              {id === 'activity' && <ActivityTab kitId={kit.id} />}
            </div>
          ))}
          {openFiles.map((id) => {
            const fileId = id.slice(2)
            const file = fileOverride[fileId] ?? fileById.get(fileId)
            if (!file) return null
            const active = activeId === id
            return (
              <div key={`${id}-${reloads[id] ?? 0}`} hidden={!active} role="tabpanel" aria-label={file.name} className={clsx('relative', panelHeight)}>
                <TabErrorBoundary title={t('kits.tabs.failed')} hint={t('kits.tabs.failedHint')} retry={t('kits.common.retry')}>
                  <FileWorkbench kit={kit} file={file} active={active && paneActive} onFile={(f) => { setFileOverride((cur) => ({ ...cur, [f.id]: f })); void refresh() }} />
                </TabErrorBoundary>
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

      {editing && <KitForm kit={kit} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); void refresh() }} />}
      {dialog?.type === 'deck' && <GenerateDeckDialog kit={kit} seed={dialog.seed} onClose={() => setDialog(null)} />}
      {dialog?.type === 'video' && <VideoStudio kit={kit} seed={dialog.seed} onClose={() => { setDialog(null); void refresh() }} />}
      {dialog?.type === 'image' && <ImagePickerDialog kitId={kit.id} initial="ai" canGenerate={!!kit.can?.generate} onPick={() => { setDialog(null); void refresh(); navigate(`/admin/kits/${kit.id}`) }} onClose={() => setDialog(null)} />}
    </>
  )
}

/** Smart next steps, pinned above the tabs so they stay in view on every tab of the kit. */
function NextUp({ kit, onDialog, onUpload }: { kit: KitDetail; onDialog: (d: Dialog) => void; onUpload: () => void }) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const [open, setOpen] = useState(() => { try { return localStorage.getItem('tedc.kit.next.collapsed') !== '1' } catch { return true } })
  const suggestions = useGet<{ data: Suggestion[] }>(kit.can?.edit ? `/admin/kits/${kit.id}/suggestions` : null, { v: `${kit.files.length}-${kit.completeness.percent}-${kit.status}` })
  const list = suggestions.data?.data ?? []
  if (!kit.can?.edit || !list.length) return null

  const toggle = () => setOpen((v) => { try { localStorage.setItem('tedc.kit.next.collapsed', v ? '1' : '0') } catch { /* storage unavailable */ } return !v })
  const act = (s: Suggestion) => {
    if (s.action === 'generate_deck') onDialog({ type: 'deck', seed: s.params })
    else if (s.action === 'generate_video') onDialog({ type: 'video', seed: s.params })
    else if (s.action === 'generate_image') onDialog({ type: 'image' })
    else onUpload()
  }

  return (
    <section aria-label={t('kits.workspace.next')} className="mb-5 overflow-hidden rounded-2xl border border-gold-300/70 bg-gradient-to-l from-gold-100/60 via-white to-white shadow-sm">
      <button type="button" onClick={toggle} aria-expanded={open} className="flex w-full items-center gap-2.5 px-4 py-3 text-start">
        <span className="grid size-8 shrink-0 place-items-center rounded-xl bg-gradient-to-br from-gold-400 to-gold-600 text-navy-950"><Sparkles className="size-4" /></span>
        <span className="min-w-0 flex-1"><span className="block font-bold text-navy-900">{t('kits.workspace.next')}</span>{open && <span className="block truncate text-xs text-slate-500">{t('kits.workspace.nextHint')}</span>}</span>
        <span className="rounded-full bg-navy-900 px-2 py-0.5 text-xs font-bold text-gold-300">{list.length}</span>
        <ChevronDown className={clsx('size-4 text-slate-400 transition', open && 'rotate-180')} />
      </button>
      {open && (
        <div className="flex gap-3 overflow-x-auto px-4 pb-4 [scrollbar-width:thin]">
          {list.map((s) => {
            const Icon = s.action === 'generate_deck' ? Presentation : s.action === 'generate_video' ? Clapperboard : s.action === 'generate_image' ? ImageIcon : FileUp
            const ai = s.action.startsWith('generate')
            return (
              <button key={s.key} type="button" disabled={ai && !kit.can?.generate} onClick={() => act(s)} className="group flex w-72 shrink-0 items-start gap-3 rounded-2xl border border-navy-100 bg-white p-3.5 text-start transition hover:-translate-y-0.5 hover:border-gold-400 hover:shadow-glass disabled:opacity-50">
                <span className={clsx('grid size-10 shrink-0 place-items-center rounded-xl', ai ? 'bg-gradient-to-br from-gold-400 to-gold-600 text-navy-950' : 'bg-navy-900 text-gold-300')}><Icon className="size-5" /></span>
                <span className="min-w-0"><span className="flex items-center gap-1.5 font-bold text-navy-900">{en ? s.title_en : s.title_ar}{ai && <Badge color="gold">AI</Badge>}</span><span className="mt-0.5 line-clamp-2 block text-xs leading-relaxed text-slate-500">{en ? s.hint_en : s.hint_ar}</span></span>
              </button>
            )
          })}
        </div>
      )}
    </section>
  )
}

function Overview({ kit, onGo }: { kit: KitDetail; onGo: (t: Section) => void }) {
  const { t } = useTranslation()
  const activity = useGet<{ data: Activity[] }>(`/admin/kits/${kit.id}/activity`, { per_page: 6 })
  const review = kit.review

  return (
    <div className="grid gap-6 xl:grid-cols-[minmax(0,1.6fr)_minmax(0,1fr)]">
      <div className="space-y-6">
        <Card>
          <div className="mb-4 flex items-center justify-between"><h3 className="text-lg font-bold text-navy-900">{t('kits.workspace.checklist')}</h3><span className="text-sm font-bold text-gold-700">{fmt.percent(kit.completeness.percent)}</span></div>
          <Progress value={kit.completeness.percent} className="mb-5" />
          <ul className="grid gap-2 sm:grid-cols-2">
            {kit.completeness.items.map((i) => (
              <li key={i.key} className={clsx('flex items-start gap-3 rounded-xl border p-3', i.done ? 'border-emerald-200 bg-emerald-50/50' : 'border-navy-100 bg-white')}>
                <span className={clsx('mt-0.5 grid size-5 shrink-0 place-items-center rounded-full', i.done ? 'bg-emerald-500 text-white' : 'border-2 border-navy-200')}>{i.done && <Check className="size-3.5" />}</span>
                <span className="min-w-0"><span className={clsx('block text-sm font-semibold', i.done ? 'text-emerald-800' : 'text-navy-900')}>{t(`kits.checklist.${i.key}`)}</span>{!i.done && <span className="block text-xs text-slate-500">{t(`kits.checklist.${i.key}Hint`)}</span>}</span>
              </li>
            ))}
          </ul>
        </Card>

        <Card>
          <h3 className="mb-3 text-lg font-bold text-navy-900">{t('kits.workspace.objectives')}</h3>
          {kit.objectives.length === 0 ? <p className="text-sm text-slate-400">{t('kits.workspace.noObjectives')}</p> : (
            <ol className="space-y-2">{kit.objectives.map((o, i) => <li key={i} className="flex gap-3 text-sm text-navy-900"><span className="mt-0.5 grid size-6 shrink-0 place-items-center rounded-full bg-navy-900 text-xs font-bold text-gold-300">{i + 1}</span><span dir="auto" className="leading-relaxed">{o}</span></li>)}</ol>
          )}
        </Card>
      </div>

      <div className="space-y-6">
        {review && (
          <Card>
            <div className="mb-3 flex items-center justify-between"><h3 className="text-lg font-bold text-navy-900">{t('kits.workspace.reviewStatus')}</h3><Badge color={review.status === 'approved' ? 'green' : review.status === 'changes_requested' ? 'red' : 'amber'}>{t(`kits.workspace.reviewStates.${review.status}`)}</Badge></div>
            <dl className="space-y-2 text-sm">
              <div className="flex justify-between gap-3"><dt className="text-slate-500">{t('kits.home.round', { n: review.round })}</dt><dd className="text-navy-900">{review.submitted_by}</dd></div>
              {review.decided_by && <div className="flex justify-between gap-3"><dt className="text-slate-500">{t('kits.workspace.decidedBy')}</dt><dd className="font-semibold text-navy-900">{review.decided_by}</dd></div>}
              {review.note && <p className="rounded-xl bg-ivory p-3 text-xs leading-relaxed text-slate-600" dir="auto">{review.note}</p>}
              {review.summary && <div className="grid grid-cols-3 gap-2 pt-1 text-center">{(['open', 'addressed', 'resolved'] as const).map((k) => <div key={k} className="rounded-xl bg-navy-100/50 py-2"><div className="text-lg font-bold text-navy-900">{review.summary?.[k] ?? 0}</div><div className="text-[11px] text-slate-500">{t(`kits.comments.status.${k}`)}</div></div>)}</div>}
            </dl>
            <Button className="mt-4 w-full" size="sm" variant="outline" onClick={() => onGo('review')}>{t('kits.workspace.openReview')}</Button>
          </Card>
        )}
        <Card>
          <h3 className="mb-3 text-lg font-bold text-navy-900">{t('kits.workspace.team')}</h3>
          <ul className="space-y-2.5">
            {[{ user_id: kit.owner_id, name: kit.owner?.name ?? '', role: 'developer' as KitRole, email: null }, ...(kit.members ?? [])].map((m) => (
              <li key={m.user_id} className="flex items-center gap-3"><Avatar name={m.name ?? ''} size={36} /><div className="min-w-0 flex-1"><div className="truncate text-sm font-semibold text-navy-900">{m.name}</div><div className="truncate text-xs text-slate-400">{m.email}</div></div><span className={clsx('rounded-full px-2 py-0.5 text-[11px] font-bold', m.role === 'qa' ? 'bg-gold-100 text-gold-700' : 'bg-navy-100 text-navy-800')}>{t(`kits.roles.${m.role}`)}</span></li>
            ))}
          </ul>
        </Card>
        <Card>
          <div className="mb-3 flex items-center justify-between"><h3 className="text-lg font-bold text-navy-900">{t('kits.workspace.tabs.activity')}</h3><button type="button" className="text-xs font-semibold text-link" onClick={() => onGo('activity')}>{t('kits.workspace.viewAll')}</button></div>
          <ActivityList items={activity.data?.data ?? []} loading={activity.isLoading} />
        </Card>
      </div>
    </div>
  )
}

function ActivityList({ items, loading }: { items: Activity[]; loading?: boolean }) {
  const { t, i18n } = useTranslation()
  if (loading) return <Spinner />
  if (!items.length) return <Empty text={t('kits.workspace.noActivity')} />
  return (
    <ul className="relative space-y-3 border-s border-navy-100 ps-4">
      {items.map((a) => (
        <li key={a.id} className="relative"><span className="absolute -start-[21px] top-1.5 size-2.5 rounded-full bg-gold-500 ring-4 ring-white" />
          <p className="text-sm text-navy-900"><b>{a.user?.name ?? '—'}</b> <span className="text-slate-600">{t(`kits.activity.${a.action}`, { defaultValue: a.action })}</span>{typeof a.meta?.name === 'string' && <span className="font-semibold" dir="auto"> «{a.meta.name}»</span>}{typeof a.meta?.round === 'number' && <span className="text-slate-400"> · {t('kits.home.round', { n: a.meta.round })}</span>}</p>
          {typeof a.meta?.note === 'string' && a.meta.note && <p className="mt-0.5 text-xs text-slate-500" dir="auto">{a.meta.note}</p>}
          <p className="text-[11px] text-slate-400">{relativeTime(a.created_at, i18n.language)}</p>
        </li>
      ))}
    </ul>
  )
}

function ActivityTab({ kitId }: { kitId: string }) {
  const [page, setPage] = useState(1)
  const { data, isLoading } = useGet<{ data: Activity[]; meta: { last_page: number } }>(`/admin/kits/${kitId}/activity`, { page, per_page: 30 })
  const { t } = useTranslation()
  return (
    <Card>
      <ActivityList items={data?.data ?? []} loading={isLoading} />
      {(data?.meta.last_page ?? 1) > 1 && <div className="mt-5 flex justify-center gap-2"><Button size="sm" variant="outline" disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('kits.common.previous')}</Button><Button size="sm" variant="outline" disabled={page >= (data?.meta.last_page ?? 1)} onClick={() => setPage(page + 1)}>{t('kits.common.next')}</Button></div>}
    </Card>
  )
}

/** All comments of the kit across files, plus the review rounds. */
function ReviewTab({ kit, onOpenFile }: { kit: KitDetail; onOpenFile: (fileId: string, comment?: string) => void }) {
  const { t } = useTranslation()
  const comments = useKitComments(kit.id, null)
  const rounds = useGet<{ data: NonNullable<KitDetail['review']>[] }>(`/admin/kits/${kit.id}/reviews`)
  const all = useMemo(() => comments.data?.data ?? [], [comments.data])
  const label = useCallback((a: Anchor | null) => (a?.page ? t('kits.viewer.pageN', { n: a.page }) : a?.slide_index != null ? t('kits.editor.slideN', { n: a.slide_index + 1 }) : a?.at != null ? `${Math.floor(a.at / 60)}:${String(Math.floor(a.at % 60)).padStart(2, '0')}` : null), [t])

  return (
    <div className="grid gap-6 xl:grid-cols-[minmax(0,1.5fr)_minmax(0,1fr)]">
      <Card className="min-h-[32rem]">
        <h3 className="mb-4 text-lg font-bold text-navy-900">{t('kits.workspace.allComments')}</h3>
        <div className="h-[36rem]">
          <CommentsPanel kit={kit} comments={all} loading={comments.isLoading} anchorLabel={label} onJump={(c) => c.file_id && onOpenFile(c.file_id, c.id)} />
        </div>
      </Card>
      <Card>
        <h3 className="mb-4 text-lg font-bold text-navy-900">{t('kits.workspace.rounds')}</h3>
        {rounds.isLoading ? <Spinner /> : !rounds.data?.data.length ? <Empty text={t('kits.workspace.noRounds')} /> : (
          <ol className="space-y-3">
            {rounds.data.data.map((r) => (
              <li key={r.round} className="rounded-2xl border border-navy-100 p-3.5">
                <div className="flex items-center justify-between"><span className="font-bold text-navy-900">{t('kits.home.round', { n: r.round })}</span><Badge color={r.status === 'approved' ? 'green' : r.status === 'changes_requested' ? 'red' : 'amber'}>{t(`kits.workspace.reviewStates.${r.status}`)}</Badge></div>
                <p className="mt-1 text-xs text-slate-500">{r.submitted_by} → {r.decided_by ?? '…'}</p>
                {r.note && <p className="mt-2 rounded-lg bg-ivory p-2.5 text-xs leading-relaxed text-slate-600" dir="auto">{r.note}</p>}
                {r.summary && <div className="mt-2 flex gap-3 text-[11px] font-semibold text-slate-500"><span>{r.summary.open ?? 0} {t('kits.comments.status.open')}</span><span>{r.summary.addressed ?? 0} {t('kits.comments.status.addressed')}</span><span>{r.summary.resolved ?? 0} {t('kits.comments.status.resolved')}</span></div>}
              </li>
            ))}
          </ol>
        )}
      </Card>
    </div>
  )
}

/** Every file of the kit: drag files in, or create / generate new ones. */
function Files({ kit, onChanged, onDialog, onOpen }: { kit: KitDetail; onChanged: () => unknown; onDialog: (d: Dialog) => void; onOpen: (fileId: string) => void }) {
  const { t, i18n } = useTranslation()
  const input = useRef<HTMLInputElement>(null)
  const [category, setCategory] = useState<FileCategory | ''>('')
  const [uploading, setUploading] = useState<{ name: string; done: boolean }[]>([])
  const [over, setOver] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const canManage = !!kit.can?.manage
  const shown = kit.files.filter((f) => !category || f.category === category)

  const upload = async (files: FileList | File[]) => {
    const list = Array.from(files)
    if (!list.length) return
    setError(null)
    setUploading(list.map((f) => ({ name: f.name, done: false })))
    for (const [i, file] of list.entries()) {
      try {
        const body = new FormData()
        body.append('file', file)
        if (category) body.append('category', category)
        await api.post(`/admin/kits/${kit.id}/files`, body)
      } catch (e) {
        setError(`${file.name}: ${errorMessage(e)}`)
      }
      setUploading((cur) => cur.map((u, n) => (n === i ? { ...u, done: true } : u)))
    }
    setUploading([])
    onChanged()
  }
  const create = async () => {
    const name = await dialogs.prompt(t('kits.files.newDeckName'), t('kits.files.newDeckDefault'))
    if (!name) return
    try {
      const res = await api.post<{ data: KitFile }>(`/admin/kits/${kit.id}/files/create`, { name, language: i18n.language === 'en' ? 'en' : 'ar' })
      await onChanged()
      onOpen(res.data.data.id)
    } catch (e) { setError(errorMessage(e)) }
  }
  const remove = async (f: KitFile) => {
    if (!await dialogs.confirm(t('kits.files.confirmDelete', { name: f.name }))) return
    try { await api.delete(`/admin/kits/${kit.id}/files/${f.id}`); onChanged() } catch (e) { setError(errorMessage(e)) }
  }
  const patch = async (f: KitFile, body: Partial<Pick<KitFile, 'name' | 'category'>>) => {
    try { await api.put(`/admin/kits/${kit.id}/files/${f.id}`, body); onChanged() } catch (e) { setError(errorMessage(e)) }
  }
  const download = async (f: KitFile) => {
    try { const r = await api.get<{ data: { url: string } }>(`/admin/kits/${kit.id}/files/${f.id}/download`); window.open(r.data.data.url, '_blank', 'noopener') } catch (e) { setError(errorMessage(e)) }
  }

  return (
    <div className="space-y-5" onDragOver={(e) => { if (canManage) { e.preventDefault(); setOver(true) } }} onDragLeave={(e) => { if (e.currentTarget === e.target) setOver(false) }} onDrop={(e) => { e.preventDefault(); setOver(false); if (canManage) void upload(e.dataTransfer.files) }}>
      {canManage || kit.can?.generate ? (
        <div className="flex flex-wrap items-center gap-2">
          {canManage && <Button variant="primary" icon={<UploadCloud className="size-4" />} onClick={() => input.current?.click()}>{t('kits.files.upload')}</Button>}
          {canManage && <Button variant="outline" icon={<FilePlus2 className="size-4" />} onClick={create}>{t('kits.files.newDeck')}</Button>}
          {kit.can?.generate && <Button variant="gold" icon={<Sparkles className="size-4" />} onClick={() => onDialog({ type: 'deck' })}>{t('kits.files.generateDeck')}</Button>}
          {kit.can?.generate && <Button variant="outline" icon={<Clapperboard className="size-4" />} onClick={() => onDialog({ type: 'video' })}>{t('kits.files.generateVideo')}</Button>}
          {kit.can?.generate && <Button variant="outline" icon={<ImageIcon className="size-4" />} onClick={() => onDialog({ type: 'image' })}>{t('kits.files.generateImage')}</Button>}
          <input ref={input} type="file" multiple className="hidden" accept=".pptx,.ppt,.pdf,.docx,.doc,.odt,.txt,.png,.jpg,.jpeg,.gif,.webp,.svg,.mp4,.webm,.mov,.xlsx,.zip" onChange={(e) => { if (e.target.files) void upload(e.target.files); e.target.value = '' }} />
        </div>
      ) : null}

      <div className="flex flex-wrap gap-1.5">
        <button type="button" aria-pressed={category === ''} onClick={() => setCategory('')} className={clsx('rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset', category === '' ? 'bg-navy-900 text-white ring-navy-900' : 'bg-white text-slate-500 ring-navy-100')}>{t('kits.common.all')} {kit.files.length}</button>
        {FILE_CATEGORIES.filter((c) => kit.files.some((f) => f.category === c)).map((c) => <button key={c} type="button" aria-pressed={category === c} onClick={() => setCategory(c)} className={clsx('rounded-full px-3 py-1 text-xs font-semibold ring-1 ring-inset', category === c ? 'bg-navy-900 text-white ring-navy-900' : 'bg-white text-slate-500 ring-navy-100')}>{t(`kits.categories.${c}`)} {kit.files.filter((f) => f.category === c).length}</button>)}
      </div>

      {uploading.length > 0 && (
        <div className="space-y-1.5 rounded-2xl border border-gold-300 bg-gold-100/40 p-3">{uploading.map((u) => <div key={u.name} className="flex items-center gap-2 text-sm">{u.done ? <CheckCircle2 className="size-4 text-emerald-600" /> : <Loader2 className="size-4 animate-spin text-gold-700" />}<span className="truncate" dir="auto">{u.name}</span></div>)}</div>
      )}
      {error && <div className="rounded-xl bg-red-50 p-3 text-sm text-danger">{error}</div>}

      <div className={clsx('relative rounded-3xl transition', over && 'ring-2 ring-gold-500 ring-offset-4')}>
        {over && <div className="pointer-events-none absolute inset-0 z-10 grid place-items-center rounded-3xl bg-gold-100/80 text-lg font-bold text-gold-700 backdrop-blur-sm"><UploadCloud className="me-2 size-7" />{t('kits.files.dropHere')}</div>}
        {shown.length === 0 ? (
          <button type="button" disabled={!canManage} onClick={() => input.current?.click()} className="grid w-full place-items-center gap-2 rounded-3xl border-2 border-dashed border-navy-200 bg-white/60 px-6 py-16 text-slate-500 transition hover:border-gold-400 disabled:cursor-default">
            <UploadCloud className="size-10 text-gold-600" /><span className="text-lg font-bold text-navy-900">{t('kits.files.emptyTitle')}</span><span className="max-w-md text-sm">{t('kits.files.emptyText')}</span>
          </button>
        ) : (
          <div className="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            {shown.map((f) => {
              const Icon = kindIcon[f.kind]
              const CatIcon = categoryIcon[f.category]
              return (
                <article key={f.id} className="group flex flex-col overflow-hidden rounded-2xl border border-navy-100 bg-white shadow-sm transition hover:-translate-y-0.5 hover:border-gold-400 hover:shadow-glass">
                  <a href={`/admin/kits/${kit.id}?tab=f:${f.id}`} onClick={(e) => { if (e.metaKey || e.ctrlKey || e.shiftKey || e.button !== 0) return; e.preventDefault(); onOpen(f.id) }} className="flex items-start gap-3 p-4">
                    <span className={clsx('grid size-12 shrink-0 place-items-center rounded-xl', kindTint[f.kind])}><Icon className="size-6" /></span>
                    <div className="min-w-0 flex-1">
                      <h4 className="truncate font-bold text-navy-900 group-hover:text-link" dir="auto">{f.name}</h4>
                      <div className="mt-1 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-[11px] text-slate-500">
                        <span className="inline-flex items-center gap-1"><CatIcon className="size-3" />{t(`kits.categories.${f.category}`)}</span>
                        <span>{t(`kits.kinds.${f.kind}`)}</span>{f.slides_count != null && <span>{f.slides_count} {t('kits.editor.slidesShort')}</span>}{f.size > 0 && <span>{fmtSize(f.size)}</span>}<span>v{f.version}</span>
                      </div>
                    </div>
                    {(f.open_comments ?? 0) > 0 && <span className="inline-flex items-center gap-1 rounded-full bg-red-50 px-2 py-0.5 text-xs font-bold text-red-700"><MessageSquare className="size-3" />{f.open_comments}</span>}
                  </a>
                  <div className="mt-auto flex items-center gap-1 border-t border-navy-100 bg-ivory/50 px-3 py-2">
                    <span className="min-w-0 flex-1 truncate text-[11px] text-slate-400">{f.updated_by ?? f.uploaded_by} · {f.updated_at ? relativeTime(f.updated_at, i18n.language) : ''}</span>
                    {f.has_binary && <button type="button" onClick={() => download(f)} className="grid size-7 place-items-center rounded-lg text-slate-500 hover:bg-white hover:text-navy-900" aria-label={t('kits.file.download')}><Download className="size-4" /></button>}
                    {canManage && (
                      <>
                        <select value={f.category} onChange={(e) => patch(f, { category: e.target.value as FileCategory })} aria-label={t('kits.files.category')} className="w-24 rounded-lg border-0 bg-transparent py-1 text-[11px] font-semibold text-slate-500 hover:bg-white">{FILE_CATEGORIES.map((c) => <option key={c} value={c}>{t(`kits.categories.${c}`)}</option>)}</select>
                        <button type="button" onClick={async () => { const n = await dialogs.prompt(t('kits.files.rename'), f.name); if (n && n !== f.name) void patch(f, { name: n }) }} className="grid size-7 place-items-center rounded-lg text-slate-500 hover:bg-white hover:text-navy-900" aria-label={t('kits.files.rename')}><Pencil className="size-4" /></button>
                        <button type="button" onClick={() => remove(f)} className="grid size-7 place-items-center rounded-lg text-slate-400 hover:bg-white hover:text-danger" aria-label={t('kits.common.delete')}><Trash2 className="size-4" /></button>
                      </>
                    )}
                  </div>
                </article>
              )
            })}
          </div>
        )}
      </div>
    </div>
  )
}
