import clsx from 'clsx'
import { Copy, LayoutTemplate, MessageSquare, Plus, Trash2 } from 'lucide-react'
import { useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { LAYOUTS, slideTitle, type LayoutId, type Slide, type Theme } from './model'
import { SlideView } from './SlideView'
import type { Presence } from './useDeckEditor'

type Props = {
  slides: Slide[]
  theme: Theme
  activeId: string | null
  canEdit: boolean
  commentCounts: Record<string, number>
  others: Presence[]
  dirtyIds: Set<string>
  onSelect: (id: string) => void
  onAdd: (layout: LayoutId) => void
  onDuplicate: (id: string) => void
  onDelete: (id: string) => void
  onMove: (from: string, to: string) => void
}

const initials = (n: string) => n.replace(/^(د\.|أ\.|م\.|Dr\.|Ms\.|Mr\.|Eng\.)\s*/, '').split(' ').slice(0, 2).map((s) => s[0]).join('')

/** Slide thumbnails: reorder by dragging, see where teammates are and which slides have open comments. */
export default function SlideRail({ slides, theme, activeId, canEdit, commentCounts, others, dirtyIds, onSelect, onAdd, onDuplicate, onDelete, onMove }: Props) {
  const { t } = useTranslation()
  const [menu, setMenu] = useState(false)
  const [over, setOver] = useState<string | null>(null)
  const drag = useRef<string | null>(null)
  const listRef = useRef<HTMLDivElement>(null)

  return (
    <div className="flex h-full min-h-0 flex-col">
      <div ref={listRef} className="min-h-0 flex-1 space-y-3 overflow-y-auto p-3" role="listbox" aria-label={t('kits.editor.slides')}>
        {slides.map((s, i) => {
          const active = s.id === activeId
          const here = others.filter((p) => p.slide_id === s.id)
          const count = commentCounts[s.id] ?? 0
          return (
            <div key={s.id} role="option" aria-selected={active} tabIndex={0} draggable={canEdit}
              onClick={() => onSelect(s.id)} onKeyDown={(e) => e.key === 'Enter' && onSelect(s.id)}
              onDragStart={(e) => { drag.current = s.id; e.dataTransfer.effectAllowed = 'move'; e.dataTransfer.setData('text/plain', s.id) }}
              onDragOver={(e) => { e.preventDefault(); setOver(s.id) }} onDragLeave={() => setOver((o) => (o === s.id ? null : o))}
              onDrop={(e) => { e.preventDefault(); if (drag.current && drag.current !== s.id) onMove(drag.current, s.id); drag.current = null; setOver(null) }}
              onDragEnd={() => { drag.current = null; setOver(null) }}
              className={clsx('group relative flex cursor-pointer gap-2 rounded-xl p-1.5 transition', active ? 'bg-gold-100/60 ring-2 ring-gold-500' : 'hover:bg-white/70', over === s.id && 'ring-2 ring-sky-400')}>
              <span className={clsx('w-5 shrink-0 pt-1 text-center text-[11px] font-bold', active ? 'text-gold-700' : 'text-slate-400')}>{i + 1}</span>
              <div className="relative min-w-0 flex-1">
                <SlideView slide={s} theme={theme} width={172} className="rounded-md ring-1 ring-black/10" />
                <div className="pointer-events-none absolute inset-x-0 bottom-0 truncate bg-gradient-to-t from-black/50 to-transparent px-1.5 pb-1 pt-4 text-[10px] font-semibold text-white opacity-0 transition group-hover:opacity-100">{slideTitle(s) || t('kits.editor.untitled')}</div>
                {count > 0 && <span className="absolute -end-1 -top-1 inline-flex items-center gap-0.5 rounded-full bg-red-600 px-1.5 py-0.5 text-[10px] font-bold text-white shadow"><MessageSquare className="size-2.5" />{count}</span>}
                {dirtyIds.has(s.id) && <span className="absolute start-1 top-1 size-2 rounded-full bg-amber-500 ring-2 ring-white" title={t('kits.editor.unsaved')} />}
                {here.length > 0 && (
                  <div className="absolute -start-1 -top-1 flex -space-x-1.5 rtl:space-x-reverse">
                    {here.slice(0, 3).map((p) => <span key={p.user_id} title={p.name} className="grid size-5 place-items-center rounded-full bg-navy-900 text-[9px] font-bold text-gold-300 ring-2 ring-white">{initials(p.name)}</span>)}
                  </div>
                )}
                {canEdit && (
                  <div className="absolute bottom-1 end-1 flex gap-1 opacity-0 transition group-hover:opacity-100">
                    <button type="button" aria-label={t('kits.editor.duplicateSlide')} onClick={(e) => { e.stopPropagation(); onDuplicate(s.id) }} className="grid size-6 place-items-center rounded-md bg-white/90 text-navy-800 shadow hover:bg-white"><Copy className="size-3.5" /></button>
                    {slides.length > 1 && <button type="button" aria-label={t('kits.editor.deleteSlide')} onClick={(e) => { e.stopPropagation(); onDelete(s.id) }} className="grid size-6 place-items-center rounded-md bg-white/90 text-danger shadow hover:bg-white"><Trash2 className="size-3.5" /></button>}
                  </div>
                )}
              </div>
            </div>
          )
        })}
      </div>
      {canEdit && (
        <div className="relative border-t border-navy-100 p-3">
          <button type="button" onClick={() => setMenu(!menu)} aria-expanded={menu} className="flex w-full items-center justify-center gap-2 rounded-xl bg-navy-900 px-3 py-2.5 text-sm font-semibold text-white shadow transition hover:bg-navy-800"><Plus className="size-4" />{t('kits.editor.addSlide')}</button>
          {menu && (
            <div className="absolute inset-x-3 bottom-full z-20 mb-2 grid grid-cols-2 gap-1.5 rounded-2xl border border-navy-100 bg-white p-2 shadow-glass">
              {LAYOUTS.map((l) => (
                <button key={l} type="button" onClick={() => { onAdd(l); setMenu(false) }} className="flex items-center gap-2 rounded-lg px-2.5 py-2 text-start text-xs font-semibold text-navy-900 hover:bg-ivory"><LayoutTemplate className="size-4 text-gold-600" />{t(`kits.editor.layouts.${l}`)}</button>
              ))}
            </div>
          )}
        </div>
      )}
    </div>
  )
}
