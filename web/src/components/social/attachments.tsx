import clsx from 'clsx'
import { ChevronLeft, ChevronRight, Download, ExternalLink, FileArchive, FileSpreadsheet, FileText, Link2, Music, Paperclip, Presentation, RotateCw, X } from 'lucide-react'
import { useCallback, useEffect, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { Button } from '@/components/ui'
import { api, errorMessage } from '@/lib/api'
import { fmt } from '@/lib/format'
import { toast } from '@/lib/toast'
import { fileExt as ext, type Attachment, type AttachmentsState, type Draft } from './useAttachments'

/** The attach and add-link buttons, the limits hint and the chips for what has been added so far. */
export function AttachmentTray({ state, compact = false }: { state: AttachmentsState; compact?: boolean }) {
  const { t } = useTranslation()
  const input = useRef<HTMLInputElement>(null)
  const [linkOpen, setLinkOpen] = useState(false)
  const [url, setUrl] = useState('')
  const [name, setName] = useState('')
  if (!state.enabled) return null
  const full = state.items.length >= state.max
  const saveLink = () => { if (state.addLink(url, name)) { setUrl(''); setName(''); setLinkOpen(false) } }

  return (
    <div className="space-y-2">
      {state.items.length > 0 && (
        <ul className={clsx('grid gap-2', compact ? 'grid-cols-1 sm:grid-cols-2' : 'grid-cols-1 sm:grid-cols-2 lg:grid-cols-3')} aria-live="polite">
          {state.items.map((d) => <DraftChip key={d.key} d={d} onRemove={() => state.remove(d.key)} onRetry={() => state.retry(d.key)} />)}
        </ul>
      )}
      {linkOpen && (
        <div className="flex flex-wrap items-center gap-2 rounded-xl border border-navy-100 bg-navy-50/40 p-2">
          <input className="input min-w-48 flex-[2]" dir="ltr" type="url" inputMode="url" placeholder="https://" aria-label={String(t('soc.files.linkUrl'))} value={url} onChange={(e) => setUrl(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), saveLink())} autoFocus />
          <input className="input min-w-36 flex-1" placeholder={String(t('soc.files.linkName'))} aria-label={String(t('soc.files.linkName'))} value={name} maxLength={200} onChange={(e) => setName(e.target.value)} onKeyDown={(e) => e.key === 'Enter' && (e.preventDefault(), saveLink())} />
          <Button size="sm" variant="outline" disabled={!url.trim()} onClick={saveLink}>{t('soc.files.addLink')}</Button>
          <Button size="sm" variant="ghost" onClick={() => setLinkOpen(false)}>{t('soc.common.cancel')}</Button>
        </div>
      )}
      <div className="flex flex-wrap items-center gap-1.5">
        <input ref={input} type="file" multiple hidden accept={state.accept} onChange={(e) => { if (e.target.files) state.addFiles(e.target.files); e.target.value = '' }} />
        <ToolButton icon={<Paperclip className="size-4" />} disabled={full} onClick={() => input.current?.click()}>{t('soc.files.attach')}</ToolButton>
        {!linkOpen && <ToolButton icon={<Link2 className="size-4" />} disabled={full} onClick={() => setLinkOpen(true)}>{t('soc.files.link')}</ToolButton>}
        <span className="ms-1 text-xs text-slate-400">{t('soc.files.hint', { n: state.max, mb: state.limits.file_max_mb })}</span>
      </div>
    </div>
  )
}

function ToolButton({ icon, children, onClick, disabled }: { icon: ReactNode; children: ReactNode; onClick: () => void; disabled?: boolean }) {
  return (
    <button type="button" onClick={onClick} disabled={disabled}
      className="inline-flex items-center gap-1.5 rounded-full border border-navy-100 px-3 py-1.5 text-xs font-semibold text-navy-800 transition-colors hover:border-gold-400 hover:text-gold-700 disabled:cursor-not-allowed disabled:opacity-50">
      {icon}{children}
    </button>
  )
}

