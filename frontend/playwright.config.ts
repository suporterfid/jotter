import { defineConfig, devices } from '@playwright/test'

export default defineConfig({
  testDir: './e2e',
  globalSetup: './e2e/auth.setup.ts',
  // The browser scenarios intentionally share the seeded administrator and
  // mutable default workspace, so parallel workers would contaminate state.
  fullyParallel: false,
  workers: 1,
  forbidOnly: Boolean(process.env.CI),
  retries: process.env.CI ? 2 : 0,
  reporter: 'list',
  timeout: 60_000,
  use: {
    baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://jotter-web',
    navigationTimeout: 60_000,
    trace: 'on-first-retry',
    storageState: 'test-results/.auth/admin.json',
  },
  projects: [
    {
      name: 'chromium',
      use: { ...devices['Desktop Chrome'] },
    },
  ],
})
