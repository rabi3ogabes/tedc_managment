import { useCallback, useEffect, useRef, useState } from 'react'
import { useTranslation } from 'react-i18next'
import { api, errorMessage } from '@/lib/api'
import { toast } from '@/lib/toast'
import { putFile, type SignedUpload } from '@/lib/upload'

/** What a space allows the composer to send (from the space detail). */
export type FileLimits = { allowed: boolean; file_max_mb: number; post_total_mb: number; files_per_post: number; files_per_comment: number; direct_max_mb: number; extensions: string[] }

/** An attachment as the API shows it: a private file with short-lived links, or a link to another site. */
export type Attachment =
  | { type: 'file'; id: string; name: string; mime: string; size: number; width?: number | null; height?: number | null; kind: 'image' | 'video' | 'audio' | 'pdf' | 'document'; url: string | null; thumb_url: string | null }
  | { type: 'link'; url: string; name: string }

/** What a post or comment sends: an upload it owns, or a link. */
export type AttachmentInput = { type: 'file'; upload_id: string } | { type: 'link'; url: string; name: string }

export type Draft =
  | { key: string; type: 'file'; name: string; size: number; preview: string | null; progress: number; status: 'uploading' | 'ready' | 'error'; uploadId?: string; error?: string; file: File }
  | { key: string; type: 'link'; url: string; name: string }

const DEFAULT_LIMITS: FileLimits = { allowed: true, file_max_mb: 10, post_total_mb: 50, files_per_post: 10, files_per_comment: 5, direct_max_mb: 4, extensions: ['pdf', 'jpg', 'jpeg', 'png', 'webp', 'gif', 'doc', 'docx', 'xls', 'xlsx', 'ppt', 'pptx', 'mp4', 'mp3', 'm4a', 'zip'] }
export const fileExt = (name: string) => (name.includes('.') ? name.split('.').pop()!.toLowerCase() : '')
let seq = 0

/**
 * The files and links a person is adding to a post or comment. Small files go through the API, big ones straight to
 * storage with a signed address; the server checks every file again before it can be attached.
 */
