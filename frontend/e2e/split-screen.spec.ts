import { expect, test } from '@playwright/test'

async function createNote(page: import('@playwright/test').Page, path: string): Promise<void> {
  await page.click('[data-testid="new-note-btn"]')
  await page.fill('[data-testid="create-note-input"]', path)
  await page.click('[data-testid="create-note-submit"]')
  await expect(page.locator('[data-testid="create-note-input"]')).toBeHidden({ timeout: 10000 })
  const noteNode = page.locator(`[data-item-type="note"][data-item-note-path="${path}"]`)
  await expect(noteNode).toBeVisible({ timeout: 10000 })
  await noteNode.click()
  await expect(page.locator('[data-testid="editor-path"]')).toContainText(path)
}

test('isolates split panes and restores their layout', async ({ page }) => {
  await page.goto('/')
  await expect(page.locator('.brand-title')).toHaveText('Jotter', { timeout: 10000 })

  const suffix = Date.now().toString(36)
  await createNote(page, `e2e-split-a-${suffix}.md`)
  await createNote(page, `e2e-split-b-${suffix}.md`)

  await page.locator('[data-pane-id="primary"] [data-testid="split-tab-btn"]').click()
  await expect(page.locator('[data-pane-id="primary"]')).toBeVisible()
  await expect(page.locator('[data-pane-id="secondary"]')).toBeVisible()

  const primaryView = page.locator('[data-pane-id="primary"]')
  const secondaryView = page.locator('[data-pane-id="secondary"]')
  await primaryView.locator('[data-testid="view-mode-split"]').click()
  await primaryView.locator('[data-testid="markdown-textarea"]').fill('# Shared Heading\n\nPrimary source')
  await page.waitForTimeout(1200)
  await expect(primaryView.locator('[data-testid="save-status-indicator"]')).toContainText('Saved', { timeout: 10000 })
  await secondaryView.locator('[data-testid="view-mode-split"]').click()
  await secondaryView.locator('[data-testid="markdown-textarea"]').fill('# Shared Heading\n\nSecondary source')
  await page.waitForTimeout(1200)
  await expect(secondaryView.locator('[data-testid="save-status-indicator"]')).toContainText('Saved', { timeout: 10000 })
  await expect(primaryView.locator('[data-testid="markdown-textarea"]')).toHaveValue(/Shared Heading/)
  await expect(secondaryView.locator('[data-testid="markdown-textarea"]')).toHaveValue(/Shared Heading/)

  const headingIds = await page.locator('[data-pane-id] .markdown-preview h1').evaluateAll((headings) =>
    headings.map((heading) => heading.id)
  )
  expect(new Set(headingIds).size).toBe(2)

  expect(await primaryView.getAttribute('data-active-note-id')).not.toBe(
    await secondaryView.getAttribute('data-active-note-id')
  )

  await page.reload()
  await expect(page.locator('[data-pane-id="primary"]')).toBeVisible()
  await expect(page.locator('[data-pane-id="secondary"]')).toBeVisible()
  await expect(page.locator('[data-pane-id="primary"] [data-testid="editor-path"]')).toBeVisible()
  await expect(page.locator('[data-pane-id="secondary"] [data-testid="editor-path"]')).toBeVisible()
})
