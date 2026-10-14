import { expect, test, type Page } from '@playwright/test'

type Fixture = { assessment_id: string; program_id: string; title_ar: string; title_en: string }
const fx = (): Fixture => JSON.parse(process.env.E2E_FACE ?? '{}') as Fixture

/** Signs in through the local demo-account picker (the password is never typed or stored here). */
async function signIn(page: Page, account: RegExp) {
  await page.goto('/login')
  await page.getByRole('button', { name: account }).click()
  await page.waitForURL((u) => !u.pathname.startsWith('/login'))
}

test('camera presence check: consent, a face event without any image, and the flag in the live view', async ({ page, context }) => {
  const f = fx()
  const events: unknown[] = []
  page.on('request', (r) => { if (r.method() === 'POST' && /\/me\/attempts\/[^/]+\/events$/.test(r.url())) events.push(r.postDataJSON()) })

  // The learner: consent first, then the exam runs with the check on.
  await signIn(page, /متدرب 4/)
  await page.goto(`/portal/assessments/${f.assessment_id}/take`)
  await expect(page.getByRole('heading', { name: /التحقق من الحضور بالكاميرا|Camera presence check/ })).toBeVisible()
  await page.getByRole('button', { name: /شغّل الكاميرا وابدأ|Turn on the camera and start/ }).click()
  await expect(page.getByText(/التحقق بالكاميرا يعمل|Camera check on/)).toBeVisible({ timeout: 90_000 })

  // The fake camera shows no face: after three seconds one "no face" event goes out, carrying its type only.
  const sent = await page.waitForRequest((r) => r.method() === 'POST' && /\/events$/.test(r.url()) && (r.postDataJSON() as { type?: string })?.type === 'face_absent', { timeout: 60_000 })
  expect(sent.postDataJSON()).toEqual({ type: 'face_absent' })
  await expect(page.getByText(/لا نراك|We cannot see you/)).toBeVisible()
  expect(JSON.stringify(events)).not.toMatch(/data:image|base64/)

  // The invigilator sees the flag in the live view.
  await context.clearCookies()
  await page.evaluate(() => { localStorage.clear(); sessionStorage.clear() })
  await signIn(page, /Super Admin/)
  await page.goto(`/admin/programs/${f.program_id}?tab=assessments`)
  const row = page.locator('div', { hasText: f.title_ar }).filter({ has: page.getByRole('button', { name: '+' }) }).last()
  await row.getByRole('button', { name: '+' }).click()
  await page.getByRole('button', { name: /^(المراقبة|Invigilation)$/ }).click()
  await expect(page.getByText(/لم يظهر وجه أمام الشاشة|No face at the screen/).first()).toBeVisible()
})
