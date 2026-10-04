import { ChevronLeft, ChevronRight, StickyNote, X } from 'lucide-react'
import { useEffect, useState } from 'react'
import { useTranslation } from 'react-i18next'
import type { Deck } from './model'
import { SlideView } from './SlideView'

/** Full-screen slideshow: arrows / click to move, N for the speaker notes, Esc to leave. */
export default function Presenter({ deck, start = 0, onClose }: { deck: Deck; start?: number; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const [i, setI] = useState(Math.min(start, deck.slides.length - 1))
  const [notes, setNotes] = useState(false)
  const [size, setSize] = useState({ w: window.innerWidth, h: window.innerHeight })
  const rtl = i18n.dir() === 'rtl'
  const go = (d: number) => setI((n) => Math.max(0, Math.min(deck.slides.length - 1, n + d)))

  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
      else if (e.key === 'ArrowRight' || e.key === 'ArrowDown' || e.key === 'PageDown' || e.key === ' ') { e.preventDefault(); go(e.key === 'ArrowRight' && rtl ? -1 : 1) }
      else if (e.key === 'ArrowLeft' || e.key === 'ArrowUp' || e.key === 'PageUp') { e.preventDefault(); go(e.key === 'ArrowLeft' && rtl ? 1 : -1) }
      else if (e.key.toLowerCase() === 'n') setNotes((v) => !v)
    }
    const onResize = () => setSize({ w: window.innerWidth, h: window.innerHeight })
    window.addEventListener('keydown', onKey)
    window.addEventListener('resize', onResize)
    document.documentElement.requestFullscreen?.().catch(() => undefined)
    return () => {
      window.removeEventListener('keydown', onKey)
      window.removeEventListener('resize', onResize)
      if (document.fullscreenElement) void document.exitFullscreen().catch(() => undefined)
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [])

  const slide = deck.slides[i]
  const reserved = notes ? 140 : 0
  const width = Math.min(size.w, (size.h - reserved) * (16 / 9))
  return (
    <div className="fixed inset-0 z-[80] flex flex-col items-center justify-center bg-black" onClick={() => go(1)}>
      <SlideView slide={slide} theme={deck.theme} width={width} placeholder playable />
      {notes && <div className="mt-3 max-h-32 w-full max-w-4xl overflow-y-auto rounded-xl bg-white/10 p-3 text-sm leading-relaxed text-white" dir="auto" onClick={(e) => e.stopPropagation()}>{slide.notes || t('kits.editor.noNotes')}</div>}
      <div className="absolute inset-x-0 bottom-3 flex items-center justify-center gap-2 opacity-0 transition hover:opacity-100 focus-within:opacity-100" onClick={(e) => e.stopPropagation()}>
        <button type="button" onClick={() => go(rtl ? 1 : -1)} className="grid size-10 place-items-center rounded-full bg-white/15 text-white hover:bg-white/25" aria-label={t('kits.common.previous')}><ChevronLeft className="size-5" /></button>
        <span className="rounded-full bg-white/15 px-3 py-1 text-sm text-white" dir="ltr">{i + 1} / {deck.slides.length}</span>
        <button type="button" onClick={() => go(rtl ? -1 : 1)} className="grid size-10 place-items-center rounded-full bg-white/15 text-white hover:bg-white/25" aria-label={t('kits.common.next')}><ChevronRight className="size-5" /></button>
        <button type="button" onClick={() => setNotes((v) => !v)} className="grid size-10 place-items-center rounded-full bg-white/15 text-white hover:bg-white/25" aria-label={t('kits.editor.notes')}><StickyNote className="size-5" /></button>
        <button type="button" onClick={onClose} className="grid size-10 place-items-center rounded-full bg-white/15 text-white hover:bg-white/25" aria-label={t('kits.common.close')}><X className="size-5" /></button>
      </div>
    </div>
  )
}
