import type { SupabaseClient } from '@supabase/supabase-js'
import { useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { api, sessionStore } from './api'
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
