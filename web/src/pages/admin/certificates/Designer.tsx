import clsx from 'clsx'
import * as pdfjs from 'pdfjs-dist'
import { AlignCenter, AlignLeft, AlignRight, ArrowLeft, Copy, Eye, FileUp, ImagePlus, Layers, QrCode, RectangleHorizontal, Redo2, Save, Trash2, Type, Undo2, ZoomIn, ZoomOut } from 'lucide-react'
import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { useNavigate, useParams } from 'react-router-dom'
import { Badge, Button, Card, ErrorState, Field, Modal, Spinner } from '@/components/ui'
import { useGet } from '@/hooks/useApi'
import { api, errorMessage } from '@/lib/api'
import PageCanvas, { useTemplateFile } from './PageCanvas'
import { PT_TO_MM, uid, type CertElement, type CertTemplate, type TemplateMeta } from './types'
import { dialogs } from '@/lib/dialogs'

pdfjs.GlobalWorkerOptions.workerSrc = new URL('pdfjs-dist/build/pdf.worker.min.mjs', import.meta.url).toString()

/** First page of a PDF drawn to a JPEG, with its real size in millimetres. */
async function pdfToImage(file: File): Promise<{ blob: Blob; widthMm: number; heightMm: number }> {
  const task = pdfjs.getDocument({ data: await file.arrayBuffer() })
  const doc = await task.promise
  const page = await doc.getPage(1)
  const base = page.getViewport({ scale: 1 })
  const viewport = page.getViewport({ scale: Math.min(3, 2400 / base.width) })
  const canvas = document.createElement('canvas')
  canvas.width = Math.floor(viewport.width)
  canvas.height = Math.floor(viewport.height)
  const ctx = canvas.getContext('2d')
  if (!ctx) throw new Error('canvas')
  // JPEG has no transparency: paint the page white first. A JPEG keeps the certificate file small.
  ctx.fillStyle = '#ffffff'
  ctx.fillRect(0, 0, canvas.width, canvas.height)
  await page.render({ canvasContext: ctx, viewport, canvas }).promise
  const blob = await new Promise<Blob>((resolve, reject) => canvas.toBlob((b) => (b ? resolve(b) : reject(new Error('jpeg'))), 'image/jpeg', 0.92))
  void task.destroy()
  return { blob, widthMm: Math.round(base.width * PT_TO_MM * 10) / 10, heightMm: Math.round(base.height * PT_TO_MM * 10) / 10 }
}

async function imageSize(file: File): Promise<{ w: number; h: number }> {
  const url = URL.createObjectURL(file)
  try {
    return await new Promise((resolve, reject) => {
      const img = new Image()
      img.onload = () => resolve({ w: img.naturalWidth, h: img.naturalHeight })
      img.onerror = reject
      img.src = url
    })
  } finally {
    URL.revokeObjectURL(url)
  }
}

const PRESETS: { id: string; w: number; h: number }[] = [{ id: 'a4l', w: 297, h: 210 }, { id: 'a4p', w: 210, h: 297 }, { id: 'a3l', w: 420, h: 297 }]

function Num({ label, value, onChange, step = 1, min, max }: { label: string; value: number; onChange: (v: number) => void; step?: number; min?: number; max?: number }) {
  return (
    <label className="block">
      <span className="mb-1 block text-[11px] font-semibold text-slate-500">{label}</span>
      <input type="number" dir="ltr" className="input !py-1.5 text-sm" value={Number.isFinite(value) ? value : 0} step={step} min={min} max={max} onChange={(e) => onChange(Number(e.target.value))} />
    </label>
  )
}

