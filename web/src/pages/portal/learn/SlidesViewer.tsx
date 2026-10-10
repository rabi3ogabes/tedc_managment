import * as pdfjs from 'pdfjs-dist'
import { ChevronLeft, ChevronRight, Download, ExternalLink } from 'lucide-react'
import { useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Spinner } from '@/components/ui'
import { api } from '@/lib/api'
import type { LessonDetail, ProgressResult } from './types'

pdfjs.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).toString()

/** PDF slides one page at a time; every slide shown is reported, so progress is the share of slides actually seen. */
function PdfSlides({ lesson, url, onProgress }: { lesson: LessonDetail; url: string; onProgress: (r: ProgressResult) => void }) {
  const { t } = useTranslation()
  const canvas = useRef<HTMLCanvasElement>(null)
  const [doc, setDoc] = useState<pdfjs.PDFDocumentProxy | null>(null)
  const [page, setPage] = useState(Math.max(1, Math.round(lesson.progress.position) || 1))
  const [error, setError] = useState(false)
  const dwell = lesson.rules.min_seconds_per_slide ?? 0

  useEffect(() => {
    const task = pdfjs.getDocument({ url })
    let cancelled = false
    task.promise.then((d) => !cancelled && setDoc(d)).catch(() => !cancelled && setError(true))
    return () => { cancelled = true; void task.destroy() }
  }, [url])

  useEffect(() => {
    if (!doc) return
    let render: pdfjs.RenderTask | null = null
    let cancelled = false
    void doc.getPage(Math.min(page, doc.numPages)).then((p) => {
      if (cancelled || !canvas.current) return
      const el = canvas.current
      const ratio = Math.min(2, window.devicePixelRatio || 1)
      const vp = p.getViewport({ scale: (el.parentElement?.clientWidth ?? 900) / p.getViewport({ scale: 1 }).width * ratio })
      el.width = Math.floor(vp.width)
      el.height = Math.floor(vp.height)
      el.style.width = '100%'
      const ctx = el.getContext('2d')
      if (!ctx) return
      render = p.render({ canvasContext: ctx, viewport: vp, canvas: el })
      render.promise.catch(() => undefined)
    })
    // A slide counts as seen once it has stayed on screen for a moment — or for the author's minimum time per slide.
    const id = window.setTimeout(() => {
      api.post<{ data: ProgressResult }>(`/me/lessons/${lesson.id}/slide`, { slide: page, total: doc.numPages }).then((r) => onProgress(r.data.data)).catch(() => undefined)
    }, Math.max(1200, dwell * 1000))
    return () => { cancelled = true; render?.cancel(); window.clearTimeout(id) }
  }, [doc, page, lesson.id, onProgress, dwell])

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (!doc || ['INPUT', 'TEXTAREA', 'SELECT'].includes((e.target as HTMLElement).tagName)) return
      if (e.key === 'ArrowRight' || e.key === 'PageDown') setPage((p) => Math.min(doc.numPages, p + 1))
      if (e.key === 'ArrowLeft' || e.key === 'PageUp') setPage((p) => Math.max(1, p - 1))
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  }, [doc])

  if (error) return <div className="rounded-2xl bg-red-50 p-6 text-center text-danger">{t('learn.mediaMissing')}</div>
  if (!doc) return <Spinner />
  return (
    <div className="overflow-hidden rounded-2xl border border-navy-100 bg-white shadow-glass">
      <div className="bg-slate-100 p-3"><canvas ref={canvas} className="mx-auto rounded-lg bg-white shadow" /></div>
      <div className="flex items-center justify-between gap-3 border-t border-navy-100 p-3" dir="ltr">
        <Button variant="outline" size="sm" icon={<ChevronLeft className="size-4" />} disabled={page <= 1} onClick={() => setPage(page - 1)}>{t('learn.previous')}</Button>
        <span className="text-sm font-semibold text-navy-900">{t('learn.slide', { n: page, total: doc.numPages })}</span>
        <Button variant="outline" size="sm" disabled={page >= doc.numPages} onClick={() => setPage(page + 1)}>{t('learn.next')}<ChevronRight className="size-4" /></Button>
      </div>
      <div className="h-1.5 bg-navy-100"><div className="h-full bg-gradient-to-r from-gold-500 to-gold-300 transition-all" style={{ width: `${(page / doc.numPages) * 100}%` }} /></div>
      {dwell > 0 && <p className="bg-navy-50 px-3 py-2 text-center text-xs text-slate-600">{t('learn.slideWait', { s: dwell })}</p>}
    </div>
  )
}

/** A presentation: PDFs open page by page in the lesson; PowerPoint files are opened by the learner who then confirms. */
export default function SlidesViewer({ lesson, onProgress, onConfirm }: { lesson: LessonDetail; onProgress: (r: ProgressResult) => void; onConfirm: () => void }) {
  const { t } = useTranslation()
  const media = lesson.media
  if (!media) return <div className="grid min-h-48 place-items-center rounded-2xl bg-navy-950 text-white/70">{t('learn.mediaMissing')}</div>
  const isPdf = media.kind === 'file' ? media.mime === 'application/pdf' : /\.pdf($|\?)/i.test(media.url)

  return (
    <div className="space-y-3">
      {isPdf ? <PdfSlides lesson={lesson} url={media.url} onProgress={onProgress} /> : (
        <div className="rounded-2xl border border-navy-100 bg-white p-8 text-center shadow-glass">
          <ExternalLink className="mx-auto size-10 text-gold-600" />
          <p className="mt-3 font-semibold text-navy-900">{media.kind === 'file' ? media.name : t('learn.link')}</p>
          <div className="mt-4 flex flex-wrap justify-center gap-3">
            <Button variant="gold" icon={<ExternalLink className="size-4" />} onClick={() => window.open(media.url, '_blank', 'noopener')}>{t('learn.openFile')}</Button>
            <Button variant="primary" onClick={onConfirm}>{t('learn.confirmDone')}</Button>
          </div>
        </div>
      )}
      {lesson.rules.downloadable && media.kind === 'file' && <a href={media.url} download className="inline-flex items-center gap-2 text-sm font-semibold text-link"><Download className="size-4" />{t('learn.download')}</a>}
    </div>
  )
}
