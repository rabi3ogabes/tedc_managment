import clsx from 'clsx'
import { Crosshair, Minus, Plus } from 'lucide-react'
import { useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { corridorPath, type CentreMap, type FloorId, type Place, type Pt } from './engine'

type Box = [number, number, number, number]
export type Marker = { at: Pt; kind: 'you' | 'target' | 'sign' | 'entrance' | 'door' | 'step'; label?: string; active?: boolean }

/**
 * The centre's floor plan as a vector drawing in the platform's colours (docs/wayfinding.md): stone corridors, room cards, the building's
 * cores, stairs and lifts, labelled in the brand typeface. It pans and zooms with a finger, a mouse or the buttons, and its camera keeps
 * `focus` in view (the visitor and the destination), gliding whenever it changes.
 */
export default function FloorPlan({ map, floor, route, markers = [], highlight, dim, focus, inset, onPick, picking, className, children, svgRef }: {
  map: CentreMap
  /** screen pixels covered by panels on each side: the camera frames `focus` in the space that stays visible */
  inset?: { top?: number; right?: number; bottom?: number; left?: number }
  floor: FloorId
  route?: Pt[] | null
  markers?: Marker[]
  highlight?: string | null
  dim?: boolean
  focus?: Pt[] | null
  onPick?: (hit: { place: Place | null; at: Pt }) => void
  picking?: boolean
  className?: string
  children?: ReactNode
  svgRef?: React.Ref<SVGSVGElement>
}) {
  const { t, i18n } = useTranslation()
  const en = i18n.language === 'en'
  const { w, h } = map.size
  const box = useRef<HTMLDivElement>(null)
  const [size, setSize] = useState({ w: 1, h: 1 })
  const [view, setView] = useState<Box>([0, 0, w, h])
  const viewRef = useRef(view)
  viewRef.current = view
  const anim = useRef<number>(0)
  const [follow, setFollow] = useState(true)

  useEffect(() => {
    const el = box.current
    if (!el) return
    const ro = new ResizeObserver(([e]) => setSize({ w: e.contentRect.width || 1, h: e.contentRect.height || 1 }))
    ro.observe(el)
    return () => ro.disconnect()
  }, [])

  // The camera frames a box of the plan inside the part of the screen no panel covers, never stretched, never closer than a few rooms
  // nor further than the whole floor.
  const ins = { top: inset?.top ?? 0, right: inset?.right ?? 0, bottom: inset?.bottom ?? 0, left: inset?.left ?? 0 }
  const fit = (b: Box): Box => {
    const aw = Math.max(120, size.w - ins.left - ins.right), ah = Math.max(120, size.h - ins.top - ins.bottom)
    const kMin = w / 3.4 / Math.max(aw, 320)
    const kMax = Math.max(w / aw, h / ah) * 1.08
    const k = Math.min(kMax, Math.max(kMin, b[2] / aw, b[3] / ah))
    const cx = b[0] + b[2] / 2, cy = b[1] + b[3] / 2
    return [cx - (ins.left + aw / 2) * k, cy - (ins.top + ah / 2) * k, size.w * k, size.h * k]
  }

  const glide = (to: Box) => {
    cancelAnimationFrame(anim.current)
    const from = viewRef.current
    const start = performance.now()
    const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches
    const dur = reduce ? 0 : 650
    const step = (now: number) => {
      const k = dur ? Math.min(1, (now - start) / dur) : 1
      const e = 1 - Math.pow(1 - k, 3)
      setView(from.map((v, i) => v + (to[i] - v) * e) as Box)
      if (k < 1) anim.current = requestAnimationFrame(step)
    }
    anim.current = requestAnimationFrame(step)
  }

  // Keyed on the points themselves: the caller builds a new array on every render.
  const focusKey = focus?.map((p) => `${Math.round(p[0])},${Math.round(p[1])}`).join(';') ?? ''
  const focusBox = useMemo<Box | null>(() => {
    if (!focusKey) return null
    const pts = focusKey.split(';').map((p) => p.split(',').map(Number))
    const xs = pts.map((p) => p[0]), ys = pts.map((p) => p[1])
    const pad = 70
    return [Math.min(...xs) - pad, Math.min(...ys) - pad, Math.max(...xs) - Math.min(...xs) + pad * 2, Math.max(...ys) - Math.min(...ys) + pad * 2]
  }, [focusKey])
  const insetKey = `${ins.top},${ins.right},${ins.bottom},${ins.left}`

  const recentre = () => glide(fit(focusBox ?? [0, 0, w, h]))
  // The first framing (once the container has a size) is immediate, so the plan never appears empty; later ones glide.
  const framed = useRef(false)
  useEffect(() => {
    if (size.w <= 1) return
    if (!framed.current) { framed.current = true; setView(fit(focusBox ?? [0, 0, w, h])); return }
    if (follow || !focusBox) recentre()
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [focusBox, size.w, size.h, floor, insetKey])
  useEffect(() => () => cancelAnimationFrame(anim.current), [])

  // ------------------------------------------------------------------ gestures
  const pointers = useRef(new Map<number, { x: number; y: number }>())
  const moved = useRef(0)
  const toMap = (cx: number, cy: number): Pt => {
    const r = box.current!.getBoundingClientRect()
    const v = viewRef.current
    return [v[0] + ((cx - r.left) / r.width) * v[2], v[1] + ((cy - r.top) / r.height) * v[3]]
  }
  const zoomAt = (factor: number, cx?: number, cy?: number) => {
    const r = box.current!.getBoundingClientRect()
    const v = viewRef.current
    const [mx, my] = cx === undefined ? [v[0] + v[2] / 2, v[1] + v[3] / 2] : toMap(cx, cy!)
    const nw = Math.min(Math.max(v[2] * factor, w / 8), Math.max(w, h * (r.width / r.height)) * 1.6)
    const k = nw / v[2]
    setView([mx - (mx - v[0]) * k, my - (my - v[1]) * k, nw, v[3] * k])
    setFollow(false)
  }

  const onDown = (e: React.PointerEvent) => {
    try { (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId) } catch { /* a pointer the browser no longer tracks */ }
    pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY })
    moved.current = 0
    cancelAnimationFrame(anim.current)
  }
  const onMove = (e: React.PointerEvent) => {
    const prev = pointers.current.get(e.pointerId)
    if (!prev) return
    const r = box.current!.getBoundingClientRect()
    if (pointers.current.size === 2) {
      const [a, b] = [...pointers.current.values()]
      const before = Math.hypot(a.x - b.x, a.y - b.y)
      pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY })
      const [c, d] = [...pointers.current.values()]
      const after = Math.hypot(c.x - d.x, c.y - d.y)
      if (before > 0 && after > 0) zoomAt(before / after, (c.x + d.x) / 2, (c.y + d.y) / 2)
      moved.current += 10
      return
    }
    const dx = e.clientX - prev.x, dy = e.clientY - prev.y
    moved.current += Math.abs(dx) + Math.abs(dy)
    pointers.current.set(e.pointerId, { x: e.clientX, y: e.clientY })
    if (moved.current > 4) {
      const v = viewRef.current
      setView([v[0] - (dx / r.width) * v[2], v[1] - (dy / r.height) * v[3], v[2], v[3]])
      setFollow(false)
    }
  }
  const onUp = (e: React.PointerEvent) => {
    pointers.current.delete(e.pointerId)
    if (moved.current <= 4 && onPick && pointers.current.size === 0) {
      const at = toMap(e.clientX, e.clientY)
      const place = map.places.find((p) => p.floor === floor && inside(p, at)) ?? null
      onPick({ place, at })
    }
  }

  // ------------------------------------------------------------------ drawing
  // The traced, smooth edge of the corridors when the map carries it; else the corridor cells themselves.
  const floorPath = useMemo(() => map.floors.find((f) => f.id === floor)?.outline || corridorPath(map, floor), [map, floor])
  const places = map.places.filter((p) => p.floor === floor)
  const shapes = map.shapes.filter((s) => s.floor === floor)
  const connectors = map.connectors.filter((c) => c.rect[floor])
  const routeD = route && route.length > 1 ? 'M' + route.map((p) => `${p[0].toFixed(1)} ${p[1].toFixed(1)}`).join('L') : null
  const scale = view[2] / Math.max(1, size.w)   // map units per screen pixel: markers and the route keep their size on screen

  return (
    <div ref={box} className={clsx('relative touch-none select-none overflow-hidden', picking ? 'cursor-crosshair' : 'cursor-grab active:cursor-grabbing', className)}
      onPointerDown={onDown} onPointerMove={onMove} onPointerUp={onUp} onPointerCancel={onUp}
      onWheel={(e) => zoomAt(e.deltaY > 0 ? 1.12 : 1 / 1.12, e.clientX, e.clientY)} onDoubleClick={(e) => zoomAt(0.6, e.clientX, e.clientY)}>
      <svg ref={svgRef} viewBox={view.join(' ')} className="size-full" role="img" aria-label={t('wayfinding.planOf', { floor: (en ? map.floors.find((f) => f.id === floor)?.en : map.floors.find((f) => f.id === floor)?.ar) ?? '' })}
        style={{ fontFamily: 'var(--font-sans)' }} xmlns="http://www.w3.org/2000/svg">
        <defs>
          <filter id="wf-lift" x="-10%" y="-10%" width="120%" height="130%"><feDropShadow dx="0" dy="3" stdDeviation="4" floodColor="#1a1a1a" floodOpacity="0.12" /></filter>
          <filter id="wf-glow" x="-50%" y="-50%" width="200%" height="200%"><feGaussianBlur stdDeviation="6" /></filter>
          <pattern id="wf-void" width="10" height="10" patternUnits="userSpaceOnUse" patternTransform="rotate(45)"><rect width="10" height="10" fill="var(--color-ivory)" /><line x1="0" y1="0" x2="0" y2="10" stroke="var(--color-navy-100)" strokeWidth="3" /></pattern>
          <linearGradient id="wf-core" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="#3b3f4a" /><stop offset="1" stopColor="#23262e" /></linearGradient>
          <linearGradient id="wf-room" x1="0" y1="0" x2="0" y2="1"><stop offset="0" stopColor="var(--color-surface)" /><stop offset="1" stopColor="var(--color-ivory)" /></linearGradient>
        </defs>

        {/* the floor: corridors in warm stone, with a hairline edge */}
        <g filter="url(#wf-lift)">
          <path d={floorPath} fillRule="evenodd" fill="var(--color-gold-200)" stroke="var(--color-gold-200)" strokeWidth={7} strokeLinejoin="round" />
          <path d={floorPath} fillRule="evenodd" fill="#fdfbf7" stroke="#fdfbf7" strokeWidth={3} strokeLinejoin="round" />
        </g>

        <g opacity={dim ? 0.55 : 1} style={{ transition: 'opacity .4s' }}>
          {shapes.map((s, i) => s.kind === 'void'
            ? <polygon key={i} points={s.points!.map((p) => p.join(',')).join(' ')} fill="url(#wf-void)" stroke="var(--color-navy-100)" strokeWidth={1.5} />
            : <path key={i} d={chamfered(s.rect!, s.chamfer ?? 0)} fill="url(#wf-core)" filter="url(#wf-lift)" />)}
          {places.map((p) => <Room key={p.id} p={p} en={en} lit={highlight === p.id} t={t} />)}
          {connectors.map((c) => <Connector key={c.id} kind={c.kind} rect={c.rect[floor]} />)}
        </g>

        {routeD && (
          <g pointerEvents="none">
            <path d={routeD} fill="none" stroke="var(--color-navy-500)" strokeOpacity={0.35} strokeWidth={16 * scale} strokeLinecap="round" strokeLinejoin="round" filter="url(#wf-glow)" />
            <path d={routeD} fill="none" stroke="var(--color-navy-800)" strokeWidth={8 * scale} strokeLinecap="round" strokeLinejoin="round" />
            <path d={routeD} fill="none" stroke="var(--color-gold-300)" strokeWidth={2.6 * scale} strokeLinecap="round" strokeLinejoin="round" strokeDasharray={`${10 * scale} ${14 * scale}`} className="wf-flow" />
          </g>
        )}

        {markers.map((m, i) => <MarkerGlyph key={i} m={m} s={scale} />)}
      </svg>

      <div className="pointer-events-none absolute inset-0">{children}</div>

      <div className="absolute bottom-4 end-4 flex flex-col overflow-hidden rounded-2xl bg-white/90 shadow-glass ring-1 ring-navy-100 backdrop-blur" onPointerDown={(e) => e.stopPropagation()}>
        <button type="button" onClick={() => zoomAt(0.75)} className="grid size-11 place-items-center text-navy-900 transition hover:bg-gold-50" aria-label={t('wayfinding.zoomIn')}><Plus className="size-5" /></button>
        <span className="h-px bg-navy-100" />
        <button type="button" onClick={() => zoomAt(1.33)} className="grid size-11 place-items-center text-navy-900 transition hover:bg-gold-50" aria-label={t('wayfinding.zoomOut')}><Minus className="size-5" /></button>
        <span className="h-px bg-navy-100" />
        <button type="button" onClick={() => { setFollow(true); recentre() }} className={clsx('grid size-11 place-items-center transition hover:bg-gold-50', follow ? 'text-gold-700' : 'text-navy-900')} aria-label={t('wayfinding.recentre')} aria-pressed={follow}><Crosshair className="size-5" /></button>
      </div>
    </div>
  )
}

