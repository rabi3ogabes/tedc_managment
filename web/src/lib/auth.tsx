/* eslint-disable @typescript-eslint/no-explicit-any */
import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { activeRoleStore } from './activeRole'
import { api, sessionStore } from './api'
import type { Me, RoleGrant } from './types'
import i18n from '@/i18n'

type AuthState = {
  user: Me | null
  loading: boolean
  login: (email: string, password: string) => Promise<Me>
  /** Finishes a sign-in from a session the server returned (after a second factor, single sign-on or a directory login). */
  finish: (data: any) => Me
  logout: () => void
  can: (permission: string) => boolean
  activeRole: RoleGrant | null
  /** Works as another of the user's roles: no new sign-in, the data is reloaded for the new role. */
  switchRole: (grantId: string) => Promise<RoleGrant | null>
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

  const finish = useCallback((data: any) => {
    sessionStore.set({ access_token: data.access_token, refresh_token: data.refresh_token, expires_at: Date.now() + data.expires_in * 1000 })
    setUser(data.user)
    if (data.user.locale && data.user.locale !== i18n.language) i18n.changeLanguage(data.user.locale)
    try { if (data.password_expired) sessionStorage.setItem('tedc.pwexpired', '1'); else sessionStorage.removeItem('tedc.pwexpired') } catch { /* storage unavailable */ }
    return data.user as Me
  }, [])

  const login = useCallback(async (email: string, password: string) => {
    let device: string | undefined
    try { device = localStorage.getItem('tedc.device') ?? undefined } catch { /* storage unavailable */ }
    const { data } = await api.post('/auth/login', { email, password, device_token: device })
    if (data.mfa_required) throw new MfaRequired({ mfa_token: data.mfa_token, methods: data.methods ?? [], enrolled: !!data.enrolled })
    return finish(data)
  }, [finish])

  const switchRole = useCallback(async (grantId: string) => {
    const { data } = await api.post('/auth/active-role', { role_user_id: grantId })
    activeRoleStore.set(grantId)
    // Everything on screen was loaded for the previous role.
    queryClient.clear()
    setUser(data.data)
    return (data.data as Me).active_role ?? null
  }, [queryClient])

  const logout = useCallback(() => {
    sessionStore.set(null)
    activeRoleStore.set(null)
    setUser(null)
    queryClient.clear()
  }, [queryClient])

  const value = useMemo<AuthState>(() => ({
    user,
    loading,
    login,
    finish,
    logout,
    refresh,
    switchRole,
    activeRole: user?.active_role ?? user?.roles.find((r) => r.active) ?? null,
    can: (permission) => !!user && (user.permissions.includes('*') || user.permissions.includes(permission)),
    hasRole: (...roles) => !!user && user.roles.some((r) => roles.includes(r.slug)),
  }), [user, loading, login, finish, logout, refresh, switchRole])

  return <AuthContext.Provider value={value}>{children}</AuthContext.Provider>
}

export type MfaChallenge = { mfa_token: string; methods: string[]; enrolled: boolean }

/** Thrown by login() when the password was right but a second factor is needed. */
export class MfaRequired extends Error {
  challenge: MfaChallenge
  constructor(challenge: MfaChallenge) {
    super('mfa_required')
    this.challenge = challenge
  }
}

export function useAuth() {
  const ctx = useContext(AuthContext)
  if (!ctx) throw new Error('useAuth must be used within AuthProvider')
  return ctx
}

/** Where a user lands after signing in or switching role: the landing page of the active role. */
export function homeFor(user: Me): string {
  const active = user.active_role ?? user.roles.find((r) => r.active)
  if (active?.landing_route) return active.landing_route
  const staff = ['super_admin', 'center_admin', 'program_coordinator', 'executive', 'school_admin', 'trainer']
  if (user.roles.some((r) => staff.includes(r.slug))) return '/admin'
  // Kit developers and the QA team work in the Training Kit Studio.
  return user.roles.some((r) => ['kit_developer', 'qa_reviewer'].includes(r.slug)) ? '/admin/kits' : '/portal'
}
