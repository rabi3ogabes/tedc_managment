/**
 * The focused layout for pages opened from another LMS (an LTI launch): no portal menus, a slim bar with the way back
 * to the full site. Kept for this browser tab only.
 */
const KEY = 'tedc.embedded'

export const embedded = {
  get(): boolean {
    try { return sessionStorage.getItem(KEY) === '1' } catch { return false }
  },
  set(on: boolean): void {
    try { if (on) sessionStorage.setItem(KEY, '1'); else sessionStorage.removeItem(KEY) } catch { /* storage unavailable: the full layout is shown */ }
    window.dispatchEvent(new Event('tedc:embedded'))
  },
}
