import clsx from 'clsx'
import { Lock } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useTranslation } from 'react-i18next'
import { api } from '@/lib/api'
import { eventsUrl, type ShieldSettings, type Viewer } from './api'

/**
 * Content protection for the training kit. The kit belongs to the training centre: it is shown, never handed over. While it is on
 * screen the trainer's name and number are tiled across it (so a photo of the screen names its source), print / save / copy / drag
 * and the context menu are refused, the content turns black the moment a screenshot shortcut is held (Win+Shift, Cmd+Shift) or
 * pressed (PrintScreen, which also wipes the clipboard), and every attempt is sent to the centre. A browser cannot forbid the
 * operating system from capturing the screen, so this is a deterrent with a trail — the black cover and the watermark are the part
 * that survives a picture being taken.
 */
type Surface = 'reader' | 'presenter' | 'audience'
type Block = null | { kind: 'capture' | 'hidden' | 'modifier' | 'print' | 'tamper' }

type Props = {
  settings: ShieldSettings
  viewer: Viewer
  surface: Surface
  kitId: string
  fileId?: string
  programId?: string | null
  sessionId?: string | null
  /** Sparse tiling for the audience screen so the slides stay readable. */
  sparse?: boolean
  className?: string
  children: ReactNode
}

const NOTICE = '© ملك المركز التدريبي — The training kit belongs to the training centre'

function tile(lines: string[], sparse: boolean): string {
  const w = sparse ? 560 : 400
  const h = sparse ? 340 : 230
  const text = lines.map((l, i) => `<text x="${w / 2}" y="${h / 2 + (i - (lines.length - 1) / 2) * 22}" text-anchor="middle" font-family="Tajawal,Segoe UI,Arial,sans-serif" font-size="15" font-weight="600" fill="rgba(20,20,30,${sparse ? 0.085 : 0.12})" stroke="rgba(255,255,255,${sparse ? 0.07 : 0.1})" stroke-width="0.6" paint-order="stroke">${l.replace(/[<>&]/g, '')}</text>`).join('')
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" width="${w}" height="${h}"><g transform="rotate(-22 ${w / 2} ${h / 2})">${text}</g></svg>`
  return `url("data:image/svg+xml;utf8,${encodeURIComponent(svg)}")`
}

