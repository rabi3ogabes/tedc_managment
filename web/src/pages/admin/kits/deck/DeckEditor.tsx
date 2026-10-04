import clsx from 'clsx'
import { AudioLines, CheckCircle2, CircleAlert, CloudUpload, Download, Hand, History, PanelRight, Image as ImageIcon, Loader2, MessageSquare, MessageSquareDashed, MousePointer2, Play, Redo2, ScanSearch, Shapes, Sparkles, Square, Type, Undo2, Video, ZoomIn, ZoomOut } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import { useSearchParams } from 'react-router-dom'
import { useTranslation } from 'react-i18next'
import { Avatar, Button, Spinner } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { useAuth } from '@/lib/auth'
import CommentsPanel from '../comments/CommentsPanel'
import { useCommentActions, useKitComments } from '../comments/api'
import type { Anchor, Asset, Finding, Kit, KitComment, KitFile, MediaKind } from '../types'
import VideoStudio from '../ai/VideoStudio'
import MediaPickerDialog from './MediaPickerDialog'
import Inspector from './Inspector'
import { audioEl, clone, imageEl, isMedia, newSlide, para, shapeEl, slideText, slideTitle, textEl, textParagraphsFromString, uid, videoEl, type Deck, type El, type LayoutId, type Slide } from './model'
import { exportPptx } from './pptx'
import Presenter from './Presenter'
import SlideCanvas, { type PinDraft } from './SlideCanvas'
import SlideRail from './SlideRail'
import { AiPanel, ChecksPanel, VersionsPanel } from './SidePanels'
import { useDeckEditor } from './useDeckEditor'

type Tab = 'format' | 'comments' | 'ai' | 'checks' | 'history'
type PickerState = { mode: 'ai' | 'library' | 'upload'; target: 'element' | 'new' | 'background'; kind?: MediaKind } | null

const mapSlide = (d: Deck, id: string, fn: (s: Slide) => Slide): Deck => ({ ...d, slides: d.slides.map((s) => (s.id === id ? fn(s) : s)) })
const mapEl = (s: Slide, id: string, fn: (e: El) => El): Slide => ({ ...s, elements: s.elements.map((e) => (e.id === id ? fn(e) : e)) })

function Tool({ label, onClick, active, disabled, children }: { label: string; onClick: () => void; active?: boolean; disabled?: boolean; children: React.ReactNode }) {
  return (
    <button type="button" title={label} aria-label={label} aria-pressed={active} disabled={disabled} onClick={onClick}
      className={clsx('grid size-9 place-items-center rounded-lg transition disabled:opacity-35', active ? 'bg-navy-900 text-white shadow' : 'text-navy-800 hover:bg-navy-100/70')}>
      {children}
    </button>
  )
}

const Divider = () => <span className="mx-1 h-6 w-px shrink-0 bg-navy-100" />

