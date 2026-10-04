import clsx from 'clsx'
import { Check, ImagePlus, MessageSquare } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { H, W, isMedia, textParagraphsFromString, type El, type Slide, type TextEl, type Theme } from './model'
import { ElementBox } from './SlideView'
import type { KitComment } from '../types'
import { severityTone } from '../comments/api'

export type PinDraft = { x: number; y: number; element_id: string | null }

type Props = {
  slide: Slide
  theme: Theme
  width: number
  selectedId: string | null
  onSelect: (id: string | null) => void
  editable: boolean
  mode: 'edit' | 'review'
  /** Live changes while dragging do not create history entries. */
  onChangeEl: (id: string, patch: Partial<El>, opts?: { live?: boolean }) => void
  onBeginGesture: () => void
  comments: KitComment[]
  activeCommentId: string | null
  onPinClick: (c: KitComment) => void
  onPlacePin: (draft: PinDraft) => void
  draftPin: PinDraft | null
  /** A picture, video or sound was pressed (an empty one, or double-click on a filled one): let the user choose it. */
  onActivate?: (el: El) => void
  activateLabel?: string
}

type Handle = 'nw' | 'n' | 'ne' | 'e' | 'se' | 's' | 'sw' | 'w'
const HANDLES: { id: Handle; x: number; y: number; cursor: string }[] = [
  { id: 'nw', x: 0, y: 0, cursor: 'nwse-resize' }, { id: 'n', x: 0.5, y: 0, cursor: 'ns-resize' }, { id: 'ne', x: 1, y: 0, cursor: 'nesw-resize' }, { id: 'e', x: 1, y: 0.5, cursor: 'ew-resize' },
  { id: 'se', x: 1, y: 1, cursor: 'nwse-resize' }, { id: 's', x: 0.5, y: 1, cursor: 'ns-resize' }, { id: 'sw', x: 0, y: 1, cursor: 'nesw-resize' }, { id: 'w', x: 0, y: 0.5, cursor: 'ew-resize' },
]
const SNAP = 6

/** Where a comment pin sits on the slide (element-relative when it is attached to an element). */
export function pinPosition(c: KitComment, slide: Slide): { x: number; y: number } | null {
  const a = c.anchor
  if (!a || (a.type !== 'point' && a.type !== 'element')) return null
  const el = a.element_id ? slide.elements.find((e) => e.id === a.element_id) : null
  if (el) return { x: el.x + (a.x ?? el.w - 12), y: el.y + (a.y ?? 12) }
  if (a.x == null || a.y == null) return null
  return { x: a.x, y: a.y }
}

