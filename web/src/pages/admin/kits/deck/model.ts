/** Editable slide-deck model (mirrors backend App\Services\Kits\DeckModel). Canvas is always 1280 x 720. */
export const W = 1280
export const H = 720

export type Paragraph = { text: string; size: number; bold: boolean; italic: boolean; color: string; align: 'left' | 'center' | 'right'; bullet: boolean; level: number }
export type TextStyle = { fontFamily?: string | null; valign: 'top' | 'middle' | 'bottom'; fill?: string | null; lineHeight: number; padding: number }

type Base = { id: string; x: number; y: number; w: number; h: number; rotation: number }
export type TextEl = Base & { type: 'text'; paragraphs: Paragraph[]; style: TextStyle }
export type ImageEl = Base & { type: 'image'; asset_id: string | null; src?: string | null; alt: string; prompt: string; fit: 'cover' | 'contain'; radius: number }
export type VideoEl = Base & { type: 'video'; asset_id: string | null; src?: string | null; alt: string; prompt: string; autoplay: boolean; loop: boolean; radius: number }
export type AudioEl = Base & { type: 'audio'; asset_id: string | null; src?: string | null; alt: string; prompt: string; autoplay: boolean; loop: boolean; radius: number }
export type ShapeEl = Base & { type: 'shape'; shape: 'rect' | 'round' | 'ellipse' | 'line'; fill: string | null; stroke: string | null; strokeWidth: number; radius: number; opacity: number }
export type MediaEl = ImageEl | VideoEl | AudioEl
export type El = TextEl | ImageEl | VideoEl | AudioEl | ShapeEl

export type Slide = {
  id: string; rev: number; layout: string; background: { color: string; asset_id: string | null; src?: string | null }; notes: string; elements: El[]
  by?: string | null; by_name?: string | null; at?: string | null
}

export type Theme = { primary: string; accent: string; text: string; background: string; muted: string; font: string; dir: 'rtl' | 'ltr' }
export type Deck = { version: 1; size: { w: number; h: number }; theme: Theme; slides: Slide[] }

let counter = 0
export const uid = (prefix: string) => `${prefix}_${Math.random().toString(36).slice(2, 8)}${(counter++ % 36).toString(36)}`

export const DEFAULT_THEME: Theme = { primary: '#8A1538', accent: '#A29475', text: '#1A1A1A', background: '#FFFFFF', muted: '#6B7280', font: 'Qatar Sans', dir: 'rtl' }

export const para = (text: string, o: Partial<Paragraph> = {}, dir: 'rtl' | 'ltr' = 'rtl'): Paragraph => ({
  text, size: 28, bold: false, italic: false, color: '#1A1A1A', align: dir === 'rtl' ? 'right' : 'left', bullet: false, level: 0, ...o,
})

export const textEl = (x: number, y: number, w: number, h: number, paragraphs: Paragraph[], style: Partial<TextStyle> = {}): TextEl => ({
  id: uid('e'), type: 'text', x, y, w, h, rotation: 0, paragraphs, style: { valign: 'top', lineHeight: 1.3, padding: 8, ...style },
})
export const shapeEl = (shape: ShapeEl['shape'], x: number, y: number, w: number, h: number, fill: string | null, o: Partial<ShapeEl> = {}): ShapeEl => ({
  id: uid('e'), type: 'shape', shape, x, y, w, h, rotation: 0, fill, stroke: null, strokeWidth: 0, radius: 0, opacity: 1, ...o,
})
export const imageEl = (x: number, y: number, w: number, h: number, o: Partial<ImageEl> = {}): ImageEl => ({
  id: uid('e'), type: 'image', x, y, w, h, rotation: 0, asset_id: null, alt: '', prompt: '', fit: 'cover', radius: 0, ...o,
})

export const videoEl = (x: number, y: number, w: number, h: number, o: Partial<VideoEl> = {}): VideoEl => ({
  id: uid('e'), type: 'video', x, y, w, h, rotation: 0, asset_id: null, alt: '', prompt: '', autoplay: false, loop: false, radius: 12, ...o,
})
export const audioEl = (x: number, y: number, w: number, h: number, o: Partial<AudioEl> = {}): AudioEl => ({
  id: uid('e'), type: 'audio', x, y, w, h, rotation: 0, asset_id: null, alt: '', prompt: '', autoplay: false, loop: false, radius: 16, ...o,
})
export const isMedia = (e: El | null | undefined): e is MediaEl => !!e && (e.type === 'image' || e.type === 'video' || e.type === 'audio')

