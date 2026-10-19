import mammoth from 'mammoth'
import * as pdfjs from 'pdfjs-dist'
import { FileQuestion } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Spinner } from '@/components/ui'
import { safeHtml } from '@/lib/safeHtml'
import type { Deck } from '../kits/deck/model'
import { SlideView } from '../kits/deck/SlideView'
import { fetchBuffer, useBlobUrl, useProtectedDeck, type FileView } from './api'

pdfjs.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).toString()

/** The kit's viewers: pages, slides, documents and media drawn from bytes fetched through the API — no file address, no text layer to copy, no download control. */

export function usePdfDoc(stream: string | null | undefined) {
  const [doc, setDoc] = useState<pdfjs.PDFDocumentProxy | null>(null)
  const [error, setError] = useState(false)
  useEffect(() => {
    if (!stream) return
    let cancelled = false
    let task: pdfjs.PDFDocumentLoadingTask | null = null
    setDoc(null); setError(false)
    void (async () => {
      try {
        const data = await fetchBuffer(stream)
        if (cancelled) return
        task = pdfjs.getDocument({ data })
        const d = await task.promise
        if (!cancelled) setDoc(d)
      } catch { if (!cancelled) setError(true) }
    })()
    return () => { cancelled = true; void task?.destroy() }
  }, [stream])
  return { doc, error }
}

/** One PDF page on a canvas, sharp on dense screens: `width` wide, or fitted inside `width` x `height` when a height is given. */
export function PdfPage({ doc, page, width, height, className }: { doc: pdfjs.PDFDocumentProxy; page: number; width: number; height?: number; className?: string }) {
  const canvas = useRef<HTMLCanvasElement>(null)
  const [ratio, setRatio] = useState(1.414)
  useEffect(() => {
    let cancelled = false
    let task: pdfjs.RenderTask | null = null
    void (async () => {
      const p = await doc.getPage(page)
      if (cancelled || !canvas.current) return
      const base = p.getViewport({ scale: 1 })
      const fit = height ? Math.min(width / base.width, height / base.height) : width / base.width
      setRatio(base.height / base.width)
      const dpr = Math.min(2, window.devicePixelRatio || 1)
      const viewport = p.getViewport({ scale: fit * dpr })
      const c = canvas.current
      c.width = Math.floor(viewport.width); c.height = Math.floor(viewport.height)
      c.style.width = `${base.width * fit}px`; c.style.height = `${base.height * fit}px`
      task = p.render({ canvasContext: c.getContext('2d')!, viewport, canvas: c })
      await task.promise.catch(() => undefined)
    })()
    return () => { cancelled = true; task?.cancel() }
  }, [doc, page, width, height])
  return <canvas ref={canvas} className={className} style={{ width: height ? undefined : width, height: height ? undefined : width * ratio, display: 'block', background: '#fff' }} />
}

function useWidth(max = 980) {
  const ref = useRef<HTMLDivElement>(null)
  const [w, setW] = useState(max)
  useEffect(() => {
    const el = ref.current
    if (!el) return
    const ro = new ResizeObserver(() => setW(Math.max(280, Math.min(max, Math.floor(el.clientWidth)))))
    ro.observe(el)
    setW(Math.max(280, Math.min(max, Math.floor(el.clientWidth))))
    return () => ro.disconnect()
  }, [max])
  return [ref, w] as const
}

export function ProtectedPdf({ stream }: { stream: string }) {
  const { t } = useTranslation()
  const { doc, error } = usePdfDoc(stream)
  const [ref, width] = useWidth()
  const [visible, setVisible] = useState<Set<number>>(new Set([1, 2]))
  const marks = useRef<Record<number, HTMLDivElement | null>>({})
  useEffect(() => {
    if (!doc) return
    const io = new IntersectionObserver((entries) => {
      setVisible((v) => { const n = new Set(v); entries.forEach((e) => { if (e.isIntersecting) n.add(Number((e.target as HTMLElement).dataset.page)) }); return n })
    }, { rootMargin: '600px 0px' })
    Object.values(marks.current).forEach((el) => el && io.observe(el))
    return () => io.disconnect()
  }, [doc])
  if (error) return <Failed />
  if (!doc) return <Spinner />
  return (
    <div ref={ref} className="mx-auto flex max-w-[980px] flex-col items-center gap-4">
      {Array.from({ length: doc.numPages }, (_, i) => i + 1).map((p) => (
        <div key={p} data-page={p} ref={(el) => { marks.current[p] = el }} className="overflow-hidden rounded-lg shadow-md ring-1 ring-navy-100" style={{ width, minHeight: visible.has(p) ? undefined : width * 1.414 }}>
          {visible.has(p) ? <PdfPage doc={doc} page={p} width={width} /> : <div className="grid place-items-center text-xs text-slate-400" style={{ height: width * 1.414 }}>{t('teach.page', { n: p })}</div>}
        </div>
      ))}
    </div>
  )
}