/** The interactive stage: select, move, resize, rotate, edit text in place, pin comments. */
export default function SlideCanvas({ slide, theme, width, selectedId, onSelect, editable, mode, onChangeEl, onBeginGesture, comments, activeCommentId, onPinClick, onPlacePin, draftPin, onActivate, activateLabel }: Props) {
  const k = width / W
  const stage = useRef<HTMLDivElement>(null)
  const [editing, setEditing] = useState<string | null>(null)
  const [guides, setGuides] = useState<{ v: number[]; h: number[] }>({ v: [], h: [] })
  const [hover, setHover] = useState<string | null>(null)
  const selected = slide.elements.find((e) => e.id === selectedId) ?? null
  const reviewing = mode === 'review'

  useEffect(() => { setEditing(null) }, [slide.id])
  useEffect(() => { if (selectedId !== editing) setEditing(null) }, [selectedId, editing])

  const point = useCallback((e: { clientX: number; clientY: number }) => {
    const r = stage.current!.getBoundingClientRect()
    return { x: (e.clientX - r.left) / k, y: (e.clientY - r.top) / k }
  }, [k])

  const topElementAt = useCallback((x: number, y: number): El | null => {
    for (let i = slide.elements.length - 1; i >= 0; i--) {
      const el = slide.elements[i]
      if (x >= el.x && x <= el.x + el.w && y >= el.y && y <= el.y + Math.max(el.h, 10)) return el
    }
    return null
  }, [slide.elements])

  const snapMove = (el: El, nx: number, ny: number) => {
    const xs = [0, W / 2, W], ys = [0, H / 2, H]
    slide.elements.forEach((o) => { if (o.id !== el.id) { xs.push(o.x, o.x + o.w / 2, o.x + o.w); ys.push(o.y, o.y + o.h / 2, o.y + o.h) } })
    const edgesX = [nx, nx + el.w / 2, nx + el.w], edgesY = [ny, ny + el.h / 2, ny + el.h]
    let dx = 0, dy = 0
    const v: number[] = [], h: number[] = []
    let best = SNAP + 1
    edgesX.forEach((ex) => xs.forEach((gx) => { const d = gx - ex; if (Math.abs(d) < best) { best = Math.abs(d); dx = d } }))
    if (best <= SNAP) edgesX.forEach((ex) => xs.forEach((gx) => { if (Math.abs(gx - (ex + dx)) < 0.5) v.push(gx) })); else dx = 0
    best = SNAP + 1
    edgesY.forEach((ey) => ys.forEach((gy) => { const d = gy - ey; if (Math.abs(d) < best) { best = Math.abs(d); dy = d } }))
    if (best <= SNAP) edgesY.forEach((ey) => ys.forEach((gy) => { if (Math.abs(gy - (ey + dy)) < 0.5) h.push(gy) })); else dy = 0
    return { x: nx + dx, y: ny + dy, v, h }
  }

  const startDrag = (e: React.PointerEvent, el: El) => {
    if (!editable || reviewing || editing === el.id || e.button !== 0) return
    e.stopPropagation()
    onSelect(el.id)
    const start = point(e)
    const origin = { x: el.x, y: el.y }
    let began = false
    const move = (ev: PointerEvent) => {
      const p = point(ev)
      const dx = p.x - start.x, dy = p.y - start.y
      if (!began && Math.hypot(dx, dy) * k < 3) return
      if (!began) { began = true; onBeginGesture() }
      const snapped = ev.altKey ? { x: origin.x + dx, y: origin.y + dy, v: [], h: [] } : snapMove(el, origin.x + dx, origin.y + dy)
      setGuides({ v: snapped.v, h: snapped.h })
      onChangeEl(el.id, { x: Math.round(snapped.x), y: Math.round(snapped.y) }, { live: true })
    }
    const up = () => {
      window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up); setGuides({ v: [], h: [] })
      // Pressing an empty picture / video / sound (without dragging it) opens the chooser straight away.
      if (!began && isMedia(el) && !el.asset_id && !el.src) onActivate?.(el)
    }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', up)
  }

  const startResize = (e: React.PointerEvent, el: El, handle: Handle) => {
    e.stopPropagation()
    e.preventDefault()
    onBeginGesture()
    const start = point(e)
    const o = { x: el.x, y: el.y, w: el.w, h: el.h }
    const ratio = o.w / Math.max(o.h, 1)
    const keep = el.type === 'image' || el.type === 'video'
    const move = (ev: PointerEvent) => {
      const p = point(ev)
      let dx = p.x - start.x, dy = p.y - start.y
      let { x, y, w, h } = o
      if (handle.includes('e')) w = o.w + dx
      if (handle.includes('w')) { w = o.w - dx; x = o.x + dx }
      if (handle.includes('s')) h = o.h + dy
      if (handle.includes('n')) { h = o.h - dy; y = o.y + dy }
      if ((keep || ev.shiftKey) && handle.length === 2) {
        const nh = w / ratio
        if (handle.includes('n')) y = o.y + o.h - nh
        h = nh
      }
      if (w < 12) { if (handle.includes('w')) x = o.x + o.w - 12; w = 12 }
      if (h < 8) { if (handle.includes('n')) y = o.y + o.h - 8; h = 8 }
      dx = 0; dy = 0
      onChangeEl(el.id, { x: Math.round(x), y: Math.round(y), w: Math.round(w), h: Math.round(h) }, { live: true })
    }
    const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up) }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', up)
  }

  const startRotate = (e: React.PointerEvent, el: El) => {
    e.stopPropagation()
    e.preventDefault()
    onBeginGesture()
    const cx = el.x + el.w / 2, cy = el.y + el.h / 2
    const move = (ev: PointerEvent) => {
      const p = point(ev)
      let deg = (Math.atan2(p.y - cy, p.x - cx) * 180) / Math.PI + 90
      if (ev.shiftKey) deg = Math.round(deg / 15) * 15
      onChangeEl(el.id, { rotation: Math.round(((deg + 540) % 360) - 180) }, { live: true })
    }
    const up = () => { window.removeEventListener('pointermove', move); window.removeEventListener('pointerup', up) }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', up)
  }

  const onStageDown = (e: React.PointerEvent) => {
    if (e.target !== e.currentTarget && !(e.target as HTMLElement).dataset.stage) return
    if (reviewing) {
      const p = point(e)
      const hit = topElementAt(p.x, p.y)
      onPlacePin(hit ? { element_id: hit.id, x: Math.round(p.x - hit.x), y: Math.round(p.y - hit.y) } : { element_id: null, x: Math.round(p.x), y: Math.round(p.y) })
    } else onSelect(null)
  }

  const pins = useMemo(() => comments.map((c, i) => ({ c, i, pos: pinPosition(c, slide) })).filter((p) => p.pos), [comments, slide])
  const draftPos = draftPin ? (draftPin.element_id ? (() => { const el = slide.elements.find((x) => x.id === draftPin.element_id); return el ? { x: el.x + draftPin.x, y: el.y + draftPin.y } : null })() : { x: draftPin.x, y: draftPin.y }) : null

  return (
    <div className="relative select-none" style={{ width, height: H * k }} onPointerDown={onStageDown} data-stage>
      <div ref={stage} data-stage style={{ width: W, height: H, transform: `scale(${k})`, transformOrigin: '0 0', position: 'absolute', left: 0, top: 0, overflow: 'hidden', background: slide.background.color, boxShadow: '0 0 0 1px rgba(0,0,0,.08)' }}>
        {slide.background.src && <img data-stage src={slide.background.src} alt="" draggable={false} style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover' }} />}
        {slide.elements.map((el) => (
          <ElementBox key={el.id} el={el} theme={theme}
            onPointerDown={(e) => { if (reviewing) return; startDrag(e, el) }}
            onPointerEnter={() => setHover(el.id)} onPointerLeave={() => setHover((h) => (h === el.id ? null : h))}
            onDoubleClick={() => { if (!editable || reviewing) return; if (el.type === 'text') { onSelect(el.id); setEditing(el.id) } else if (isMedia(el)) { onSelect(el.id); onActivate?.(el) } }}
            style={{ cursor: reviewing ? 'crosshair' : editable ? (editing === el.id ? 'text' : 'move') : 'default', outline: reviewing && hover === el.id ? '3px dashed rgba(162,148,117,.9)' : undefined, visibility: editing === el.id ? 'hidden' : undefined }} />
        ))}
        {editing && selected?.type === 'text' && editing === selected.id && (
          <InlineEditor el={selected} theme={theme} onDone={(paragraphs) => { onChangeEl(selected.id, { paragraphs } as Partial<TextEl>); setEditing(null) }} />
        )}
        {reviewing && <div data-stage className="absolute inset-0" style={{ cursor: 'crosshair' }} onPointerDown={(e) => { if (e.target === e.currentTarget) { const p = point(e); const hit = topElementAt(p.x, p.y); onPlacePin(hit ? { element_id: hit.id, x: Math.round(p.x - hit.x), y: Math.round(p.y - hit.y) } : { element_id: null, x: Math.round(p.x), y: Math.round(p.y) }); e.stopPropagation() } }} />}
      </div>

      {/* Overlay in screen pixels so handles and pins keep their size at any zoom. */}
      <div className="pointer-events-none absolute inset-0">
        {guides.v.map((x) => <span key={`v${x}`} className="absolute inset-y-0 w-px bg-fuchsia-500/80" style={{ left: x * k }} />)}
        {guides.h.map((y) => <span key={`h${y}`} className="absolute inset-x-0 h-px bg-fuchsia-500/80" style={{ top: y * k }} />)}
        {selected && !reviewing && editable && editing !== selected.id && (
          <div className="absolute" style={{ left: selected.x * k, top: selected.y * k, width: selected.w * k, height: Math.max(selected.h, 4) * k, transform: selected.rotation ? `rotate(${selected.rotation}deg)` : undefined }}>
            <div className="absolute inset-0 border-2 border-sky-500" />
            {!selected.rotation && HANDLES.map((h) => (
              <span key={h.id} onPointerDown={(e) => startResize(e, selected, h.id)} className="pointer-events-auto absolute size-3 rounded-sm border-2 border-sky-500 bg-white"
                style={{ left: `calc(${h.x * 100}% - 6px)`, top: `calc(${h.y * 100}% - 6px)`, cursor: h.cursor }} />
            ))}
            {isMedia(selected) && onActivate && (
              <button type="button" onPointerDown={(e) => e.stopPropagation()} onClick={() => onActivate(selected)}
                className="pointer-events-auto absolute -bottom-11 start-1/2 inline-flex -translate-x-1/2 items-center gap-1.5 whitespace-nowrap rounded-full bg-navy-900 px-3.5 py-1.5 text-xs font-bold text-white shadow-lg ring-2 ring-white transition hover:bg-navy-800 rtl:translate-x-1/2">
                <ImagePlus className="size-3.5 text-gold-300" />{activateLabel}
              </button>
            )}
            <span onPointerDown={(e) => startRotate(e, selected)} className="pointer-events-auto absolute -top-7 left-1/2 size-3.5 -translate-x-1/2 cursor-grab rounded-full border-2 border-sky-500 bg-white" />
            <span className="absolute -top-7 left-1/2 h-4 w-px -translate-x-1/2 translate-y-3.5 bg-sky-500" />
          </div>
        )}
        {selected && (reviewing || !editable) && <div className="absolute border-2 border-dashed border-gold-500" style={{ left: selected.x * k, top: selected.y * k, width: selected.w * k, height: selected.h * k }} />}
        {pins.map(({ c, i, pos }) => {
          const active = activeCommentId === c.id
          const done = c.status === 'resolved'
          return (
            <button key={c.id} type="button" onClick={(e) => { e.stopPropagation(); onPinClick(c) }} title={c.body}
              className={clsx('pointer-events-auto absolute grid -translate-x-1/2 -translate-y-full place-items-center rounded-full rounded-bl-none text-[11px] font-bold text-white shadow-lg ring-2 ring-white transition', active ? 'z-20 size-8 scale-110' : 'size-6 hover:scale-110', done && 'opacity-60')}
              style={{ left: pos!.x * k, top: pos!.y * k, background: done ? '#16A34A' : c.status === 'addressed' ? '#D97706' : severityTone[c.severity].pin }}>
              {done ? <Check className="size-3.5" /> : i + 1}
            </button>
          )
        })}
        {draftPos && <span className="absolute grid size-7 -translate-x-1/2 -translate-y-full animate-bounce place-items-center rounded-full rounded-bl-none bg-gold-500 text-white shadow-lg ring-2 ring-white" style={{ left: draftPos.x * k, top: draftPos.y * k }}><MessageSquare className="size-3.5" /></span>}
      </div>
    </div>
  )
}

