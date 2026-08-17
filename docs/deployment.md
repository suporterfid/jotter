# Shared-hosting deployment

Build the artifact with Docker:

```sh
./scripts/jt.sh release
./scripts/jt.sh release:verify
```

On PowerShell:

```powershell
.\scripts\jt.ps1 release
.\scripts\jt.ps1 release:verify
```

The release command writes `dist/jotter-release.zip` and `dist/jotter-release.zip.sha256`. The verification command must pass before the ZIP is deployed or shared.

## Deploy

1. Verify the SHA-256 checksum.
2. Extract the zip. It contains a top-level `app/` directory.
3. Create `app/.env` from `app/.env.example` and provide unique production values. Never upload a development `.env`.
4. Point the domain's document root at `app/public/`.
5. Ensure `storage/` and `bootstrap/cache/` are writable by PHP.
6. Run `php artisan migrate --force` using the host's PHP 8.2+ CLI facility.
7. Keep debug mode off and use HTTPS.

## Generic OIDC SSO

Jotter keeps local authentication as the default. To enable a standard corporate
OIDC provider (Google Workspace, Entra ID, Okta, Keycloak, or another compatible
issuer), set these environment values in the production `.env`:

```dotenv
JOTTER_AUTH_PROVIDER=oidc
JOTTER_OIDC_ISSUER_URL=https://idp.example.com
JOTTER_OIDC_CLIENT_ID=jotter
JOTTER_OIDC_CLIENT_SECRET=replace-with-the-client-secret
JOTTER_OIDC_REDIRECT_URI=https://jotter.example.com/api/auth/oidc/callback
JOTTER_OIDC_SCOPES="openid profile email"
JOTTER_OIDC_POST_LOGIN_REDIRECT_URI=https://jotter.example.com
JOTTER_OIDC_ALLOW_INSECURE_HTTP=false
JOTTER_OIDC_TRUSTED_EMAIL_CLAIM=false
```

Register exactly `https://jotter.example.com/api/auth/oidc/callback` as the
provider's redirect URI. The adapter uses discovery at
`<issuer>/.well-known/openid-configuration`, authorization-code flow with PKCE
S256, and the `openid profile email` scopes. The client secret stays in `.env`;
never commit it or put it in browser state. Production issuers and redirect URIs
must use HTTPS, and TLS certificate verification remains enabled.

On the first successful login, Jotter creates an active non-admin user with no
workspace or tenant membership. An administrator must grant membership manually;
OIDC group or claim mapping is intentionally not enabled. Local and GrandpaSSOn
authentication remain separate providers. The callback is request/response only
and does not require a queue worker, daemon, websocket, or long-running process.

The artifact includes production `vendor/` dependencies and compiled `public/build/` assets. It excludes tests, frontend sources, container files, development tooling, and secrets.

Schedule a bounded reconcile for each workspace vault (adjust the id and frequency to the host):

```sh
php artisan vault:reindex --workspace=1
```

Schedule a daily audit log prune to enforce retention limits (adjust days as needed):

```sh
php artisan audit:prune --days=90
```

Keep vault directories outside the web root. Notes are never served as static files from `public/`.