function inside(p: Place, [x, y]: Pt) {
  if (p.outline) {
    let inPoly = false
    for (let i = 0, j = p.outline.length - 1; i < p.outline.length; j = i++) {
      const [xi, yi] = p.outline[i], [xj, yj] = p.outline[j]
      if (yi > y !== yj > y && x < ((xj - xi) * (y - yi)) / (yj - yi) + xi) inPoly = !inPoly
    }
    return inPoly
  }
  const [rx, ry, rw, rh] = p.rect
  return x >= rx && x <= rx + rw && y >= ry && y <= ry + rh
}

function chamfered([x, y, w, h]: [number, number, number, number], c: number) {
  if (!c) return `M${x + 6} ${y}h${w - 12}q6 0 6 6v${h - 12}q0 6 -6 6h${-(w - 12)}q-6 0 -6 -6v${-(h - 12)}q0 -6 6 -6z`
  return `M${x + c} ${y}h${w - 2 * c}l${c} ${c}v${h - 2 * c}l${-c} ${c}h${-(w - 2 * c)}l${-c} ${-c}v${-(h - 2 * c)}z`
}

const KIND: Record<Place['kind'], { fill: string; stroke: string; ink: string; tag: string }> = {
  train: { fill: 'url(#wf-room)', stroke: 'var(--color-navy-100)', ink: 'var(--color-navy-900)', tag: 'var(--color-navy-700)' },
  lab: { fill: 'var(--color-navy-50)', stroke: 'var(--color-navy-200)', ink: 'var(--color-navy-900)', tag: 'var(--color-navy-700)' },
  meet: { fill: 'var(--color-gold-100)', stroke: 'var(--color-gold-300)', ink: 'var(--color-gold-900)', tag: 'var(--color-gold-800)' },
  public: { fill: 'var(--color-gold-50)', stroke: 'var(--color-gold-300)', ink: 'var(--color-gold-900)', tag: 'var(--color-gold-800)' },
  office: { fill: 'var(--color-ivory-dark)', stroke: 'var(--color-navy-100)', ink: 'var(--color-navy-800)', tag: 'var(--color-navy-600)' },
  pray: { fill: '#ecf7f1', stroke: '#b7e0c8', ink: '#14532d', tag: '#15803d' },
  wc: { fill: '#f1f4f8', stroke: '#d6dde8', ink: '#334155', tag: '#64748b' },
}

