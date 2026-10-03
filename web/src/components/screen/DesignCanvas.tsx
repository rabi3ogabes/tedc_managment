import clsx from 'clsx'
import { useEffect, useMemo, useRef, useState, type CSSProperties, type PointerEvent as RPointerEvent } from 'react'
import { useTranslation } from 'react-i18next'
import { BrandMark } from '@/components/public/Logo'
import { customPatternTile } from '@/lib/theme'
import { CANVAS_H, CANVAS_W, tokenValues, type DesignBackground, type DesignEl, type ScreenContext, type ScreenDesign } from './design'

type Handle = 'nw' | 'ne' | 'sw' | 'se'

/** CSS background of the design (brand, solid colour, gradient). Pictures are layered separately. */
export function backgroundStyle(bg: DesignBackground): CSSProperties {
  if (bg.type === 'solid') return { background: bg.color }
  if (bg.type === 'gradient') return { background: `linear-gradient(${bg.angle}deg, ${bg.color}, ${bg.color2})` }
  if (bg.type === 'image') return { background: bg.color }
  return { background: 'linear-gradient(135deg, var(--color-navy-950) 0%, var(--color-navy-900) 55%, var(--color-navy-800) 100%)' }
}

function ImageLayer({ bg }: { bg: DesignBackground }) {
  const [tile, setTile] = useState('none')
  useEffect(() => {
    let alive = true
    if (bg.type !== 'image' || !bg.image) { setTile('none'); return }
    // Opacity is baked into the tile when the picture can be read; otherwise it is applied with CSS below.
    void customPatternTile({ type: 'custom', image: bg.image, opacity: bg.image_opacity, tint: null, size: bg.image_size, color: '#000' }).then((u) => alive && setTile(u))
    return () => { alive = false }
  }, [bg.type, bg.image, bg.image_opacity, bg.image_size])
  if (bg.type !== 'image' || !bg.image || tile === 'none') return null
  const baked = tile.startsWith('url("data:')
  return <div className="absolute inset-0" style={{ backgroundImage: tile, backgroundSize: bg.image_fit === 'cover' ? 'cover' : `${bg.image_size}px auto`, backgroundRepeat: bg.image_fit === 'cover' ? 'no-repeat' : 'repeat', backgroundPosition: 'center', opacity: baked ? 1 : bg.image_opacity / 100 }} />
}

const fillStyle = (el: DesignEl): CSSProperties => (el.fill2 ? { background: `linear-gradient(${el.angle}deg, ${el.fill === 'transparent' ? 'transparent' : el.fill}, ${el.fill2})` } : { background: el.fill })
const flex = { start: 'flex-start', center: 'center', end: 'flex-end' } as const

function Ring({ value, total, accent }: { value: number; total: number; accent: string }) {
  const r = 52
  const c = 2 * Math.PI * r
  return (
    <svg viewBox="0 0 120 120" className="size-full -rotate-90"><circle cx="60" cy="60" r={r} fill="none" strokeWidth="9" stroke="rgba(255,255,255,.14)" /><circle cx="60" cy="60" r={r} fill="none" strokeWidth="9" strokeLinecap="round" stroke={accent} strokeDasharray={c} strokeDashoffset={c - c * (total ? value / total : 0)} style={{ transition: 'stroke-dashoffset .7s' }} /></svg>
  )
}

const STATUS = { present: '#34d399', late: '#fbbf24', expected: 'rgba(255,255,255,.35)', absent: '#f87171' } as const