/** Plain-text editor that keeps the paragraph styling; a new line becomes a new paragraph in the style of the previous one. */
function InlineEditor({ el, theme, onDone }: { el: TextEl; theme: Theme; onDone: (p: TextEl['paragraphs']) => void }) {
  const ref = useRef<HTMLTextAreaElement>(null)
  const first = el.paragraphs[0]
  const initial = el.paragraphs.map((p) => p.text).join('\n')
  const [value, setValue] = useState(initial)
  useEffect(() => { ref.current?.focus(); ref.current?.select() }, [])
  const done = () => onDone(value === initial ? el.paragraphs : textParagraphsFromString(value, el.paragraphs))
  return (
    <textarea
      ref={ref} value={value} dir={theme.dir} onChange={(e) => setValue(e.target.value)} onBlur={done}
      onKeyDown={(e) => { if (e.key === 'Escape' || ((e.ctrlKey || e.metaKey) && e.key === 'Enter')) { e.preventDefault(); done() } e.stopPropagation() }}
      onPointerDown={(e) => e.stopPropagation()}
      style={{
        position: 'absolute', left: el.x, top: el.y, width: el.w, height: Math.max(el.h, 60), padding: el.style.padding, boxSizing: 'border-box', resize: 'none', background: el.style.fill ?? 'rgba(255,255,255,.92)',
        border: '2px solid #0EA5E9', outline: 'none', fontSize: first?.size ?? 24, lineHeight: el.style.lineHeight, fontWeight: first?.bold ? 700 : 400, fontStyle: first?.italic ? 'italic' : 'normal',
        color: first?.color ?? '#1A1A1A', textAlign: first?.align ?? 'right', fontFamily: `'${el.style.fontFamily || theme.font}', var(--font-sans), sans-serif`, transform: el.rotation ? `rotate(${el.rotation}deg)` : undefined, zIndex: 5,
      }}
    />
  )
}
