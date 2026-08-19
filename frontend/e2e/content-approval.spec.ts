import { expect, test } from '@playwright/test'

test.describe('Content approval workflow', () => {
  test('submits and approves a note from the editor review drawer', async ({ page }) => {
    await page.goto('/')

    await page.click('[data-testid="new-note-btn"]')
    await page.waitForSelector('[data-testid="create-note-input"]', { state: 'visible' })
    await page.fill('[data-testid="create-note-input"]', `approval-${Date.now()}.md`)
    await page.click('[data-testid="create-note-submit"]')

    await expect(page.locator('[data-testid="editor-title"]')).toBeVisible({ timeout: 10000 })
    await page.click('[data-testid="review-drawer-btn"]')

    const drawer = page.locator('[data-testid="review-drawer"]')
    await expect(drawer).toBeVisible()
    await expect(drawer.locator('[data-testid="review-state"]')).toContainText('Draft')

    await drawer.locator('[data-testid="review-submit"]').click()
    await expect(drawer.locator('[data-testid="review-state"]')).toContainText('In review')

    await drawer.locator('[data-testid="review-approve"]').click()
    await expect(drawer.locator('[data-testid="review-state"]')).toContainText('Approved')
  })
})
