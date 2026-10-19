import clsx from 'clsx'
import { Hourglass, Sparkles } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { fmtClock } from '../api'
import type { Overlay, Pointer, Show, Source, Stroke, Tool, Zoom } from './state'

type Interactive = {
  tool: Tool
  color: string
  size: number
  onStrokes: (index: number, strokes: Stroke[]) => void
  onPointer: (p: Pointer) => void
  onZoom: (z: Zoom) => void
}

/**
 * One slide on a black stage with everything the presenter draws over it: ink, the laser, the spotlight, the magnifier, the black
 * and white screens and the centre cards (who was picked, the break timer). The presenter's own stage is interactive; the audience's
 * is the same picture, read-only.
 */
export default function Stage({ source, show, pointer, width, height, interactive, className }: { source: Source; show: Show; pointer: Pointer; width: number; height: number; interactive?: Interactive; className?: string }) {
  const box = useRef<HTMLDivElement>(null)
  const drawing = useRef<Stroke | null>(null)
  const [live, setLive] = useState<Stroke | null>(null)
  const w = Math.min(width, height * (16 / 9))
  const h = w * (9 / 16)
  const strokes = show.strokes[show.index] ?? []
  const norm = (e: React.PointerEvent) => {
    const r = box.current!.getBoundingClientRect()
    return [Math.max(0, Math.min(1, (e.clientX - r.left) / r.width)), Math.max(0, Math.min(1, (e.clientY - r.top) / r.height))] as [number, number]
  }
  const tool = interactive?.tool ?? 'none'

  const down = (e: React.PointerEvent) => {
    if (!interactive) return
    if (tool === 'pen' || tool === 'marker') {
      e.currentTarget.setPointerCapture(e.pointerId)
      drawing.current = { tool, color: interactive.color, w: tool === 'marker' ? interactive.size * 3 : interactive.size, pts: [norm(e)] }
      setLive(drawing.current)
    } else if (tool === 'zoom') {
      const [x, y] = norm(e)
      interactive.onZoom(show.zoom ? null : { x, y, scale: 2.4 })
    }
  }
  const move = (e: React.PointerEvent) => {
    if (!interactive) return
    if (drawing.current) { drawing.current = { ...drawing.current, pts: [...drawing.current.pts, norm(e)] }; setLive(drawing.current) }
    else if (tool === 'laser' || tool === 'spot') { const [x, y] = norm(e); interactive.onPointer({ x, y, mode: tool }) }
  }
  const up = () => {
    if (!interactive || !drawing.current) return
    const done = drawing.current
    drawing.current = null; setLive(null)
    if (done.pts.length > 1) interactive.onStrokes?.(show.index, [...strokes, done])
  }

  return (
    <div ref={box} className={clsx('relative overflow-hidden bg-black', className)} style={{ width: w, height: h, touchAction: tool === 'pen' || tool === 'marker' ? 'none' : undefined }}
      onPointerDown={down} onPointerMove={move} onPointerUp={up} onPointerLeave={() => { if (tool === 'laser' || tool === 'spot') interactive?.onPointer(null) }}>
      <div className="absolute inset-0 grid place-items-center" style={show.zoom ? { transform: `scale(${show.zoom.scale})`, transformOrigin: `${show.zoom.x * 100}% ${show.zoom.y * 100}%`, transition: 'transform .35s cubic-bezier(.2,.7,.2,1)' } : { transition: 'transform .35s cubic-bezier(.2,.7,.2,1)' }}>
        {source.ready ? source.render(show.index, w, h) : <div className="text-sm text-white/50">…</div>}
        <Ink strokes={live ? [...strokes, live] : strokes} />
      </div>
      {pointer?.mode === 'spot' && <div className="pointer-events-none absolute inset-0" style={{ background: `radial-gradient(circle ${h * 0.22}px at ${pointer.x * 100}% ${pointer.y * 100}%, transparent 0, transparent 96%, rgba(0,0,0,.8) 100%)` }} />}
      {pointer?.mode === 'laser' && <span className="pointer-events-none absolute size-5 -translate-x-1/2 -translate-y-1/2 rounded-full bg-red-500 shadow-[0_0_18px_8px_rgba(239,68,68,.65)]" style={{ left: `${pointer.x * 100}%`, top: `${pointer.y * 100}%` }} />}
      {show.blackout !== 'off' && <div className="absolute inset-0 z-20" style={{ background: show.blackout === 'black' ? '#000' : '#fff' }} />}
      <OverlayCard overlay={show.overlay} />
    </div>
  )
}

function Ink({ strokes }: { strokes: Stroke[] }) {
  if (!strokes.length) return null
  return (
    <svg className="pointer-events-none absolute inset-0 size-full" viewBox="0 0 1000 562.5" preserveAspectRatio="none">
      {strokes.map((s, i) => (
        <polyline key={i} points={s.pts.map(([x, y]) => `${x * 1000},${y * 562.5}`).join(' ')} fill="none" stroke={s.color} strokeWidth={s.w * 2} strokeLinecap="round" strokeLinejoin="round" opacity={s.tool === 'marker' ? 0.42 : 1} />
      ))}
    </svg>
  )
}

function OverlayCard({ overlay }: { overlay: Overlay }) {
  const [now, setNow] = useState(Date.now())
  useEffect(() => {
    if (overlay?.kind !== 'timer') return
    const id = window.setInterval(() => setNow(Date.now()), 250)
    return () => window.clearInterval(id)
  }, [overlay])
  if (!overlay) return null
  if (overlay.kind === 'timer') {
    const left = overlay.endsAt - now
    return (
      <div className="absolute inset-0 z-30 grid place-items-center bg-navy-950/90 text-center text-white">
        <div><Hourglass className="mx-auto size-10 text-gold-300" /><div className="mt-3 text-lg font-semibold text-white/80" dir="auto">{overlay.label}</div><div className={clsx('mt-2 font-display font-bold tabular-nums', left <= 0 ? 'text-6xl text-gold-300' : 'text-8xl')} dir="ltr">{left <= 0 ? '00:00' : fmtClock(left)}</div></div>
      </div>
    )
  }
  if (overlay.kind === 'picker') {
    return <div className="absolute inset-0 z-30 grid place-items-center bg-navy-950/95 text-center text-white"><div className="px-6"><Sparkles className="mx-auto size-10 text-gold-300" /><div className="mt-4 font-display text-6xl font-bold leading-tight text-gold-200" dir="auto">{overlay.name}</div></div></div>
  }
  return <div className="absolute inset-0 z-30 grid place-items-center bg-navy-950/90 px-10 text-center text-4xl font-bold leading-snug text-white" dir="auto">{overlay.text}</div>
}
