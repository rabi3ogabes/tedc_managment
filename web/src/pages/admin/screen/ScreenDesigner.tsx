import clsx from 'clsx'
import { AlignCenter, AlignLeft, AlignRight, ArrowDown, ArrowUp, Circle, Clock, Copy, Image as ImageIcon, Layers, Percent, PictureInPicture2, RotateCcw, Square, Tag, Trash2, Type, Undo2, Redo2, Users, Calendar } from 'lucide-react'
import { useCallback, useMemo, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Button, Card, Field } from '@/components/ui'
import DesignCanvas from '@/components/screen/DesignCanvas'
import { buildContext, makeElement, presetDesign, TOKENS, type DesignEl, type ElementType, type ScreenDesign } from '@/components/screen/design'
import { sampleDay } from '@/components/screen/sample'
import { useCenterName } from '@/lib/ThemeProvider'
import { ImageField } from '../brand/controls'
import type { ScreenTemplate } from '../../RoomScreen'

const PALETTE: [ElementType, typeof Type][] = [['text', Type], ['logo', PictureInPicture2], ['clock', Clock], ['date', Calendar], ['status', Tag], ['progress', Percent], ['ring', Circle], ['trainees', Users], ['image', ImageIcon], ['shape', Square]]

function Num({ label, value, onChange, min, max, step = 1 }: { label: string; value: number; onChange: (v: number) => void; min?: number; max?: number; step?: number }) {
  return (
    <label className="block"><span className="mb-1 block text-[11px] font-semibold text-slate-500">{label}</span>
      <input type="number" dir="ltr" className="input !py-1.5 text-sm" value={Number.isFinite(value) ? value : 0} min={min} max={max} step={step} onChange={(e) => onChange(Number(e.target.value))} /></label>
  )
}

function Color({ label, value, onChange, allowClear }: { label: string; value: string; onChange: (v: string) => void; allowClear?: boolean }) {
  const { t } = useTranslation()
  const solid = /^#[0-9a-fA-F]{6}/.test(value) ? value.slice(0, 7) : '#ffffff'
  return (
    <label className="block"><span className="mb-1 block text-[11px] font-semibold text-slate-500">{label}</span>
      <span className="flex items-center gap-2"><input type="color" className="h-9 w-12 cursor-pointer rounded-lg border border-navy-100 bg-white p-1" value={solid} onChange={(e) => onChange(e.target.value)} />
        {allowClear && <button type="button" className="text-[11px] font-semibold text-link" onClick={() => onChange('')}>{t('studio.designer.none')}</button>}</span></label>
  )
}

function Group({ title, children }: { title: string; children: ReactNode }) {
  return <div className="space-y-3 border-t border-navy-100 pt-4 first:border-0 first:pt-0"><div className="text-xs font-bold text-navy-900">{title}</div>{children}</div>
}

