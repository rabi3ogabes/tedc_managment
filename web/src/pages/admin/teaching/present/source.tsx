import { useMemo } from 'react'
import { SlideView } from '../../kits/deck/SlideView'
import { useProtectedDeck, type FileView } from '../api'
import { PdfPage, usePdfDoc } from '../viewers'
import type { Source } from './state'

/** The slides of a presentation file — a studio deck or a PDF — as one thing the presenter can step through. */
export function usePresentSource(view: FileView | undefined): Source {
  const deck = useProtectedDeck(view?.deck ?? null)
  const pdf = usePdfDoc(!view?.deck && view?.file.kind === 'pdf' ? view.stream : null)
  return useMemo<Source>(() => {
    if (view?.deck) {
      const slides = deck?.slides ?? []
      return {
        kind: 'deck', ready: !!deck, error: false, count: slides.length, notes: (i) => slides[i]?.notes ?? '',
        render: (i, w, h) => deck && slides[i] ? <SlideView slide={slides[i]} theme={deck.theme} width={Math.min(w, h * (16 / 9))} placeholder playable /> : null,
        thumb: (i, w) => deck && slides[i] ? <SlideView slide={slides[i]} theme={deck.theme} width={w} /> : null,
      }
    }
    const doc = pdf.doc
    return {
      kind: 'pdf', ready: !!doc, error: pdf.error, count: doc?.numPages ?? 0, notes: () => '',
      render: (i, w, h) => doc ? <PdfPage doc={doc} page={i + 1} width={w} height={h} /> : null,
      thumb: null,
    }
  }, [view, deck, pdf.doc, pdf.error])
}
