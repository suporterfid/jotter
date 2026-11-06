import { createHash, randomBytes } from 'node:crypto'
import { expect, test, type APIRequestContext, type Page } from '@playwright/test'

/**
 * The OAuth consent screen as Claude web or ChatGPT drive it (brain plan PR 5):
 * dynamic registration, authorize with PKCE, consent, code exchange, MCP call
 * with the access token, and revocation from Administration → Connected apps.
 *
 * The client's redirect target is a loopback URL (allowed by the default
 * JOTTER_OAUTH_REDIRECT_ALLOWLIST); the browser never reaches it because the
 * test intercepts the navigation and reads the code from the URL.
 */
const REDIRECT_URI = 'http://127.0.0.1:9/oauth/callback'

function base64url(bytes: Buffer): string {
  return bytes.toString('base64').replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '')
}

function pkce(): { verifier: string; challenge: string } {
  const verifier = base64url(randomBytes(32))
  return { verifier, challenge: base64url(createHash('sha256').update(verifier).digest()) }
}

async function oauthEnabled(request: APIRequestContext): Promise<boolean> {
  return (await request.get('/.well-known/oauth-authorization-server')).ok()
}

async function registerClient(request: APIRequestContext, name: string): Promise<{ clientId: string; resource: string }> {
  const resource = (await (await request.get('/.well-known/oauth-protected-resource')).json()).resource as string
  const registration = await request.post('/oauth/register', {
    data: {
      client_name: name,
      redirect_uris: [REDIRECT_URI],
      token_endpoint_auth_method: 'none',
      grant_types: ['authorization_code', 'refresh_token'],
    },
  })
  expect(registration.status()).toBe(201)

  return { clientId: (await registration.json()).client_id as string, resource }
}

function authorizePath(clientId: string, challenge: string, resource: string, state: string): string {
  const query = new URLSearchParams({
    response_type: 'code',
    client_id: clientId,
    redirect_uri: REDIRECT_URI,
    state,
    code_challenge: challenge,
    code_challenge_method: 'S256',
    scope: 'mcp:read offline_access',
    resource,
  })

  return `/oauth/authorize?${query.toString()}`
}

/** Captures the redirect back to the client instead of letting the browser fail on it. */
async function captureCallback(page: Page): Promise<() => URL | null> {
  let captured: URL | null = null
  await page.route('http://127.0.0.1:9/**', async (route) => {
    captured = new URL(route.request().url())
    await route.fulfill({ status: 200, contentType: 'text/plain', body: 'callback received' })
  })

  return () => captured
}

test.describe('OAuth consent for MCP web clients', () => {
  test('a signed-in person grants one workspace, the client gets a working token, and revoking it cuts access', async ({ page }) => {
    test.skip(!(await oauthEnabled(page.request)), 'JOTTER_OAUTH_ENABLED is false in this stack')

    const workspaces = (await (await page.request.get('/api/workspaces')).json()).data as Array<{ id: number; slug: string }>
    expect(workspaces.length).toBeGreaterThan(0)
    const chosen = workspaces[0]

    const clientName = `E2E Agent ${Date.now()}`
    const { clientId, resource } = await registerClient(page.request, clientName)
    const { verifier, challenge } = pkce()
    const callback = await captureCallback(page)

    await page.goto(authorizePath(clientId, challenge, resource, 'e2e-state'))
    await expect(page.locator('h1')).toContainText(clientName)
    await expect(page.getByText('127.0.0.1').first()).toBeVisible()
    await expect(page.locator('[data-testid="oauth-loopback-warning"], .warn').first()).toBeVisible()

    // Nothing is pre-selected; approving without a workspace is refused.
    const boxes = page.locator('input[type="checkbox"][data-testid^="oauth-ws-"]')
    expect(await boxes.count()).toBeGreaterThan(0)
    for (const box of await boxes.all()) {
      await expect(box).not.toBeChecked()
    }
    await page.click('[data-testid="oauth-approve"]')
    await expect(page.locator('p.error')).toBeVisible()

    await page.check(`[data-testid="oauth-ws-${chosen.slug}"]`)
    await page.click('[data-testid="oauth-approve"]')
    await expect.poll(callback).not.toBeNull()

    const returned = callback()!
    expect(returned.searchParams.get('state')).toBe('e2e-state')
    expect(returned.searchParams.get('iss')).toBeTruthy()
    const code = returned.searchParams.get('code')
    expect(code).toMatch(/^jt_oac_/)

    const exchange = await page.request.post('/oauth/token', {
      form: {
        grant_type: 'authorization_code',
        client_id: clientId,
        code: code!,
        redirect_uri: REDIRECT_URI,
        code_verifier: verifier,
        resource,
      },
    })
    expect(exchange.ok()).toBeTruthy()
    const accessToken = (await exchange.json()).access_token as string
    expect(accessToken).toMatch(/^jt_oat_/)

    const mcp = (token: string) =>
      page.request.post('/api/mcp', {
        headers: { Authorization: `Bearer ${token}` },
        data: { jsonrpc: '2.0', id: 1, method: 'tools/call', params: { name: 'list_workspaces', arguments: {} } },
      })
    const listed = await mcp(accessToken)
    expect(listed.ok()).toBeTruthy()
    const slugs = JSON.parse((await listed.json()).result.content[0].text).map((ws: { slug: string }) => ws.slug)
    expect(slugs).toEqual([chosen.slug])

    // Administration → Connected apps lists the grant and revokes it.
    await page.unrouteAll()
    await page.goto('/')
    await page.click('[data-testid="more-actions-btn"]')
    await page.click('[data-testid="admin-panel-btn"]')
    await page.click('[data-testid="admin-tab-apps"]')
    const row = page.locator('[data-testid^="admin-app-"]', { hasText: clientName }).first()
    await expect(row).toBeVisible()
    page.once('dialog', (dialog) => dialog.accept())
    await row.locator('[data-testid^="admin-app-revoke-"]').click()
    await expect(page.locator('[data-testid^="admin-app-"]', { hasText: clientName })).toHaveCount(0)

    expect((await mcp(accessToken)).status()).toBe(401)
  })

  test('without a session the request waits for sign-in, resumes, and a denial goes back to the client', async ({ browser }) => {
    const context = await browser.newContext({ storageState: { cookies: [], origins: [] } })
    const page = await context.newPage()
    test.skip(!(await oauthEnabled(context.request)), 'JOTTER_OAUTH_ENABLED is false in this stack')

    const clientName = `E2E Login ${Date.now()}`
    const { clientId, resource } = await registerClient(context.request, clientName)
    const { challenge } = pkce()
    const callback = await captureCallback(page)

    await page.goto(authorizePath(clientId, challenge, resource, 'login-state'))
    await expect(page).toHaveURL(/\/\?login=1&return_to=%2Foauth%2Fauthorize%3F/)

    await page.fill('[data-testid="login-email"]', 'admin@example.com')
    await page.fill('[data-testid="login-password"]', 'password12345')
    await page.click('[data-testid="login-submit"]')

    // The SPA follows return_to back to the consent screen.
    await expect(page).toHaveURL(/\/oauth\/authorize\?/)
    await expect(page.locator('h1')).toContainText(clientName)

    await page.click('[data-testid="oauth-deny"]')
    await expect.poll(callback).not.toBeNull()
    expect(callback()!.searchParams.get('error')).toBe('access_denied')
    expect(callback()!.searchParams.get('state')).toBe('login-state')

    await context.close()
  })
})
