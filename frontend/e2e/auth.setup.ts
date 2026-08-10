import { mkdirSync } from 'node:fs'
import { request } from '@playwright/test'

export default async function globalSetup() {
  const baseURL = process.env.PLAYWRIGHT_BASE_URL ?? 'http://jotter-web'
  const context = await request.newContext({ baseURL })

  await context.get('/sanctum/csrf-cookie')
  const login = await context.post('/api/auth/login', {
    data: { email: 'admin@example.com', password: 'password12345' },
  })

  if (!login.ok()) {
    throw new Error(`E2E admin login failed with ${login.status()}`)
  }

  const locale = await context.post('/api/user/locale', {
    data: { locale: 'en' },
  })

  if (!locale.ok()) {
    throw new Error(`E2E locale setup failed with ${locale.status()}`)
  }

  mkdirSync('test-results/.auth', { recursive: true })
  await context.storageState({ path: 'test-results/.auth/admin.json' })
  await context.dispose()
}
