import clsx from 'clsx'
import { AlignCenter, AlignLeft, AlignRight, ArrowDownToLine, ArrowUpToLine, Bold, Copy, Image as ImageIcon, Italic, List, Sparkles, Trash2, Upload, X } from 'lucide-react'
import { useTranslation } from 'react-i18next'
import { Button, Field } from '@/components/ui'
import type { El, ImageEl, ShapeEl, Slide, TextEl, Theme } from './model'

const FONTS = ['Qatar Sans', 'Tajawal', 'Cairo', 'Arial', 'Tahoma', 'Times New Roman', 'Georgia']

export function ColorField({ label, value, onChange, allowNone = false }: { label: string; value: string | null | undefined; onChange: (v: string | null) => void; allowNone?: boolean }) {
  const { t } = useTranslation()
  return (
    <div className="flex items-center gap-2">
      <span className="w-20 shrink-0 text-xs text-slate-500">{label}</span>
      <label className="relative size-8 shrink-0 cursor-pointer overflow-hidden rounded-lg ring-1 ring-navy-100" style={{ background: value ?? 'repeating-conic-gradient(#e5e7eb 0% 25%, #fff 0% 50%) 50% / 10px 10px' }}>
        <input type="color" value={value ?? '#ffffff'} onChange={(e) => onChange(e.target.value.toUpperCase())} className="absolute inset-0 cursor-pointer opacity-0" aria-label={label} />
      </label>
      <input className="input !w-24 !py-1.5 font-mono text-xs uppercase" dir="ltr" value={value ?? ''} placeholder="#RRGGBB" onChange={(e) => /^#[0-9a-f]{6}$/i.test(e.target.value) && onChange(e.target.value.toUpperCase())} />
      {allowNone && value && <button type="button" onClick={() => onChange(null)} className="text-slate-400 hover:text-navy-900" aria-label={t('kits.editor.noFill')}><X className="size-4" /></button>}
    </div>
  )
}

function Num({ label, value, onChange, min = -2000, max = 4000, step = 1, suffix }: { label: string; value: number; onChange: (v: number) => void; min?: number; max?: number; step?: number; suffix?: string }) {
  return (
    <label className="block">
      <span className="mb-0.5 block text-[11px] text-slate-500">{label}</span>
      <div className="relative">
        <input type="number" className="input !py-1.5 text-xs" dir="ltr" min={min} max={max} step={step} value={Number.isFinite(value) ? Math.round(value * 100) / 100 : 0} onChange={(e) => onChange(Math.max(min, Math.min(max, Number(e.target.value) || 0)))} />
        {suffix && <span className="pointer-events-none absolute end-2 top-1/2 -translate-y-1/2 text-[10px] text-slate-400">{suffix}</span>}
      </div>
    </label>
  )
}

function Toggle({ on, onClick, children, label }: { on: boolean; onClick: () => void; children: React.ReactNode; label: string }) {
  return <button type="button" aria-pressed={on} aria-label={label} title={label} onClick={onClick} className={clsx('grid size-8 place-items-center rounded-lg border transition', on ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white text-slate-600 hover:border-gold-400')}>{children}</button>
}

type Props = {
  slide: Slide
  theme: Theme
  selected: El | null
  canEdit: boolean
  onChange: (patch: Partial<El>, opts?: { group?: string }) => void
  onSlide: (patch: Partial<Slide>) => void
  onDuplicate: () => void
  onDelete: () => void
  onOrder: (to: 'front' | 'back') => void
  onPickImage: (mode: 'upload' | 'library' | 'ai', kind?: 'image' | 'video' | 'audio') => void
  onClearSlideImage: () => void
}

export default function Inspector({ slide, theme, selected, canEdit, onChange, onSlide, onDuplicate, onDelete, onOrder, onPickImage, onClearSlideImage }: Props) {
  const { t } = useTranslation()
  const disabled = !canEdit

  if (!selected) {
    return (
      <fieldset disabled={disabled} className="space-y-4">
        <h4 className="text-sm font-bold text-navy-900">{t('kits.editor.slideSettings')}</h4>
        <ColorField label={t('kits.editor.background')} value={slide.background.color} onChange={(v) => onSlide({ background: { ...slide.background, color: v ?? '#FFFFFF' } })} />
        {slide.background.asset_id || slide.background.src ? <Button size="sm" variant="outline" onClick={onClearSlideImage}>{t('kits.editor.removeBackgroundImage')}</Button> : null}
        <Field label={t('kits.editor.notes')} hint={t('kits.editor.notesHint')}><textarea rows={7} className="input text-sm" dir="auto" value={slide.notes} onChange={(e) => onSlide({ notes: e.target.value })} /></Field>
        {slide.by_name && <p className="text-[11px] text-slate-400">{t('kits.editor.lastEdit', { name: slide.by_name })}</p>}
        <p className="rounded-xl bg-ivory p-3 text-xs leading-relaxed text-slate-500">{t('kits.editor.selectHint')}</p>
      </fieldset>
    )
  }

  const set = (patch: Partial<El>, group?: string) => onChange(patch, { group })
  return (
    <fieldset disabled={disabled} className="space-y-5">
      <div className="flex items-center justify-between">
        <h4 className="text-sm font-bold text-navy-900">{t(`kits.editor.types.${selected.type}`)}</h4>
        <div className="flex gap-1">
          <Toggle on={false} onClick={() => onOrder('front')} label={t('kits.editor.toFront')}><ArrowUpToLine className="size-4" /></Toggle>
          <Toggle on={false} onClick={() => onOrder('back')} label={t('kits.editor.toBack')}><ArrowDownToLine className="size-4" /></Toggle>
          <Toggle on={false} onClick={onDuplicate} label={t('kits.editor.duplicate')}><Copy className="size-4" /></Toggle>
          <Toggle on={false} onClick={onDelete} label={t('kits.common.delete')}><Trash2 className="size-4 text-danger" /></Toggle>
        </div>
      </div>

      {selected.type === 'text' && <TextControls el={selected} theme={theme} set={set} />}
      {selected.type === 'shape' && <ShapeControls el={selected} set={set} />}
      {(selected.type === 'video' || selected.type === 'audio') && (
        <div className="space-y-3">
          <div className="grid grid-cols-3 gap-1.5">
            <Button size="sm" variant="outline" icon={<Upload className="size-3.5" />} onClick={() => onPickImage('upload', selected.type as 'video' | 'audio')}>{t('kits.editor.upload')}</Button>
            <Button size="sm" variant="outline" icon={<ImageIcon className="size-3.5" />} onClick={() => onPickImage('library', selected.type as 'video' | 'audio')}>{t('kits.editor.library')}</Button>
            <Button size="sm" variant="gold" icon={<Sparkles className="size-3.5" />} onClick={() => onPickImage('ai', selected.type as 'video' | 'audio')}>AI</Button>
          </div>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={selected.autoplay} onChange={(e) => set({ autoplay: e.target.checked })} />{t('kits.media.autoplay')}</label>
          <label className="flex items-center gap-2 text-sm"><input type="checkbox" className="accent-gold-600" checked={selected.loop} onChange={(e) => set({ loop: e.target.checked })} />{t('kits.media.loop')}</label>
          <Field label={t('kits.editor.alt')} hint={t('kits.media.altHint')}><input className="input !py-1.5 text-sm" dir="auto" value={selected.alt} onChange={(e) => set({ alt: e.target.value }, 'alt')} /></Field>
        </div>
      )}
      {selected.type === 'image' && (
        <div className="space-y-3">
          <div className="grid grid-cols-3 gap-1.5">
            <Button size="sm" variant="outline" icon={<Upload className="size-3.5" />} onClick={() => onPickImage('upload')}>{t('kits.editor.upload')}</Button>
            <Button size="sm" variant="outline" icon={<ImageIcon className="size-3.5" />} onClick={() => onPickImage('library')}>{t('kits.editor.library')}</Button>
            <Button size="sm" variant="gold" icon={<Sparkles className="size-3.5" />} onClick={() => onPickImage('ai')}>AI</Button>
          </div>
          <div className="grid grid-cols-2 gap-2">
            <Field label={t('kits.editor.fit')}>
              <select className="input !py-1.5 text-xs" value={selected.fit} onChange={(e) => set({ fit: e.target.value as ImageEl['fit'] })}><option value="cover">{t('kits.editor.fitCover')}</option><option value="contain">{t('kits.editor.fitContain')}</option></select>
            </Field>
            <Num label={t('kits.editor.radius')} value={selected.radius} min={0} max={400} onChange={(v) => set({ radius: v })} />
          </div>
          <Field label={t('kits.editor.alt')} hint={t('kits.editor.altHint')}><input className="input !py-1.5 text-sm" dir="auto" value={selected.alt} onChange={(e) => set({ alt: e.target.value }, 'alt')} /></Field>
        </div>
      )}

      <div>
        <h5 className="mb-2 text-xs font-bold text-slate-500">{t('kits.editor.arrange')}</h5>
        <div className="grid grid-cols-4 gap-2">
          <Num label="X" value={selected.x} onChange={(v) => set({ x: v }, 'pos')} />
          <Num label="Y" value={selected.y} onChange={(v) => set({ y: v }, 'pos')} />
          <Num label={t('kits.editor.width')} value={selected.w} min={1} onChange={(v) => set({ w: v }, 'size')} />
          <Num label={t('kits.editor.height')} value={selected.h} min={1} onChange={(v) => set({ h: v }, 'size')} />
        </div>
        <div className="mt-2 max-w-[8rem]"><Num label={t('kits.editor.rotation')} value={selected.rotation} min={-180} max={180} suffix="°" onChange={(v) => set({ rotation: v }, 'rot')} /></div>
      </div>
    </fieldset>
  )
}

function TextControls({ el, theme, set }: { el: TextEl; theme: Theme; set: (p: Partial<El>, group?: string) => void }) {
  const { t } = useTranslation()
  const first = el.paragraphs[0]
  const all = (patch: Partial<TextEl['paragraphs'][number]>, group?: string) => set({ paragraphs: el.paragraphs.map((p) => ({ ...p, ...patch })) }, group)
  const uniform = (k: 'bold' | 'italic' | 'bullet') => el.paragraphs.every((p) => p[k])
  return (
    <div className="space-y-3">
      <div className="grid grid-cols-[1fr_auto] gap-2">
        <select className="input !py-1.5 text-xs" value={el.style.fontFamily ?? ''} aria-label={t('kits.editor.font')} onChange={(e) => set({ style: { ...el.style, fontFamily: e.target.value || null } })}>
          <option value="">{theme.font} ({t('kits.editor.default')})</option>{FONTS.filter((f) => f !== theme.font).map((f) => <option key={f} value={f}>{f}</option>)}
        </select>
        <input type="number" min={6} max={200} className="input !w-20 !py-1.5 text-xs" dir="ltr" aria-label={t('kits.editor.size')} value={first?.size ?? 24} onChange={(e) => all({ size: Math.max(6, Math.min(200, Number(e.target.value) || 24)) }, 'size')} />
      </div>
      <div className="flex flex-wrap items-center gap-1.5">
        <Toggle on={uniform('bold')} label={t('kits.editor.bold')} onClick={() => all({ bold: !uniform('bold') })}><Bold className="size-4" /></Toggle>
        <Toggle on={uniform('italic')} label={t('kits.editor.italic')} onClick={() => all({ italic: !uniform('italic') })}><Italic className="size-4" /></Toggle>
        <span className="mx-1 h-6 w-px bg-navy-100" />
        <Toggle on={first?.align === 'left'} label={t('kits.editor.alignLeft')} onClick={() => all({ align: 'left' })}><AlignLeft className="size-4" /></Toggle>
        <Toggle on={first?.align === 'center'} label={t('kits.editor.alignCenter')} onClick={() => all({ align: 'center' })}><AlignCenter className="size-4" /></Toggle>
        <Toggle on={first?.align === 'right'} label={t('kits.editor.alignRight')} onClick={() => all({ align: 'right' })}><AlignRight className="size-4" /></Toggle>
        <span className="mx-1 h-6 w-px bg-navy-100" />
        <Toggle on={uniform('bullet')} label={t('kits.editor.bullets')} onClick={() => all({ bullet: !uniform('bullet') })}><List className="size-4" /></Toggle>
      </div>
      <ColorField label={t('kits.editor.textColor')} value={first?.color} onChange={(v) => all({ color: v ?? '#1A1A1A' })} />
      <ColorField label={t('kits.editor.fill')} value={el.style.fill ?? null} allowNone onChange={(v) => set({ style: { ...el.style, fill: v } })} />
      <div className="grid grid-cols-3 gap-2">
        <Field label={t('kits.editor.valign')}>
          <select className="input !py-1.5 text-xs" value={el.style.valign} onChange={(e) => set({ style: { ...el.style, valign: e.target.value as TextEl['style']['valign'] } })}>{(['top', 'middle', 'bottom'] as const).map((v) => <option key={v} value={v}>{t(`kits.editor.valigns.${v}`)}</option>)}</select>
        </Field>
        <Num label={t('kits.editor.lineHeight')} value={el.style.lineHeight} min={0.8} max={3} step={0.05} onChange={(v) => set({ style: { ...el.style, lineHeight: v } }, 'lh')} />
        <Num label={t('kits.editor.padding')} value={el.style.padding} min={0} max={100} onChange={(v) => set({ style: { ...el.style, padding: v } }, 'pad')} />
      </div>
    </div>
  )
}

function ShapeControls({ el, set }: { el: ShapeEl; set: (p: Partial<El>, group?: string) => void }) {
  const { t } = useTranslation()
  return (
    <div className="space-y-3">
      <Field label={t('kits.editor.shape')}>
        <select className="input !py-1.5 text-xs" value={el.shape} onChange={(e) => set({ shape: e.target.value as ShapeEl['shape'] })}>{(['rect', 'round', 'ellipse', 'line'] as const).map((s) => <option key={s} value={s}>{t(`kits.editor.shapes.${s}`)}</option>)}</select>
      </Field>
      {el.shape !== 'line' && <ColorField label={t('kits.editor.fill')} value={el.fill} allowNone onChange={(v) => set({ fill: v })} />}
      <ColorField label={t('kits.editor.stroke')} value={el.stroke} allowNone onChange={(v) => set({ stroke: v, strokeWidth: el.strokeWidth || 3 })} />
      <div className="grid grid-cols-3 gap-2">
        <Num label={t('kits.editor.strokeWidth')} value={el.strokeWidth} min={0} max={60} onChange={(v) => set({ strokeWidth: v }, 'sw')} />
        {el.shape !== 'ellipse' && el.shape !== 'line' && <Num label={t('kits.editor.radius')} value={el.radius} min={0} max={400} onChange={(v) => set({ radius: v }, 'rad')} />}
        <label className="block"><span className="mb-0.5 block text-[11px] text-slate-500">{t('kits.editor.opacity')}</span><input type="range" min={0} max={1} step={0.05} value={el.opacity} onChange={(e) => set({ opacity: Number(e.target.value) }, 'op')} className="w-full accent-gold-600" /></label>
      </div>
    </div>
  )
}