export type LayoutId = 'blank' | 'title' | 'content' | 'two_column' | 'image_text' | 'section'
export const LAYOUTS: LayoutId[] = ['title', 'content', 'two_column', 'image_text', 'section', 'blank']

/** A ready slide for the add-slide picker. */
export function newSlide(layout: LayoutId, theme: Theme, labels: { title: string; body: string; left: string; right: string; subtitle: string }): Slide {
  const rtl = theme.dir === 'rtl'
  const P = (text: string, o: Partial<Paragraph> = {}) => para(text, { color: theme.text, ...o }, theme.dir)
  const frame = (): El[] => [
    shapeEl('rect', 0, 0, 1280, 14, theme.primary),
    textEl(80, 40, 1120, 92, [P(labels.title, { size: 38, bold: true, color: theme.primary })], { valign: 'middle', lineHeight: 1.15 }),
    shapeEl('rect', rtl ? 1080 : 88, 138, 120, 5, theme.accent),
  ]
  let elements: El[] = []
  let bg = '#FFFFFF'
  switch (layout) {
    case 'title':
      bg = theme.primary
      elements = [
        shapeEl('ellipse', rtl ? -180 : 900, 420, 560, 560, theme.accent, { opacity: 0.22 }),
        shapeEl('rect', rtl ? 1100 : 100, 200, 80, 6, theme.accent),
        textEl(100, 224, 1080, 190, [P(labels.title, { size: 56, bold: true, color: '#FFFFFF' })], { lineHeight: 1.2 }),
        textEl(100, 430, 1080, 110, [P(labels.subtitle, { size: 26, color: '#F3E9D2' })]),
      ]
      break
    case 'section':
      bg = '#5E0E26'
      elements = [shapeEl('rect', rtl ? 1264 : 0, 0, 16, 720, theme.accent), textEl(100, 250, 1080, 160, [P(labels.title, { size: 52, bold: true, color: '#FFFFFF' })], { valign: 'middle' })]
      break
    case 'two_column': {
      const xs = rtl ? [660, 80] : [80, 660]
      elements = [...frame(), ...xs.flatMap((x, i) => [shapeEl('round', x, 170, 540, 470, '#F7F3EA', { radius: 18 }), textEl(x + 12, 186, 516, 440, [P(i ? labels.right : labels.left, { size: 26, bullet: true })])])]
      break
    }
    case 'image_text':
      elements = [...frame(), textEl(rtl ? 600 : 80, 170, 600, 470, [P(labels.body, { size: 28, bullet: true })]), imageEl(rtl ? 80 : 760, 170, 440, 440, { radius: 16 })]
      break
    case 'content':
      elements = [...frame(), textEl(80, 170, 1120, 470, [P(labels.body, { size: 30, bullet: true })])]
      break
    default:
      elements = []
  }
  return { id: uid('s'), rev: 1, layout, background: { color: bg, asset_id: null }, notes: '', elements }
}

/** Plain text of a slide (search, analysis, PPTX notes). */
export const slideText = (s: Slide) => s.elements.flatMap((e) => (e.type === 'text' ? e.paragraphs.map((p) => p.text) : [])).filter(Boolean).join('\n')

export const slideTitle = (s: Slide): string => {
  const titleEl = s.elements.find((e): e is TextEl => e.type === 'text' && e.y < 240 && (e.paragraphs[0]?.size ?? 0) >= 30 && !!e.paragraphs[0]?.text)
  return titleEl?.paragraphs[0].text ?? s.elements.find((e): e is TextEl => e.type === 'text' && !!e.paragraphs[0]?.text)?.paragraphs[0].text ?? ''
}

export const clone = <T,>(v: T): T => JSON.parse(JSON.stringify(v)) as T

/** JSON without volatile fields; used to detect what changed. */
export const slideSignature = (s: Slide) => JSON.stringify({ ...s, rev: 0, by: 0, by_name: 0, at: 0, background: { ...s.background, src: null }, elements: s.elements.map((e) => (e.type === 'image' || e.type === 'video' || e.type === 'audio' ? { ...e, src: null } : e)) })

export function textParagraphsFromString(value: string, previous: Paragraph[]): Paragraph[] {
  const lines = value.split('\n')
  return lines.map((text, i) => {
    const base = previous[Math.min(i, previous.length - 1)] ?? previous[0]
    return { ...base, text }
  })
}