function Room({ p, en, lit, t }: { p: Place; en: boolean; lit: boolean; t: (k: string) => string }) {
  const k = KIND[p.kind]
  const [x, y, w, h] = p.rect
  const cx = p.outline ? p.outline.reduce((s, q) => s + q[0], 0) / p.outline.length : x + w / 2
  const cy = p.outline ? p.outline.reduce((s, q) => s + q[1], 0) / p.outline.length : y + h / 2
  const tall = h > w * 1.35
  const span = tall ? h : w
  const thin = tall ? w : h
  const name = en ? p.en : p.ar
  const shape = p.outline
    ? <polygon points={p.outline.map((q) => q.join(',')).join(' ')} />
    : <rect x={x + 2} y={y + 2} width={w - 4} height={h - 4} rx={10} />
  return (
    <g>
      {lit && <g className="wf-pulse" fill="none" stroke="var(--color-gold-500)" strokeWidth={10} opacity={0.5} filter="url(#wf-glow)">{shape}</g>}
      <g fill={k.fill} stroke={lit ? 'var(--color-gold-600)' : k.stroke} strokeWidth={lit ? 4 : 1.5} filter="url(#wf-lift)">{shape}</g>
      <g transform={tall ? `rotate(-90 ${cx} ${cy})` : undefined} textAnchor="middle" fill={k.ink} style={{ pointerEvents: 'none' }}>
        {p.number && (p.kind === 'train' || p.kind === 'lab') ? (
          <>
            <text x={cx} y={cy - Math.min(thin, 90) * 0.2} fontSize={Math.min(13, thin * 0.16)} fontWeight={600} fill={k.tag} dominantBaseline="central">{p.kind === 'lab' ? t('wayfinding.kind.lab') : t('wayfinding.kind.train')}</text>
            <text x={cx} y={cy + Math.min(thin, 90) * 0.13} fontSize={Math.min(40, thin * 0.42, span * 0.3)} fontWeight={800} dominantBaseline="central">{p.number}</text>
          </>
        ) : p.kind === 'wc' || p.kind === 'pray' ? (
          <KindIcon kind={p.kind} cx={tall ? cx : cx} cy={cy} size={Math.min(26, thin * 0.42)} color={k.tag} />
        ) : (
          <text x={cx} y={cy} fontSize={Math.max(10, Math.min(22, (span / Math.max(4, name.length)) * 1.5, thin * 0.24))} fontWeight={700} dominantBaseline="central">{name}</text>
        )}
      </g>
    </g>
  )
}

