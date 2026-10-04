import clsx from 'clsx'
import { LayoutGrid, X } from 'lucide-react'
import { useRef, useState, type DragEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { DeliveryBadge, deliveryMeta } from './common'
import type { KitDelivery } from './types'

export type DocTab = { id: string; code?: string; title: string; delivery?: KitDelivery | null; loading?: boolean }

type Props = {
  tabs: DocTab[]
  active: string
  onSelect: (id: string) => void
  onClose: (id: string) => void
  onReorder: (from: string, to: string) => void
  onContextMenu: (id: string, x: number, y: number) => void
}

/** The rail of open kits: the studio first, then one closable, draggable tab per kit with its kind (in person / online / hybrid) on it. */
export default function KitDocTabs({ tabs, active, onSelect, onClose, onReorder, onContextMenu }: Props) {
  const { t } = useTranslation()
  const dragId = useRef<string | null>(null)
  const [over, setOver] = useState<string | null>(null)
  const drop = (e: DragEvent, id: string) => { e.preventDefault(); if (dragId.current && dragId.current !== id) onReorder(dragId.current, id); dragId.current = null; setOver(null) }

  return (
    <div role="tablist" aria-label={t('kits.workbench.tabs')} className="mb-5 flex items-stretch gap-2 overflow-x-auto pb-1 [scrollbar-width:thin]">
      <button type="button" role="tab" aria-selected={active === 'home'} onClick={() => onSelect('home')}
        className={clsx('flex shrink-0 items-center gap-2 rounded-2xl border px-4 py-2.5 text-sm font-extrabold transition', active === 'home' ? 'border-navy-900 bg-navy-900 text-white shadow-glass' : 'border-navy-100 bg-white text-navy-900 hover:border-gold-400')}>
        <LayoutGrid className={clsx('size-4', active === 'home' ? 'text-gold-300' : 'text-gold-600')} />{t('kits.workbench.studio')}
      </button>

      {tabs.map((tab) => {
        const isActive = tab.id === active
        const meta = tab.delivery ? deliveryMeta[tab.delivery] : null
        const Icon = meta?.icon ?? LayoutGrid
        return (
          <div key={tab.id} role="tab" tabIndex={0} aria-selected={isActive} draggable
            onClick={() => onSelect(tab.id)} onAuxClick={(e) => { if (e.button === 1) { e.preventDefault(); onClose(tab.id) } }} onMouseDown={(e) => e.button === 1 && e.preventDefault()}
            onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onSelect(tab.id) } }}
            onContextMenu={(e) => { e.preventDefault(); onContextMenu(tab.id, e.clientX, e.clientY) }}
            onDragStart={(e) => { dragId.current = tab.id; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', tab.id) }}
            onDragOver={(e) => { e.preventDefault(); setOver(tab.id) }} onDragLeave={() => setOver((cur) => (cur === tab.id ? null : cur))} onDrop={(e) => drop(e, tab.id)} onDragEnd={() => { dragId.current = null; setOver(null) }}
            className={clsx('group relative flex min-w-[11.5rem] max-w-[17rem] shrink-0 cursor-pointer select-none items-center gap-2.5 rounded-2xl border py-2 ps-2.5 pe-2 transition',
              isActive ? 'border-navy-900 bg-navy-900 text-white shadow-glass' : 'border-navy-100 bg-white text-navy-900 hover:border-gold-400', over === tab.id && 'ring-2 ring-gold-400')}>
            <span className={clsx('grid size-9 shrink-0 place-items-center rounded-xl', meta ? meta.solid : 'bg-navy-100 text-navy-800')}><Icon className="size-4.5" /></span>
            <span className="min-w-0 flex-1 text-start">
              <span className={clsx('block truncate font-mono text-[10px] font-bold', isActive ? 'text-gold-300' : 'text-slate-400')} dir="ltr">{tab.code ?? '…'}</span>
              <span className="block truncate text-[13px] font-bold leading-tight" dir="auto">{tab.loading ? t('kits.workbench.loading') : tab.title}</span>
            </span>
            <button type="button" tabIndex={-1} draggable={false} aria-label={`${t('kits.workbench.close')}: ${tab.title}`} onClick={(e) => { e.stopPropagation(); onClose(tab.id) }}
              className={clsx('grid size-6 shrink-0 place-items-center rounded-lg transition', isActive ? 'text-white/60 hover:bg-white/15 hover:text-white' : 'text-slate-400 opacity-0 hover:bg-navy-100 hover:text-navy-900 group-hover:opacity-100 focus:opacity-100')}>
              <X className="size-4" />
            </button>
            {isActive && tab.delivery && <span className="pointer-events-none absolute -bottom-2.5 start-1/2 -translate-x-1/2 rtl:translate-x-1/2"><DeliveryBadge delivery={tab.delivery} className="!text-[9px] shadow" /></span>}
          </div>
        )
      })}
    </div>
  )
}
