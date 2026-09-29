import clsx from 'clsx'
import { ChevronLeft, ChevronRight, LayoutGrid, Plus, X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState, type ComponentType, type DragEvent, type MouseEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { HOME } from './registry'

export type StripTab = { id: string; label: string; icon: ComponentType<{ className?: string }>; dirty: boolean }

type Props = {
  tabs: StripTab[]
  active: string
  onSelect: (id: string) => void
  onClose: (id: string) => void
  onReorder: (from: string, to: string) => void
  onContextMenu: (id: string, x: number, y: number) => void
  onAdd: () => void
}

/** Browser-style tab rail: pinned home, closable / draggable tabs, overflow arrows. */
export default function TabStrip({ tabs, active, onSelect, onClose, onReorder, onContextMenu, onAdd }: Props) {
  const { t, i18n } = useTranslation()
  const rtl = i18n.dir() === 'rtl'
  const scroller = useRef<HTMLDivElement>(null)
  const [overflow, setOverflow] = useState(false)
  const [dragging, setDragging] = useState<string | null>(null)
  const dragId = useRef<string | null>(null)
  const [over, setOver] = useState<string | null>(null)

  const measure = useCallback(() => {
    const el = scroller.current
    if (el) setOverflow(el.scrollWidth > el.clientWidth + 2)
  }, [])

  useEffect(() => {
    const el = scroller.current
    if (!el) return
    measure()
    const ro = new ResizeObserver(measure)
    ro.observe(el)
    return () => ro.disconnect()
  }, [measure, tabs.length])

  // Keep the active tab visible.
  useEffect(() => {
    scroller.current?.querySelector<HTMLElement>('[data-active="true"]')?.scrollIntoView({ block: 'nearest', inline: 'nearest', behavior: 'smooth' })
  }, [active, tabs.length])

  const scrollBy = (dir: 1 | -1) => scroller.current?.scrollBy({ left: dir * 260, behavior: 'smooth' })
  const StartIcon = rtl ? ChevronRight : ChevronLeft
  const EndIcon = rtl ? ChevronLeft : ChevronRight

  const onDragStart = (e: DragEvent, id: string) => {
    dragId.current = id
    setDragging(id)
    e.dataTransfer.effectAllowed = 'move'
    e.dataTransfer.setData('text/plain', id)
  }
  const onDrop = (e: DragEvent, id: string) => {
    e.preventDefault()
    if (dragId.current && dragId.current !== id) onReorder(dragId.current, id)
    dragId.current = null
    setDragging(null)
    setOver(null)
  }
  const onAux = (e: MouseEvent, id: string) => {
    if (e.button === 1) { e.preventDefault(); onClose(id) }
  }

  return (
    <div className="flex items-end gap-1 rounded-t-2xl bg-gradient-to-l from-navy-950 via-navy-900 to-navy-800 px-2 pt-2 shadow-inner" role="tablist" aria-label={t('mgmt.settings.title')}>
      <button
        type="button" role="tab" aria-selected={active === HOME} data-active={active === HOME} onClick={() => onSelect(HOME)}
        className={clsx('group relative flex shrink-0 items-center gap-2 rounded-t-xl px-4 py-2.5 text-sm font-bold transition', active === HOME ? 'bg-ivory text-navy-900 shadow-[0_-2px_0_0_var(--color-gold-500)_inset]' : 'text-white/70 hover:bg-white/10 hover:text-white')}
      >
        <LayoutGrid className="size-4" />
        <span className="hidden sm:inline">{t('mgmt.settings.home')}</span>
      </button>

      {overflow && <button type="button" aria-label={t('mgmt.settings.scrollStart')} onClick={() => scrollBy(-1)} className="mb-1 grid size-8 shrink-0 place-items-center rounded-lg text-white/70 hover:bg-white/10 hover:text-white"><StartIcon className="size-4" /></button>}

      <div ref={scroller} onScroll={measure} className="flex min-w-0 flex-1 items-end gap-1 overflow-x-auto [scrollbar-width:none] [&::-webkit-scrollbar]:hidden">
        {tabs.map((tab) => {
          const isActive = tab.id === active
          const Icon = tab.icon
          return (
            <div
              key={tab.id} role="tab" tabIndex={0} aria-selected={isActive} data-active={isActive} draggable
              onClick={() => onSelect(tab.id)} onAuxClick={(e) => onAux(e, tab.id)} onMouseDown={(e) => e.button === 1 && e.preventDefault()}
              onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelect(tab.id) } }}
              onContextMenu={(e) => { e.preventDefault(); onContextMenu(tab.id, e.clientX, e.clientY) }}
              onDragStart={(e) => onDragStart(e, tab.id)} onDragOver={(e) => { e.preventDefault(); setOver(tab.id) }} onDragLeave={() => setOver((cur) => (cur === tab.id ? null : cur))}
              onDrop={(e) => onDrop(e, tab.id)} onDragEnd={() => { dragId.current = null; setDragging(null); setOver(null) }}
              className={clsx(
                'group relative flex min-w-[9rem] max-w-[15rem] shrink-0 cursor-pointer select-none items-center gap-2 rounded-t-xl py-2.5 ps-3.5 pe-2 text-sm font-semibold transition',
                isActive ? 'bg-ivory text-navy-900 shadow-[0_-2px_0_0_var(--color-gold-500)_inset]' : 'text-white/70 hover:bg-white/10 hover:text-white',
                dragging === tab.id && 'opacity-40', over === tab.id && dragging && dragging !== tab.id && 'ring-2 ring-gold-400',
              )}
            >
              <Icon className={clsx('size-4 shrink-0', isActive ? 'text-gold-600' : 'text-gold-300/80')} />
              <span className="min-w-0 flex-1 truncate">{tab.label}</span>
              {tab.dirty && <span title={t('mgmt.settings.dirtyDot')} className="size-2 shrink-0 animate-pulse rounded-full bg-amber-500" />}
              <button
                type="button" aria-label={`${t('mgmt.settings.closeTab')}: ${tab.label}`} tabIndex={-1} draggable={false}
                onClick={(e) => { e.stopPropagation(); onClose(tab.id) }}
                className={clsx('grid size-5 shrink-0 place-items-center rounded-md transition', isActive ? 'text-slate-400 hover:bg-navy-100 hover:text-navy-900' : 'text-white/40 opacity-0 hover:bg-white/20 hover:text-white group-hover:opacity-100 focus:opacity-100')}
              >
                <X className="size-3.5" />
              </button>
            </div>
          )
        })}
      </div>

      {overflow && <button type="button" aria-label={t('mgmt.settings.scrollEnd')} onClick={() => scrollBy(1)} className="mb-1 grid size-8 shrink-0 place-items-center rounded-lg text-white/70 hover:bg-white/10 hover:text-white"><EndIcon className="size-4" /></button>}
      <button type="button" aria-label={t('mgmt.settings.openSetting')} title={`${t('mgmt.settings.openSetting')} (Ctrl/⌘ K)`} onClick={onAdd} className="mb-1 grid size-8 shrink-0 place-items-center rounded-lg bg-gold-500/90 text-navy-950 transition hover:bg-gold-400"><Plus className="size-4" /></button>
    </div>
  )
}
