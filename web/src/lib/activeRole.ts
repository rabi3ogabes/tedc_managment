const KEY = 'tedc.activeRole'

/** The role grant the user works in on this device (sent as `X-Active-Role`); empty = the server picks the remembered or highest role. */
export const activeRoleStore = {
  get(): string | null {
    try { return localStorage.getItem(KEY) } catch { return null }
  },
  set(id: string | null) {
    try { if (id) localStorage.setItem(KEY, id); else localStorage.removeItem(KEY) } catch { /* storage unavailable */ }
  },
}
