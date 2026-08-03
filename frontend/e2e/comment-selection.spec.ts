import { expect, test } from '@playwright/test'

test.describe('Selection-triggered comments (#261)', () => {
  test('selecting text in the editor shows a Comment button that anchors a comment to the selection', async ({ page }) => {
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

    const path = `e2e-comment-selection-${Date.now()}.md`
    await page.click('[data-testid="new-note-btn"]')
    await page.waitForSelector('[data-testid="create-note-input"]', { state: 'visible' })
    await page.fill('[data-testid="create-note-input"]', path)
    await page.click('[data-testid="create-note-submit"]')
    await expect(page.locator('[data-testid="editor-path"]')).toContainText(path, { timeout: 10000 })

    const editorTextarea = page.locator('[data-testid="markdown-textarea"]')
    await editorTextarea.fill('First line\nSomething interesting here.\nThird line.')
    await expect(page.locator('[data-testid="save-status-indicator"]')).toContainText('Saved', { timeout: 10000 })

    // Double-click a word in the textarea to trigger a real browser text
    // selection (native behavior: selects the word under the cursor).
    await editorTextarea.dblclick({ position: { x: 60, y: 30 } })

    const commentTrigger = page.locator('[data-testid="comment-trigger-btn"]')
    await expect(commentTrigger).toBeVisible({ timeout: 5000 })

    await commentTrigger.click()
    const composerTextarea = page.locator('[data-testid="comment-composer-textarea"]')
    await expect(composerTextarea).toBeVisible()
    await composerTextarea.fill('Great point here!')
    await page.click('[data-testid="comment-composer-submit"]')

    await expect(page.locator('[data-testid="comment-composer"]')).toBeHidden()

    // The comment list itself now lives in the right-hand comments drawer
    // (#262), not stacked inline below the editor — open it to verify the
    // submitted comment landed with its anchor.
    await page.click('[data-testid="comments-drawer-btn"]')
    const commentItem = page.locator('[data-testid="comment-item"]').first()
    await expect(commentItem).toContainText('Great point here!', { timeout: 10000 })
    await expect(commentItem.locator('.comment-anchor')).toBeVisible()
  })
})
