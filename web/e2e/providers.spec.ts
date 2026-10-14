import { expect, test, type Page } from '@playwright/test'

/** Signs in through the local demo-account picker (the password is never typed or stored here). */
async function signIn(page: Page, account: RegExp) {
  await page.goto('/login')
  await page.getByRole('button', { name: account }).click()
  await page.waitForURL((u) => !u.pathname.startsWith('/login'))
}

/** The first-visit tour is not what is being tested: set it aside whenever it is in the way. */
async function setTourAside(page: Page) {
  const skip = page.getByRole('button', { name: /^(تخطي|Skip)$/ })
  for (let i = 0; i < 3; i++) {
    if (!(await skip.first().isVisible({ timeout: 1500 }).catch(() => false))) return
    await skip.first().click()
  }
}

async function switchProvider(page: Page, card: ReturnType<Page['getByRole']>, on: boolean) {
  await card.getByRole('button', { name: /^(الإعدادات|Settings)$/ }).click()
  const dialog = page.getByRole('dialog')
  await dialog.getByRole('checkbox', { name: /^(مفعّل|Switched on)$/ }).setChecked(on)
  await dialog.getByRole('button', { name: /^(حفظ|Save)$/ }).click()
  await expect(dialog).toBeHidden()
}

test('learning providers: switch one on in the training mode, read it, and find its courses', async ({ page }) => {
  await signIn(page, /Center Admin/)
  await page.goto('/admin/standards')
  await setTourAside(page)
  await page.getByRole('button', { name: /^(مزوّدو المحتوى|Content providers)$/ }).click()
  const card = page.getByRole('article', { name: /Coursera|كورسيرا/ })
  await expect(card).toBeVisible()

  await switchProvider(page, card, true)
  await expect(card.getByText(/^(وضع التدريب|Training mode)$/)).toBeVisible()
  await card.getByRole('button', { name: /^(قراءة الآن|Read now)$/ }).click()
  await expect(page.getByText('Coursera course 101')).toBeVisible()
  await expect(page.getByText('Coursera course 102')).toBeVisible()

  // The search narrows the catalogue as the person pauses typing.
  await page.getByRole('searchbox', { name: /ابحث في عناوين الدورات|Search course titles/ }).fill('102')
  await expect(page.getByText('Coursera course 101')).toBeHidden()
  await expect(page.getByText('Coursera course 102')).toBeVisible()

  // Nothing could not be counted, and the page says so.
  await expect(page.getByText(/لا أخطاء|No errors/)).toBeVisible()

  await switchProvider(page, card, false)   // leave the local installation as it was found
  await expect(card.getByText(/^(متوقف|Switched off)$/)).toBeVisible()
})
