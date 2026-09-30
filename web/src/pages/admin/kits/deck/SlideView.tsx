import clsx from 'clsx'
import { ImageIcon } from 'lucide-react'
import { memo, type CSSProperties } from 'react'
import { H, W, type El, type ImageEl, type Paragraph, type Slide, type TextEl, type Theme } from './model'

export function TextContent({ el, theme }: { el: TextEl; theme: Theme }) {
  const family = el.style.fontFamily || theme.font
  return (
    <div
      dir={theme.dir}
      style={{
        width: '100%', height: '100%', display: 'flex', flexDirection: 'column', justifyContent: el.style.valign === 'middle' ? 'center' : el.style.valign === 'bottom' ? 'flex-end' : 'flex-start',
        padding: el.style.padding, boxSizing: 'border-box', lineHeight: el.style.lineHeight, fontFamily: `'${family}', var(--font-sans), sans-serif`, background: el.style.fill ?? undefined, overflow: 'visible',
      }}
    >
      {el.paragraphs.map((p, i) => <ParagraphView key={i} p={p} dir={theme.dir} />)}
    </div>
  )
}

function ParagraphView({ p, dir }: { p: Paragraph; dir: 'rtl' | 'ltr' }) {
  const style: CSSProperties = { fontSize: p.size, fontWeight: p.bold ? 700 : 400, fontStyle: p.italic ? 'italic' : 'normal', color: p.color, textAlign: p.align, minHeight: p.size * 0.9, paddingInlineStart: p.level * 36 }
  if (!p.bullet) return <div style={style}>{p.text || ' '}</div>
  return (
    <div style={{ ...style, display: 'flex', gap: p.size * 0.45, justifyContent: p.align === 'center' ? 'center' : undefined }} dir={dir}>
      <span aria-hidden style={{ flex: 'none', lineHeight: 'inherit', opacity: 0.9 }}>{p.level % 2 ? '–' : '•'}</span>
      <span style={{ flex: '0 1 auto', textAlign: p.align }}>{p.text || ' '}</span>
    </div>
  )
}

export function ImageContent({ el, placeholder = true }: { el: ImageEl; placeholder?: boolean }) {
  if (el.src) return <img src={el.src} alt={el.alt} draggable={false} style={{ width: '100%', height: '100%', objectFit: el.fit, borderRadius: el.radius, display: 'block', userSelect: 'none' }} />
  if (!placeholder) return null
  return (
    <div style={{ width: '100%', height: '100%', borderRadius: el.radius, border: '3px dashed #C9B98D', background: 'repeating-linear-gradient(45deg,#FBF8F1,#FBF8F1 14px,#F5EFE0 14px,#F5EFE0 28px)', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 12, color: '#8A7B55', padding: 24, textAlign: 'center' }}>
      <ImageIcon size={Math.min(72, el.w / 4)} strokeWidth={1.4} />
      {el.prompt && el.h > 160 && <span style={{ fontSize: 20, lineHeight: 1.3, maxHeight: 96, overflow: 'hidden' }} dir="auto">{el.prompt}</span>}
    </div>
  )
}

export function ShapeContent({ el }: { el: Extract<El, { type: 'shape' }> }) {
  if (el.shape === 'line') {
    return (
      <svg width="100%" height="100%" viewBox={`0 0 ${el.w} ${Math.max(el.h, 1)}`} preserveAspectRatio="none" style={{ overflow: 'visible', display: 'block' }}>
        <line x1={0} y1={0} x2={el.w} y2={el.h} stroke={el.stroke ?? el.fill ?? '#6B7280'} strokeWidth={Math.max(1, el.strokeWidth || 3)} opacity={el.opacity} />
      </svg>
    )
  }
  return <div style={{ width: '100%', height: '100%', background: el.fill ?? 'transparent', opacity: el.opacity, border: el.stroke && el.strokeWidth ? `${el.strokeWidth}px solid ${el.stroke}` : undefined, borderRadius: el.shape === 'ellipse' ? '50%' : el.shape === 'round' ? el.radius || 18 : el.radius || 0, boxSizing: 'border-box' }} />
}

/** Positions one element on the 1280 x 720 canvas (shared by the viewer and the editor). */
export function ElementBox({ el, theme, children, style, className, placeholder = true, ...rest }: { el: El; theme: Theme; children?: React.ReactNode; style?: CSSProperties; className?: string; placeholder?: boolean } & React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div {...rest} className={className} data-el={el.id} style={{ position: 'absolute', left: el.x, top: el.y, width: el.w, height: el.h, transform: el.rotation ? `rotate(${el.rotation}deg)` : undefined, ...style }}>
      {el.type === 'text' ? <TextContent el={el} theme={theme} /> : el.type === 'image' ? <ImageContent el={el} placeholder={placeholder} /> : <ShapeContent el={el} />}
      {children}
    </div>
  )
}

type Props = { slide: Slide; theme: Theme; width: number; className?: string; placeholder?: boolean }

/** A slide drawn at any size (thumbnails, previews, slideshow). */
export const SlideView = memo(function SlideView({ slide, theme, width, className, placeholder = false }: Props) {
  const k = width / W
  return (
    <div className={clsx('relative shrink-0 overflow-hidden', className)} style={{ width, height: H * k }}>
      <div style={{ width: W, height: H, transform: `scale(${k})`, transformOrigin: '0 0', position: 'relative', overflow: 'hidden', background: slide.background.color }}>
        {slide.background.src && <img src={slide.background.src} alt="" draggable={false} style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover' }} />}
        {slide.elements.map((el) => <ElementBox key={el.id} el={el} theme={theme} placeholder={placeholder} />)}
      </div>
    </div>
  )
})
