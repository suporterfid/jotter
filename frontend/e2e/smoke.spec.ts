import { expect, test } from '@playwright/test'

test('Jotter application loads sidebar and brand title', async ({ page }) => {
  await page.goto('/')

  const loginEmail = page.locator('[data-testid="login-email"]')
  const isLoginVisible = await loginEmail.isVisible({ timeout: 3000 }).catch(() => false)
  if (isLoginVisible) {
    await page.fill('[data-testid="login-email"]', 'admin@example.com')
    await page.fill('[data-testid="login-password"]', 'password12345')
    await page.click('[data-testid="login-submit"]')
    await expect(loginEmail).toBeHidden({ timeout: 10000 })
  }

  await expect(page.locator('.brand-title')).toHaveText('Jotter', { timeout: 10000 })
  await expect(page.locator('.sidebar')).toBeVisible()
})
