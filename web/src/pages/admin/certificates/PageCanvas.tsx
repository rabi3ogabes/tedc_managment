import clsx from 'clsx'
import { QRCodeSVG } from 'qrcode.react'
import { useEffect, useRef, useState, type PointerEvent as RPointerEvent } from 'react'
import { api } from '@/lib/api'
import { FONT_STACK, PT_TO_MM, fillTokens, type CertElement } from './types'

/** Loads a template file (background / asset) through the authenticated API as an object URL. */
export function useTemplateFile(templateId: string | null, name: string | null, version?: string | null) {
  const [url, setUrl] = useState<string | null>(null)
  useEffect(() => {
    if (!templateId || !name) { setUrl(null); return }
    let href: string | null = null
    let cancelled = false
    api.get(`/admin/certificate-templates/${templateId}/file/${name}`, { responseType: 'blob' })
      .then((r) => { if (!cancelled) { href = URL.createObjectURL(r.data as Blob); setUrl(href) } })
      .catch(() => !cancelled && setUrl(null))
    return () => { cancelled = true; if (href) URL.revokeObjectURL(href) }
  }, [templateId, name, version])
  return url
}

type Handle = 'nw' | 'ne' | 'sw' | 'se'

type Props = {
  widthMm: number
  heightMm: number
  elements: CertElement[]
  background?: string | null
  /** name → object URL of the template's image assets */
  assets?: Record<string, string>
  /** sample values to fill the placeholders with; null shows the raw {{placeholders}} */
  values: Record<string, string> | null
  selectedId?: string | null
  interactive?: boolean
  onSelect?: (id: string | null) => void
  onChange?: (id: string, patch: Partial<CertElement>, commit: boolean) => void
  className?: string
}