/** Free-form designer of the room screen: drag the elements where you want them, then colour, background and picture. */
export default function ScreenDesigner({ template, design, onChange }: { template: ScreenTemplate; design: ScreenDesign; onChange: (d: ScreenDesign) => void }) {
  const { t, i18n } = useTranslation()
  const centerName = useCenterName()
  const [list, setList] = useState<'live' | 'idle'>('live')
  const [selected, setSelected] = useState<string | null>(null)
  const [past, setPast] = useState<ScreenDesign[]>([])
  const [future, setFuture] = useState<ScreenDesign[]>([])
  const pending = useRef<ScreenDesign | null>(null)
  const [now] = useState(() => new Date())
  const ctx = useMemo(() => buildContext(sampleDay(now), now, true, i18n.language, centerName, template, list === 'idle'), [now, i18n.language, centerName, template, list])
  const elements = design[list]
  const el = elements.find((e) => e.id === selected) ?? null

  const push = useCallback((prev: ScreenDesign) => { setPast((p) => [...p.slice(-39), prev]); setFuture([]) }, [])
  const apply = (next: ScreenDesign) => { push(design); onChange(next) }
  const setElements = (next: DesignEl[]) => apply({ ...design, [list]: next })
  const patch = (id: string, p: Partial<DesignEl>) => setElements(elements.map((e) => (e.id === id ? { ...e, ...p } : e)))

  const onCanvas = (id: string, p: Partial<DesignEl>, commit: boolean) => {
    if (commit) { if (pending.current) { push(pending.current); pending.current = null } return }
    if (!pending.current) pending.current = design
    onChange({ ...design, [list]: design[list].map((e) => (e.id === id ? { ...e, ...p } : e)) })
  }
  const undo = () => { const prev = past.at(-1); if (!prev) return; setFuture((f) => [design, ...f]); setPast((p) => p.slice(0, -1)); onChange(prev) }
  const redo = () => { const next = future[0]; if (!next) return; setPast((p) => [...p, design]); setFuture((f) => f.slice(1)); onChange(next) }

  const add = (type: ElementType) => { const e = makeElement(type); setElements([...elements, e]); setSelected(e.id) }
  const remove = (id: string) => { setElements(elements.filter((e) => e.id !== id)); setSelected(null) }
  const duplicate = (id: string) => { const s = elements.find((e) => e.id === id); if (!s) return; const c = { ...s, id: makeElement('text').id, x: s.x + 2, y: s.y + 2 }; setElements([...elements, c]); setSelected(c.id) }
  const order = (id: string, d: 1 | -1) => { const i = elements.findIndex((e) => e.id === id); const j = i + d; if (j < 0 || j >= elements.length) return; const next = [...elements];[next[i], next[j]] = [next[j], next[i]]; setElements(next) }
  const bg = design.background
  const setBg = (p: Partial<ScreenDesign['background']>) => apply({ ...design, background: { ...bg, ...p } })

  return (
    <div className="space-y-4">
      <div className="flex flex-wrap items-center gap-3 rounded-2xl border border-navy-100 bg-white p-3">
        <div role="tablist" className="inline-flex rounded-xl border border-navy-100 p-1 text-sm font-bold">
          {(['live', 'idle'] as const).map((k) => <button key={k} type="button" role="tab" aria-selected={list === k} onClick={() => { setList(k); setSelected(null) }} className={clsx('rounded-lg px-4 py-1.5 transition', list === k ? 'bg-navy-900 text-white' : 'text-slate-600 hover:bg-ivory')}>{t(`studio.designer2.tabs.${k}`)}</button>)}
        </div>
        <div className="flex flex-wrap gap-1.5">
          {PALETTE.map(([type, Icon]) => <button key={type} type="button" onClick={() => add(type)} title={t(`studio.designer2.types.${type}`)} className="inline-flex items-center gap-1.5 rounded-lg border border-navy-100 bg-white px-2.5 py-1.5 text-xs font-semibold text-navy-800 transition hover:border-gold-400 hover:bg-ivory"><Icon className="size-4 text-gold-600" />{t(`studio.designer2.types.${type}`)}</button>)}
        </div>
        <div className="ms-auto flex items-center gap-1">
          <Button variant="ghost" size="sm" aria-label="undo" disabled={!past.length} icon={<Undo2 className="size-4" />} onClick={undo} />
          <Button variant="ghost" size="sm" aria-label="redo" disabled={!future.length} icon={<Redo2 className="size-4" />} onClick={redo} />
          <Button variant="outline" size="sm" icon={<RotateCcw className="size-4" />} onClick={() => window.confirm(t('studio.designer2.confirmReset')) && apply(presetDesign(template))}>{t('studio.designer2.reset')}</Button>
        </div>
      </div>

      <div className="grid gap-4 xl:grid-cols-[minmax(0,1fr)_20rem]">
        <div className="min-w-0 space-y-2">
          <div className="overflow-hidden rounded-2xl border border-navy-100 shadow-glass">
            <DesignCanvas design={design} ctx={ctx} list={list} interactive selectedId={selected} onSelect={setSelected} onChange={onCanvas} />
          </div>
          <p className="text-xs text-slate-500">{t('studio.designer2.tips')}</p>
          <Card className="space-y-1">
            <div className="flex items-center gap-2 text-sm font-bold text-navy-900"><Layers className="size-4 text-gold-600" />{t('studio.designer.layers')}</div>
            <ul className="grid gap-1 sm:grid-cols-2">{[...elements].reverse().map((e) => <li key={e.id}><button type="button" onClick={() => setSelected(e.id)} className={clsx('flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-start text-xs', e.id === selected ? 'bg-navy-900 text-white' : 'text-slate-700 hover:bg-ivory')}><span className="font-semibold">{t(`studio.designer2.types.${e.type}`)}</span><span className="truncate opacity-60">{e.type === 'text' ? e.text : ''}</span></button></li>)}</ul>
          </Card>
        </div>

        <Card className="h-fit space-y-4">
          {!el ? (
            <>
              <div className="text-sm font-bold text-navy-900">{t('studio.designer2.background')}</div>
              <div className="grid grid-cols-4 gap-1.5">
                {(['brand', 'solid', 'gradient', 'image'] as const).map((k) => <button key={k} type="button" aria-pressed={bg.type === k} onClick={() => setBg({ type: k })} className={clsx('rounded-lg border px-1 py-2 text-[11px] font-bold transition', bg.type === k ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 hover:border-gold-400')}>{t(`studio.designer2.bg.${k}`)}</button>)}
              </div>
              {bg.type === 'brand' && <p className="text-xs text-slate-500">{t('studio.designer2.bg.brandHint')}</p>}
              {(bg.type === 'solid' || bg.type === 'gradient' || bg.type === 'image') && <Color label={bg.type === 'gradient' ? t('studio.designer2.bg.from') : t('studio.designer2.bg.color')} value={bg.color} onChange={(v) => setBg({ color: v })} />}
              {bg.type === 'gradient' && (
                <>
                  <Color label={t('studio.designer2.bg.to')} value={bg.color2} onChange={(v) => setBg({ color2: v })} />
                  <Field label={`${t('studio.designer2.bg.angle')} · ${bg.angle}°`}><input type="range" min={0} max={360} className="w-full accent-gold-600" value={bg.angle} onChange={(e) => setBg({ angle: Number(e.target.value) })} /></Field>
                </>
              )}
              {bg.type === 'image' && (
                <>
                  <ImageField kind="pattern" label={t('studio.designer2.bg.imageLabel')} value={bg.image || null} aspect="aspect-[16/7]" contain onChange={(url) => setBg({ image: url ?? '' })} />
                  <div className="flex gap-2">{(['cover', 'tile'] as const).map((m) => <button key={m} type="button" aria-pressed={bg.image_fit === m} onClick={() => setBg({ image_fit: m })} className={clsx('flex-1 rounded-lg border px-2 py-1.5 text-xs font-bold', bg.image_fit === m ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100')}>{t(`studio.designer2.bg.${m}`)}</button>)}</div>
                  <Field label={`${t('studio.tpl.bg.opacity')} · ${bg.image_opacity}%`}><input type="range" min={0} max={100} className="w-full accent-gold-600" value={bg.image_opacity} onChange={(e) => setBg({ image_opacity: Number(e.target.value) })} /></Field>
                  {bg.image_fit === 'tile' && <Field label={`${t('studio.tpl.bg.size')} · ${bg.image_size}px`}><input type="range" min={16} max={600} className="w-full accent-gold-600" value={bg.image_size} onChange={(e) => setBg({ image_size: Number(e.target.value) })} /></Field>}
                </>
              )}
              <Group title={t('studio.designer2.overlay')}>
                <Color label={t('studio.designer2.bg.color')} value={bg.overlay} onChange={(v) => setBg({ overlay: v })} />
                <Field label={`${t('studio.designer2.overlayStrength')} · ${bg.overlay_opacity}%`}><input type="range" min={0} max={95} className="w-full accent-gold-600" value={bg.overlay_opacity} onChange={(e) => setBg({ overlay_opacity: Number(e.target.value) })} /></Field>
              </Group>
              <p className="rounded-lg bg-ivory p-2 text-xs text-slate-500">{t('studio.designer2.selectHint')}</p>
            </>
          ) : (
            <>
              <div className="flex items-center justify-between">
                <div className="text-sm font-bold text-navy-900">{t(`studio.designer2.types.${el.type}`)}</div>
                <div className="flex gap-1">
                  <Button variant="ghost" size="sm" aria-label="forward" icon={<ArrowUp className="size-4" />} onClick={() => order(el.id, 1)} />
                  <Button variant="ghost" size="sm" aria-label="backward" icon={<ArrowDown className="size-4" />} onClick={() => order(el.id, -1)} />
                  <Button variant="ghost" size="sm" aria-label="duplicate" icon={<Copy className="size-4" />} onClick={() => duplicate(el.id)} />
                  <Button variant="ghost" size="sm" aria-label="delete" icon={<Trash2 className="size-4 text-danger" />} onClick={() => remove(el.id)} />
                </div>
              </div>

              {el.type === 'text' && (
                <Group title={t('studio.designer.content')}>
                  <textarea rows={3} dir="auto" className="input text-sm" value={el.text} onChange={(e) => patch(el.id, { text: e.target.value })} />
                  <div className="flex flex-wrap gap-1">{TOKENS.map((k) => <button key={k} type="button" onClick={() => patch(el.id, { text: `${el.text}{{${k}}}` })} className="rounded-md bg-navy-100/70 px-1.5 py-0.5 font-mono text-[10.5px] font-semibold text-navy-800 hover:bg-gold-100">{`{{${k}}}`}</button>)}</div>
                </Group>
              )}
              {el.type === 'image' && <ImageField kind="pattern" label={t('studio.designer2.types.image')} value={el.src || null} aspect="aspect-video" contain onChange={(url) => patch(el.id, { src: url ?? '' })} />}
              {el.type === 'logo' && <label className="flex items-center gap-2 text-sm font-semibold text-navy-900"><input type="checkbox" className="size-4 accent-gold-600" checked={el.logo_dark} onChange={(e) => patch(el.id, { logo_dark: e.target.checked })} />{t('studio.designer2.logoLight')}</label>}

              {['text', 'clock', 'date', 'status', 'trainees'].includes(el.type) && (
                <Group title={t('studio.designer2.textStyle')}>
                  <div className="grid grid-cols-2 gap-2"><Num label={t('studio.designer.size')} value={el.size} min={8} max={400} onChange={(v) => patch(el.id, { size: v })} /><Color label={t('studio.designer.color')} value={el.color} onChange={(v) => patch(el.id, { color: v })} /></div>
                  {el.type !== 'trainees' && (
                    <div className="flex items-center gap-2">
                      <button type="button" aria-pressed={el.bold} onClick={() => patch(el.id, { bold: !el.bold })} className={clsx('h-9 rounded-lg border px-3 text-sm font-black', el.bold ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100')}>B</button>
                      <div className="flex overflow-hidden rounded-lg border border-navy-100">{([['start', AlignRight], ['center', AlignCenter], ['end', AlignLeft]] as const).map(([a, Icon]) => <button key={a} type="button" aria-label={a} aria-pressed={el.align === a} onClick={() => patch(el.id, { align: a })} className={clsx('grid h-9 w-9 place-items-center', el.align === a ? 'bg-navy-900 text-white' : 'bg-white')}><Icon className="size-4" /></button>)}</div>
                      <select className="input !w-auto !py-1.5 text-xs" value={el.valign} onChange={(e) => patch(el.id, { valign: e.target.value as DesignEl['valign'] })}>{(['start', 'center', 'end'] as const).map((v) => <option key={v} value={v}>{t(`studio.designer.valigns.${v === 'start' ? 'top' : v === 'center' ? 'middle' : 'bottom'}`)}</option>)}</select>
                    </div>
                  )}
                </Group>
              )}
              {el.type === 'trainees' && <Group title={t('studio.designer2.listStyle')}><Num label={t('studio.designer2.columns')} value={el.columns} min={1} max={8} onChange={(v) => patch(el.id, { columns: v })} /><label className="flex items-center gap-2 text-sm font-semibold text-navy-900"><input type="checkbox" className="size-4 accent-gold-600" checked={el.show_school} onChange={(e) => patch(el.id, { show_school: e.target.checked })} />{t('studio.tpl.school')}</label></Group>}
              {(el.type === 'ring' || el.type === 'progress') && <Group title={t('studio.designer2.accent')}><Color label={t('studio.designer.color')} value={el.type === 'progress' ? el.color : el.accent || '#e2b54f'} onChange={(v) => patch(el.id, el.type === 'progress' ? { color: v, accent: v } : { accent: v })} /></Group>}

              {['text', 'status', 'trainees', 'shape', 'progress', 'logo', 'image', 'clock', 'date'].includes(el.type) && (
                <Group title={t('studio.designer2.box')}>
                  <div className="grid grid-cols-2 gap-2">
                    <Color label={t('studio.designer.fill')} value={el.fill === 'transparent' ? '#000000' : el.fill} allowClear onChange={(v) => patch(el.id, { fill: v === '' ? 'transparent' : v })} />
                    <Color label={t('studio.designer2.fill2')} value={el.fill2 || '#000000'} allowClear onChange={(v) => patch(el.id, { fill2: v })} />
                  </div>
                  {el.fill2 && <Field label={`${t('studio.designer2.bg.angle')} · ${el.angle}°`}><input type="range" min={0} max={360} className="w-full accent-gold-600" value={el.angle} onChange={(e) => patch(el.id, { angle: Number(e.target.value) })} /></Field>}
                  <div className="grid grid-cols-3 gap-2"><Num label={t('studio.designer.radius')} value={el.radius} min={0} max={200} onChange={(v) => patch(el.id, { radius: v })} /><Num label={t('studio.designer2.borderWidth')} value={el.border_width} min={0} max={20} onChange={(v) => patch(el.id, { border_width: v })} /><Color label={t('studio.designer2.border')} value={el.border || '#ffffff'} onChange={(v) => patch(el.id, { border: v })} /></div>
                  <Field label={`${t('studio.tpl.bg.opacity')} · ${el.opacity}%`}><input type="range" min={0} max={100} className="w-full accent-gold-600" value={el.opacity} onChange={(e) => patch(el.id, { opacity: Number(e.target.value) })} /></Field>
                </Group>
              )}

              <Group title={t('studio.designer.position')}>
                <div className="grid grid-cols-2 gap-2">
                  <Num label="X %" value={el.x} step={0.5} onChange={(v) => patch(el.id, { x: v })} /><Num label="Y %" value={el.y} step={0.5} onChange={(v) => patch(el.id, { y: v })} />
                  <Num label="W %" value={el.w} step={0.5} min={0.5} onChange={(v) => patch(el.id, { w: v })} /><Num label="H %" value={el.h} step={0.5} min={0.5} onChange={(v) => patch(el.id, { h: v })} />
                </div>
                <div className="flex gap-2">
                  <Button variant="outline" size="sm" className="flex-1" onClick={() => patch(el.id, { x: Math.round((50 - el.w / 2) * 100) / 100 })}>{t('studio.designer.centerH')}</Button>
                  <Button variant="outline" size="sm" className="flex-1" onClick={() => patch(el.id, { y: Math.round((50 - el.h / 2) * 100) / 100 })}>{t('studio.designer.centerV')}</Button>
                </div>
              </Group>
            </>
          )}
        </Card>
      </div>
    </div>
  )
}
