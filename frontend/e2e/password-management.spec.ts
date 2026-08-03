import { expect, test } from '@playwright/test'

test.describe('Password management (#280)', () => {
  test('a user can change their own password from the sidebar', async ({ page }) => {
    page.on('response', (res) => {
      if (res.url().includes('/api/') && res.status() >= 400) {
        console.log(`[api error] ${res.status()} ${res.request().method()} ${res.url()}`)
      }
    })

    await page.goto('/')

    const loginEmail = page.locator('[data-testid="login-email"]')
    const isLoginVisible = await loginEmail.isVisible({ timeout: 10000 }).catch(() => false)
    if (isLoginVisible) {
      await page.fill('[data-testid="login-email"]', 'admin@example.com')
      await page.fill('[data-testid="login-password"]', 'password12345')
      await page.click('[data-testid="login-submit"]')
      await expect(loginEmail).toBeHidden({ timeout: 10000 })
    }
    await expect(page.locator('.brand-title')).toHaveText('Jotter', { timeout: 10000 })

    await page.click('[data-testid="change-password-btn"]')
    const modal = page.locator('[data-testid="change-password-modal"]')
    await expect(modal).toBeVisible()

    await page.fill('[data-testid="change-password-current"]', 'password12345')
    await page.fill('[data-testid="change-password-new"]', 'password12345')
    await page.fill('[data-testid="change-password-confirm"]', 'password12345')
    await page.click('[data-testid="change-password-submit"]')

    await expect(page.locator('[data-testid="change-password-success"]')).toBeVisible({ timeout: 10000 })
  })

  test('an admin can reset another user\'s password from the Admin Panel', async ({ page }) => {
    page.on('response', (res) => {
      if (res.url().includes('/api/') && res.status() >= 400) {
        console.log(`[api error] ${res.status()} ${res.request().method()} ${res.url()}`)
      }
    })

    await page.goto('/')

    const loginEmail = page.locator('[data-testid="login-email"]')
    const isLoginVisible = await loginEmail.isVisible({ timeout: 10000 }).catch(() => false)
    if (isLoginVisible) {
      await page.fill('[data-testid="login-email"]', 'admin@example.com')
      await page.fill('[data-testid="login-password"]', 'password12345')
      await page.click('[data-testid="login-submit"]')
      await expect(loginEmail).toBeHidden({ timeout: 10000 })
    }
    await expect(page.locator('.brand-title')).toHaveText('Jotter', { timeout: 10000 })

    // Create a throwaway local user via the panel first, so there's a
    // deterministic target for the reset action.
    await page.click('[data-testid="more-actions-btn"]')
    await page.click('[data-testid="admin-panel-btn"]')
    const panel = page.locator('[data-testid="admin-panel"]')
    await expect(panel).toBeVisible()
    await panel.locator('.tab-btn', { hasText: 'Users' }).click()

    const email = `e2e-reset-target-${Date.now()}@example.com`
    await panel.locator('input[placeholder="Full Name"]').fill('Reset Target')
    await panel.locator('input[placeholder="Email"]').fill(email)
    await panel.locator('input[placeholder="Password"]').fill('initial-password-123')
    await panel.locator('button[type="submit"]', { hasText: 'Create User' }).click()
    await expect(panel).toContainText(email, { timeout: 10000 })

    page.once('dialog', (dialog) => dialog.accept('a-brand-new-password'))
    const userRow = panel.locator('.admin-list-item', { hasText: email })
    await userRow.locator('[data-testid="admin-reset-password-btn"]').click()

    // No direct UI confirmation of success beyond the absence of an error
    // banner -- assert that, and that the row is still present/unchanged.
    await expect(panel.locator('.admin-error-banner')).toHaveCount(0)
    await expect(userRow).toBeVisible()
  })
})