function KindIcon({ kind, cx, cy, size, color }: { kind: 'wc' | 'pray'; cx: number; cy: number; size: number; color: string }) {
  const s = size / 24
  return (
    <g transform={`translate(${cx - size / 2} ${cy - size / 2}) scale(${s})`} fill="none" stroke={color} strokeWidth={1.8} strokeLinecap="round" strokeLinejoin="round">
      {kind === 'wc'
        ? <><circle cx="7" cy="4" r="2" /><path d="M7 7v6m-2-6h4v6H5zM7 13v7" /><circle cx="17" cy="4" r="2" /><path d="M14 16l3-9 3 9h-6zM17 16v4" /></>
        : <><path d="M12 2a6 6 0 1 0 6 8 5 5 0 1 1-6-8z" /><path d="M4 22h16M6 22V14h12v8" /></>}
    </g>
  )
}

function Connector({ kind, rect }: { kind: 'stairs' | 'lift'; rect: [number, number, number, number] }) {
  const [x, y, w, h] = rect
  const cx = x + w / 2, cy = y + h / 2
  const size = Math.min(w, h, kind === 'lift' ? 30 : 46)
  return (
    <g filter="url(#wf-lift)" style={{ pointerEvents: 'none' }}>
      <rect x={cx - size / 2} y={cy - size / 2} width={size} height={size} rx={size * 0.26} fill={kind === 'lift' ? 'var(--color-navy-900)' : 'var(--color-surface)'} stroke={kind === 'lift' ? 'none' : 'var(--color-navy-200)'} strokeWidth={1.5} />
      <g transform={`translate(${cx - size * 0.32} ${cy - size * 0.32}) scale(${(size * 0.64) / 24})`} fill="none" stroke={kind === 'lift' ? 'var(--color-gold-300)' : 'var(--color-navy-800)'} strokeWidth={2} strokeLinecap="round" strokeLinejoin="round">
        {kind === 'stairs' ? <path d="M3 20h5v-5h5v-5h5V5h3" /> : <><path d="M8 9l4-4 4 4M8 15l4 4 4-4" /></>}
      </g>
    </g>
  )
}

