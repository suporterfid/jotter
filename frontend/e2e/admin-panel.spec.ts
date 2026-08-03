import { expect, test } from '@playwright/test'

test.describe('Admin Panel workspace creation', () => {
  test('an admin can open the Admin Panel from the sidebar and create a workspace', async ({ page }) => {
    page.on('console', (msg) => {
      if (msg.type() === 'error') console.log(`[browser console] ${msg.text()}`)
    })
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

    await page.click('[data-testid="more-actions-btn"]')
    await page.click('[data-testid="admin-panel-btn"]')

    const panel = page.locator('[data-testid="admin-panel"]')
    await expect(panel).toBeVisible()

    const slug = `e2e-admin-ws-${Date.now()}`
    // Select the first real tenant option, skipping the disabled
    // "Select a tenant…" placeholder — robust regardless of seed data size.
    const firstTenantValue = await page
      .locator('[data-testid="admin-new-workspace-tenant"] option:not([disabled])')
      .first()
      .getAttribute('value')
    await page.selectOption('[data-testid="admin-new-workspace-tenant"]', firstTenantValue!)
    await page.fill('[data-testid="admin-new-workspace-name"]', 'E2E Admin Workspace')
    await page.fill('[data-testid="admin-new-workspace-slug"]', slug)
    await page.fill('[data-testid="admin-new-workspace-vault-path"]', `/var/www/html/storage/app/vaults/${slug}`)
    await page.click('[data-testid="admin-new-workspace-submit"]')

    await expect(panel).toContainText('E2E Admin Workspace', { timeout: 10000 })

    await page.click('.close-btn')
    await expect(panel).toBeHidden()

    // The workspace switcher should refresh and offer the newly created workspace.
    await expect(page.locator('[data-testid="workspace-switcher-select"] option', { hasText: 'E2E Admin Workspace' }))
      .toHaveCount(1)
  })
})
