import { activeRoleStore } from './activeRole'
import { api, sessionStore, type StoredSession } from './api'
import { insidePath } from './insidePath'
import type { Me } from './types'

const KEY = 'tedc.impersonation'

export type ImpersonationState = {
  admin: StoredSession; target: { id: string; name: string; email: string }; adminName: string; until: number
  /** The role this tab worked in before the account was opened; the administrator returns to it. */
  adminRole?: string | null
  /** Where the end is recorded and where the administrator lands afterwards (Settings → Showcase accounts uses its own). */
  stopPath?: string; returnTo?: string
}

/** How an account is opened: a user from the Users page (the default), or a showcase account. */
export type ImpersonationVia = { open: string; stop: string; returnTo: string }

/**
 * Where an opened account starts: the home of the role it is opened in. Asking for a role (`roleId`) opens the account
 * in that one of the person's roles; otherwise it starts where the person themselves would, in the role the server chose.
 */
export function landingOf(user: Pick<Me, 'roles' | 'active_role'>, roleId?: string | null): string {
  const role = (roleId ? user.roles.find((r) => r.id === roleId) : null) ?? user.active_role ?? user.roles.find((r) => r.active) ?? user.roles[0]
  return role?.landing_route || '/portal'
}

/** The page the administrator is on, as a path inside the site (never another site's address). */
function cameFrom(): string {
  try { return insidePath(window.location.pathname + window.location.search, '/admin/users') } catch { return '/admin/users' }
}

export const impersonation = {
  get(): ImpersonationState | null {
    try {
      const raw = localStorage.getItem(KEY)
      return raw ? (JSON.parse(raw) as ImpersonationState) : null
    } catch {
      return null
    }
  },

  /**
   * Opens the user's account in this browser; the administrator's own session is kept to return to. Resolves with the
   * page to go to: the home of the role the account is opened in (`roleId`, or the one the person starts in).
   */
  async start(userId: string, via?: ImpersonationVia, roleId?: string | null): Promise<{ landing: string }> {
    const admin = sessionStore.get()
    if (!admin) throw new Error('not signed in')
    const { data } = await api.post(via?.open ?? `/admin/users/${userId}/impersonate`)
    const user = data.user as Me
    const role = (roleId && user.roles.some((r) => r.id === roleId) ? roleId : null) ?? user.active_role?.id ?? null
    localStorage.setItem(KEY, JSON.stringify({
      admin, target: { id: user.id, name: user.name, email: user.email }, adminName: data.impersonator.name, until: Date.now() + data.expires_in * 1000,
      // Back to where the account was opened from (the Users page, an employee's panel…), with its address as it was.
      adminRole: activeRoleStore.get(), stopPath: via?.stop, returnTo: via?.returnTo ?? cameFrom(),
    } satisfies ImpersonationState))
    sessionStore.set({ access_token: data.access_token, refresh_token: '', expires_at: Date.now() + data.expires_in * 1000 })
    // The tab now asks as the opened account, in the role it was opened in (never the administrator's own role).
    activeRoleStore.set(role)
    return { landing: landingOf(user, role) }
  },

  /** Back to the administrator's own account; resolves with the page to return to. */
  async stop(): Promise<string> {
    const state = impersonation.get()
    if (!state) return '/admin/users'
    sessionStore.set(state.admin)
    activeRoleStore.set(state.adminRole ?? null)
    localStorage.removeItem(KEY)
    try { await api.post(state.stopPath ?? `/admin/users/${state.target.id}/impersonate/stop`) } catch { /* the audit entry is best effort */ }
    return insidePath(state.returnTo, '/admin/users')
  },

  /** The borrowed token expired or was refused: go back without asking; resolves with the page to return to, or null. */
  restoreSilently(): string | null {
    const state = impersonation.get()
    if (!state) return null
    sessionStore.set(state.admin)
    activeRoleStore.set(state.adminRole ?? null)
    localStorage.removeItem(KEY)
    return insidePath(state.returnTo, '/admin/users')
  },
}