/** The slide studio: the kit developer and the QA team edit, review and comment on the same deck. */
export default function DeckEditor({ kit, file, onFileChange, active = true }: { kit: Kit; file: KitFile; onFileChange: (f: KitFile) => void; active?: boolean }) {
  const { t } = useTranslation()
  const { user } = useAuth()
  const ed = useDeckEditor(kit.id, file.id, !!kit.can?.edit, user?.id)
  const comments = useKitComments(kit.id, file.id)
  const commentActions = useCommentActions(kit.id)
  const [activeId, setActiveId] = useState<string | null>(null)
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [mode, setMode] = useState<'edit' | 'review'>('edit')
  const [tab, setTabState] = useState<Tab>('format')
  const [panelOpen, setPanelOpen] = useState(() => window.innerWidth >= 1180)
  const setTab = useCallback((t: Tab) => { setTabState(t); setPanelOpen(true) }, [])
  const [zoom, setZoom] = useState<'fit' | number>('fit')
  const [pin, setPin] = useState<PinDraft | null>(null)
  const [slideDraft, setSlideDraft] = useState(false)
  const [activeComment, setActiveComment] = useState<string | null>(null)
  const [picker, setPicker] = useState<PickerState>(null)
  const [studio, setStudio] = useState(false)
  const [present, setPresent] = useState(false)
  const [exporting, setExporting] = useState(false)
  const [shapeMenu, setShapeMenu] = useState(false)
  const [toast, setToast] = useState<string | null>(null)
  const [area, setArea] = useState<HTMLDivElement | null>(null)
  const [box, setBox] = useState({ w: 900, h: 600 })
  const clipboard = useRef<El | null>(null)
  const [params, setParams] = useSearchParams()
  const { deck, apply } = ed

  const canEdit = ed.canEdit && !!kit.can?.edit
  const canGenerate = !!kit.can?.generate
  const slide = deck?.slides.find((s) => s.id === activeId) ?? deck?.slides[0] ?? null
  const selected = slide?.elements.find((e) => e.id === selectedId) ?? null
  const allComments = useMemo(() => comments.data?.data ?? [], [comments.data])

  // Keep an active slide selected as slides come and go.
  useEffect(() => { if (deck && (!activeId || !deck.slides.some((s) => s.id === activeId))) setActiveId(deck.slides[0]?.id ?? null) }, [deck, activeId])
  useEffect(() => { ed.setCurrentSlide(slide?.id ?? null) }, [slide?.id, ed])
  useEffect(() => { if (selectedId && !selected) setSelectedId(null) }, [selectedId, selected])
  useEffect(() => { if (toast) { const id = setTimeout(() => setToast(null), 3200); return () => clearTimeout(id) } }, [toast])

  useEffect(() => {
    const el = area
    if (!el) return
    const ro = new ResizeObserver(() => setBox({ w: el.clientWidth, h: el.clientHeight }))
    ro.observe(el)
    setBox({ w: el.clientWidth, h: el.clientHeight })
    return () => ro.disconnect()
  }, [area])

  const stageWidth = useMemo(() => {
    const fit = Math.max(320, Math.min(box.w - 64, (box.h - 48) * (16 / 9)))
    return zoom === 'fit' ? fit : Math.round(fit * zoom)
  }, [box, zoom])

  // Slide-level comment bookkeeping -----------------------------------------------------------
  const slideIndex = useCallback((id: string | null | undefined) => (deck ? deck.slides.findIndex((s) => s.id === id) : -1), [deck])
  const commentsHere = useMemo(() => allComments.filter((c) => c.anchor?.slide_id === slide?.id), [allComments, slide?.id])
  const openCounts = useMemo(() => {
    const m: Record<string, number> = {}
    allComments.forEach((c) => { if (c.status !== 'resolved' && c.anchor?.slide_id) m[c.anchor.slide_id] = (m[c.anchor.slide_id] ?? 0) + 1 })
    return m
  }, [allComments])
  const orphaned = useMemo(() => new Set(allComments.filter((c) => c.anchor?.slide_id && deck && !deck.slides.some((s) => s.id === c.anchor?.slide_id)).map((c) => c.id)), [allComments, deck])

  const anchorLabel = useCallback((a: Anchor | null): string | null => {
    if (!a || a.type === 'file') return null
    const n = a.slide_id ? slideIndex(a.slide_id) : -1
    const base = n >= 0 ? t('kits.editor.slideN', { n: n + 1 }) : a.slide_index != null ? t('kits.editor.slideN', { n: a.slide_index + 1 }) : null
    return base && a.slide_id && n < 0 ? `${base} · ${t('kits.editor.slideRemoved')}` : base
  }, [slideIndex, t])

  const draftAnchor: Anchor | null = useMemo(() => {
    if (!slide) return null
    if (pin) return { type: pin.element_id ? 'element' : 'point', slide_id: slide.id, slide_index: slideIndex(slide.id), element_id: pin.element_id, x: pin.x, y: pin.y }
    if (slideDraft) return { type: 'slide', slide_id: slide.id, slide_index: slideIndex(slide.id) }
    return null
  }, [pin, slideDraft, slide, slideIndex])

  // Editing actions -----------------------------------------------------------------------------
  const updateEl = useCallback((id: string, patch: Partial<El>, opts?: { live?: boolean; group?: string }) => {
    if (!slide) return
    apply((d) => mapSlide(d, slide.id, (s) => mapEl(s, id, (e) => ({ ...e, ...patch }) as El)), opts?.live ? { history: false } : { history: true, group: opts?.group ?? `el:${id}:${Object.keys(patch).join()}` })
  }, [apply, slide])

  const updateSlide = useCallback((patch: Partial<Slide>, group?: string) => {
    if (!slide) return
    apply((d) => mapSlide(d, slide.id, (s) => ({ ...s, ...patch })), { group: group ?? `slide:${Object.keys(patch).join()}` })
  }, [apply, slide])

  const addSlide = (layout: LayoutId, after = true) => {
    if (!deck) return
    const s = newSlide(layout, deck.theme, { title: t('kits.editor.newTitle'), body: t('kits.editor.newBody'), left: t('kits.editor.newLeft'), right: t('kits.editor.newRight'), subtitle: t('kits.editor.newSubtitle') })
    const at = after && slide ? deck.slides.findIndex((x) => x.id === slide.id) + 1 : deck.slides.length
    apply((d) => ({ ...d, slides: [...d.slides.slice(0, at), s, ...d.slides.slice(at)] }))
    setActiveId(s.id)
    setSelectedId(null)
  }

  const duplicateSlide = (id: string) => {
    const src = deck?.slides.find((s) => s.id === id)
    if (!src || !deck) return
    const copy: Slide = { ...clone(src), id: uid('s'), rev: 1, by: null, by_name: null, at: null, elements: clone(src.elements).map((e) => ({ ...e, id: uid('e') })) }
    const at = deck.slides.findIndex((s) => s.id === id) + 1
    apply((d) => ({ ...d, slides: [...d.slides.slice(0, at), copy, ...d.slides.slice(at)] }))
    setActiveId(copy.id)
  }

  const deleteSlide = (id: string) => {
    if (!deck || deck.slides.length < 2) return
    const at = deck.slides.findIndex((s) => s.id === id)
    if (allComments.some((c) => c.anchor?.slide_id === id && c.status !== 'resolved') && !window.confirm(t('kits.editor.deleteSlideWithComments'))) return
    apply((d) => ({ ...d, slides: d.slides.filter((s) => s.id !== id) }))
    setActiveId(deck.slides[Math.max(0, at - 1)]?.id === id ? deck.slides[1]?.id ?? null : deck.slides[Math.max(0, at - 1)]?.id ?? null)
  }

  const moveSlide = (from: string, to: string) => apply((d) => {
    const slides = d.slides.filter((s) => s.id !== from)
    const moving = d.slides.find((s) => s.id === from)
    const target = slides.findIndex((s) => s.id === to)
    if (!moving || target < 0) return d
    const fromIdx = d.slides.findIndex((s) => s.id === from), toIdx = d.slides.findIndex((s) => s.id === to)
    slides.splice(fromIdx < toIdx ? target + 1 : target, 0, moving)
    return { ...d, slides }
  })

  const addElement = (el: El) => {
    if (!slide) return
    apply((d) => mapSlide(d, slide.id, (s) => ({ ...s, elements: [...s.elements, el] })))
    setSelectedId(el.id)
    setTab('format')
    setMode('edit')
  }
  const addText = () => addElement(textEl(400, 260, 480, 90, [para(t('kits.editor.newText'), { size: 30, color: deck?.theme.text ?? '#1A1A1A' }, deck?.theme.dir)]))
  const addShape = (shape: 'rect' | 'round' | 'ellipse' | 'line') => {
    setShapeMenu(false)
    addElement(shapeEl(shape, 480, shape === 'line' ? 360 : 240, shape === 'line' ? 320 : 320, shape === 'line' ? 0 : 220, shape === 'line' ? null : deck?.theme.accent ?? '#A29475', shape === 'line' ? { stroke: deck?.theme.primary ?? '#8A1538', strokeWidth: 4 } : { radius: shape === 'round' ? 24 : 0 }))
  }
  const deleteSelected = () => {
    if (!slide || !selectedId) return
    apply((d) => mapSlide(d, slide.id, (s) => ({ ...s, elements: s.elements.filter((e) => e.id !== selectedId) })))
    setSelectedId(null)
  }
  const duplicateSelected = () => {
    if (!selected || !slide) return
    const copy = { ...clone(selected), id: uid('e'), x: selected.x + 24, y: selected.y + 24 } as El
    addElement(copy)
  }
  const reorder = (to: 'front' | 'back') => {
    if (!slide || !selected) return
    apply((d) => mapSlide(d, slide.id, (s) => ({ ...s, elements: to === 'front' ? [...s.elements.filter((e) => e.id !== selected.id), selected] : [selected, ...s.elements.filter((e) => e.id !== selected.id)] })))
  }

  // Pictures ------------------------------------------------------------------------------------
  const onAsset = (asset: Asset) => {
    if (!slide || !picker) return
    const kind = picker.kind ?? 'image'
    if (picker.target === 'background') updateSlide({ background: { ...slide.background, asset_id: asset.id, src: asset.url } })
    else if (picker.target === 'element' && isMedia(selected) && selected.type === kind) updateEl(selected.id, { asset_id: asset.id, src: asset.url, alt: selected.alt || asset.prompt || asset.name || '' })
    else if (kind === 'video') addElement(videoEl(240, 120, 800, 450, { asset_id: asset.id, src: asset.url, alt: asset.name ?? '' }))
    else if (kind === 'audio') addElement(audioEl(340, 560, 600, 84, { asset_id: asset.id, src: asset.url, alt: asset.prompt ?? asset.name ?? '' }))
    else addElement(imageEl(340, 160, 600, asset.width && asset.height ? Math.round(600 * (asset.height / asset.width)) : 400, { asset_id: asset.id, src: asset.url, alt: asset.prompt ?? asset.name ?? '', fit: 'contain' }))
    setPicker(null)
  }

  /** A picture, video or sound on the slide was pressed: open the chooser for it (AI first when the user may generate). */
  const activate = (el: El) => {
    if (!isMedia(el)) return
    setPicker({ mode: el.asset_id || !canGenerate ? 'library' : 'ai', target: 'element', kind: el.type })
  }
  const addMedia = (kind: 'video' | 'audio') => setPicker({ mode: canGenerate ? 'ai' : 'library', target: 'new', kind })

  // AI ------------------------------------------------------------------------------------------
  const insertSlides = (incoming: Slide[]) => {
    if (!deck || !incoming.length) return
    const at = slide ? deck.slides.findIndex((s) => s.id === slide.id) + 1 : deck.slides.length
    const fresh = incoming.map((s) => ({ ...s, id: uid('s'), rev: 1, elements: s.elements.map((e) => ({ ...e, id: uid('e') })) }))
    apply((d) => ({ ...d, slides: [...d.slides.slice(0, at), ...fresh, ...d.slides.slice(at)] }))
    setActiveId(fresh[0].id)
  }
  const rewrite = async (mode: string) => {
    if (selected?.type !== 'text') return
    const original = selected.paragraphs.map((p) => p.text).join('\n')
    const res = await api.post<{ data: { text: string; changed: boolean; ai_available: boolean } }>(`/admin/kits/${kit.id}/ai/rewrite`, { text: original, mode })
    if (!res.data.data.changed) { setToast(t('kits.ai.noKeyShort')); return }
    updateEl(selected.id, { paragraphs: textParagraphsFromString(res.data.data.text, selected.paragraphs) })
  }

  // Review ---------------------------------------------------------------------------------------
  const createFromFindings = async (findings: Finding[]) => {
    await commentActions.bulk(findings.map((f) => ({
      body: `${f.title}\n${f.suggestion}`, file_id: file.id, category: f.category, severity: f.severity, file_version: file.version,
      anchor: f.slide_id ? { type: f.element_id ? 'element' as const : 'slide' as const, slide_id: f.slide_id, slide_index: f.slide_index, element_id: f.element_id } : { type: 'file' as const },
    })))
    setToast(t('kits.checks.created', { count: findings.length }))
  }
  const deepLink = active ? params.get('comment') : null
  useEffect(() => {
    if (!deepLink || !deck) return
    const c = allComments.find((x) => x.id === deepLink)
    if (!c) return
    const a = c.anchor
    if (a?.slide_id && deck.slides.some((s) => s.id === a.slide_id)) { setActiveId(a.slide_id); setSelectedId(a.element_id ?? null) }
    setActiveComment(c.id)
    setTab('comments')
    setParams((cur) => { const n = new URLSearchParams(cur); n.delete('comment'); return n }, { replace: true })
  }, [deepLink, deck, allComments, setParams])
  const jumpToComment = (c: KitComment) => {
    const a = c.anchor
    if (a?.slide_id && deck?.slides.some((s) => s.id === a.slide_id)) { setActiveId(a.slide_id); setSelectedId(a.element_id ?? null) }
    setActiveComment(c.id)
  }

  // Export ---------------------------------------------------------------------------------------
  const doExport = async () => {
    if (!deck) return
    setExporting(true)
    try {
      await ed.save()
      const blob = await exportPptx(deck, file.name)
      const url = URL.createObjectURL(blob)
      const a = document.createElement('a')
      a.href = url
      a.download = `${file.name}.pptx`
      a.click()
      setTimeout(() => URL.revokeObjectURL(url), 30_000)
      const body = new FormData()
      body.append('file', new File([blob], `${file.name}.pptx`, { type: 'application/vnd.openxmlformats-officedocument.presentationml.presentation' }))
      const res = await api.post<{ data: KitFile }>(`/admin/kits/${kit.id}/files/${file.id}/export`, body)
      onFileChange(res.data.data)
      setToast(t('kits.editor.exported'))
    } catch (e) {
      setToast(errorMessage(e))
    } finally {
      setExporting(false)
    }
  }

  // Keyboard -------------------------------------------------------------------------------------
  const kb = useRef({ undo: ed.undo, redo: ed.redo, save: ed.save })
  kb.current = { undo: ed.undo, redo: ed.redo, save: ed.save }
  useEffect(() => {
    if (!active) return
    const onKey = (e: KeyboardEvent) => {
      const el = e.target instanceof HTMLElement ? e.target : null
      if (el?.closest('input, textarea, select, [contenteditable="true"]')) { if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 's') { e.preventDefault(); void kb.current.save() } return }
      const mod = e.ctrlKey || e.metaKey
      const k = e.key.toLowerCase()
      if (mod && k === 's') { e.preventDefault(); void kb.current.save(); return }
      if (mod && k === 'z') { e.preventDefault(); if (e.shiftKey) kb.current.redo(); else kb.current.undo(); return }
      if (mod && k === 'y') { e.preventDefault(); kb.current.redo(); return }
      if (!deck || !slide) return
      if (e.key === 'PageDown' || e.key === 'PageUp') {
        const i = deck.slides.findIndex((s) => s.id === slide.id) + (e.key === 'PageDown' ? 1 : -1)
        if (deck.slides[i]) { e.preventDefault(); setActiveId(deck.slides[i].id); setSelectedId(null) }
        return
      }
      if (!canEdit || mode === 'review') return
      if (k === 'escape') { setSelectedId(null); return }
      if (!selected) return
      if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); deleteSelected() }
      else if (mod && k === 'd') { e.preventDefault(); duplicateSelected() }
      else if (mod && k === 'c') { clipboard.current = clone(selected) }
      else if (mod && k === 'v' && clipboard.current) { e.preventDefault(); addElement({ ...clone(clipboard.current), id: uid('e'), x: clipboard.current.x + 24, y: clipboard.current.y + 24 } as El) }
      else if (mod && (k === 'b' || k === 'i') && selected.type === 'text') { e.preventDefault(); const key = k === 'b' ? 'bold' : 'italic'; const on = selected.paragraphs.every((p) => p[key]); updateEl(selected.id, { paragraphs: selected.paragraphs.map((p) => ({ ...p, [key]: !on })) }) }
      else if (e.key.startsWith('Arrow')) {
        e.preventDefault()
        const step = e.shiftKey ? 10 : 1
        updateEl(selected.id, { x: selected.x + (e.key === 'ArrowRight' ? step : e.key === 'ArrowLeft' ? -step : 0), y: selected.y + (e.key === 'ArrowDown' ? step : e.key === 'ArrowUp' ? -step : 0) }, { group: `nudge:${selected.id}` })
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  })

  if (ed.status === 'loading' || !deck || !slide) return ed.status === 'error' ? <div className="grid h-full place-items-center p-8 text-center text-slate-500"><CircleAlert className="mx-auto mb-2 size-8 text-danger" />{ed.error ?? t('kits.editor.loadFailed')}</div> : <Spinner className="h-full" />

  const statusBadge = {
    saved: { icon: CheckCircle2, text: t('kits.editor.saved'), cls: 'text-emerald-600' }, dirty: { icon: CloudUpload, text: t('kits.editor.unsaved'), cls: 'text-amber-600' },
    saving: { icon: Loader2, text: t('kits.editor.saving'), cls: 'text-slate-500' }, conflict: { icon: CircleAlert, text: t('kits.editor.conflict'), cls: 'text-red-600' },
    error: { icon: CircleAlert, text: t('kits.editor.saveFailed'), cls: 'text-red-600' }, loading: { icon: Loader2, text: '', cls: '' },
  }[ed.status]
  const dirtyIds = ed.dirtyIds
  const tabs: { id: Tab; icon: typeof Type; label: string; badge?: number }[] = [
    { id: 'format', icon: Shapes, label: t('kits.editor.tabs.format') }, { id: 'comments', icon: MessageSquare, label: t('kits.editor.tabs.comments'), badge: allComments.filter((c) => c.status !== 'resolved').length },
    { id: 'ai', icon: Sparkles, label: t('kits.editor.tabs.ai') }, { id: 'checks', icon: ScanSearch, label: t('kits.editor.tabs.checks') }, { id: 'history', icon: History, label: t('kits.editor.tabs.history') },
  ]

  return (
    <div className="flex h-full min-h-0 flex-col bg-[#EFEAE0]">
      {/* Toolbar */}
      <div className="flex flex-wrap items-center gap-0.5 border-b border-navy-100 bg-white px-3 py-1.5">
        <Tool label={`${t('kits.editor.undo')} (Ctrl+Z)`} onClick={ed.undo} disabled={!ed.canUndo || !canEdit}><Undo2 className="size-4" /></Tool>
        <Tool label={`${t('kits.editor.redo')} (Ctrl+Y)`} onClick={ed.redo} disabled={!ed.canRedo || !canEdit}><Redo2 className="size-4" /></Tool>
        <Divider />
        <Tool label={t('kits.editor.addText')} onClick={addText} disabled={!canEdit}><Type className="size-4" /></Tool>
        <div className="relative">
          <Tool label={t('kits.editor.addShape')} onClick={() => setShapeMenu(!shapeMenu)} disabled={!canEdit} active={shapeMenu}><Square className="size-4" /></Tool>
          {shapeMenu && (
            <div className="absolute start-0 top-full z-30 mt-1 grid w-36 gap-0.5 rounded-xl border border-navy-100 bg-white p-1.5 shadow-glass">
              {(['rect', 'round', 'ellipse', 'line'] as const).map((s) => <button key={s} type="button" onClick={() => addShape(s)} className="rounded-lg px-2.5 py-1.5 text-start text-xs font-semibold text-navy-900 hover:bg-ivory">{t(`kits.editor.shapes.${s}`)}</button>)}
            </div>
          )}
        </div>
        <Tool label={t('kits.editor.addImage')} onClick={() => setPicker({ mode: 'library', target: selected?.type === 'image' ? 'element' : 'new', kind: 'image' })} disabled={!canEdit}><ImageIcon className="size-4" /></Tool>
        <Tool label={t('kits.media.addVideo')} onClick={() => addMedia('video')} disabled={!canEdit}><Video className="size-4" /></Tool>
        <Tool label={t('kits.media.addAudio')} onClick={() => addMedia('audio')} disabled={!canEdit}><AudioLines className="size-4" /></Tool>
        <Divider />
        <Tool label={t('kits.editor.modeEdit')} onClick={() => setMode('edit')} active={mode === 'edit'}><MousePointer2 className="size-4" /></Tool>
        <Tool label={t('kits.editor.modeReview')} onClick={() => { setMode('review'); setTab('comments') }} active={mode === 'review'}><MessageSquareDashed className="size-4" /></Tool>
        <Divider />
        <Tool label={t('kits.editor.zoomOut')} onClick={() => setZoom((z) => Math.max(0.5, (z === 'fit' ? 1 : z) - 0.25))}><ZoomOut className="size-4" /></Tool>
        <button type="button" onClick={() => setZoom('fit')} className="w-12 rounded-md py-1 text-center text-xs font-semibold text-slate-600 hover:bg-navy-100/70" dir="ltr" title={t('kits.editor.fitScreen')}>{zoom === 'fit' ? t('kits.editor.fit') : `${Math.round(zoom * 100)}%`}</button>
        <Tool label={t('kits.editor.zoomIn')} onClick={() => setZoom((z) => Math.min(2, (z === 'fit' ? 1 : z) + 0.25))}><ZoomIn className="size-4" /></Tool>
        <Divider />
        <Tool label={t('kits.editor.panel')} onClick={() => setPanelOpen(!panelOpen)} active={panelOpen}><PanelRight className="size-4" /></Tool>
        <Button size="sm" variant="outline" icon={<Play className="size-4" />} onClick={() => setPresent(true)}>{t('kits.editor.present')}</Button>
        <Button className="ms-1" size="sm" variant="outline" loading={exporting} icon={<Download className="size-4" />} onClick={doExport}>{t('kits.editor.export')}</Button>

        <div className="ms-auto flex items-center gap-3">
          {ed.others.length > 0 && (
            <div className="flex -space-x-2 rtl:space-x-reverse" title={ed.others.map((p) => p.name).join('، ')}>
              {ed.others.slice(0, 4).map((p) => <span key={p.user_id} className="ring-2 ring-white rounded-full"><Avatar name={p.name} size={26} /></span>)}
              <span className="ms-3 self-center text-[11px] font-semibold text-emerald-600">{t('kits.editor.editingNow', { count: ed.others.length })}</span>
            </div>
          )}
          {!canEdit && <span className="inline-flex items-center gap-1 rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-600"><Hand className="size-3.5" />{t('kits.editor.readOnly')}</span>}
          <span className={clsx('inline-flex items-center gap-1.5 text-xs font-semibold', statusBadge.cls)}><statusBadge.icon className={clsx('size-4', ed.status === 'saving' && 'animate-spin')} />{statusBadge.text}</span>
        </div>
      </div>

      {ed.conflicts.length > 0 && (
        <div className="border-b border-red-200 bg-red-50 px-4 py-2.5 text-sm text-red-900">
          <div className="font-bold">{t('kits.editor.conflictTitle')}</div>
          <ul className="mt-1.5 space-y-1.5">
            {ed.conflicts.map((c) => (
              <li key={c.slide_id} className="flex flex-wrap items-center gap-2">
                <button type="button" className="font-semibold underline" onClick={() => setActiveId(c.slide_id)}>{t('kits.editor.slideN', { n: slideIndex(c.slide_id) + 1 })}</button>
                <span className="text-xs">{t('kits.editor.changedBy', { name: c.server.by_name ?? '—' })}</span>
                <Button size="sm" variant="outline" onClick={() => ed.resolveConflict(c.slide_id, 'theirs')}>{t('kits.editor.takeTheirs')}</Button>
                <Button size="sm" variant="danger" onClick={() => ed.resolveConflict(c.slide_id, 'mine')}>{t('kits.editor.keepMine')}</Button>
              </li>
            ))}
          </ul>
        </div>
      )}

      <div className="relative flex min-h-0 flex-1">
        <aside className="hidden w-[228px] shrink-0 border-e border-navy-100 bg-[#F6F2EA] md:block">
          <SlideRail slides={deck.slides} theme={deck.theme} activeId={slide.id} canEdit={canEdit} commentCounts={openCounts} others={ed.others} dirtyIds={dirtyIds}
            onSelect={(id) => { setActiveId(id); setSelectedId(null); setPin(null) }} onAdd={(l) => addSlide(l)} onDuplicate={duplicateSlide} onDelete={deleteSlide} onMove={moveSlide} />
        </aside>

        <main className="relative flex min-w-0 flex-1 flex-col">
          <div ref={setArea} className="min-h-0 flex-1 overflow-auto p-6">
            <div className="mx-auto w-fit">
              <SlideCanvas slide={slide} theme={deck.theme} width={stageWidth} selectedId={selectedId} onSelect={(id) => { setSelectedId(id); if (id) setTabState('format') }} editable={canEdit} mode={mode}
                onChangeEl={updateEl} onBeginGesture={ed.checkpoint} comments={commentsHere.filter((c) => c.status !== 'resolved' || activeComment === c.id)} activeCommentId={activeComment}
                onPinClick={(c) => { setActiveComment(c.id); setTab('comments') }} onPlacePin={(p) => { setPin(p); setSlideDraft(false); setTab('comments') }} draftPin={pin} onActivate={activate} activateLabel={t('kits.media.change')} />
              {mode === 'review' && <p className="mt-3 text-center text-xs font-semibold text-gold-700">{t('kits.editor.reviewHint')}</p>}
            </div>
          </div>
          <div className="flex items-center justify-between gap-3 border-t border-navy-100 bg-white px-4 py-1.5 text-xs text-slate-500">
            <span className="truncate font-semibold text-navy-900" dir="auto">{slideTitle(slide) || t('kits.editor.untitled')}</span>
            <span className="shrink-0">{t('kits.editor.slideOf', { n: deck.slides.findIndex((s) => s.id === slide.id) + 1, total: deck.slides.length })} · {t('kits.editor.words', { count: slideText(slide).split(/\s+/).filter(Boolean).length })}{slide.by_name ? ` · ${t('kits.editor.lastEdit', { name: slide.by_name })}` : ''}</span>
          </div>
        </main>

        <aside className={clsx('w-[368px] max-w-[94vw] shrink-0 flex-col border-s border-navy-100 bg-white max-[1179px]:absolute max-[1179px]:inset-y-0 max-[1179px]:end-0 max-[1179px]:z-30 max-[1179px]:shadow-2xl', panelOpen ? 'flex' : 'hidden')}>
          <div className="flex border-b border-navy-100 px-1.5" role="tablist">
            {tabs.map((tb) => (
              <button key={tb.id} type="button" role="tab" aria-selected={tab === tb.id} onClick={() => setTab(tb.id)} title={tb.label}
                className={clsx('relative flex flex-1 items-center justify-center gap-1.5 px-1 py-3 text-xs font-semibold transition', tab === tb.id ? 'text-navy-900' : 'text-slate-400 hover:text-navy-800')}>
                <tb.icon className="size-4" /><span className="hidden xl:inline">{tb.label}</span>
                {!!tb.badge && <span className="rounded-full bg-red-600 px-1.5 text-[10px] font-bold text-white">{tb.badge}</span>}
                {tab === tb.id && <span className="absolute inset-x-2 bottom-0 h-0.5 rounded-full bg-gold-500" />}
              </button>
            ))}
          </div>
          <div className="min-h-0 flex-1 overflow-y-auto p-4">
            {tab === 'format' && (
              <Inspector slide={slide} theme={deck.theme} selected={selected} canEdit={canEdit}
                onChange={(patch, o) => selected && updateEl(selected.id, patch, o)} onSlide={(p) => updateSlide(p)} onDuplicate={duplicateSelected} onDelete={deleteSelected} onOrder={reorder}
                onPickImage={(m, k) => setPicker({ mode: m, target: 'element', kind: k ?? 'image' })} onClearSlideImage={() => updateSlide({ background: { ...slide.background, asset_id: null, src: null } })} />
            )}
            {tab === 'comments' && (
              <div className="flex h-full min-h-[24rem] flex-col gap-2">
                {kit.can?.comment && !pin && !slideDraft && (
                  <div className="flex gap-1.5">
                    <Button size="sm" variant="outline" icon={<MessageSquareDashed className="size-3.5" />} onClick={() => { setMode('review') }}>{t('kits.editor.pinOnSlide')}</Button>
                    <Button size="sm" variant="ghost" onClick={() => setSlideDraft(true)}>{t('kits.editor.commentSlide')}</Button>
                  </div>
                )}
                {orphaned.size > 0 && <p className="rounded-lg bg-amber-50 px-3 py-1.5 text-[11px] text-amber-800">{t('kits.editor.orphaned', { count: orphaned.size })}</p>}
                <div className="min-h-0 flex-1">
                  <CommentsPanel kit={kit} fileId={file.id} fileVersion={file.version} comments={allComments} loading={comments.isLoading} draft={draftAnchor} onClearDraft={() => { setPin(null); setSlideDraft(false) }}
                    scopeLabel={t('kits.editor.thisSlide')} scopeFilter={(c) => c.anchor?.slide_id === slide.id} activeId={activeComment} onActive={(c) => setActiveComment(c?.id ?? null)} onJump={jumpToComment} anchorLabel={anchorLabel} />
                </div>
              </div>
            )}
            {tab === 'ai' && (
              <AiPanel kit={kit} fileId={file.id} hasTextSelection={selected?.type === 'text'} onInsertSlides={insertSlides} onRewrite={rewrite}
                onImage={() => setPicker({ mode: 'ai', target: selected?.type === 'image' ? 'element' : 'new', kind: 'image' })} />
            )}
            {tab === 'checks' && <ChecksPanel kit={kit} fileId={file.id} canComment={!!kit.can?.comment} onComment={createFromFindings} onJump={(f) => { if (f.slide_id) { setActiveId(f.slide_id); setSelectedId(f.element_id) } }} />}
            {tab === 'history' && <VersionsPanel kit={kit} fileId={file.id} canEdit={canEdit} onSaved={() => ed.save()} onRestored={(d, rev) => { ed.replaceDeck(d, rev); setActiveId(d.slides[0]?.id ?? null); setToast(t('kits.versions.restored')) }} />}
          </div>
        </aside>
      </div>

      {picker && !studio && <MediaPickerDialog kitId={kit.id} kind={picker.kind ?? 'image'} initial={picker.mode} prompt={isMedia(selected) && selected.type === (picker.kind ?? 'image') ? selected.prompt || selected.alt : slideTitle(slide)} canGenerate={!!kit.can?.generate} onPick={onAsset} onClose={() => setPicker(null)} onVideoStudio={() => setStudio(true)} />}
      {studio && <VideoStudio kit={kit as never} onClose={() => { setStudio(false); setPicker(null) }} onSaved={(asset) => { setStudio(false); onAsset(asset) }} />}
      {present && <Presenter deck={deck} start={deck.slides.findIndex((s) => s.id === slide.id)} onClose={() => setPresent(false)} />}
      {toast && <div className="fixed bottom-6 start-1/2 z-[90] -translate-x-1/2 rounded-full bg-navy-900 px-5 py-2.5 text-sm font-semibold text-white shadow-glass rtl:translate-x-1/2">{toast}</div>}
      <span className="sr-only" aria-live="polite">{statusBadge.text}</span>
    </div>
  )
}