export function useAttachments(spaceId: string, limits: FileLimits | undefined, scope: 'post' | 'comment') {
  const { t } = useTranslation()
  const lim = limits ?? DEFAULT_LIMITS
  const max = scope === 'comment' ? lim.files_per_comment : lim.files_per_post
  const [items, setItems] = useState<Draft[]>([])
  const live = useRef(items)
  useEffect(() => { live.current = items }, [items])
  const inflight = useRef(new Map<string, AbortController>())
  const patch = useCallback((key: string, p: Partial<Extract<Draft, { type: 'file' }>>) => setItems((xs) => xs.map((x) => (x.key === key && x.type === 'file' ? { ...x, ...p } : x))), [])

  const send = useCallback(async (key: string, file: File) => {
    patch(key, { status: 'uploading', progress: 0, error: undefined })
    const ctl = new AbortController()
    inflight.current.set(key, ctl)
    const signal = ctl.signal
    try {
      let row: { id: string }
      if (file.size <= lim.direct_max_mb * 1024 * 1024) {
        const body = new FormData()
        body.append('space_id', spaceId)
        body.append('file', file)
        row = (await api.post('/social/uploads', body, { signal, onUploadProgress: (e) => e.total && patch(key, { progress: Math.round((e.loaded / e.total) * 100) }) })).data.data
      } else {
        const signed = (await api.post<{ data: { id: string; upload: SignedUpload } }>('/social/uploads/sign', { space_id: spaceId, name: file.name, mime: file.type || 'application/octet-stream', size: file.size }, { signal })).data.data
        patch(key, { uploadId: signed.id })
        await putFile(signed.upload, file, (p) => patch(key, { progress: Math.min(p, 99) }), signal)
        row = (await api.post(`/social/uploads/${signed.id}/complete`, undefined, { signal })).data.data
      }
      if (signal.aborted) { void api.delete(`/social/uploads/${row.id}`).catch(() => undefined); return }
      patch(key, { status: 'ready', progress: 100, uploadId: row.id })
    } catch (e) {
      if (!signal.aborted) patch(key, { status: 'error', error: errorMessage(e) })
    } finally {
      inflight.current.delete(key)
    }
  }, [spaceId, lim.direct_max_mb, patch])

  const addFiles = (list: FileList | File[]) => {
    const files = Array.from(list)
    const room = max - live.current.length
    if (files.length > room) toast(String(t('soc.files.tooMany', { n: max })), 'error')
    const next: Draft[] = []
    for (const file of files.slice(0, Math.max(0, room))) {
      if (!lim.extensions.includes(fileExt(file.name))) { toast(String(t('soc.files.badType', { name: file.name })), 'error'); continue }
      if (file.size > lim.file_max_mb * 1024 * 1024) { toast(String(t('soc.files.tooBig', { name: file.name, mb: lim.file_max_mb })), 'error'); continue }
      const key = `f${++seq}`
      next.push({ key, type: 'file', name: file.name, size: file.size, preview: file.type.startsWith('image/') ? URL.createObjectURL(file) : null, progress: 0, status: 'uploading', file })
    }
    if (!next.length) return
    setItems((xs) => [...xs, ...next])
    next.forEach((d) => d.type === 'file' && void send(d.key, d.file))
  }

  const addLink = (url: string, name: string) => {
    if (live.current.length >= max) { toast(String(t('soc.files.tooMany', { n: max })), 'error'); return false }
    if (!/^https?:\/\/[^\s<>"]+$/i.test(url.trim())) { toast(String(t('soc.files.badLink')), 'error'); return false }
    setItems((xs) => [...xs, { key: `l${++seq}`, type: 'link', url: url.trim(), name: name.trim() || url.trim() }])
    return true
  }

  const remove = (key: string) => {
    const d = live.current.find((x) => x.key === key)
    inflight.current.get(key)?.abort()
    if (d?.type === 'file') {
      if (d.preview) URL.revokeObjectURL(d.preview)
      if (d.uploadId) void api.delete(`/social/uploads/${d.uploadId}`).catch(() => undefined)
    }
    setItems((xs) => xs.filter((x) => x.key !== key))
  }

  const retry = (key: string) => {
    const d = live.current.find((x) => x.key === key)
    if (d?.type === 'file') void send(key, d.file)
  }

  /** After a successful post: the files now belong to it, so only the previews are released. */
  const clear = () => {
    live.current.forEach((d) => d.type === 'file' && d.preview && URL.revokeObjectURL(d.preview))
    setItems([])
  }

  useEffect(() => () => {
    inflight.current.forEach((c) => c.abort())
    live.current.forEach((d) => d.type === 'file' && d.preview && URL.revokeObjectURL(d.preview))
  }, [])

  const payload = items.flatMap<AttachmentInput>((d) => (d.type === 'link' ? [{ type: 'link', url: d.url, name: d.name }] : d.status === 'ready' && d.uploadId ? [{ type: 'file', upload_id: d.uploadId }] : []))
  const uploading = items.some((d) => d.type === 'file' && d.status === 'uploading')
  const failed = items.some((d) => d.type === 'file' && d.status === 'error')

  return { items, addFiles, addLink, remove, retry, clear, payload, uploading, failed, max, limits: lim, accept: lim.extensions.map((e) => `.${e}`).join(','), enabled: lim.allowed }
}

export type AttachmentsState = ReturnType<typeof useAttachments>

/** Lets people drop or paste files anywhere on the composer. */
export function useDropZone(state: AttachmentsState) {
  const [over, setOver] = useState(false)
  const depth = useRef(0)
  if (!state.enabled) return { over: false, props: {} }
  const hasFiles = (e: React.DragEvent) => Array.from(e.dataTransfer?.types ?? []).includes('Files')
  return {
    over,
    props: {
      onDragEnter: (e: React.DragEvent) => { if (!hasFiles(e)) return; e.preventDefault(); depth.current++; setOver(true) },
      onDragOver: (e: React.DragEvent) => { if (hasFiles(e)) e.preventDefault() },
      onDragLeave: () => { depth.current = Math.max(0, depth.current - 1); if (depth.current === 0) setOver(false) },
      onDrop: (e: React.DragEvent) => { if (!hasFiles(e)) return; e.preventDefault(); depth.current = 0; setOver(false); state.addFiles(e.dataTransfer.files) },
      onPaste: (e: React.ClipboardEvent) => { if (e.clipboardData.files.length) { e.preventDefault(); state.addFiles(e.clipboardData.files) } },
    },
  }
}
