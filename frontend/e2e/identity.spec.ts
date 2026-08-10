import { expect, test } from '@playwright/test'

test.describe('Canonical visual identity', () => {
  test('system theme follows the OS and an explicit light choice persists', async ({ page }) => {
    await page.emulateMedia({ colorScheme: 'dark' })
    await page.goto('/')

    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')

    const preference = page.getByLabel('Theme')
    await preference.selectOption('light')
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')

    await page.reload()
    await expect(preference).toHaveValue('light')
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'light')
  })

  test('published pages expose a persistent theme selector and reflow at 320px', async ({ page }) => {
    await page.setViewportSize({ width: 320, height: 800 })

    const response = await page.request.post('/api/workspaces/1/publish')
    expect(response.ok()).toBeTruthy()
    const { site_url: siteUrl } = await response.json() as { site_url: string }
    const url = new URL(siteUrl)

    await page.goto(`${url.pathname}${url.search}`)

    const preference = page.locator('#publish-theme-preference')
    await expect(preference).toBeVisible()
    await preference.selectOption('dark')
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark')

    await page.reload()
    await expect(preference).toHaveValue('dark')

    const width = await page.evaluate(() => ({
      client: document.documentElement.clientWidth,
      scroll: document.documentElement.scrollWidth,
    }))
    expect(width.scroll).toBeLessThanOrEqual(width.client)
  })
})
