import { useEffect, useRef, useState } from 'react'
import { api } from '@/lib/api'
import type { Deck } from '../kits/deck/model'

export type Bi = { ar: string; en: string }
export const pickBi = (b: Bi | null | undefined, lang: string) => (b ? (lang === 'en' ? b.en || b.ar : b.ar || b.en) : '')

export type ShieldSettings = {
  watermark: boolean
  blank_on_capture_keys: boolean
  blank_on_blur_reader: boolean
  blank_on_blur_presenter: boolean
  block_print_copy: boolean
  require_acknowledgement: boolean
  log_events: boolean
  notify_admin: boolean
  notify_window_minutes: number
}
export type Viewer = { name: string; number: string; id: string }

export type KitFileRow = { id: string; name: string; kind: string; category: string; size: number | null; revision: number; is_deck: boolean; presentable: boolean; mime: string | null }
export type KitInfo = {
  id: string; code: string; title_ar: string; title_en: string; description_ar?: string | null; description_en?: string | null; version: number; hours: number; objectives?: string[] | string | null
  files: KitFileRow[]; program_id: string | null; acknowledged: boolean; protection: ShieldSettings; viewer: Viewer; ownership: Bi
}
export type FileView = { file: KitFileRow; kit: { id: string; title_ar: string; title_en: string }; stream: string | null; deck: Deck | null }

export type SessionRow = { id: string; program_id: string; sequence: number | null; title_ar: string | null; title_en: string | null; starts_at: string; ends_at: string; status: string; mode: string | null; room: Bi | null }
export type GroupRow = { id: string; title_ar: string; title_en: string; from: string | null; to: string | null; rooms: Bi[]; supervisor: Bi | null }
export type TeachingProgram = {
  id: string; code: string; title_ar: string; title_en: string; status: string; delivery_mode: string; start_date: string | null; end_date: string | null; sessions: number; trainees: number
  next_session: SessionRow | null; groups: GroupRow[]; kits: { id: string; code: string; title_ar: string; title_en: string; version: number; hours: number; files: number }[]
}
export type TeachingHome = { programs: TeachingProgram[]; ownership: Bi }
export type ProgramDetail = {
  id: string; code: string; title_ar: string; title_en: string; status: string; delivery_mode: string; sessions: SessionRow[]; roster: { id: string; name: string }[]
  kits: { id: string; code: string; title_ar: string; title_en: string; version: number; files: KitFileRow[] }[]
}

export const eventsUrl = (kitId: string) => `/me/teaching/kits/${kitId}/events`

/** Fetches a protected file as a blob through the API (with the person's token); no address of it is ever shown or kept. */
export async function fetchBlob(path: string): Promise<Blob> {
  return (await api.get(path, { responseType: 'blob' })).data as Blob
}

export async function fetchBuffer(path: string): Promise<ArrayBuffer> {
  return (await api.get(path, { responseType: 'arraybuffer' })).data as ArrayBuffer
}

/** The deck with its pictures, videos and sounds fetched through the protected route and swapped for local blobs. */
export function useProtectedDeck(deck: Deck | null | undefined) {
  const [resolved, setResolved] = useState<Deck | null>(null)
  const urls = useRef<string[]>([])
  useEffect(() => {
    if (!deck) { setResolved(null); return }
    let cancelled = false
    const cache = new Map<string, string>()
    const paths = new Set<string>()
    const collect = (s?: string | null) => { if (s && s.startsWith('/me/teaching/')) paths.add(s) }
    deck.slides.forEach((s) => { collect(s.background.src); s.elements.forEach((e) => { if ('src' in e) collect(e.src) }) })
    void (async () => {
      const queue = [...paths]
      await Promise.all(Array.from({ length: Math.min(4, queue.length) }, async () => {
        for (let p = queue.shift(); p; p = queue.shift()) {
          try {
            const url = URL.createObjectURL(await fetchBlob(p))
            urls.current.push(url)
            cache.set(p, url)
          } catch { /* a picture that fails to load leaves its placeholder */ }
        }
      }))
      if (cancelled) return
      const swap = (s?: string | null) => (s && cache.has(s) ? cache.get(s)! : null)
      setResolved({ ...deck, slides: deck.slides.map((s) => ({ ...s, background: { ...s.background, src: swap(s.background.src) }, elements: s.elements.map((e) => ('src' in e ? { ...e, src: swap(e.src) } : e)) })) } as Deck)
    })()
    return () => { cancelled = true; urls.current.forEach((u) => URL.revokeObjectURL(u)); urls.current = [] }
  }, [deck])
  return resolved
}

/** One kit-file's bytes as an object URL (video, audio, image) for the lifetime of the component. */
export function useBlobUrl(path: string | null | undefined) {
  const [url, setUrl] = useState<string | null>(null)
  const [error, setError] = useState(false)
  useEffect(() => {
    if (!path) return
    let cancelled = false
    let made: string | null = null
    setUrl(null); setError(false)
    fetchBlob(path).then((b) => { if (!cancelled) { made = URL.createObjectURL(b); setUrl(made) } }).catch(() => { if (!cancelled) setError(true) })
    return () => { cancelled = true; if (made) URL.revokeObjectURL(made) }
  }, [path])
  return { url, error }
}

export function fmtClock(ms: number): string {
  const s = Math.max(0, Math.floor(ms / 1000))
  const h = Math.floor(s / 3600)
  const m = Math.floor((s % 3600) / 60)
  const sec = s % 60
  return `${h ? `${h}:${String(m).padStart(2, '0')}` : m}:${String(sec).padStart(2, '0')}`
}

export function fmtDateTime(iso: string | null | undefined, lang: string, time = true): string {
  if (!iso) return ''
  const d = new Date(iso)
  const opts: Intl.DateTimeFormatOptions = time ? { year: 'numeric', month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' } : { year: 'numeric', month: 'short', day: 'numeric' }
  return new Intl.DateTimeFormat(lang === 'en' ? 'en-GB' : 'ar-QA-u-nu-latn', opts).format(d)
}
