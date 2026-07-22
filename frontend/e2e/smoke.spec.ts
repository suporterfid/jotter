import { expect, test } from '@playwright/test'

test('Jotter landing screen loads', async ({ page }) => {
  await page.goto('/')

  await expect(page.getByRole('heading', { name: 'Jotter' })).toBeVisible()
  await expect(page.getByText('Your pocket notebook')).toBeVisible()
})
