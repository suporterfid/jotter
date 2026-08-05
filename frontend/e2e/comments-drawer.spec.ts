import { expect, test } from '@playwright/test'

test.describe('Right-drawer mechanism (#262)', () => {
  test('opens the comments drawer as an overlay without unmounting the note, and posts a comment', async ({ page }) => {
    await page.goto('/')

    const loginEmail = page.locator('[data-testid="login-email"]')
    const isLoginVisible = await loginEmail.isVisible({ timeout: 10000 }).catch(() => false)
    if (isLoginVisible) {
      await page.fill('[data-testid="login-email"]', 'admin@example.com')
      await page.fill('[data-testid="login-password"]', 'password12345')
      await page.click('[data-testid="login-submit"]')
      await expect(loginEmail).toBeHidden({ timeout: 10000 })
    }

    await page.click('[data-testid="new-note-btn"]')
    await page.waitForSelector('[data-testid="create-note-input"]', { state: 'visible' })
    await page.fill('[data-testid="create-note-input"]', 'e2e-drawer.md')
    await page.click('[data-testid="create-note-submit"]')
    await expect(page.locator('[data-testid="editor-title"]')).toHaveValue(/e2e-drawer/, { timeout: 10000 })

    // WY.5 (#325) made 'live' the default view mode; this test asserts
    // the raw textarea's visibility directly, so switch to Edit first.
    await page.click('[data-testid="view-mode-edit"]')

    const editorTextarea = page.locator('[data-testid="markdown-textarea"]')
    await editorTextarea.fill('# Drawer Test Note')
    await expect(page.locator('[data-testid="save-status-indicator"]')).toContainText('Saved', { timeout: 10000 })

    // Drawer closed by default; the note editor is visible underneath.
    await expect(page.locator('[data-testid="comments-drawer"]')).toHaveCount(0)
    await expect(editorTextarea).toBeVisible()

    // Opening the drawer overlays the note rather than replacing it —
    // both must be visible at once, unlike the workspace-level view
    // switches (Graph/Attachments/etc.) that unmount the note entirely.
    await page.click('[data-testid="comments-drawer-btn"]')
    await expect(page.locator('[data-testid="comments-drawer"]')).toBeVisible()
    await expect(editorTextarea).toBeVisible()

    await page.fill('[data-testid="comment-input"]', 'A drawer-posted comment')
    await page.click('[data-testid="comment-submit-btn"]')
    await expect(page.locator('[data-testid="comment-item"]').last()).toContainText('A drawer-posted comment', { timeout: 10000 })

    await page.click('[data-testid="comments-drawer-close-btn"]')
    await expect(page.locator('[data-testid="comments-drawer"]')).toHaveCount(0)
  })
})
