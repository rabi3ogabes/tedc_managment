/* eslint-disable @typescript-eslint/no-explicit-any */
import { createContext, useCallback, useContext, useEffect, useMemo, useRef, useState, type ReactNode } from 'react'
import { useQueryClient } from '@tanstack/react-query'
import { accessChanged, dueForSync, SYNC_EVERY } from './accessSync'
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
  // The profile the page is showing, and when the server was last asked for it (see sync below).
  const current = useRef<Me | null>(null)
  useEffect(() => { current.current = user }, [user])
  const lastSync = useRef(0)

  const refresh = useCallback(async () => {
    if (!sessionStore.get()) return setLoading(false)
    lastSync.current = Date.now()
    try {
      const { data } = await api.get('/auth/me')
      // This tab now works in the role the server answered with, whatever another tab switches to afterwards.
      activeRoleStore.set((data.data as Me).active_role?.id ?? null)
      setUser(data.data)
    } catch {
      sessionStore.set(null)
      setUser(null)
    } finally {
      setLoading(false)
    }
  }, [])

  // The rights of a role can change while its holders are signed in (Settings → Roles & permissions), and so can the
  // roles a person holds. The server applies the change on the very next request; the page learns of it here, so the
  // menus and buttons follow without signing in again: whenever the tab is looked at again, and every few minutes
  // while it stays in front. A check that fails changes nothing (the person stays signed in with what they had).
  const sync = useCallback(async () => {
    const before = current.current
    if (!before || !sessionStore.get() || !dueForSync(lastSync.current, Date.now())) return
    lastSync.current = Date.now()
    try {
      const { data } = await api.get('/auth/me')
      const after = data.data as Me
      if (current.current?.id !== after.id || !accessChanged(current.current, after)) return
      activeRoleStore.set(after.active_role?.id ?? null)
      setUser(after)
      // What was loaded with the old rights is asked for again with the new ones.
      void queryClient.invalidateQueries()
    } catch { /* offline, or the session ended: the usual handling of the next request deals with it */ }
  }, [queryClient])

  useEffect(() => {
    const onLook = () => { if (document.visibilityState === 'visible') void sync() }
    document.addEventListener('visibilitychange', onLook)
    window.addEventListener('focus', onLook)
    const timer = window.setInterval(onLook, SYNC_EVERY)
    return () => { document.removeEventListener('visibilitychange', onLook); window.removeEventListener('focus', onLook); window.clearInterval(timer) }
  }, [sync])

  useEffect(() => {
    refresh()
    const onLogout = () => setUser(null)
    window.addEventListener('tedc:logout', onLogout)
    return () => window.removeEventListener('tedc:logout', onLogout)
  }, [refresh])

  const finish = useCallback((data: any) => {
    sessionStore.set({ access_token: data.access_token, refresh_token: data.refresh_token, expires_at: Date.now() + data.expires_in * 1000 })
    // A sign-in starts in the role the server chose for this person (never one left over from whoever used the tab before).
    activeRoleStore.set((data.user as Me).active_role?.id ?? null)
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
    // From here every request of this tab is sent as the new role. Other tabs keep the role they are working in.
    activeRoleStore.set(grantId)
    // Nothing that was loaded for the previous role is kept. The new role is part of the key of every personal query
    // (queryKeyFor) and the page itself is rebuilt for it (AdminLayout), so no page can go on showing the other role's data.
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

/**
 * The id of the role grant the person is working in, or null when nobody is signed in. It is part of the key of every personal
 * query, so reading it here also redraws whoever asked when the role changes. Safe outside the provider (returns null).
 */
export function useActiveRoleId(): string | null {
  return useContext(AuthContext)?.activeRole?.id ?? null
}

/** Where a user lands after signing in or switching role: the landing page of the active role. */
export function homeFor(user: Me): string {
  const active = user.active_role ?? user.roles.find((r) => r.active)
  if (active?.landing_route) return active.landing_route
  const staff = ['super_admin', 'center_admin', 'program_coordinator', 'executive', 'school_admin', 'trainer']
  const has = (p: string) => user.permissions.includes(p) || user.roles.some((r) => r.slug === 'super_admin')
  if (user.roles.some((r) => staff.includes(r.slug))) {
    // The dashboard needs its own permission: a trainer, for one, starts at the programs they teach instead.
    if (has('dashboard.view')) return '/admin'
    if (has('programs.view')) return '/admin/programs'
  }
  // Kit developers and the QA team work in the Training Kit Studio.
  return user.roles.some((r) => ['kit_developer', 'qa_reviewer'].includes(r.slug)) ? '/admin/kits' : '/portal'
}