function DraftChip({ d, onRemove, onRetry }: { d: Draft; onRemove: () => void; onRetry: () => void }) {
  const { t } = useTranslation()
  const isFile = d.type === 'file'
  const error = isFile && d.status === 'error'
  return (
    <li className={clsx('relative flex min-w-0 items-center gap-3 overflow-hidden rounded-xl border bg-surface p-2 pe-9', error ? 'border-danger/40 bg-danger/5' : 'border-navy-100')}>
      <span className="relative grid size-11 shrink-0 place-items-center">
        {isFile && d.preview
          ? <img src={d.preview} alt="" className={clsx('size-11 rounded-lg object-cover transition-opacity duration-200', d.status === 'uploading' && 'opacity-60')} />
          : <span className="grid size-11 place-items-center rounded-lg bg-navy-50 text-navy-700">{isFile ? <TypeIcon name={d.name} /> : <Link2 className="size-5" />}</span>}
        {isFile && d.status === 'uploading' && <ProgressRing value={d.progress} label={d.name} />}
      </span>
      <span className="min-w-0 flex-1">
        <bdi className="block truncate text-sm font-semibold text-navy-900">{d.name}</bdi>
        <span className={clsx('block truncate text-xs', error ? 'text-danger' : 'text-slate-500')}>
          {isFile ? (error ? d.error : d.status === 'uploading' ? t('soc.files.uploading', { p: d.progress }) : fmt.size(d.size)) : <bdi dir="ltr">{host(d.url)}</bdi>}
        </span>
      </span>
      {error && <button type="button" onClick={onRetry} className="rounded-lg p-1.5 text-navy-700 hover:bg-navy-50" aria-label={String(t('soc.files.retry'))}><RotateCw className="size-4" /></button>}
      <button type="button" onClick={onRemove} className="absolute end-1.5 top-1.5 rounded-lg p-1 text-slate-400 hover:bg-navy-50 hover:text-danger" aria-label={String(t('soc.files.remove', { name: d.name }))}><X className="size-4" /></button>
    </li>
  )
}

function ProgressRing({ value, label }: { value: number; label: string }) {
  const c = 2 * Math.PI * 18
  return (
    <svg viewBox="0 0 44 44" className="absolute inset-0 size-11 -rotate-90" role="progressbar" aria-valuenow={value} aria-valuemin={0} aria-valuemax={100} aria-label={label}>
      <circle cx="22" cy="22" r="18" fill="none" strokeWidth="3" className="stroke-white/80" />
      <circle cx="22" cy="22" r="18" fill="none" strokeWidth="3" strokeLinecap="round" className="stroke-gold-500 transition-[stroke-dashoffset] duration-200 ease-out motion-reduce:transition-none" strokeDasharray={c} strokeDashoffset={c * (1 - value / 100)} />
    </svg>
  )
}

const host = (url: string) => { try { return new URL(url).host } catch { return url } }

function TypeIcon({ name, className = 'size-5' }: { name: string; className?: string }) {
  const e = ext(name)
  if (['xls', 'xlsx'].includes(e)) return <FileSpreadsheet className={className} />
  if (['ppt', 'pptx'].includes(e)) return <Presentation className={className} />
  if (e === 'zip') return <FileArchive className={className} />
  if (['mp3', 'm4a'].includes(e)) return <Music className={className} />
  return <FileText className={className} />
}

/** Opens a file through a fresh short-lived link (the links in a loaded feed expire after 15 minutes). */
async function openFile(id: string) {
  // The tab is opened before the request so pop-up blockers see the click; it is cut off from this page before it loads anything.
  const tab = window.open('', '_blank')
  if (tab) tab.opener = null
  try {
    const { data } = await api.get(`/social/files/${id}`)
    if (tab) tab.location.href = data.data.url
    else window.open(data.data.url, '_blank', 'noopener,noreferrer')
  } catch (e) {
    tab?.close()
    toast(errorMessage(e), 'error')
  }
}

/** An image that asks for a fresh link once if its link has expired. */
function LiveImage({ id, src, alt, className }: { id: string; src: string | null; alt: string; className?: string }) {
  const [url, setUrl] = useState(src)
  const [from, setFrom] = useState(src)
  const [tried, setTried] = useState(false)
  if (from !== src) { setFrom(src); setUrl(src); setTried(false) }   // a new link from a reloaded feed
  const refresh = async () => {
    if (tried) return
    setTried(true)
    try { setUrl((await api.get(`/social/files/${id}`)).data.data.url) } catch { /* the broken image stays as it is */ }
  }
  return url ? <img src={url} alt={alt} className={className} loading="lazy" decoding="async" onError={refresh} /> : <span className={clsx('block bg-navy-50', className)} />
}