function MarkerGlyph({ m, s }: { m: Marker; s: number }) {
  const [x, y] = m.at
  if (m.kind === 'you') {
    return (
      <g pointerEvents="none">
        <circle cx={x} cy={y} r={26 * s} fill="var(--color-navy-500)" opacity={0.18} className="wf-ping" />
        <circle cx={x} cy={y} r={11 * s} fill="var(--color-navy-700)" stroke="#fff" strokeWidth={4 * s} />
      </g>
    )
  }
  if (m.kind === 'target') {
    return (
      <g pointerEvents="none" transform={`translate(${x} ${y}) scale(${s})`}>
        <ellipse cx={0} cy={2} rx={9} ry={3.5} fill="#000" opacity={0.18} />
        <path d="M0 0c-9-12-15-19-15-27a15 15 0 0 1 30 0c0 8-6 15-15 27z" fill="var(--color-gold-500)" stroke="#fff" strokeWidth={3} />
        <circle cx={0} cy={-27} r={5.5} fill="#fff" />
      </g>
    )
  }
  const color = m.kind === 'sign' ? 'var(--color-gold-600)' : m.kind === 'entrance' ? '#0f766e' : m.kind === 'door' ? 'var(--color-gold-600)' : 'var(--color-navy-700)'
  return (
    <g pointerEvents="none" transform={`translate(${x} ${y}) scale(${s})`}>
      <circle r={m.active ? 10 : 7} fill={color} stroke="#fff" strokeWidth={3} />
      {m.label && <text y={-16} textAnchor="middle" fontSize={13} fontWeight={700} fill="var(--color-navy-900)" stroke="#fff" strokeWidth={4} paintOrder="stroke">{m.label}</text>}
    </g>
  )
}
