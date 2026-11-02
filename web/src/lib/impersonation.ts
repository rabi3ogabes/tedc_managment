import { api, sessionStore, type StoredSession } from './api'

const KEY = 'tedc.impersonation'

export type ImpersonationState = {
  admin: StoredSession; target: { id: string; name: string; email: string }; adminName: string; until: number
  /** Where the end is recorded and where the administrator lands afterwards (Settings → Showcase accounts uses its own). */
  stopPath?: string; returnTo?: string
}

/** How an account is opened: a user from the Users page (the default), or a showcase account. */
export type ImpersonationVia = { open: string; stop: string; returnTo: string }

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
  async start(userId: string, via?: ImpersonationVia): Promise<{ landing: 'admin' | 'portal' }> {
    const admin = sessionStore.get()
    if (!admin) throw new Error('not signed in')
    const { data } = await api.post(via?.open ?? `/admin/users/${userId}/impersonate`)
    localStorage.setItem(KEY, JSON.stringify({
      admin, target: { id: data.user.id, name: data.user.name, email: data.user.email }, adminName: data.impersonator.name, until: Date.now() + data.expires_in * 1000,
      stopPath: via?.stop, returnTo: via?.returnTo,
    } satisfies ImpersonationState))
    sessionStore.set({ access_token: data.access_token, refresh_token: '', expires_at: Date.now() + data.expires_in * 1000 })
    const staff = (data.user.permissions as string[]).some((p) => p === '*' || p === 'dashboard.view' || p.endsWith('.manage'))
    return { landing: staff ? 'admin' : 'portal' }
  },

  /** Back to the administrator's own account; resolves with the page to return to. */
  async stop(): Promise<string> {
    const state = impersonation.get()
    if (!state) return '/admin/users'
    sessionStore.set(state.admin)
    localStorage.removeItem(KEY)
    try { await api.post(state.stopPath ?? `/admin/users/${state.target.id}/impersonate/stop`) } catch { /* the audit entry is best effort */ }
    return state.returnTo ?? '/admin/users'
  },

  /** The borrowed token expired or was refused: go back without asking; resolves with the page to return to, or null. */
  restoreSilently(): string | null {
    const state = impersonation.get()
    if (!state) return null
    sessionStore.set(state.admin)
    localStorage.removeItem(KEY)
    return state.returnTo ?? '/admin/users'
  },
}
