import { expect, test } from '@playwright/test'

test.describe('Jotter Notes E2E Journey', () => {
  test('creates, edits, renders wikilinks, and searches notes', async ({ page }) => {
    page.on('console', (msg) => {
      if (msg.type() === 'error') console.log(`[browser console] ${msg.text()}`)
    })
    page.on('pageerror', (err) => console.log(`[browser pageerror] ${err.message}`))
    page.on('requestfailed', (req) => console.log(`[requestfailed] ${req.method()} ${req.url()} ${req.failure()?.errorText}`))
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

    // Verify sidebar loaded
    await expect(page.locator('.brand-title')).toHaveText('Jotter', { timeout: 10000 })

    // Open New Note Modal
    await page.click('[data-testid="new-note-btn"]')
    await page.waitForSelector('[data-testid="create-note-input"]', { state: 'visible' })
    await page.fill('[data-testid="create-note-input"]', 'e2e-demo.md')
    await page.click('[data-testid="create-note-submit"]')

    // Active note editor should appear. Title is now an editable textarea
    // (#257), not chrome text, so assert its value rather than textContent.
    await expect(page.locator('[data-testid="editor-title"]')).toHaveValue(/e2e-demo/, { timeout: 10000 })
    await expect(page.locator('[data-testid="editor-path"]')).toContainText('e2e-demo.md')

    // WY.5 (#325) made 'live' the default view mode; this test needs the
    // raw textarea AND the rendered .markdown-preview pane both visible,
    // so switch to Split explicitly.
    await page.click('[data-testid="view-mode-split"]')

    // Edit content with headings, wikilinks, and malicious script to test safe rendering
    const editorTextarea = page.locator('[data-testid="markdown-textarea"]')
    await editorTextarea.fill('# Demo Note\n\nLinking to [[welcome.md|Welcome]] note.\n\n<script>window.XSS_EXECUTED=true;</script>')

    // Content autosaves ~1s after typing stops; wait for the transient
    // status pill to confirm the save completed.
    await expect(page.locator('[data-testid="save-status-indicator"]')).toContainText('Saved', { timeout: 10000 })

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
