import { expect, test } from '@playwright/test'

test.describe('Jotter Notes E2E Journey', () => {
  test('creates, edits, renders wikilinks, and searches notes', async ({ page }) => {
    await page.goto('/')

    const loginEmail = page.locator('[data-testid="login-email"]')
    const isLoginVisible = await loginEmail.isVisible({ timeout: 3000 }).catch(() => false)
    if (isLoginVisible) {
      await page.fill('[data-testid="login-email"]', 'admin@example.com')
      await page.fill('[data-testid="login-password"]', 'password12345')
      await page.click('[data-testid="login-submit"]')
      await expect(loginEmail).toBeHidden({ timeout: 10000 })
    }

    // Verify sidebar loaded
    await expect(page.locator('.brand-title')).toHaveText('Jotter', { timeout: 10000 })

    // Open New Note Modal
    await page.click('[data-testid="new-note-btn"]')
    await page.waitForSelector('[data-testid="create-note-input"]', { state: 'visible' })
    await page.fill('[data-testid="create-note-input"]', 'e2e-demo.md')
    await page.click('[data-testid="create-note-submit"]')

    // Active note editor should appear
    await expect(page.locator('[data-testid="editor-title"]')).toContainText('e2e-demo', { timeout: 10000 })
    await expect(page.locator('[data-testid="editor-path"]')).toContainText('e2e-demo.md')

    // Edit content with headings, wikilinks, and malicious script to test safe rendering
    const editorTextarea = page.locator('[data-testid="markdown-textarea"]')
    await editorTextarea.fill('# Demo Note\n\nLinking to [[welcome.md|Welcome]] note.\n\n<script>window.XSS_EXECUTED=true;</script>')

    // Save note
    await page.click('[data-testid="save-note-btn"]')
    await expect(page.locator('[data-testid="save-note-btn"]')).toContainText('Saved')

    // Check preview rendering for heading and wikilink
    const preview = page.locator('.markdown-preview')
    await expect(preview).toContainText('Demo Note')
    await expect(preview.locator('a.wikilink')).toContainText('Welcome')

    // Confirm XSS safety: script tag was sanitized and did not execute
    const xssExecuted = await page.evaluate(() => (window as unknown as Record<string, boolean>).XSS_EXECUTED)
    expect(xssExecuted).toBeUndefined()

    // Test Search UI
    const searchInput = page.locator('.search-input')
    await searchInput.fill('Demo Note')
    await expect(page.locator('.section-label')).toContainText('Filtered Notes')
    await expect(page.locator('.notes-list')).toContainText('e2e-demo.md')
  })
})