export default function Designer() {
  const { id } = useParams()
  const { t } = useTranslation()
  const navigate = useNavigate()
  const query = useGet<{ data: CertTemplate; meta: TemplateMeta }>(id ? `/admin/certificate-templates/${id}` : null)

  const [tpl, setTpl] = useState<CertTemplate | null>(null)
  const [elements, setElements] = useState<CertElement[]>([])
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [past, setPast] = useState<CertElement[][]>([])
  const [future, setFuture] = useState<CertElement[][]>([])
  const [dirty, setDirty] = useState(false)
  const [zoom, setZoom] = useState(1)
  const [sample, setSample] = useState(true)
  const [saving, setSaving] = useState(false)
  const [busy, setBusy] = useState<string | null>(null)
  const [message, setMessage] = useState<{ ok: boolean; text: string } | null>(null)
  const [preview, setPreview] = useState<string | null>(null)
  const [assets, setAssets] = useState<Record<string, string>>({})
  const [bgVersion, setBgVersion] = useState(0)
  const pending = useRef<CertElement[] | null>(null)
  const lastEdit = useRef<{ key: string; at: number } | null>(null)
  const bgInput = useRef<HTMLInputElement>(null)
  const assetInput = useRef<HTMLInputElement>(null)

  const meta = query.data?.meta
  const background = useTemplateFile(tpl?.has_background ? tpl.id : null, 'background', `${bgVersion}`)

  useEffect(() => {
    if (query.data?.data && !tpl) {
      setTpl(query.data.data)
      setElements(query.data.data.elements)
    }
  }, [query.data, tpl])

  // Load the template's image assets for the canvas.
  useEffect(() => {
    if (!tpl) return
    const wanted = elements.filter((e) => e.type === 'image' && e.src && !assets[e.src]).map((e) => e.src as string)
    wanted.forEach((name) => {
      api.get(`/admin/certificate-templates/${tpl.id}/file/${name}`, { responseType: 'blob' })
        .then((r) => setAssets((a) => ({ ...a, [name]: URL.createObjectURL(r.data as Blob) })))
        .catch(() => undefined)
    })
  }, [elements, tpl, assets])

  const selected = elements.find((e) => e.id === selectedId) ?? null

  // History ------------------------------------------------------------------------------------------------------
  const record = useCallback((prev: CertElement[], key?: string) => {
    const now = Date.now()
    // Typing in a field is one undo step, not one per character.
    if (key && lastEdit.current?.key === key && now - lastEdit.current.at < 900) { lastEdit.current.at = now; return }
    lastEdit.current = key ? { key, at: now } : null
    setPast((p) => [...p.slice(-49), prev])
    setFuture([])
  }, [])

  const apply = useCallback((next: CertElement[], key?: string) => {
    setElements((prev) => { record(prev, key); return next })
    setDirty(true)
  }, [record])

  const update = (elId: string, patch: Partial<CertElement>) => apply(elements.map((e) => (e.id === elId ? { ...e, ...patch } : e)), `${elId}:${Object.keys(patch).join(',')}`)

  const onCanvasChange = (elId: string, patch: Partial<CertElement>, commit: boolean) => {
    if (commit) {
      if (pending.current) { const base = pending.current; pending.current = null; setPast((p) => [...p.slice(-49), base]); setFuture([]); setDirty(true) }
      return
    }
    setElements((prev) => { if (!pending.current) pending.current = prev; return prev.map((e) => (e.id === elId ? { ...e, ...patch } : e)) })
  }

  const undo = () => setPast((p) => {
    const prev = p.at(-1)
    if (!prev) return p
    setFuture((f) => [elements, ...f])
    setElements(prev)
    setDirty(true)
    return p.slice(0, -1)
  })
  const redo = () => setFuture((f) => {
    const next = f[0]
    if (!next) return f
    setPast((p) => [...p, elements])
    setElements(next)
    setDirty(true)
    return f.slice(1)
  })

  // Adding & removing ---------------------------------------------------------------------------------------------
  const add = (type: CertElement['type']) => {
    const base = { id: uid(), x: 35, y: 40, w: 30, h: 8 }
    const el: CertElement = type === 'text'
      ? { ...base, type, text: t('studio.designer.newText'), font: 'xbriyaz', size: 16, color: '#222222', bold: false, align: 'center', valign: 'middle', dir: 'rtl', line: 1.4 }
      : type === 'qr' ? { ...base, type, x: 45, y: 72, w: 8, h: 11.4 }
      : type === 'rect' ? { ...base, type, h: 0.4, w: 40, x: 30, y: 50, stroke: '#7b1e3a', stroke_width: 0.3, fill: '#7b1e3a', radius: 0 }
      : { ...base, type, w: 14, h: 14 }
    apply([...elements, el])
    setSelectedId(el.id)
    if (type === 'image') assetInput.current?.click()
  }
  const remove = (elId: string) => { apply(elements.filter((e) => e.id !== elId)); setSelectedId(null) }
  const duplicate = (elId: string) => {
    const src = elements.find((e) => e.id === elId)
    if (!src) return
    const copy = { ...src, id: uid(), x: src.x + 2, y: src.y + 2 }
    apply([...elements, copy])
    setSelectedId(copy.id)
  }
  const reorder = (elId: string, dir: 1 | -1) => {
    const i = elements.findIndex((e) => e.id === elId)
    const j = i + dir
    if (i < 0 || j < 0 || j >= elements.length) return
    const next = [...elements]
    ;[next[i], next[j]] = [next[j], next[i]]
    apply(next)
  }

  // Keyboard: arrows nudge, Delete removes, Ctrl+D duplicates, Ctrl+Z / Ctrl+Y undo and redo ------------------------
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const target = e.target as HTMLElement
      if (['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName)) return
      const mod = e.ctrlKey || e.metaKey
      if (mod && e.key.toLowerCase() === 'z') { e.preventDefault(); e.shiftKey ? redo() : undo(); return }
      if (mod && e.key.toLowerCase() === 'y') { e.preventDefault(); redo(); return }
      if (!selected) return
      if (mod && e.key.toLowerCase() === 'd') { e.preventDefault(); duplicate(selected.id) }
      else if (e.key === 'Delete' || e.key === 'Backspace') { e.preventDefault(); remove(selected.id) }
      else if (e.key.startsWith('Arrow')) {
        e.preventDefault()
        const step = e.shiftKey ? 1 : 0.2
        const patch = e.key === 'ArrowLeft' ? { x: selected.x - step } : e.key === 'ArrowRight' ? { x: selected.x + step } : e.key === 'ArrowUp' ? { y: selected.y - step } : { y: selected.y + step }
        update(selected.id, Object.fromEntries(Object.entries(patch).map(([k, v]) => [k, Math.round(v * 100) / 100])))
      }
    }
    window.addEventListener('keydown', onKey)
    return () => window.removeEventListener('keydown', onKey)
  })

  useEffect(() => {
    const warn = (e: BeforeUnloadEvent) => { if (dirty) e.preventDefault() }
    window.addEventListener('beforeunload', warn)
    return () => window.removeEventListener('beforeunload', warn)
  }, [dirty])

  if (query.isLoading || (query.data && !tpl)) return <Spinner />
  if (query.error || !tpl || !meta) return <ErrorState onRetry={() => query.refetch()} />

  // Saving, preview, files ----------------------------------------------------------------------------------------
  const save = async () => {
    setSaving(true)
    setMessage(null)
    try {
      const { data } = await api.put<{ data: CertTemplate }>(`/admin/certificate-templates/${tpl.id}`, { name_ar: tpl.name_ar, name_en: tpl.name_en, kind: tpl.kind, width_mm: tpl.width_mm, height_mm: tpl.height_mm, elements })
      setTpl(data.data)
      setDirty(false)
      setMessage({ ok: true, text: t('studio.designer.saved') })
    } catch (e) {
      setMessage({ ok: false, text: errorMessage(e) })
    } finally {
      setSaving(false)
    }
  }

  const openPreview = async () => {
    setBusy('preview')
    setMessage(null)
    try {
      const r = await api.post('/admin/certificate-templates/preview', { template_id: tpl.id, width_mm: tpl.width_mm, height_mm: tpl.height_mm, elements }, { responseType: 'blob' })
      setPreview(URL.createObjectURL(r.data as Blob))
    } catch (e) {
      setMessage({ ok: false, text: errorMessage(e) })
    } finally {
      setBusy(null)
    }
  }

  const uploadBackground = async (file: File) => {
    setBusy('background')
    setMessage(null)
    try {
      const form = new FormData()
      if (file.type === 'application/pdf' || file.name.toLowerCase().endsWith('.pdf')) {
        const { blob, widthMm, heightMm } = await pdfToImage(file)
        form.append('image', blob, 'background.jpg')
        form.append('source_pdf', file)
        form.append('width_mm', String(widthMm))
        form.append('height_mm', String(heightMm))
      } else {
        // A picture takes the page size of its orientation (A4), keeping its own proportions.
        const { w, h } = await imageSize(file)
        const width = w >= h ? 297 : 210
        form.append('image', file)
        form.append('width_mm', String(width))
        form.append('height_mm', String(Math.round(((width * h) / w) * 10) / 10))
      }
      const { data } = await api.post<{ data: CertTemplate }>(`/admin/certificate-templates/${tpl.id}/background`, form)
      setTpl({ ...tpl, ...data.data, name_ar: tpl.name_ar, name_en: tpl.name_en, kind: tpl.kind })
      setBgVersion((v) => v + 1)
      setMessage({ ok: true, text: t('studio.designer.backgroundSet') })
    } catch (e) {
      setMessage({ ok: false, text: errorMessage(e) })
    } finally {
      setBusy(null)
    }
  }

  const removeBackground = async () => {
    setBusy('background')
    try {
      const { data } = await api.delete<{ data: CertTemplate }>(`/admin/certificate-templates/${tpl.id}/background`)
      setTpl({ ...tpl, has_background: data.data.has_background, has_source_pdf: false })
      setBgVersion((v) => v + 1)
    } catch (e) {
      setMessage({ ok: false, text: errorMessage(e) })
    } finally {
      setBusy(null)
    }
  }

  const uploadAsset = async (file: File) => {
    if (!selected || selected.type !== 'image') return
    setBusy('asset')
    try {
      const form = new FormData()
      form.append('image', file)
      const { data } = await api.post<{ data: { name: string } }>(`/admin/certificate-templates/${tpl.id}/assets`, form)
      setAssets((a) => ({ ...a, [data.data.name]: URL.createObjectURL(file) }))
      update(selected.id, { src: data.data.name })
    } catch (e) {
      setMessage({ ok: false, text: errorMessage(e) })
    } finally {
      setBusy(null)
    }
  }

  const setSize = (w: number, h: number) => { setTpl({ ...tpl, width_mm: w, height_mm: h }); setDirty(true) }
  const tokens = meta.tokens[tpl.kind] ?? []
  const insertToken = (token: string) => {
    if (!selected || selected.type !== 'text') return
    update(selected.id, { text: `${selected.text ?? ''}{{${token}}}` })
  }

  return (
    <div className="-mx-2">
      {/* Top bar */}
      <div className="mb-4 flex flex-wrap items-center gap-3 rounded-2xl border border-navy-100 bg-white p-3">
        <Button variant="ghost" size="sm" icon={<ArrowLeft className="size-4 rtl:rotate-180" />} onClick={async () => (!dirty || await dialogs.confirm(t('studio.designer.leave'))) && navigate('/admin/certificate-templates')}>{t('studio.designer.back')}</Button>
        <div className="grid min-w-[16rem] flex-1 gap-2 sm:grid-cols-2">
          <input dir="rtl" className="input !py-1.5 text-sm font-bold" value={tpl.name_ar} onChange={(e) => { setTpl({ ...tpl, name_ar: e.target.value }); setDirty(true) }} aria-label={t('studio.designer.nameAr')} />
          <input dir="ltr" className="input !py-1.5 text-sm font-bold" value={tpl.name_en} onChange={(e) => { setTpl({ ...tpl, name_en: e.target.value }); setDirty(true) }} aria-label={t('studio.designer.nameEn')} />
        </div>
        <Badge color={tpl.kind === 'trainer' ? 'gold' : 'navy'}>{t(`studio.kind.${tpl.kind}`)}</Badge>
        {tpl.is_default && <Badge color="green">{t('studio.list.default')}</Badge>}
        <div className="ms-auto flex items-center gap-2">
          <Button variant="ghost" size="sm" aria-label="undo" disabled={!past.length} icon={<Undo2 className="size-4" />} onClick={undo} />
          <Button variant="ghost" size="sm" aria-label="redo" disabled={!future.length} icon={<Redo2 className="size-4" />} onClick={redo} />
          <Button variant="outline" loading={busy === 'preview'} icon={<Eye className="size-4" />} onClick={openPreview}>{t('studio.designer.previewPdf')}</Button>
          <Button variant="gold" loading={saving} disabled={!dirty} icon={<Save className="size-4" />} onClick={save}>{dirty ? t('studio.designer.save') : t('studio.designer.savedShort')}</Button>
        </div>
      </div>
      {message && <div className={clsx('mb-4 rounded-xl p-3 text-sm', message.ok ? 'bg-emerald-50 text-emerald-800' : 'bg-red-50 text-danger')}>{message.text}</div>}

      <div className="grid gap-4 xl:grid-cols-[15rem_minmax(0,1fr)_18rem]">
        {/* Left: tools */}
        <div className="space-y-4">
          <Card className="space-y-3">
            <h3 className="text-sm font-bold text-navy-900">{t('studio.designer.background')}</h3>
            <input ref={bgInput} type="file" hidden accept="application/pdf,image/png,image/jpeg,image/webp" onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) void uploadBackground(f) }} />
            <Button variant="outline" size="sm" className="w-full" loading={busy === 'background'} icon={<FileUp className="size-4" />} onClick={() => bgInput.current?.click()}>{tpl.has_background ? t('studio.designer.replaceBackground') : t('studio.designer.uploadBackground')}</Button>
            <p className="text-xs leading-relaxed text-slate-500">{t('studio.designer.backgroundHint')}</p>
            {tpl.has_background && <button type="button" className="text-xs font-semibold text-danger hover:underline" onClick={removeBackground}>{t('studio.designer.removeBackground')}</button>}
            <div>
              <div className="label">{t('studio.designer.pageSize')}</div>
              <div className="flex flex-wrap gap-1.5">
                {PRESETS.map((p) => <button key={p.id} type="button" onClick={() => setSize(p.w, p.h)} className={clsx('rounded-full border px-2.5 py-1 text-xs font-semibold', tpl.width_mm === p.w && tpl.height_mm === p.h ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 text-slate-600 hover:border-gold-400')}>{t(`studio.designer.sizes.${p.id}`)}</button>)}
              </div>
              <div className="mt-2 grid grid-cols-2 gap-2"><Num label="mm ↔" value={tpl.width_mm} onChange={(v) => setSize(v, tpl.height_mm)} min={50} max={1000} /><Num label="mm ↕" value={tpl.height_mm} onChange={(v) => setSize(tpl.width_mm, v)} min={50} max={1000} /></div>
            </div>
          </Card>

          <Card className="space-y-3">
            <h3 className="text-sm font-bold text-navy-900">{t('studio.designer.add')}</h3>
            <div className="grid grid-cols-2 gap-2">
              {([['text', Type], ['qr', QrCode], ['image', ImagePlus], ['rect', RectangleHorizontal]] as const).map(([type, Icon]) => (
                <button key={type} type="button" onClick={() => add(type)} className="flex flex-col items-center gap-1 rounded-xl border border-navy-100 bg-white p-3 text-xs font-semibold text-navy-800 transition hover:border-gold-400 hover:bg-ivory"><Icon className="size-5 text-gold-600" />{t(`studio.designer.types.${type}`)}</button>
              ))}
            </div>
            <input ref={assetInput} type="file" hidden accept="image/png,image/jpeg,image/webp" onChange={(e) => { const f = e.target.files?.[0]; e.target.value = ''; if (f) void uploadAsset(f) }} />
          </Card>

          <Card className="space-y-2">
            <h3 className="text-sm font-bold text-navy-900">{t('studio.designer.layers')}</h3>
            {elements.length === 0 && <p className="text-xs text-slate-400">{t('studio.designer.noElements')}</p>}
            <ul className="max-h-64 space-y-1 overflow-y-auto">
              {[...elements].reverse().map((el) => (
                <li key={el.id}>
                  <button type="button" onClick={() => setSelectedId(el.id)} className={clsx('flex w-full items-center gap-2 rounded-lg px-2 py-1.5 text-start text-xs', el.id === selectedId ? 'bg-navy-900 text-white' : 'text-slate-700 hover:bg-ivory')}>
                    <Layers className="size-3.5 shrink-0 opacity-60" /><span className="truncate">{el.type === 'text' ? (el.text ?? '').split('\n')[0] || '—' : t(`studio.designer.types.${el.type}`)}</span>
                  </button>
                </li>
              ))}
            </ul>
          </Card>
        </div>

        {/* Center: the page */}
        <div className="min-w-0">
          <div className="mb-2 flex flex-wrap items-center justify-between gap-2 text-xs text-slate-500">
            <label className="inline-flex items-center gap-2 font-semibold text-navy-800"><input type="checkbox" className="size-4 accent-gold-600" checked={sample} onChange={(e) => setSample(e.target.checked)} />{t('studio.designer.sampleData')}</label>
            <span className="hidden sm:block">{t('studio.designer.tips')}</span>
            <div className="flex items-center gap-1">
              <Button variant="ghost" size="sm" aria-label="zoom out" icon={<ZoomOut className="size-4" />} onClick={() => setZoom((z) => Math.max(0.5, Math.round((z - 0.1) * 10) / 10))} />
              <span className="w-10 text-center font-mono">{Math.round(zoom * 100)}%</span>
              <Button variant="ghost" size="sm" aria-label="zoom in" icon={<ZoomIn className="size-4" />} onClick={() => setZoom((z) => Math.min(1.8, Math.round((z + 0.1) * 10) / 10))} />
            </div>
          </div>
          <div className="overflow-auto rounded-2xl border border-navy-100 bg-slate-200/60 p-4">
            <div className="mx-auto shadow-glass" style={{ width: `${zoom * 100}%`, maxWidth: `${zoom * 1400}px` }}>
              <PageCanvas
                widthMm={tpl.width_mm} heightMm={tpl.height_mm} elements={elements} background={background} assets={assets}
                values={sample ? meta.sample : null} selectedId={selectedId} interactive onSelect={setSelectedId} onChange={onCanvasChange}
              />
            </div>
          </div>
        </div>

        {/* Right: properties */}
        <Card className="h-fit space-y-4">
          {!selected ? (
            <div className="py-8 text-center text-sm text-slate-500"><Layers className="mx-auto mb-2 size-8 text-slate-300" />{t('studio.designer.selectHint')}</div>
          ) : (
            <>
              <div className="flex items-center justify-between">
                <h3 className="text-sm font-bold text-navy-900">{t(`studio.designer.types.${selected.type}`)}</h3>
                <div className="flex gap-1">
                  <Button variant="ghost" size="sm" aria-label="forward" onClick={() => reorder(selected.id, 1)}>↑</Button>
                  <Button variant="ghost" size="sm" aria-label="backward" onClick={() => reorder(selected.id, -1)}>↓</Button>
                  <Button variant="ghost" size="sm" aria-label="duplicate" icon={<Copy className="size-4" />} onClick={() => duplicate(selected.id)} />
                  <Button variant="ghost" size="sm" aria-label="delete" icon={<Trash2 className="size-4 text-danger" />} onClick={() => remove(selected.id)} />
                </div>
              </div>

              {selected.type === 'text' && (
                <>
                  <Field label={t('studio.designer.content')}>
                    <textarea rows={4} dir={selected.dir ?? 'rtl'} className="input text-sm" value={selected.text ?? ''} onChange={(e) => update(selected.id, { text: e.target.value })} />
                  </Field>
                  <div>
                    <div className="label">{t('studio.designer.placeholders')}</div>
                    <div className="flex flex-wrap gap-1">
                      {tokens.map((k) => <button key={k} type="button" onClick={() => insertToken(k)} title={meta.sample[k]} className="rounded-md bg-navy-100/70 px-1.5 py-0.5 font-mono text-[10.5px] font-semibold text-navy-800 hover:bg-gold-100">{`{{${k}}}`}</button>)}
                    </div>
                  </div>
                  <div className="grid grid-cols-2 gap-2">
                    <Field label={t('studio.designer.font')}><select className="input !py-1.5 text-sm" value={selected.font ?? 'xbriyaz'} onChange={(e) => update(selected.id, { font: e.target.value })}>{Object.entries(meta.fonts).map(([k, v]) => <option key={k} value={k}>{v}</option>)}</select></Field>
                    <Num label={t('studio.designer.size')} value={selected.size ?? 13} min={4} max={200} step={0.5} onChange={(v) => update(selected.id, { size: v })} />
                  </div>
                  <div className="flex items-end gap-2">
                    <label className="block"><span className="mb-1 block text-[11px] font-semibold text-slate-500">{t('studio.designer.color')}</span><input type="color" className="h-9 w-14 cursor-pointer rounded-lg border border-navy-100 bg-white p-1" value={selected.color || '#000000'} onChange={(e) => update(selected.id, { color: e.target.value })} /></label>
                    <button type="button" aria-pressed={!!selected.bold} onClick={() => update(selected.id, { bold: !selected.bold })} className={clsx('h-9 rounded-lg border px-3 text-sm font-black', selected.bold ? 'border-navy-900 bg-navy-900 text-white' : 'border-navy-100 bg-white')}>B</button>
                    <div className="flex overflow-hidden rounded-lg border border-navy-100">
                      {([['right', AlignRight], ['center', AlignCenter], ['left', AlignLeft]] as const).map(([a, Icon]) => <button key={a} type="button" aria-label={a} aria-pressed={selected.align === a} onClick={() => update(selected.id, { align: a })} className={clsx('grid h-9 w-9 place-items-center', selected.align === a ? 'bg-navy-900 text-white' : 'bg-white')}><Icon className="size-4" /></button>)}
                    </div>
                  </div>
                  <div className="grid grid-cols-3 gap-2">
                    <Field label={t('studio.designer.valign')}><select className="input !py-1.5 text-sm" value={selected.valign ?? 'middle'} onChange={(e) => update(selected.id, { valign: e.target.value as CertElement['valign'] })}>{(['top', 'middle', 'bottom'] as const).map((v) => <option key={v} value={v}>{t(`studio.designer.valigns.${v}`)}</option>)}</select></Field>
                    <Field label={t('studio.designer.direction')}><select className="input !py-1.5 text-sm" value={selected.dir ?? 'rtl'} onChange={(e) => update(selected.id, { dir: e.target.value as 'rtl' | 'ltr' })}><option value="rtl">RTL</option><option value="ltr">LTR</option></select></Field>
                    <Num label={t('studio.designer.lineHeight')} value={selected.line ?? 1.4} step={0.1} min={0.8} max={3} onChange={(v) => update(selected.id, { line: v })} />
                  </div>
                </>
              )}

              {selected.type === 'rect' && (
                <div className="grid grid-cols-2 gap-2">
                  <label className="block"><span className="mb-1 block text-[11px] font-semibold text-slate-500">{t('studio.designer.fill')}</span><div className="flex items-center gap-1"><input type="color" className="h-9 w-12 rounded-lg border border-navy-100 bg-white p-1" value={selected.fill || '#ffffff'} onChange={(e) => update(selected.id, { fill: e.target.value })} /><button type="button" className="text-[11px] text-slate-500 underline" onClick={() => update(selected.id, { fill: '' })}>{t('studio.designer.none')}</button></div></label>
                  <label className="block"><span className="mb-1 block text-[11px] font-semibold text-slate-500">{t('studio.designer.stroke')}</span><input type="color" className="h-9 w-12 rounded-lg border border-navy-100 bg-white p-1" value={selected.stroke || '#000000'} onChange={(e) => update(selected.id, { stroke: e.target.value })} /></label>
                  <Num label={t('studio.designer.strokeWidth')} value={selected.stroke_width ?? 0} step={0.1} min={0} max={20} onChange={(v) => update(selected.id, { stroke_width: v })} />
                  <Num label={t('studio.designer.radius')} value={selected.radius ?? 0} min={0} max={100} onChange={(v) => update(selected.id, { radius: v })} />
                </div>
              )}

              {selected.type === 'image' && (
                <Button variant="outline" size="sm" className="w-full" loading={busy === 'asset'} icon={<ImagePlus className="size-4" />} onClick={() => assetInput.current?.click()}>{selected.src ? t('studio.designer.replaceImage') : t('studio.designer.chooseImage')}</Button>
              )}
              {selected.type === 'qr' && <p className="rounded-lg bg-ivory p-2 text-xs text-slate-600">{t('studio.designer.qrHint')}</p>}

              <div>
                <div className="label">{t('studio.designer.position')}</div>
                <div className="grid grid-cols-2 gap-2">
                  <Num label="X %" value={selected.x} step={0.5} onChange={(v) => update(selected.id, { x: v })} />
                  <Num label="Y %" value={selected.y} step={0.5} onChange={(v) => update(selected.id, { y: v })} />
                  <Num label="W %" value={selected.w} step={0.5} min={0} onChange={(v) => update(selected.id, { w: v })} />
                  <Num label="H %" value={selected.h} step={0.5} min={0} onChange={(v) => update(selected.id, { h: v })} />
                </div>
                <div className="mt-2 flex gap-2">
                  <Button variant="outline" size="sm" className="flex-1" onClick={() => update(selected.id, { x: Math.round((50 - selected.w / 2) * 100) / 100 })}>{t('studio.designer.centerH')}</Button>
                  <Button variant="outline" size="sm" className="flex-1" onClick={() => update(selected.id, { y: Math.round((50 - selected.h / 2) * 100) / 100 })}>{t('studio.designer.centerV')}</Button>
                </div>
              </div>
            </>
          )}
        </Card>
      </div>

      <Modal open={!!preview} onClose={() => { if (preview) URL.revokeObjectURL(preview); setPreview(null) }} title={t('studio.designer.previewTitle')} wide>
        {preview && <iframe src={preview} title="preview" className="h-[70vh] w-full rounded-xl border border-navy-100 bg-ivory" />}
        <p className="mt-3 text-xs text-slate-500">{t('studio.designer.previewHint')}</p>
      </Modal>
    </div>
  )
}
