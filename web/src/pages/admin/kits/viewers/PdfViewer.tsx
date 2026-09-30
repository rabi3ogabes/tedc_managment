import clsx from 'clsx'
import * as pdfjs from 'pdfjs-dist'
import { Check, Minus, Plus } from 'lucide-react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Spinner } from '@/components/ui'
import { severityTone } from '../comments/api'
import type { Anchor, KitComment } from '../types'

pdfjs.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).toString()

type Props = {
  url: string
  reviewing: boolean
  comments: KitComment[]
  activeId: string | null
  draft: Anchor | null
  currentPage: number
  onPage: (p: number) => void
  onPlace: (a: Anchor) => void
  onPinClick: (c: KitComment) => void
  scrollTo: number | null
}

/** PDF pages drawn to canvases; click a page in review mode to pin a comment at that spot. */
export default function PdfViewer({ url, reviewing, comments, activeId, draft, currentPage, onPage, onPlace, onPinClick, scrollTo }: Props) {
  const { t } = useTranslation()
  const [doc, setDoc] = useState<pdfjs.PDFDocumentProxy | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [scale, setScale] = useState(1.25)
  const pages = useRef<Record<number, HTMLDivElement | null>>({})
  const container = useRef<HTMLDivElement>(null)

  useEffect(() => {
    let cancelled = false
    const task = pdfjs.getDocument({ url })
    task.promise.then((d) => { if (!cancelled) setDoc(d) }).catch((e: Error) => { if (!cancelled) setError(e.message) })
    return () => { cancelled = true; void task.destroy() }
  }, [url])

  // Track the page in view.
  useEffect(() => {
    const root = container.current
    if (!root || !doc) return
    const io = new IntersectionObserver((entries) => {
      const best = entries.filter((e) => e.isIntersecting).sort((a, b) => b.intersectionRatio - a.intersectionRatio)[0]
      if (best) onPage(Number((best.target as HTMLElement).dataset.page))
    }, { root, threshold: [0.4, 0.7] })
    Object.values(pages.current).forEach((el) => el && io.observe(el))
    return () => io.disconnect()
  }, [doc, onPage, scale])

  useEffect(() => { if (scrollTo) pages.current[scrollTo]?.scrollIntoView({ behavior: 'smooth', block: 'start' }) }, [scrollTo])

  if (error) return <div className="grid h-full place-items-center p-8 text-sm text-danger">{t('kits.viewer.pdfFailed')}: {error}</div>
  if (!doc) return <Spinner className="h-full" />

  return (
    <div className="relative flex h-full min-h-0 flex-col">
      <div className="flex items-center justify-center gap-2 border-b border-navy-100 bg-white px-3 py-1.5 text-xs text-slate-500">
        <button type="button" className="grid size-7 place-items-center rounded-md hover:bg-navy-100/70" onClick={() => setScale((s) => Math.max(0.6, s - 0.2))} aria-label={t('kits.editor.zoomOut')}><Minus className="size-4" /></button>
        <span className="w-12 text-center font-semibold" dir="ltr">{Math.round(scale * 80)}%</span>
        <button type="button" className="grid size-7 place-items-center rounded-md hover:bg-navy-100/70" onClick={() => setScale((s) => Math.min(3, s + 0.2))} aria-label={t('kits.editor.zoomIn')}><Plus className="size-4" /></button>
        <span className="ms-4 font-semibold text-navy-900" dir="ltr">{currentPage} / {doc.numPages}</span>
        {reviewing && <span className="ms-4 font-semibold text-gold-700">{t('kits.viewer.pdfHint')}</span>}
      </div>
      <div ref={container} className="min-h-0 flex-1 space-y-5 overflow-auto bg-[#E9E4D9] p-6">
        {Array.from({ length: doc.numPages }, (_, i) => i + 1).map((n) => (
          <PdfPage key={n} doc={doc} n={n} scale={scale} reviewing={reviewing} setRef={(el) => { pages.current[n] = el }}
            comments={comments.filter((c) => c.anchor?.page === n)} activeId={activeId} draft={draft?.page === n ? draft : null} onPlace={onPlace} onPinClick={onPinClick} />
        ))}
      </div>
    </div>
  )
}

