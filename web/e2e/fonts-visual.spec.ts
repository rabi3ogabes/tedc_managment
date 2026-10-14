import { mkdirSync, writeFileSync } from 'node:fs'
import { join } from 'node:path'
import { expect, test, type Page } from '@playwright/test'

/**
 * UX-03: the Ministry's Lusail typeface against the fallback, on three key pages, to approve line heights.
 * For each page it saves a screenshot with the fallback font and (when the licensed files are in web/public/fonts/lusail/) one with Lusail,
 * records how tall the text lines are, and fails when any text is clipped. The screenshots and the measurements go to test-results/fonts/.
 *   npm run e2e -- fonts-visual
 * Local only (the dev servers must be up); it signs in through the demo-account picker and never types a password.
 */
const OUT = join('test-results', 'fonts')
const PAGES = [
  { id: 'login', path: '/login', account: null as RegExp | null, text: ['h1', 'label', 'button'] },
  { id: 'portal-home', path: '/portal', account: /متدرب 4/, text: ['h1', 'h2', 'p', 'a'] },
  { id: 'admin-employees', path: '/admin/employees', account: /Super Admin/, text: ['h1', 'th', 'td', 'button'] },
]

async function signIn(page: Page, account: RegExp) {
  await page.goto('/login')
  await page.getByRole('button', { name: account }).click()
  await page.waitForURL((u) => !u.pathname.startsWith('/login'))
}

/** How tall the lines of the page's text are (CSS px), and the text that does not fit its box. */
async function measure(page: Page, selectors: string[]) {
  return page.evaluate((sels) => {
    const out: { selector: string; family: string; fontSize: number; lineHeight: number; ratio: number }[] = []
    const clipped: string[] = []
    for (const sel of sels) {
      const el = Array.from(document.querySelectorAll<HTMLElement>(sel)).find((e) => e.offsetParent !== null && (e.textContent ?? '').trim().length > 2)
      if (!el) continue
      const css = getComputedStyle(el)
      const size = parseFloat(css.fontSize)
      const line = css.lineHeight === 'normal' ? size * 1.2 : parseFloat(css.lineHeight)
      out.push({ selector: sel, family: css.fontFamily.split(',')[0].trim(), fontSize: size, lineHeight: line, ratio: Math.round((line / size) * 100) / 100 })
    }
    for (const el of Array.from(document.querySelectorAll<HTMLElement>('h1,h2,h3,p,th,td,label,button,a'))) {
      if (el.offsetParent === null || !(el.textContent ?? '').trim()) continue
      const css = getComputedStyle(el)
      if ((css.overflow === 'hidden' || css.overflowY === 'hidden') && el.scrollHeight > el.clientHeight + 2 && css.textOverflow !== 'ellipsis' && css.webkitLineClamp === 'none') clipped.push((el.textContent ?? '').trim().slice(0, 40))
    }
    return { out, clipped }
  }, selectors)
}

/** The page has finished loading: the opening splash is gone, requests have settled and the fonts are ready. */
async function ready(page: Page) {
  await page.waitForLoadState('networkidle')
  await page.locator('.splash').first().waitFor({ state: 'detached', timeout: 30_000 }).catch(() => undefined)
  await page.waitForLoadState('networkidle')
  await page.evaluate(() => document.fonts.ready)
}

async function lusailAvailable(page: Page) {
  const res = await page.request.head('/fonts/lusail/Lusail-Regular.woff2')
  return res.ok() && /font|octet-stream/i.test(res.headers()['content-type'] ?? '')
}

for (const p of PAGES) {
  test(`${p.id}: fallback font and Lusail, line heights and clipping`, async ({ page }) => {
    mkdirSync(OUT, { recursive: true })
    if (p.account) await signIn(page, p.account)
    await page.addInitScript(() => { try { sessionStorage.removeItem('tedc.lusail') } catch { /* private window */ } })

    // The fallback: what everyone sees until the licensed files are in place.
    await page.route('**/fonts/lusail/**', (route) => route.fulfill({ status: 404, contentType: 'text/html', body: '' }))
    await page.goto(p.path)
    await ready(page)
    await page.screenshot({ path: join(OUT, `${p.id}-fallback.png`), fullPage: true })
    const fallback = await measure(page, p.text)
    expect(fallback.out.length, 'the page showed no text to measure (still loading?)').toBeGreaterThan(0)
    expect(fallback.clipped, 'text clipped with the fallback font').toEqual([])
    await page.unroute('**/fonts/lusail/**')

    const report: Record<string, unknown> = { page: p.id, fallback }
    if (await lusailAvailable(page)) {
      await page.evaluate(() => sessionStorage.removeItem('tedc.lusail'))
      await page.goto(p.path)
      await ready(page)
      await page.waitForFunction(() => document.fonts.check('16px "Lusail"'))
      await page.screenshot({ path: join(OUT, `${p.id}-lusail.png`), fullPage: true })
      const lusail = await measure(page, p.text)
      report.lusail = lusail
      expect(lusail.clipped, 'text clipped with Lusail').toEqual([])
      for (const m of lusail.out) expect(m.ratio, `${p.id} ${m.selector}: lines in Lusail are ${m.ratio}× the size`).toBeGreaterThanOrEqual(1.2)
    } else {
      test.info().annotations.push({ type: 'lusail', description: 'The licensed files are not in web/public/fonts/lusail/: only the fallback was captured.' })
    }
    writeFileSync(join(OUT, `${p.id}.json`), JSON.stringify(report, null, 2))
  })
}
