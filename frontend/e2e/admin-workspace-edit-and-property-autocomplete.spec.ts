import { expect, test } from '@playwright/test'

test.describe('Admin workspace edit and property-name autocomplete (#282)', () => {
  test('an admin can edit a workspace name and slug from the Admin Panel', async ({ page }) => {
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

    // Create a throwaway workspace to edit, so this test doesn't depend
    // on -- or mutate -- the seeded default workspace.
    const uniqueName = `Editable Workspace ${Date.now()}`
    const slug = `e2e-editable-ws-${Date.now()}`
    const firstTenantValue = await page
      .locator('[data-testid="admin-new-workspace-tenant"] option:not([disabled])')
      .first()
      .getAttribute('value')
    await page.selectOption('[data-testid="admin-new-workspace-tenant"]', firstTenantValue!)
    await page.fill('[data-testid="admin-new-workspace-name"]', uniqueName)
    await page.fill('[data-testid="admin-new-workspace-slug"]', slug)
    await page.fill('[data-testid="admin-new-workspace-vault-path"]', `/var/www/html/storage/app/vaults/${slug}`)
    await page.click('[data-testid="admin-new-workspace-submit"]')
    await expect(panel).toContainText(uniqueName, { timeout: 10000 })

    // Locate the row by its current (pre-edit) text to find the right
    // Edit button, but switch to page-level testids once editing starts --
    // the row's static text disappears once it becomes an input, so a
    // hasText-filtered locator would stop resolving.
    const row = panel.locator('.admin-list-item', { hasText: uniqueName })
    await row.locator('[data-testid="admin-edit-workspace-btn"]').click()
    await page.fill('[data-testid="admin-edit-workspace-name"]', 'Renamed Workspace')
    await page.click('[data-testid="admin-edit-workspace-save"]')

    await expect(panel).toContainText('Renamed Workspace', { timeout: 10000 })
    await expect(panel.locator('[data-testid="admin-edit-workspace-name"]')).toHaveCount(0)
  })

  test('the Add-property name field offers existing workspace property names as suggestions', async ({ page }) => {
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

    const path = `e2e-property-autocomplete-${Date.now()}.md`
    await page.click('[data-testid="new-note-btn"]')
    await page.waitForSelector('[data-testid="create-note-input"]', { state: 'visible' })
    await page.fill('[data-testid="create-note-input"]', path)
    await page.click('[data-testid="create-note-submit"]')
    await expect(page.locator('[data-testid="editor-path"]')).toContainText(path, { timeout: 10000 })

    // The Properties panel is collapsed by default -- expand it first.
    await page.locator('[aria-label="Properties"] [data-testid="panel-collapse-toggle"]').click()

    // Add a real property first so it becomes a known workspace-wide name.
    await page.fill('[data-testid="property-name-input"]', 'priority')
    await page.selectOption('[data-testid="property-type-select"]', 'numeric')
    await page.fill('[data-testid="property-value-input"]', '3')
    await page.click('[data-testid="property-add-btn"]')
    await expect(page.locator('[data-testid="property-value-btn"]')).toContainText('3', { timeout: 10000 })

    // A second note in the same workspace should see "priority" offered
    // via the datalist once the panel re-fetches known properties.
    await page.click('[data-testid="new-note-btn"]')
    await page.waitForSelector('[data-testid="create-note-input"]', { state: 'visible' })
    await page.fill('[data-testid="create-note-input"]', `e2e-property-autocomplete-2-${Date.now()}.md`)
    await page.click('[data-testid="create-note-submit"]')
    await expect(page.locator('[data-testid="editor-path"]')).toContainText('e2e-property-autocomplete-2', { timeout: 10000 })

    // The panel component instance is reused across note switches, so the
    // known-properties list only re-fetches on focus of the name field.
    const propertiesRefetch = page.waitForResponse((res) => res.url().includes('/properties') && res.request().method() === 'GET')
    await page.locator('[data-testid="property-name-input"]').focus()
    await propertiesRefetch

    const optionValues = await page.locator('#known-property-names option').evaluateAll(
      (opts) => opts.map((o) => (o as HTMLOptionElement).value)
    )
    expect(optionValues).toContain('priority')
  })
})