function PdfPage({ doc, n, scale, reviewing, setRef, comments, activeId, draft, onPlace, onPinClick }: { doc: pdfjs.PDFDocumentProxy; n: number; scale: number; reviewing: boolean; setRef: (el: HTMLDivElement | null) => void; comments: KitComment[]; activeId: string | null; draft: Anchor | null; onPlace: (a: Anchor) => void; onPinClick: (c: KitComment) => void }) {
  const canvas = useRef<HTMLCanvasElement>(null)
  const host = useRef<HTMLDivElement>(null)
  const [size, setSize] = useState<{ w: number; h: number } | null>(null)
  const [visible, setVisible] = useState(n <= 2)

  useEffect(() => {
    const el = host.current
    if (!el) return
    const io = new IntersectionObserver(([e]) => { if (e.isIntersecting) setVisible(true) }, { rootMargin: '600px' })
    io.observe(el)
    return () => io.disconnect()
  }, [])

  useEffect(() => {
    let task: pdfjs.RenderTask | null = null
    let cancelled = false
    void doc.getPage(n).then((page) => {
      const vp = page.getViewport({ scale })
      if (!cancelled) setSize({ w: vp.width, h: vp.height })
      if (!visible || cancelled || !canvas.current) return
      const ratio = window.devicePixelRatio || 1
      const c = canvas.current
      c.width = Math.floor(vp.width * ratio)
      c.height = Math.floor(vp.height * ratio)
      c.style.width = `${vp.width}px`
      c.style.height = `${vp.height}px`
      const ctx = c.getContext('2d')
      if (!ctx) return
      task = page.render({ canvasContext: ctx, viewport: page.getViewport({ scale: scale * ratio }), canvas: c })
      task.promise.catch(() => undefined)
    })
    return () => { cancelled = true; task?.cancel() }
  }, [doc, n, scale, visible])

  const place = (e: React.PointerEvent) => {
    if (!reviewing || !size) return
    const r = (e.currentTarget as HTMLElement).getBoundingClientRect()
    onPlace({ type: 'point', page: n, x: Math.round(((e.clientX - r.left) / r.width) * 1000) / 1000, y: Math.round(((e.clientY - r.top) / r.height) * 1000) / 1000 })
  }

  return (
    <div ref={(el) => { host.current = el; setRef(el) }} data-page={n} className="relative mx-auto w-fit bg-white shadow-lg" style={{ width: size?.w, height: size?.h, minWidth: size ? undefined : 300, minHeight: size ? undefined : 400 }}>
      <canvas ref={canvas} className={clsx('block', reviewing && 'cursor-crosshair')} onPointerDown={place} />
      <span className="absolute -top-4 start-0 text-[10px] font-bold text-slate-400" dir="ltr">{n}</span>
      {comments.map((c, i) => c.anchor?.x != null && c.anchor.y != null && (
        <button key={c.id} type="button" onClick={() => onPinClick(c)} title={c.body}
          className={clsx('absolute grid -translate-x-1/2 -translate-y-full place-items-center rounded-full rounded-bl-none text-[11px] font-bold text-white shadow-lg ring-2 ring-white transition', activeId === c.id ? 'z-10 size-8' : 'size-6 hover:scale-110', c.status === 'resolved' && 'opacity-60')}
          style={{ left: `${c.anchor.x * 100}%`, top: `${c.anchor.y * 100}%`, background: c.status === 'resolved' ? '#16A34A' : c.status === 'addressed' ? '#D97706' : severityTone[c.severity].pin }}>
          {c.status === 'resolved' ? <Check className="size-3.5" /> : i + 1}
        </button>
      ))}
      {draft && draft.x != null && draft.y != null && <span className="absolute size-7 -translate-x-1/2 -translate-y-full animate-bounce rounded-full rounded-bl-none bg-gold-500 ring-2 ring-white" style={{ left: `${draft.x * 100}%`, top: `${draft.y * 100}%` }} />}
    </div>
  )
}

export const useSortedComments = (comments: KitComment[]) => useMemo(() => [...comments], [comments])
