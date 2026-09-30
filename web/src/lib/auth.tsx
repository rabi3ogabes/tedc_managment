import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { api, sessionStore } from './api'
import type { Me } from './types'
import i18n from '@/i18n'

type AuthState = {
  user: Me | null
  loading: boolean
  login: (email: string, password: string) => Promise<Me>
  logout: () => void
  can: (permission: string) => boolean
  hasRole: (...roles: string[]) => boolean
  refresh: () => Promise<void>
}

const AuthContext = createContext<AuthState | null>(null)

export function AuthProvider({ children }: { children: ReactNode }) {
  const [user, setUser] = useState<Me | null>(null)
  const [loading, setLoading] = useState(!!sessionStore.get())
  const queryClient = useQueryClient()

  const refresh = useCallback(async () => {
    if (!sessionStore.get()) return setLoading(false)
    try {
      const { data } = await api.get('/auth/me')
      setUser(data.data)
    } catch {
      sessionStore.set(null)
      setUser(null)
    } finally {
      setLoading(false)
    }
  }, [])

  useEffect(() => {
    refresh()
    const onLogout = () => setUser(null)
    window.addEventListener('tedc:logout', onLogout)
    return () => window.removeEventListener('tedc:logout', onLogout)
  }, [refresh])

  const login = useCallback(async (email: string, password: string) => {
    const { data } = await api.post('/auth/login', { email, password })
    sessionStore.set({ access_token: data.access_token, refresh_token: data.refresh_token, expires_at: Date.now() + data.expires_in * 1000 })
    setUser(data.user)
    if (data.user.locale && data.user.locale !== i18n.language) i18n.changeLanguage(data.user.locale)
    return data.user as Me
  }, [])

  const logout = useCallback(() => {
    sessionStore.set(null)
    setUser(null)
    queryClient.clear()
  }, [queryClient])

  const value = useMemo<AuthState>(() => ({
    user,
    loading,
    login,
    logout,
    refresh,
    can: (permission) => !!user && (user.permissions.includes('*') || user.permissions.includes(permission)),
    hasRole: (...roles) => !!user && user.roles.some((r) => roles.includes(r.slug)),
  }), [user, loading, login, logout, refresh])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}

/** Where a user lands after signing in. */
export function homeFor(user: Me): string {
  const staff = ['super_admin', 'center_admin', 'program_coordinator', 'executive', 'school_admin', 'trainer']
  if (user.roles.some((r) => staff.includes(r.slug))) return '/admin'
  // Kit developers and the QA team work in the Training Kit Studio.
  return user.roles.some((r) => ['kit_developer', 'qa_reviewer'].includes(r.slug)) ? '/admin/kits' : '/portal'
}
