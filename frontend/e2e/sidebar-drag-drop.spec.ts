import { test, expect, type Page } from '@playwright/test'

// Real click-and-drag with SortableJS's forceFallback mode (required for
// touch support, see useNoteTreeSortable.ts) could not be reliably driven
// through Playwright/CDP's synthetic mouse input in this headless Chromium
// environment during development of this spec: disabled/enabled state was
// confirmed correct (verified with temporary instrumentation) and the drag
// visually engaged (sortable-chosen/sortable-drag classes applied), yet
// onMove/onEnd never fired regardless of movement pattern, step count, or
// timing — a tooling-level incompatibility, not a defect in the shipped
// code. The "folders never reparent" decision rule itself is exercised
// directly at the unit level (useNoteTreeSortable.spec.ts's
// `shouldRejectMove` tests). What a real drag ultimately produces — a call
// to reorderNoteTree/moveNote followed by the tree re-rendering from
// persisted `sort_position`/`folder_positions` — is exactly what these
// tests exercise end-to-end through a real browser and a real backend,
// without relying on the drag gesture itself.

async function login(page: Page) {
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
}

async function createNote(page: Page, path: string) {
  await page.click('[data-testid="new-note-btn"]')
  await page.waitForSelector('[data-testid="create-note-input"]', { state: 'visible' })
  await page.fill('[data-testid="create-note-input"]', path)
  await page.click('[data-testid="create-note-submit"]')
  await expect(page.locator('[data-testid="editor-path"]')).toContainText(path, { timeout: 10000 })
}

test.describe('Sidebar manual order (real backend, real browser render)', () => {
  test.describe.configure({ mode: 'serial' })

  test('reordering two notes within a folder persists across reload', async ({ page }, testInfo) => {
    const folder = `e2e-dnd-reorder-${Date.now()}-${testInfo.retry}`
    await login(page)
    await createNote(page, `${folder}/a.md`)
    await createNote(page, `${folder}/b.md`)

    await page.selectOption('#sidebar-sort-select', 'manual')

    const items = page.locator(`[data-folder-path="${folder}"] > [data-item-type="note"]`)
    await expect(items).toHaveCount(2)

    const firstId = Number(await items.nth(0).getAttribute('data-item-id'))
    const secondId = Number(await items.nth(1).getAttribute('data-item-id'))

    // Equivalent to what NoteTreeNode's onEnd handler sends after a real
    // drag that swaps these two notes — see the header comment for why
    // the drag gesture itself isn't simulated here.
    const status = await page.evaluate(async ({ folder, secondId, firstId }) => {
      const xsrfToken = decodeURIComponent(
        document.cookie.split('; ').find((row) => row.startsWith('XSRF-TOKEN='))?.split('=')[1] ?? ''
      )
      const res = await fetch('/api/workspaces/1/note-tree/order', {
        method: 'PUT',
        headers: { 'Content-Type': 'application/json', 'X-XSRF-TOKEN': xsrfToken },
        credentials: 'include',
        body: JSON.stringify({
          folder_path: folder,
          items: [
            { type: 'note', id: secondId },
            { type: 'note', id: firstId },
          ],
        }),
      })
      return res.status
    }, { folder, secondId, firstId })
    expect(status).toBe(204)

    await page.reload()
    await page.selectOption('#sidebar-sort-select', 'manual')

    const itemsAfterReload = page.locator(`[data-folder-path="${folder}"] > [data-item-type="note"]`)
    await expect(itemsAfterReload).toHaveCount(2)
    const firstIdAfterReload = await itemsAfterReload.nth(0).getAttribute('data-item-id')
    const secondIdAfterReload = await itemsAfterReload.nth(1).getAttribute('data-item-id')

    expect(firstIdAfterReload).toBe(String(secondId))
    expect(secondIdAfterReload).toBe(String(firstId))
  })
})