export function ProtectedDocx({ stream }: { stream: string }) {
  const [html, setHtml] = useState<string | null>(null)
  const [error, setError] = useState(false)
  useEffect(() => {
    let cancelled = false
    void (async () => {
      try {
        const res = await mammoth.convertToHtml({ arrayBuffer: await fetchBuffer(stream) }, { styleMap: ['p[style-name="Title"] => h1.title:fresh'] })
        if (!cancelled) setHtml(safeHtml(res.value))
      } catch { if (!cancelled) setError(true) }
    })()
    return () => { cancelled = true }
  }, [stream])
  if (error) return <Failed />
  if (html === null) return <Spinner />
  return <article className="prose prose-slate mx-auto max-w-3xl rounded-2xl bg-white p-8 shadow-sm ring-1 ring-navy-100" dir="auto" dangerouslySetInnerHTML={{ __html: html }} />
}

export function ProtectedMedia({ stream, kind, mime }: { stream: string; kind: string; mime: string | null }) {
  const { url, error } = useBlobUrl(stream)
  if (error) return <Failed />
  if (!url) return <Spinner />
  if (kind === 'image') return <img src={url} alt="" draggable={false} className="mx-auto max-h-[75vh] rounded-xl" />
  if (mime?.startsWith('audio/')) return <audio src={url} controls controlsList="nodownload noplaybackrate" className="mx-auto w-full max-w-xl" />
  return <video src={url} controls controlsList="nodownload noremoteplayback" disablePictureInPicture playsInline className="mx-auto max-h-[75vh] w-full max-w-4xl rounded-xl bg-black" />
}

export function ProtectedDeck({ deck }: { deck: Deck }) {
  const resolved = useProtectedDeck(deck)
  const [ref, width] = useWidth(1040)
  if (!resolved) return <Spinner />
  return (
    <div ref={ref} className="mx-auto flex max-w-[1040px] flex-col gap-5">
      {resolved.slides.map((s, i) => (
        <div key={s.id} className="overflow-hidden rounded-xl shadow-md ring-1 ring-navy-100">
          <SlideView slide={s} theme={resolved.theme} width={width} placeholder />
          {s.notes && <div className="border-t border-navy-100 bg-ivory px-4 py-3 text-sm leading-7 text-slate-600" dir="auto"><span className="me-2 rounded bg-gold-100 px-1.5 py-0.5 text-xs font-bold text-gold-800">{i + 1}</span>{s.notes}</div>}
        </div>
      ))}
    </div>
  )
}

export function Failed() {
  const { t } = useTranslation()
  return <div className="mx-auto grid max-w-md place-items-center gap-2 py-16 text-center text-slate-500"><FileQuestion className="size-10 text-slate-300" /><p className="text-sm">{t('teach.loadFailed')}</p></div>
}

/** Picks the right viewer for a file. */
export function FileViewer({ view }: { view: FileView }) {
  const { t } = useTranslation()
  const f = view.file
  if (view.deck) return <ProtectedDeck deck={view.deck} />
  if (!view.stream) return <div className="mx-auto grid max-w-md place-items-center gap-2 py-16 text-center text-slate-500"><FileQuestion className="size-10 text-slate-300" /><p className="text-sm">{f.kind === 'presentation' ? t('teach.notPrepared') : t('teach.noPreview')}</p></div>
  if (f.kind === 'pdf') return <ProtectedPdf stream={view.stream} />
  if (f.kind === 'document' && /\.docx$/i.test(f.name)) return <ProtectedDocx stream={view.stream} />
  if (f.kind === 'image' || f.kind === 'video' || f.mime?.startsWith('audio/')) return <ProtectedMedia stream={view.stream} kind={f.kind} mime={f.mime} />
  return <div className="mx-auto grid max-w-md place-items-center gap-2 py-16 text-center text-slate-500"><FileQuestion className="size-10 text-slate-300" /><p className="text-sm">{t('teach.noPreview')}</p></div>
}
