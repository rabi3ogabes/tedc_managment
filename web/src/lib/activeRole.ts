const KEY = 'tedc.activeRole'

// The role used to be remembered once for the whole browser. Whatever an earlier version left there is removed, so it cannot be
// mistaken for this tab's role.
try { localStorage.removeItem(KEY) } catch { /* storage unavailable */ }

/**
 * The role grant this tab works in (sent as `X-Active-Role`); empty = the server picks the remembered or highest role.
 *
 * It is kept per tab (sessionStorage), not per browser: a person with two roles can have one tab open as «مشرف» and another as
 * «موظف», and each tab keeps asking as its own role. When it was shared, switching in one tab silently changed what every other
 * tab was asking for, so a tab went on saying one role while showing the other role's data. A tab keeps its role across a reload;
 * a new tab starts in the role the person last switched to (the server remembers it) and is then pinned to it (see auth.tsx).
 */
export const activeRoleStore = {
  get(): string | null {
    try { return sessionStorage.getItem(KEY) } catch { return null }
  },
  set(id: string | null) {
    try { if (id) sessionStorage.setItem(KEY, id); else sessionStorage.removeItem(KEY) } catch { /* storage unavailable */ }
  },
}
