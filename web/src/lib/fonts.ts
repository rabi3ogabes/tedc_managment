/**
 * The Ministry's Lusail typeface is licensed and not bundled. When its files are placed in `public/fonts/lusail/`
 * (Lusail-Regular / Medium / Bold .woff2) this registers them; when they are absent nothing is requested and the
 * stack falls back to Qatar Sans → Tajawal. One HEAD request per browser session decides.
 */
const FILES: [string, number][] = [['Lusail-Regular.woff2', 400], ['Lusail-Medium.woff2', 500], ['Lusail-Bold.woff2', 700]]
const FLAG = 'tedc.lusail'

async function present(): Promise<boolean> {
  try {
    const cached = sessionStorage.getItem(FLAG)
    if (cached) return cached === '1'
    const res = await fetch('/fonts/lusail/Lusail-Regular.woff2', { method: 'HEAD' })
    // A host that rewrites unknown paths to the app (200 + HTML) must not count as "the file is there".
    const ok = res.ok && /font|octet-stream/i.test(res.headers.get('content-type') ?? '')
    sessionStorage.setItem(FLAG, ok ? '1' : '0')
    return ok
  } catch {
    return false
  }
}

let on = false
const listeners = new Set<() => void>()

/** Whether the licensed files are in place and registered (so the font stacks can put Lusail first). */
export const lusailOn = () => on

/** Calls back once Lusail has been registered, so the theme can rebuild its font stacks. */
export function onLusail(callback: () => void) {
  listeners.add(callback)
  return () => { listeners.delete(callback) }
}

export async function registerLusail() {
  if (document.getElementById('tedc-lusail') || !(await present())) return
  const style = document.createElement('style')
  style.id = 'tedc-lusail'
  style.textContent = FILES.map(([file, weight]) => `@font-face{font-family:"Lusail";src:url("/fonts/lusail/${file}") format("woff2");font-weight:${weight};font-display:swap;}`).join('\n')
  document.head.appendChild(style)
  on = true
  listeners.forEach((callback) => callback())
}