/** Shows what a post or comment carries: an image gallery with a viewer, players for video and audio, and cards for documents and links. */
export function AttachmentList({ items, compact = false }: { items: Attachment[] | null | undefined; compact?: boolean }) {
  const list = items ?? []
  const images = list.filter((a): a is Extract<Attachment, { type: 'file' }> => a.type === 'file' && a.kind === 'image')
  const media = list.filter((a): a is Extract<Attachment, { type: 'file' }> => a.type === 'file' && (a.kind === 'video' || a.kind === 'audio'))
  const rest = list.filter((a) => a.type === 'link' || (a.kind !== 'image' && a.kind !== 'video' && a.kind !== 'audio'))
  const [view, setView] = useState<number | null>(null)
  if (!list.length) return null
  return (
    <div className={clsx('space-y-2', compact ? 'mt-2' : 'mt-3')}>
      {images.length > 0 && <Gallery images={images} compact={compact} onOpen={setView} />}
      {media.map((m) => m.kind === 'video'
        ? <video key={m.id} src={m.url ?? undefined} controls preload="metadata" playsInline className="w-full max-h-[28rem] rounded-2xl bg-navy-950" aria-label={m.name} />
        : <div key={m.id} className="flex flex-wrap items-center gap-3 rounded-2xl border border-navy-100 bg-surface p-3"><span className="grid size-10 place-items-center rounded-xl bg-gold-100 text-gold-700"><Music className="size-5" /></span><bdi className="min-w-0 flex-1 truncate text-sm font-semibold text-navy-900">{m.name}</bdi><audio src={m.url ?? undefined} controls preload="metadata" className="w-full sm:w-72" aria-label={m.name} /></div>)}
      {rest.length > 0 && <ul className={clsx('grid gap-2', !compact && 'sm:grid-cols-2')}>{rest.map((a, i) => <li key={a.type === 'file' ? a.id : `${a.url}-${i}`}><FileCard a={a} /></li>)}</ul>}
      {view !== null && <Lightbox images={images} start={view} onClose={() => setView(null)} />}
    </div>
  )
}

function Gallery({ images, compact, onOpen }: { images: Extract<Attachment, { type: 'file' }>[]; compact: boolean; onOpen: (i: number) => void }) {
  const { t } = useTranslation()
  const shown = images.slice(0, 4)
  const more = images.length - shown.length
  const one = images.length === 1
  return (
    <div className={clsx('grid gap-1.5 overflow-hidden rounded-2xl', one ? 'grid-cols-1' : 'grid-cols-2', compact && 'max-w-md')}>
      {shown.map((img, i) => (
        <button key={img.id} type="button" onClick={() => onOpen(i)} aria-label={String(t('soc.files.openImage', { name: img.name }))}
          style={one && img.width && img.height ? { aspectRatio: `${img.width} / ${img.height}` } : undefined}
          className={clsx('group relative overflow-hidden bg-navy-50', one ? 'max-h-[28rem] w-full' : 'aspect-square', images.length === 3 && i === 0 && 'row-span-2 aspect-auto')}>
          <LiveImage id={img.id} src={one ? img.url : img.thumb_url ?? img.url} alt={img.name} className={clsx('size-full object-cover transition-transform duration-300 ease-out', one && 'max-h-[28rem] object-contain', 'group-hover:scale-[1.02] motion-reduce:transition-none')} />
          {more > 0 && i === shown.length - 1 && <span className="absolute inset-0 grid place-items-center bg-navy-950/55 font-display text-2xl font-bold text-white">+{more}</span>}
        </button>
      ))}
    </div>
  )
}

