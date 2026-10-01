import { api, sessionStore, type StoredSession } from './api'

const KEY = 'tedc.impersonation'

export type ImpersonationState = { admin: StoredSession; target: { id: string; name: string; email: string }; adminName: string; until: number }

export const impersonation = {
  get(): ImpersonationState | null {
    try {
      const raw = localStorage.getItem(KEY)
      return raw ? (JSON.parse(raw) as ImpersonationState) : null
    } catch {
      return null
    }
  },

  /** Opens the user's account in this browser; the administrator's own session is kept to return to. */
  async start(userId: string): Promise<{ landing: 'admin' | 'portal' }> {
    const admin = sessionStore.get()
    if (!admin) throw new Error('not signed in')
    const { data } = await api.post(`/admin/users/${userId}/impersonate`)
    localStorage.setItem(KEY, JSON.stringify({
      admin, target: { id: data.user.id, name: data.user.name, email: data.user.email }, adminName: data.impersonator.name, until: Date.now() + data.expires_in * 1000,
    } satisfies ImpersonationState))
    sessionStore.set({ access_token: data.access_token, refresh_token: '', expires_at: Date.now() + data.expires_in * 1000 })
    const staff = (data.user.permissions as string[]).some((p) => p === '*' || p === 'dashboard.view' || p.endsWith('.manage'))
    return { landing: staff ? 'admin' : 'portal' }
  },

  /** Back to the administrator's own account. */
  async stop(): Promise<void> {
    const state = impersonation.get()
    if (!state) return
    sessionStore.set(state.admin)
    localStorage.removeItem(KEY)
    try { await api.post(`/admin/users/${state.target.id}/impersonate/stop`) } catch { /* the audit entry is best effort */ }
  },

  /** The borrowed token expired or was refused: go back without asking. */
  restoreSilently() {
    const state = impersonation.get()
    if (!state) return false
    sessionStore.set(state.admin)
    localStorage.removeItem(KEY)
    return true
  },
}
