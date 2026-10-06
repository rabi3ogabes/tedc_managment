import type { SupabaseClient } from '@supabase/supabase-js'
import { useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { API_URL, api, sessionStore } from './api'
import { useAuth } from './auth'
import { playChime, setChimeEnabled } from './chime'
import { toast } from './toast'

const url = import.meta.env.VITE_SUPABASE_URL as string | undefined
// Publishable key (sb_publishable_…) or legacy anon key — never the secret key.
const key = (import.meta.env.VITE_SUPABASE_PUBLISHABLE_KEY || import.meta.env.VITE_SUPABASE_ANON_KEY) as string | undefined

let client: Promise<SupabaseClient> | null = null
/** The Supabase SDK (~60 KB) is loaded on demand, only for signed-in users with Realtime configured. */
function supabase(): Promise<SupabaseClient> | null {
  if (!url || !key) return null
  client ??= import('@supabase/supabase-js').then(({ createClient }) => createClient(url, key, { auth: { persistSession: false } }))
  return client
}

/**
 * Subscribes to Supabase Realtime INSERTs on `notifications`. Row Level Security
 * (see supabase/setup.sql) guarantees each user only receives their own rows.
 * Without Supabase configuration, the layout falls back to polling.
 */
export function useRealtimeNotifications() {
  const { user } = useAuth()
  const qc = useQueryClient()

  useEffect(() => {
    const loading = supabase()
    const session = sessionStore.get()
    if (!loading || !user || !session) return

    // The saved choice about the chime follows the person across devices.
    api.get('/me/notification-preferences').then((r) => setChimeEnabled(Boolean(r.data?.data?.sound))).catch(() => undefined)

    let cancelled = false
    let cleanup: (() => void) | undefined
    loading.then((sb) => {
      if (cancelled) return
      sb.realtime.setAuth(session.access_token)
      const channel = sb
        .channel(`notifications:${user.id}`)
        .on('postgres_changes', { event: 'INSERT', schema: 'public', table: 'notifications', filter: `user_id=eq.${user.id}` }, (payload) => {
          qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0] ?? '').startsWith('/me') })
          // A visible pop-up with a soft chime (when the person has not switched it off) and a pulse on the bell.
          const row = payload.new as { title_ar?: string; title_en?: string } | undefined
          const title = (document.documentElement.lang === 'en' ? row?.title_en || row?.title_ar : row?.title_ar || row?.title_en) ?? ''
          if (title) toast(title, 'info')
          playChime()
          window.dispatchEvent(new CustomEvent('tedc:notification'))
        })
        .subscribe()
      cleanup = () => sb.removeChannel(channel)
    })

    return () => {
      cancelled = true
      cleanup?.()
    }
  }, [user, qc])
}

/**
 * Server-sent events from the API (driver `sse`, chosen by the server): no third-party service. One window of about 25 seconds at a time, then it reconnects
 * from the last time it saw. Without a stream the layout keeps polling.
 */
export function useSseNotifications() {
  const { user } = useAuth()
  const qc = useQueryClient()
  useEffect(() => {
    if (!user) return
    let stop = false
    let since = new Date().toISOString()
    const loop = async () => {
      let driver = 'polling'
      try { driver = (await api.get('/me/realtime/config')).data?.data?.driver } catch { /* polling */ }
      if (driver !== 'sse') return
      while (!stop) {
        const session = sessionStore.get()
        if (!session) break
        try {
          const res = await fetch(`${API_URL}/me/realtime/stream?since=${encodeURIComponent(since)}`, { headers: { Authorization: `Bearer ${session.access_token}`, Accept: 'text/event-stream' } })
          if (!res.ok || !res.body) throw new Error(String(res.status))
          const reader = res.body.getReader()
          const dec = new TextDecoder()
          let buf = ''
          for (;;) {
            const { done, value } = await reader.read()
            if (done || stop) break
            buf += dec.decode(value, { stream: true })
            const parts = buf.split('\n\n')
            buf = parts.pop() ?? ''
            for (const part of parts) {
              const data = part.split('\n').find((l) => l.startsWith('data: '))
              if (!data) continue
              const row = JSON.parse(data.slice(6)) as { title_ar?: string; title_en?: string; created_at?: string; since?: string }
              if (part.startsWith('event: notification')) {
                if (row.created_at) since = row.created_at
                qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0] ?? '').startsWith('/me') })
                const title = (document.documentElement.lang === 'en' ? row.title_en || row.title_ar : row.title_ar || row.title_en) ?? ''
                if (title) toast(title, 'info')
                playChime()
                window.dispatchEvent(new CustomEvent('tedc:notification'))
              } else if (part.startsWith('event: end') && row.since) since = row.since
            }
          }
        } catch {
          await new Promise((r) => window.setTimeout(r, 15000))
        }
      }
    }
    void loop()
    return () => { stop = true }
  }, [user, qc])
}
