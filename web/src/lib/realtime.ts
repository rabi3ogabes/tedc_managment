import { createClient, type SupabaseClient } from '@supabase/supabase-js'
import { useQueryClient } from '@tanstack/react-query'
import { useEffect } from 'react'
import { sessionStore } from './api'
import { useAuth } from './auth'

const url = import.meta.env.VITE_SUPABASE_URL as string | undefined
const key = import.meta.env.VITE_SUPABASE_ANON_KEY as string | undefined

let client: SupabaseClient | null = null
function supabase() {
  if (!url || !key) return null
  client ??= createClient(url, key, { auth: { persistSession: false } })
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
    const sb = supabase()
    const session = sessionStore.get()
    if (!sb || !user || !session) return

    sb.realtime.setAuth(session.access_token)
    const channel = sb
      .channel(`notifications:${user.id}`)
      .on('postgres_changes', { event: 'INSERT', schema: 'public', table: 'notifications', filter: `user_id=eq.${user.id}` }, () => {
        qc.invalidateQueries({ predicate: (q) => String(q.queryKey[0] ?? '').startsWith('/me') })
      })
      .subscribe()

    return () => {
      sb.removeChannel(channel)
    }
  }, [user, qc])
}