export function Shield({ settings, viewer, surface, kitId, fileId, programId, sessionId, sparse = false, className, children }: Props) {
  const { t } = useTranslation()
  const [block, setBlock] = useState<Block>(null)
  const [wait, setWait] = useState(0)
  const [minute, setMinute] = useState(() => Math.floor(Date.now() / 60000))
  const [layerKey, setLayerKey] = useState(0)
  const cover = useRef<HTMLDivElement>(null)
  const layer = useRef<HTMLDivElement>(null)
  const sent = useRef<Record<string, number>>({})
  const hold = useRef<{ since: number; active: boolean }>({ since: 0, active: false })
  const blockRef = useRef<Block>(null)
  const s = settings
  const blankOnBlur = surface === 'reader' ? s.blank_on_blur_reader : s.blank_on_blur_presenter

  const report = useCallback((kind: string, meta: Record<string, unknown> = {}) => {
    const now = Date.now()
    if (kind !== 'capture_attempt' && now - (sent.current[kind] ?? 0) < 15000) return
    sent.current[kind] = now
    void api.post(eventsUrl(kitId), { kind, file_id: fileId, program_id: programId ?? undefined, session_id: sessionId ?? undefined, meta: { surface, ...meta } }).catch(() => undefined)
  }, [kitId, fileId, programId, sessionId, surface])

  // The cover is switched on in the same tick as the key event (before React renders), so a shortcut that captures the screen sees black.
  const hide = useCallback((kind: NonNullable<Block>['kind'], reportAs?: string, meta?: Record<string, unknown>) => {
    if (cover.current) cover.current.style.display = 'flex'
    blockRef.current = { kind }
    setBlock({ kind })
    if (kind === 'capture') setWait(3)
    if (reportAs) report(reportAs, meta)
  }, [report])

  const show = useCallback(() => {
    if (cover.current) cover.current.style.display = 'none'
    blockRef.current = null
    setBlock(null)
  }, [])

  const wipeClipboard = useCallback(() => {
    try { void navigator.clipboard?.writeText(NOTICE).catch(() => undefined) } catch { /* no clipboard access */ }
  }, [])

  // Countdown before the trainer may resume after a capture attempt.
  useEffect(() => {
    if (block?.kind !== 'capture' || wait <= 0) return
    const id = window.setTimeout(() => setWait((n) => n - 1), 1000)
    return () => window.clearTimeout(id)
  }, [block, wait])

  // The minute in the watermark follows the clock.
  useEffect(() => {
    const id = window.setInterval(() => setMinute(Math.floor(Date.now() / 60000)), 30000)
    return () => window.clearInterval(id)
  }, [])

  useEffect(() => {
    const protect = s.block_print_copy
    const typing = (e: Event) => { const el = e.target as HTMLElement | null; return !!el && (el.tagName === 'INPUT' || el.tagName === 'TEXTAREA' || el.isContentEditable) }

    const onKeyDown = (e: KeyboardEvent) => {
      const k = e.key
      if (s.blank_on_capture_keys) {
        if (k === 'PrintScreen' || e.code === 'PrintScreen') { hide('capture', 'capture_attempt', { key: 'PrintScreen' }); wipeClipboard(); return }
        // Win+Shift+S and Cmd+Shift+3/4/5 take the picture on the third key; the first two are ours to see, so the content is already black.
        if (e.metaKey && e.shiftKey) {
          hold.current = { since: Date.now(), active: true }
          hide('modifier')
          if (['s', '3', '4', '5'].includes(k.toLowerCase())) { hide('capture', 'capture_attempt', { key: `Meta+Shift+${k.toUpperCase()}` }); wipeClipboard() }
          return
        }
      }
      if (!protect) return
      const mod = e.ctrlKey || e.metaKey
      const low = k.toLowerCase()
      if (mod && ['p', 's'].includes(low)) { e.preventDefault(); hide('print', 'print_attempt', { key: `${mod ? 'Ctrl+' : ''}${low.toUpperCase()}` }); return }
      if (mod && ['u', 'c', 'x', 'a'].includes(low) && !typing(e)) { e.preventDefault(); return }
      if (k === 'F12' || (mod && e.shiftKey && ['i', 'j', 'c'].includes(low))) { e.preventDefault(); report('blocked', { key: k }) }
    }
    const onKeyUp = (e: KeyboardEvent) => {
      if (s.blank_on_capture_keys && (e.key === 'PrintScreen' || e.code === 'PrintScreen')) { hide('capture', 'capture_attempt', { key: 'PrintScreen' }); wipeClipboard(); return }
      if (hold.current.active && !(e.metaKey && e.shiftKey)) {
        hold.current.active = false
        window.setTimeout(() => { if (blockRef.current?.kind === 'modifier') show() }, 900)
      }
    }
    const onBlur = () => {
      if (hold.current.active || Date.now() - hold.current.since < 1500) { hide('capture', 'capture_attempt', { key: 'Meta+Shift' }); wipeClipboard(); return }
      if (blankOnBlur) hide('hidden')
    }
    const onFocus = () => { if (blockRef.current?.kind === 'hidden') show() }
    const onVisibility = () => { if (document.hidden) { if (blankOnBlur) hide('hidden') } else onFocus() }
    const stop = (e: Event) => { if (!typing(e)) e.preventDefault() }
    const onCopy = (e: ClipboardEvent) => { e.preventDefault(); e.clipboardData?.setData('text/plain', NOTICE) }
    const onBeforePrint = () => hide('print', 'print_attempt', { key: 'print' })
    const onAfterPrint = () => { if (blockRef.current?.kind === 'print') show() }

    window.addEventListener('keydown', onKeyDown, true)
    window.addEventListener('keyup', onKeyUp, true)
    window.addEventListener('blur', onBlur)
    window.addEventListener('focus', onFocus)
    document.addEventListener('visibilitychange', onVisibility)
    window.addEventListener('beforeprint', onBeforePrint)
    window.addEventListener('afterprint', onAfterPrint)
    if (protect) {
      document.addEventListener('contextmenu', stop)
      document.addEventListener('dragstart', stop)
      document.addEventListener('selectstart', stop)
      document.addEventListener('copy', onCopy)
      document.addEventListener('cut', onCopy)
    }
    const style = document.createElement('style')
    style.textContent = '@media print { html, body { display: none !important; } }'
    document.head.appendChild(style)
    return () => {
      window.removeEventListener('keydown', onKeyDown, true)
      window.removeEventListener('keyup', onKeyUp, true)
      window.removeEventListener('blur', onBlur)
      window.removeEventListener('focus', onFocus)
      document.removeEventListener('visibilitychange', onVisibility)
      window.removeEventListener('beforeprint', onBeforePrint)
      window.removeEventListener('afterprint', onAfterPrint)
      document.removeEventListener('contextmenu', stop)
      document.removeEventListener('dragstart', stop)
      document.removeEventListener('selectstart', stop)
      document.removeEventListener('copy', onCopy)
      document.removeEventListener('cut', onCopy)
      style.remove()
    }
  }, [s.block_print_copy, s.blank_on_capture_keys, blankOnBlur, hide, show, report, wipeClipboard])

  // Someone removing or hiding the watermark in the developer tools blacks the content out until it is back.
  useEffect(() => {
    if (!s.watermark) return
    const id = window.setInterval(() => {
      const el = layer.current
      const bad = !el || !el.isConnected || (() => { const c = getComputedStyle(el); return c.display === 'none' || c.visibility !== 'visible' || Number(c.opacity) < 0.5 || el.offsetWidth < 10 })()
      if (bad && !blockRef.current) { hide('tamper', 'tamper'); setLayerKey((k) => k + 1) }
    }, 1500)
    return () => window.clearInterval(id)
  }, [s.watermark, hide])

  const stamp = useMemo(() => {
    const d = new Date(minute * 60000)
    const pad = (n: number) => String(n).padStart(2, '0')
    const when = `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}`
    return tile([`${viewer.name} · ${viewer.number}`, when, t('teach.watermark')], sparse)
  }, [minute, viewer, sparse, t])

  const resumable = block && block.kind !== 'modifier' && block.kind !== 'hidden' && block.kind !== 'print'
  return (
    <div className={clsx('relative', className)} style={{ userSelect: s.block_print_copy ? 'none' : undefined, WebkitUserSelect: s.block_print_copy ? 'none' : undefined, WebkitTouchCallout: 'none' } as React.CSSProperties} data-shield>
      {children}
      {s.watermark && <div key={layerKey} ref={layer} aria-hidden className="pointer-events-none absolute inset-0 z-40" style={{ backgroundImage: stamp, backgroundRepeat: 'repeat' }} />}
      <div ref={cover} className="absolute inset-0 z-50 flex-col items-center justify-center gap-3 bg-black px-6 text-center text-white" style={{ display: 'none' }} role="alert">
        <Lock className="size-10 text-gold-300" />
        <div className="text-xl font-bold">{block?.kind === 'hidden' ? t('teach.shield.hiddenTitle') : t('teach.shield.blockedTitle')}</div>
        <p className="max-w-md text-sm leading-7 text-white/75">
          {block?.kind === 'capture' || block?.kind === 'modifier' ? t('teach.shield.captured') : block?.kind === 'print' ? t('teach.shield.print') : block?.kind === 'tamper' ? t('teach.shield.tamper') : t('teach.shield.hidden')}
        </p>
        <p className="text-xs font-semibold text-gold-300">{t('teach.ownership')}</p>
        {resumable && (
          <button type="button" disabled={wait > 0} onClick={show} className="mt-2 rounded-xl bg-gold-500 px-5 py-2 text-sm font-bold text-navy-950 disabled:opacity-50">
            {wait > 0 ? `${t('teach.shield.resume')} (${wait})` : t('teach.shield.resume')}
          </button>
        )}
      </div>
    </div>
  )
}