/** The page of a certificate with its elements. Read-only for thumbnails; draggable and resizable in the designer. */
export default function PageCanvas({ widthMm, heightMm, elements, background, assets = {}, values, selectedId, interactive, onSelect, onChange, className }: Props) {
  const page = useRef<HTMLDivElement>(null)
  const [pxPerMm, setPxPerMm] = useState(3)
  const [guides, setGuides] = useState<{ v: boolean; h: boolean }>({ v: false, h: false })

  useEffect(() => {
    const el = page.current
    if (!el) return
    const update = () => setPxPerMm(el.getBoundingClientRect().width / widthMm)
    update()
    const ro = new ResizeObserver(update)
    ro.observe(el)
    return () => ro.disconnect()
  }, [widthMm])

  const start = (e: RPointerEvent, el: CertElement, mode: 'move' | Handle) => {
    if (!interactive || !onChange) return
    e.stopPropagation()
    e.preventDefault()
    onSelect?.(el.id)
    const rect = page.current!.getBoundingClientRect()
    const o = { px: e.clientX, py: e.clientY, x0: el.x, y0: el.y, w0: el.w, h0: el.h }
    ;(e.currentTarget as HTMLElement).setPointerCapture?.(e.pointerId)

    const move = (ev: PointerEvent) => {
      const dx = ((ev.clientX - o.px) / rect.width) * 100
      const dy = ((ev.clientY - o.py) / rect.height) * 100
      const snap = !ev.altKey
      if (mode === 'move') {
        let x = o.x0 + dx
        let y = o.y0 + dy
        const g = { v: false, h: false }
        if (snap) {
          const cx = x + el.w / 2
          if (Math.abs(cx - 50) < 0.8) { x = 50 - el.w / 2; g.v = true }
          const cy = y + el.h / 2
          if (Math.abs(cy - 50) < 0.8) { y = 50 - el.h / 2; g.h = true }
        }
        setGuides(g)
        onChange(el.id, { x: round(x), y: round(y) }, false)
      } else {
        let { x0: x, y0: y, w0: w, h0: h } = o
        if (mode.includes('e')) w = Math.max(1, o.w0 + dx)
        if (mode.includes('s')) h = Math.max(0.6, o.h0 + dy)
        if (mode.includes('w')) { w = Math.max(1, o.w0 - dx); x = o.x0 + o.w0 - w }
        if (mode.includes('n')) { h = Math.max(0.6, o.h0 - dy); y = o.y0 + o.h0 - h }
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

  return (
    <div
      ref={page}
      dir="ltr"
      onPointerDown={() => interactive && onSelect?.(null)}
      className={clsx('relative w-full select-none overflow-hidden bg-white', className)}
      style={{ aspectRatio: `${widthMm} / ${heightMm}` }}
    >
      {background && <img src={background} alt="" draggable={false} className="pointer-events-none absolute inset-0 size-full object-fill" />}
      {guides.v && <div className="pointer-events-none absolute inset-y-0 left-1/2 z-20 w-px bg-fuchsia-500/70" />}
      {guides.h && <div className="pointer-events-none absolute inset-x-0 top-1/2 z-20 h-px bg-fuchsia-500/70" />}
      {elements.map((el) => {
        const selected = interactive && selectedId === el.id
        const box = { left: `${el.x}%`, top: `${el.y}%`, width: `${el.w}%`, height: `${el.h}%` }
        return (
          <div
            key={el.id}
            style={box}
            onPointerDown={(e) => start(e, el, 'move')}
            className={clsx('absolute', interactive && 'cursor-move', selected ? 'z-10 outline outline-2 outline-offset-0 outline-gold-500' : interactive && 'hover:outline hover:outline-1 hover:outline-gold-300')}
          >
            <Content el={el} pxPerMm={pxPerMm} values={values} assets={assets} />
            {selected && (['nw', 'ne', 'sw', 'se'] as Handle[]).map((h) => (
              <span
                key={h}
                onPointerDown={(e) => start(e, el, h)}
                className={clsx('absolute size-3 rounded-sm border-2 border-gold-500 bg-white', h.includes('n') ? '-top-1.5' : '-bottom-1.5', h.includes('w') ? '-left-1.5' : '-right-1.5', h === 'nw' || h === 'se' ? 'cursor-nwse-resize' : 'cursor-nesw-resize')}
              />
            ))}
          </div>
        )
      })}
    </div>
  )
}

const round = (n: number) => Math.round(n * 100) / 100

function Content({ el, pxPerMm, values, assets }: { el: CertElement; pxPerMm: number; values: Record<string, string> | null; assets: Record<string, string> }) {
  if (el.type === 'rect') {
    return <div className="size-full" style={{ background: el.fill || 'transparent', border: el.stroke && (el.stroke_width ?? 0) > 0 ? `${(el.stroke_width ?? 0) * pxPerMm}px solid ${el.stroke}` : undefined, borderRadius: (el.radius ?? 0) * pxPerMm }} />
  }
  if (el.type === 'qr') {
    return <QRCodeSVG value="https://verify.sample/certificate" style={{ width: '100%', height: '100%' }} fgColor="#1a1a1a" bgColor="transparent" />
  }
  if (el.type === 'image') {
    const src = el.src ? assets[el.src] : undefined
    return src ? <img src={src} alt="" draggable={false} className="size-full object-contain" /> : <div className="grid size-full place-items-center border border-dashed border-slate-400 bg-slate-50 text-[10px] text-slate-400">IMG</div>
  }
  const size = (el.size ?? 13) * PT_TO_MM * pxPerMm
  const justify = el.valign === 'top' ? 'flex-start' : el.valign === 'bottom' ? 'flex-end' : 'center'
  return (
    <div className="flex size-full" style={{ alignItems: justify }}>
      <div
        dir={el.dir ?? 'rtl'}
        className="w-full whitespace-pre-wrap break-words"
        style={{ fontFamily: FONT_STACK[el.font ?? 'xbriyaz'], fontSize: size, color: el.color || '#000', fontWeight: el.bold ? 700 : 400, textAlign: el.align ?? 'center', lineHeight: el.line ?? 1.4 }}
      >
        {fillTokens(el.text ?? '', values)}
      </div>
    </div>
  )
}
