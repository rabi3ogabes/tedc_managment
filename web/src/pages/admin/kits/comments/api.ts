import { useQueryClient } from '@tanstack/react-query'
import { useCallback } from 'react'
import { useGet } from '@/hooks/useApi'
import { api } from '@/lib/api'
import type { Anchor, CommentCategory, CommentStatus, KitComment, Severity } from '../types'

export type CommentsResult = { data: KitComment[]; meta: { counts: { open: number; addressed: number; resolved: number } } }

export type NewComment = {
  body: string; file_id?: string | null; parent_id?: string | null; anchor?: Anchor | null; category?: CommentCategory; severity?: Severity; assignee_id?: string | null
  mentions?: string[]; file_version?: number | null
}

/** Comments of a kit (optionally one file), refreshed every 15 seconds so the two teams see each other's notes. */
export function useKitComments(kitId: string, fileId?: string | null, enabled = true) {
  return useGet<CommentsResult>(enabled ? `/admin/kits/${kitId}/comments` : null, { file_id: fileId || undefined }, { refetchInterval: 15_000 })
}

export function useCommentActions(kitId: string) {
  const qc = useQueryClient()
  const refresh = useCallback(() => Promise.all([
    qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0] ?? '').startsWith(`/admin/kits/${kitId}`) }),
    qc.invalidateQueries({ queryKey: ['/admin/kits/stats'] }),
  ]), [qc, kitId])

  return {
    create: useCallback(async (body: NewComment) => { const r = await api.post<{ data: KitComment }>(`/admin/kits/${kitId}/comments`, body); await refresh(); return r.data.data }, [kitId, refresh]),
    bulk: useCallback(async (comments: NewComment[]) => { await api.post(`/admin/kits/${kitId}/comments/bulk`, { comments }); await refresh() }, [kitId, refresh]),
    setStatus: useCallback(async (id: string, status: CommentStatus) => { await api.post(`/admin/kits/${kitId}/comments/${id}/status`, { status }); await refresh() }, [kitId, refresh]),
    update: useCallback(async (id: string, patch: Partial<Pick<NewComment, 'body' | 'category' | 'severity' | 'assignee_id'>>) => { await api.put(`/admin/kits/${kitId}/comments/${id}`, patch); await refresh() }, [kitId, refresh]),
    remove: useCallback(async (id: string) => { await api.delete(`/admin/kits/${kitId}/comments/${id}`); await refresh() }, [kitId, refresh]),
    refresh,
  }
}

export const severityTone: Record<Severity, { bar: string; chip: string; pin: string }> = {
  info: { bar: 'bg-sky-400', chip: 'bg-sky-50 text-sky-700 ring-sky-600/15', pin: '#0EA5E9' },
  minor: { bar: 'bg-amber-400', chip: 'bg-amber-50 text-amber-700 ring-amber-600/20', pin: '#F59E0B' },
  major: { bar: 'bg-orange-500', chip: 'bg-orange-50 text-orange-700 ring-orange-600/20', pin: '#EA580C' },
  critical: { bar: 'bg-red-600', chip: 'bg-red-50 text-red-700 ring-red-600/20', pin: '#DC2626' },
}

export function relativeTime(iso: string, lang: string): string {
  const diff = (new Date(iso).getTime() - Date.now()) / 1000
  const rtf = new Intl.RelativeTimeFormat(lang === 'en' ? 'en' : 'ar-u-nu-latn', { numeric: 'auto' })
  const abs = Math.abs(diff)
  if (abs < 60) return rtf.format(Math.round(diff), 'second')
  if (abs < 3600) return rtf.format(Math.round(diff / 60), 'minute')
  if (abs < 86400) return rtf.format(Math.round(diff / 3600), 'hour')
  if (abs < 604800) return rtf.format(Math.round(diff / 86400), 'day')
  if (abs < 2629800) return rtf.format(Math.round(diff / 604800), 'week')
  if (abs < 31557600) return rtf.format(Math.round(diff / 2629800), 'month')
  return rtf.format(Math.round(diff / 31557600), 'year')
}
