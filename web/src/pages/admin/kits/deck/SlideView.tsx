import clsx from 'clsx'
import { AudioLines, ImageIcon, Play, Video } from 'lucide-react'
import { memo, type CSSProperties } from 'react'
import { H, W, type AudioEl, type El, type ImageEl, type Paragraph, type Slide, type TextEl, type Theme, type VideoEl } from './model'

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

/** A video or a sound on a slide. Drawn as a still tile while editing; with `playable` (slideshow) it really plays. */
export function MediaContent({ el, placeholder = true, playable = false }: { el: VideoEl | AudioEl; placeholder?: boolean; playable?: boolean }) {
  const isVideo = el.type === 'video'
  if (!el.src) {
    if (!placeholder) return null
    const Icon = isVideo ? Video : AudioLines
    return (
      <div style={{ width: '100%', height: '100%', borderRadius: el.radius, border: '3px dashed #C9B98D', background: 'repeating-linear-gradient(45deg,#FBF8F1,#FBF8F1 14px,#F5EFE0 14px,#F5EFE0 28px)', display: 'flex', flexDirection: 'column', alignItems: 'center', justifyContent: 'center', gap: 10, color: '#8A7B55', padding: 16, textAlign: 'center' }}>
        <Icon size={Math.min(64, el.h / 2.2)} strokeWidth={1.4} />
        {el.prompt && el.h > 150 && <span style={{ fontSize: 20, lineHeight: 1.3, maxHeight: 80, overflow: 'hidden' }} dir="auto">{el.prompt}</span>}
      </div>
    )
  }
  if (playable) {
    return isVideo
      ? <video src={el.src} controls playsInline autoPlay={el.autoplay} loop={el.loop} style={{ width: '100%', height: '100%', objectFit: 'contain', background: '#000', borderRadius: el.radius, display: 'block' }} />
      : <div style={{ width: '100%', height: '100%', borderRadius: el.radius, background: 'linear-gradient(135deg,#5E0E26,#8A1538)', display: 'flex', alignItems: 'center', padding: '0 20px' }}><audio src={el.src} controls autoPlay={el.autoplay} loop={el.loop} style={{ width: '100%' }} /></div>
  }
  if (isVideo) {
    return (
      <div style={{ position: 'relative', width: '100%', height: '100%', borderRadius: el.radius, overflow: 'hidden', background: '#111' }}>
        <video src={`${el.src}#t=0.1`} preload="metadata" muted playsInline style={{ width: '100%', height: '100%', objectFit: 'cover', display: 'block', pointerEvents: 'none' }} />
        <span style={{ position: 'absolute', inset: 0, display: 'grid', placeItems: 'center', background: 'rgba(0,0,0,.18)' }}><span style={{ display: 'grid', placeItems: 'center', width: Math.min(96, el.h / 2.4), height: Math.min(96, el.h / 2.4), borderRadius: '50%', background: 'rgba(255,255,255,.92)', color: '#8A1538' }}><Play size={Math.min(44, el.h / 5)} fill="currentColor" /></span></span>
      </div>
    )
  }
  return (
    <div style={{ width: '100%', height: '100%', borderRadius: el.radius, background: 'linear-gradient(135deg,#5E0E26,#8A1538)', display: 'flex', alignItems: 'center', gap: 16, padding: '0 22px', color: '#fff' }}>
      <span style={{ display: 'grid', placeItems: 'center', width: Math.min(56, el.h * .7), height: Math.min(56, el.h * .7), borderRadius: '50%', background: 'rgba(255,255,255,.18)' }}><Play size={Math.min(26, el.h * .32)} fill="currentColor" /></span>
      <AudioLines size={Math.min(60, el.h * .7)} strokeWidth={1.6} style={{ opacity: .9 }} />
      <span style={{ flex: 1, fontSize: Math.min(24, el.h * .3), fontWeight: 600, overflow: 'hidden', whiteSpace: 'nowrap', textOverflow: 'ellipsis' }} dir="auto">{el.alt || ''}</span>
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
export function ElementBox({ el, theme, children, style, className, placeholder = true, playable = false, ...rest }: { el: El; theme: Theme; children?: React.ReactNode; style?: CSSProperties; className?: string; placeholder?: boolean; playable?: boolean } & React.HTMLAttributes<HTMLDivElement>) {
  return (
    <div {...rest} className={className} data-el={el.id} style={{ position: 'absolute', left: el.x, top: el.y, width: el.w, height: el.h, transform: el.rotation ? `rotate(${el.rotation}deg)` : undefined, ...style }}>
      {el.type === 'text' ? <TextContent el={el} theme={theme} /> : el.type === 'image' ? <ImageContent el={el} placeholder={placeholder} /> : el.type === 'video' || el.type === 'audio' ? <MediaContent el={el} placeholder={placeholder} playable={playable} /> : <ShapeContent el={el} />}
      {children}
    </div>
  )
}

type Props = { slide: Slide; theme: Theme; width: number; className?: string; placeholder?: boolean; playable?: boolean }

/** A slide drawn at any size (thumbnails, previews, slideshow). */
export const SlideView = memo(function SlideView({ slide, theme, width, className, placeholder = false, playable = false }: Props) {
  const k = width / W
  return (
    <div className={clsx('relative shrink-0 overflow-hidden', className)} style={{ width, height: H * k }}>
      <div style={{ width: W, height: H, transform: `scale(${k})`, transformOrigin: '0 0', position: 'absolute', left: 0, top: 0, overflow: 'hidden', background: slide.background.color }}>
        {slide.background.src && <img src={slide.background.src} alt="" draggable={false} style={{ position: 'absolute', inset: 0, width: '100%', height: '100%', objectFit: 'cover' }} />}
        {slide.elements.map((el) => <ElementBox key={el.id} el={el} theme={theme} placeholder={placeholder} playable={playable} />)}
      </div>
    </div>
  )
})
