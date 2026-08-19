import { expect, test } from '@playwright/test'

test('serves an installable shell and registers the versioned worker', async ({ page }) => {
  await page.goto('/')

  const manifest = page.locator('link[rel="manifest"]')
  await expect(manifest).toHaveAttribute('href', '/manifest.webmanifest')

  const manifestResponse = await page.request.get('/manifest.webmanifest')
  expect(manifestResponse.ok()).toBeTruthy()
  expect(manifestResponse.headers()['content-type']).toContain('json')
  expect((await manifestResponse.json()).display).toBe('standalone')

  for (const asset of ['/service-worker.js', '/offline.html']) {
    const response = await page.request.get(asset)
    expect(response.ok()).toBeTruthy()
  }

  await expect(page.locator('.brand-title')).toHaveText('Jotter', { timeout: 10000 })

  const workerScope = await page.evaluate(async () => {
    if (!('serviceWorker' in navigator)) return null
    const registration = await navigator.serviceWorker.ready
    return registration.scope
  })

  expect(workerScope).toBe(`${new URL('/', page.url()).origin}/`)
})
