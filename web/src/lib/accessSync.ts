import type { Me } from './types'

/** An open page asks again at most this often, however many times the tab is looked at. */
export const SYNC_GAP = 20_000
/** …and by itself this often while it stays in front of the person. */
export const SYNC_EVERY = 5 * 60_000

/**
 * What decides the menus and the pages a person may open: the roles they hold, the one they work in, and its rights.
 * Two profiles with the same print show the same things, so the screen is left alone.
 */
export function accessPrint(me: Pick<Me, 'roles' | 'active_role' | 'permissions'>): string {
  const roles = me.roles.map((r) => `${r.id}:${r.slug}:${r.landing_route}:${r.expires_at ?? ''}`).sort()
  return JSON.stringify([me.active_role?.id ?? null, roles, [...me.permissions].sort()])
}

/** Whether the server's answer changes what the person sees (a right given or taken, a role granted, revoked or ended). */
export function accessChanged(before: Pick<Me, 'roles' | 'active_role' | 'permissions'>, after: Pick<Me, 'roles' | 'active_role' | 'permissions'>): boolean {
  return accessPrint(before) !== accessPrint(after)
}

/** Whether enough time has passed since the last check to ask again. */
export function dueForSync(lastAt: number, now: number, gap = SYNC_GAP): boolean {
  return now - lastAt >= gap
}