function ElementView({ el, ctx, tokens, scale }: { el: DesignEl; ctx: ScreenContext; tokens: Record<string, string>; scale: number }) {
  const { t } = useTranslation()
  const f = ctx.featured
  const accent = el.accent || 'var(--sc-accent, #e2b54f)'
  const box: CSSProperties = {
    ...fillStyle(el), borderRadius: el.radius, opacity: el.opacity / 100,
    border: el.border && el.border_width ? `${el.border_width}px solid ${el.border}` : undefined,
  }
  const textBox: CSSProperties = { ...box, color: el.color, fontSize: el.size, fontWeight: el.bold ? 800 : 500, lineHeight: 1.15, display: 'flex', alignItems: flex[el.valign], justifyContent: flex[el.align], textAlign: el.align === 'center' ? 'center' : el.align === 'end' ? 'end' : 'start' }

  switch (el.type) {
    case 'shape':
      return <div className="size-full" style={box} />
    case 'text': {
      const text = el.text.replace(/\{\{\s*(\w+)\s*\}\}/g, (_, k: string) => tokens[k] ?? '')
      return <div className="size-full overflow-hidden whitespace-pre-wrap break-words px-1" style={textBox}>{text}</div>
    }
    case 'clock':
      return <div className="size-full tabular-nums" dir="ltr" style={textBox}>{ctx.now.toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit' })}</div>
    case 'date':
      return <div className="size-full" style={textBox}>{tokens.date}</div>
    case 'logo':
      return <div className="grid size-full place-items-center overflow-hidden" style={box}><BrandMark onDark={el.logo_dark} className="h-full max-h-full w-auto max-w-full" markClassName="size-full" /></div>
    case 'image':
      return el.src ? <img src={el.src} alt="" draggable={false} className="size-full object-cover" style={box} /> : <div className="grid size-full place-items-center border border-dashed border-white/40 text-white/60" style={{ fontSize: 28 }}>IMG</div>
    case 'status': {
      const live = !ctx.idle
      const text = ctx.idle ? t('studio.screen.available') : f?.state === 'live' ? t('studio.screen.live') : f?.state === 'ended' ? t('studio.screen.ended') : t('studio.screen.next')
      const bg = el.fill !== 'transparent' ? undefined : live && f?.state === 'live' ? '#10b981' : ctx.idle ? '#10b981' : '#e2b54f'
      return <div className="size-full" style={{ ...textBox, ...(bg ? { background: bg } : {}), borderRadius: el.radius || 9999 }}>{text}</div>
    }
    case 'progress': {
      const start = f ? new Date(f.starts_at).getTime() : 0
      const end = f ? new Date(f.ends_at).getTime() : 1
      const pct = f && f.state === 'live' ? Math.min(100, Math.max(0, ((ctx.now.getTime() - start) / (end - start)) * 100)) : 0
      return <div className="size-full overflow-hidden" style={{ ...box, background: el.fill === 'transparent' ? 'rgba(255,255,255,.14)' : el.fill, borderRadius: el.radius || 999 }}><div className="h-full transition-all duration-1000" style={{ width: `${pct}%`, background: `linear-gradient(90deg, ${accent}, ${el.color})`, borderRadius: 'inherit' }} /></div>
    }
    case 'ring':
      return <div className="grid size-full place-items-center" style={{ opacity: el.opacity / 100 }}><div style={{ width: '100%', height: '100%', aspectRatio: '1', maxWidth: '100%', maxHeight: '100%' }}><Ring value={f?.counts.present ?? 0} total={f?.counts.expected ?? 0} accent={accent} /></div></div>
    case 'trainees': {
      const list = f?.trainees ?? []
      return (
        <div className="size-full overflow-hidden" style={box}>
          <ul className="grid gap-2" style={{ gridTemplateColumns: `repeat(${el.columns}, minmax(0, 1fr))`, fontSize: el.size, color: el.color }}>
            {list.slice(0, 80).map((p, i) => (
              <li key={i} className="flex items-center gap-2 overflow-hidden rounded-xl border px-3 py-2" style={{ borderColor: 'rgba(255,255,255,.16)', background: 'rgba(255,255,255,.07)' }}>
                <span className="size-3 shrink-0 rounded-full" style={{ background: STATUS[p.status], boxShadow: p.status === 'present' ? '0 0 12px 2px rgba(52,211,153,.6)' : undefined }} />
                <span className="min-w-0 flex-1"><span className="block truncate font-bold">{p.name}</span>{el.show_school && p.school && <span className="block truncate opacity-60" style={{ fontSize: el.size * 0.65 }}>{p.school}</span>}</span>
              </li>
            ))}
          </ul>
        </div>
      )
    }
  }
  void scale
  return null
}

type Props = {
  design: ScreenDesign; ctx: ScreenContext
  /** Which list of elements to show; by default the one that fits the room's state. */
  list?: 'live' | 'idle'
  interactive?: boolean; selectedId?: string | null; onSelect?: (id: string | null) => void; onChange?: (id: string, patch: Partial<DesignEl>, commit: boolean) => void
  /** Fill the whole window (the TV) instead of the width of the parent (previews). */
  fill?: boolean; className?: string
}

/** The designed screen: a 1920×1080 stage scaled to fit. In the designer its elements can be dragged and resized. */
export default function DesignCanvas({ design, ctx, list, interactive, selectedId, onSelect, onChange, fill, className }: Props) {
  const { t } = useTranslation()
  const outer = useRef<HTMLDivElement>(null)
  const [scale, setScale] = useState(0.5)
  const [guides, setGuides] = useState({ v: false, h: false })

  useEffect(() => {
    const el = outer.current
    if (!el) return
    const update = () => setScale(fill ? Math.min(el.clientWidth / CANVAS_W, el.clientHeight / CANVAS_H) : el.clientWidth / CANVAS_W)
    update()
    const ro = new ResizeObserver(update)
    ro.observe(el)
    return () => ro.disconnect()
  }, [fill])

  const elements = list ? design[list] : ctx.idle ? design.idle : design.live
  const tokens = useMemo(() => tokenValues(ctx, {
    remaining: (m) => t('studio.screen.remaining', { minutes: m }), startsIn: (m) => t('studio.screen.startsIn', { minutes: m }), freeFor: (x) => t('studio.screen.freeFor', { time: x }),
    hm: (h, m) => t('studio.screen.hm', { h, m }), min: (m) => t('studio.screen.minutes', { m }),
  }), [ctx, t])

  const start = (e: RPointerEvent, el: DesignEl, mode: 'move' | Handle) => {
    if (!interactive || !onChange) return
    e.stopPropagation()
    e.preventDefault()
    onSelect?.(el.id)
    const o = { px: e.clientX, py: e.clientY, x: el.x, y: el.y, w: el.w, h: el.h }
    const sw = CANVAS_W * scale
    const sh = CANVAS_H * scale
    const round = (n: number) => Math.round(n * 100) / 100
    const move = (ev: PointerEvent) => {
      const dx = ((ev.clientX - o.px) / sw) * 100
      const dy = ((ev.clientY - o.py) / sh) * 100
      if (mode === 'move') {
        let x = o.x + dx
        let y = o.y + dy
        const g = { v: false, h: false }
        if (!ev.altKey) {
          if (Math.abs(x + el.w / 2 - 50) < 0.8) { x = 50 - el.w / 2; g.v = true }
          if (Math.abs(y + el.h / 2 - 50) < 0.8) { y = 50 - el.h / 2; g.h = true }
        }
        setGuides(g)
        onChange(el.id, { x: round(x), y: round(y) }, false)
      } else {
        let { x, y, w, h } = o
        if (mode.includes('e')) w = Math.max(1, o.w + dx)
        if (mode.includes('s')) h = Math.max(1, o.h + dy)
        if (mode.includes('w')) { w = Math.max(1, o.w - dx); x = o.x + o.w - w }
        if (mode.includes('n')) { h = Math.max(1, o.h - dy); y = o.y + o.h - h }
        onChange(el.id, { x: round(x), y: round(y), w: round(w), h: round(h) }, false)
      }
    }
    const up = () => {
      window.removeEventListener('pointermove', move)
      window.removeEventListener('pointerup', up)
      setGuides({ v: false, h: false })
      onChange(el.id, {}, true)
    }
    window.addEventListener('pointermove', move)
    window.addEventListener('pointerup', up)
  }

  const bg = design.background
  return (
    <div ref={outer} dir="ltr" onPointerDown={() => interactive && onSelect?.(null)} className={clsx('relative overflow-hidden', fill ? 'h-screen w-screen' : 'w-full', className)} style={{ ...backgroundStyle(bg), ...(fill ? {} : { aspectRatio: '16 / 9' }), ['--sc-accent' as string]: 'var(--color-gold-400)' }}>
      <div className="absolute" style={{ width: CANVAS_W, height: CANVAS_H, left: fill ? (outer.current ? (outer.current.clientWidth - CANVAS_W * scale) / 2 : 0) : 0, top: fill ? (outer.current ? (outer.current.clientHeight - CANVAS_H * scale) / 2 : 0) : 0, transform: `scale(${scale})`, transformOrigin: 'top left', ...backgroundStyle(bg) }}>
        <ImageLayer bg={bg} />
        {bg.overlay_opacity > 0 && <div className="absolute inset-0" style={{ background: bg.overlay, opacity: bg.overlay_opacity / 100 }} />}
        {guides.v && <div className="pointer-events-none absolute inset-y-0 left-1/2 z-50 w-0.5 bg-fuchsia-400/80" />}
        {guides.h && <div className="pointer-events-none absolute inset-x-0 top-1/2 z-50 h-0.5 bg-fuchsia-400/80" />}
        {elements.map((el) => {
          const selected = interactive && selectedId === el.id
          return (
            <div key={el.id} dir={ctx.lang === 'ar' ? 'rtl' : 'ltr'} onPointerDown={(e) => start(e, el, 'move')}
              className={clsx('absolute text-white', interactive && 'cursor-move')}
              style={{ left: `${el.x}%`, top: `${el.y}%`, width: `${el.w}%`, height: `${el.h}%`, outline: selected ? `${3 / Math.max(scale, 0.2)}px solid #e2b54f` : undefined, outlineOffset: 0 }}>
              <ElementView el={el} ctx={ctx} tokens={tokens} scale={scale} />
              {selected && (['nw', 'ne', 'sw', 'se'] as Handle[]).map((h) => (
                <span key={h} onPointerDown={(e) => start(e, el, h)} className="absolute rounded-sm border-2 border-gold-500 bg-white" style={{ width: 14 / Math.max(scale, 0.2), height: 14 / Math.max(scale, 0.2), [h.includes('n') ? 'top' : 'bottom']: -7 / Math.max(scale, 0.2), [h.includes('w') ? 'left' : 'right']: -7 / Math.max(scale, 0.2), cursor: h === 'nw' || h === 'se' ? 'nwse-resize' : 'nesw-resize' }} />
              ))}
            </div>
          )
        })}
      </div>
    </div>
  )
}