function Lightbox({ images, start, onClose }: { images: Extract<Attachment, { type: 'file' }>[]; start: number; onClose: () => void }) {
  const { t, i18n } = useTranslation()
  const [i, setI] = useState(start)
  const closeBtn = useRef<HTMLButtonElement>(null)
  const box = useRef<HTMLDivElement>(null)
  const rtl = i18n.dir() === 'rtl'
  const step = useCallback((d: number) => setI((x) => (x + d + images.length) % images.length), [images.length])
  useEffect(() => {
    const before = document.activeElement as HTMLElement | null
    closeBtn.current?.focus()
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') onClose()
      else if (e.key === 'ArrowRight') step(rtl ? -1 : 1)
      else if (e.key === 'ArrowLeft') step(rtl ? 1 : -1)
      else if (e.key === 'Tab') {
        const items = Array.from(box.current?.querySelectorAll<HTMLElement>('button') ?? [])
        const at = items.indexOf(document.activeElement as HTMLElement)
        e.preventDefault()
        items[(at + (e.shiftKey ? -1 : 1) + items.length) % items.length]?.focus()
      }
    }
    window.addEventListener('keydown', onKey)
    const overflow = document.body.style.overflow
    document.body.style.overflow = 'hidden'
    return () => { window.removeEventListener('keydown', onKey); document.body.style.overflow = overflow; before?.focus?.() }
  }, [onClose, step, rtl])
  const img = images[i]
  const touch = useRef<number | null>(null)
  return (
    <div ref={box} role="dialog" aria-modal="true" aria-label={img.name} className="fixed inset-0 z-[60] flex flex-col bg-navy-950/95 text-white" onMouseDown={onClose}
      onTouchStart={(e) => { touch.current = e.touches[0]?.clientX ?? null }}
      onTouchEnd={(e) => {
        const start = touch.current
        touch.current = null
        const dx = start === null ? 0 : (e.changedTouches[0]?.clientX ?? start) - start
        if (images.length > 1 && Math.abs(dx) > 50) step((dx < 0) !== rtl ? 1 : -1)
      }}>
      <div className="flex items-center gap-3 p-3 sm:p-4" onMouseDown={(e) => e.stopPropagation()}>
        <bdi className="min-w-0 flex-1 truncate text-sm font-semibold">{img.name}</bdi>
        {images.length > 1 && <span className="text-xs text-white/70" dir="ltr">{i + 1} / {images.length}</span>}
        <button type="button" onClick={() => void openFile(img.id)} className="rounded-full p-2 hover:bg-white/10" aria-label={String(t('soc.files.download'))}><Download className="size-5" /></button>
        <button ref={closeBtn} type="button" onClick={onClose} className="rounded-full p-2 hover:bg-white/10" aria-label={String(t('soc.common.close'))}><X className="size-5" /></button>
      </div>
      <div className="relative flex min-h-0 flex-1 items-center justify-center px-2 pb-6 sm:px-16">
        <LiveImage key={img.id} id={img.id} src={img.url} alt={img.name} className="max-h-full max-w-full rounded-lg object-contain shadow-2xl" />
        {images.length > 1 && <>
          <button type="button" onMouseDown={(e) => e.stopPropagation()} onClick={() => step(-1)} className="absolute start-2 top-1/2 -translate-y-1/2 rounded-full bg-white/10 p-2.5 hover:bg-white/20 sm:start-4" aria-label={String(t('soc.files.previous'))}><ChevronLeft className="size-6 rtl:rotate-180" /></button>
          <button type="button" onMouseDown={(e) => e.stopPropagation()} onClick={() => step(1)} className="absolute end-2 top-1/2 -translate-y-1/2 rounded-full bg-white/10 p-2.5 hover:bg-white/20 sm:end-4" aria-label={String(t('soc.files.next'))}><ChevronRight className="size-6 rtl:rotate-180" /></button>
        </>}
      </div>
    </div>
  )
}

function FileCard({ a }: { a: Attachment }) {
  const { t } = useTranslation()
  if (a.type === 'link') {
    return (
      <a href={a.url} target="_blank" rel="noopener noreferrer nofollow" className="flex items-center gap-3 rounded-xl border border-navy-100 bg-surface p-3 transition-colors hover:border-gold-400">
        <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-navy-50 text-navy-700"><Link2 className="size-5" /></span>
        <span className="min-w-0 flex-1"><bdi className="block truncate text-sm font-semibold text-navy-900">{a.name}</bdi><bdi dir="ltr" className="block truncate text-xs text-slate-500">{host(a.url)}</bdi></span>
        <ExternalLink className="size-4 shrink-0 text-slate-400" aria-hidden />
      </a>
    )
  }
  return (
    <button type="button" onClick={() => void openFile(a.id)} className="flex w-full items-center gap-3 rounded-xl border border-navy-100 bg-surface p-3 text-start transition-colors hover:border-gold-400">
      <span className={clsx('grid size-10 shrink-0 place-items-center rounded-lg', a.kind === 'pdf' ? 'bg-danger/10 text-danger' : 'bg-navy-50 text-navy-700')}><TypeIcon name={a.name} /></span>
      <span className="min-w-0 flex-1"><bdi className="block truncate text-sm font-semibold text-navy-900">{a.name}</bdi><span className="block text-xs text-slate-500"><span dir="ltr">{ext(a.name).toUpperCase()}</span> · {fmt.size(a.size)}</span></span>
      <Download className="size-4 shrink-0 text-slate-400" aria-label={String(t('soc.files.download'))} />
    </button>
  )
}
