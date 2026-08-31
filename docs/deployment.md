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

The release command writes `dist/jotter-release-<version>.zip` and
`dist/jotter-release-<version>.zip.sha256`, where `<version>` is the git tag when
`HEAD` is exactly tagged (for example `v1.4.0`) and `0.0.0-<short sha>` otherwise.
The same string is written to `VERSION` inside the artifact and reported by
`GET /api/auth/config` (`data.version`), `GET /api/health` (`version`), and
`php artisan jotter:doctor`. The verification command scans the newest ZIP in
`dist/` (or an explicit path: `./scripts/jt.sh release:verify dist/jotter-release-v1.4.0.zip`)
and must pass before the ZIP is deployed or shared.

To rehearse an installation before touching a host, extract the newest ZIP into
`dist/install-test/` and run the doctor inside that copy against the dev database:

```sh
./scripts/jt.sh release:doctor          # human-readable
./scripts/jt.sh release:doctor --json   # machine-readable
```

## Deploy

1. Verify the SHA-256 checksum.
2. Extract the zip. It contains a top-level `app/` directory.
3. Create `app/.env` from `app/.env.example` and provide unique production values. Never upload a development `.env`.
4. Point the domain's document root at `app/public/`.
5. Ensure `storage/` and `bootstrap/cache/` are writable by PHP.
6. Run `php artisan migrate --force` using the host's PHP 8.2+ CLI facility.
7. Keep debug mode off and use HTTPS.
8. Add the single cron entry described under [Scheduled jobs](#scheduled-jobs-one-cron-entry-per-installation).
9. Run `php artisan jotter:doctor` and fix every `[FAIL]` before handing the installation over.

## Multiple instances on one shared host

One release ZIP installs any number of times on the same Hostinger account: one
directory per client, one subdomain per client, one database (or one `DB_PREFIX`)
per client, one vault directory per client, and one cron entry per client. Nothing
is shared between installations except the PHP runtime.

Recommended layout for a client with slug `acme` served at `acme.example.com`:

```text
domains/acme.example.com/public_html/acme/   ← extracted `app/` contents (this is the Laravel root)
    .env                                     ← this installation's values only
    artisan
    public/                                  ← the subdomain's document root points HERE
    storage/, bootstrap/cache/               ← writable by PHP
    VERSION                                  ← written by `jt release`
~/vaults/acme/                               ← VAULT_BASE_PATH (outside every document root)
~/pdf-exports/acme/                          ← JOTTER_PDF_STORAGE_PATH (optional, outside every document root)
```

Rules that keep installations predictable:

- The document root of the subdomain must be `.../<slug>/public/`, never the
  Laravel root and never a parent folder shared with another installation.
- `VAULT_BASE_PATH` must be outside every document root and unique per slug. The
  doctor fails the installation when the vault resolves inside `public/`.
- `APP_INSTANCE_SLUG=<slug>` names the installation. It is added to every log line
  (`context.instance`) and printed by the doctor; it is never exposed by
  `GET /api/health`.
- `APP_URL=https://<slug>.example.com`, `APP_ENV=production`, `APP_DEBUG=false`,
  `SESSION_SECURE_COOKIE=true`, `CACHE_STORE=database`, `SESSION_DRIVER=database`.
  `.env.example` marks every value a production installation must set.
- Each installation gets its own `APP_KEY` (`php artisan key:generate --force`);
  never copy a key between clients.

Per-installation cron entry (adjust the PHP binary to the host's 8.2+ CLI):

```cron
* * * * * cd /home/<user>/domains/acme.example.com/public_html/acme && /usr/bin/php artisan schedule:run >> storage/logs/scheduler.log 2>&1
```

After extracting, migrating, and adding the cron entry, run the doctor:

```sh
cd /home/<user>/domains/acme.example.com/public_html/acme
php artisan jotter:doctor           # exit code 1 while any critical check fails
php artisan jotter:doctor --json    # for scripts and support tickets
```

The doctor verifies: PHP version and required extensions, `APP_KEY`, `APP_ENV`,
`APP_DEBUG=false`, `APP_URL` on HTTPS, `storage/` and `bootstrap/cache` writable,
`VAULT_BASE_PATH` existing, writable, and outside the document root, free disk
space for the vault, database connectivity, pending migrations, `MAIL_MAILER`
different from `log`, `APP_INSTANCE_SLUG`, and the scheduler heartbeat (a
`schedule:run` within the last 5 minutes). `APP_DEBUG` and the HTTPS check are
critical when `APP_ENV=production` and warnings otherwise; the mailer, recommended
extensions, and instance slug are always warnings.

## Health endpoint

`GET /api/health` is unauthenticated and answers with exactly three fields:

```json
{"status": "ok", "version": "v1.4.0", "scheduler_last_run_at": "2026-08-31T10:00:00+00:00"}
```

It returns HTTP 503 with `status: "unavailable"` when the database does not answer.
It deliberately exposes nothing sensitive: no instance slug, hostnames, paths,
database names, PHP version, or configuration values. The route is registered
outside the `api` middleware group so it can still answer 503 (instead of 500)
when the session, cache, or throttle stores backed by MySQL are down. Point the
host's uptime monitor at it and alert when `scheduler_last_run_at` is older than
a few minutes.

## Scheduled jobs (one cron entry per installation)

All periodic work is registered in `routes/console.php` and executed by the single
`php artisan schedule:run` cron entry above. No job needs a queue worker, daemon,
or background process; every command is bounded and idempotent, and overlapping
runs are prevented with cache locks (`CACHE_STORE=database`).

| Job | Schedule | Purpose |
| --- | --- | --- |
| `jotter:scheduler-heartbeat` | every minute | Records the last `schedule:run` time read by the doctor and `/api/health`. |
| `notifications:send-digest --limit=100` | every minute | Builds digest deliveries from unsent notifications. |
| `notifications:process-deliveries --limit=50` | every minute | Sends pending notification e-mails. `JobDispatcher` only records `SendNotificationEmail` on shared hosting; this command is its executor. |
| `pdf:process-exports` | every minute | Renders queued PDF exports (`GeneratePdfExport`) and removes expired artifacts. |
| `analytics:rollup` | every 5 minutes | Advances the usage-analytics cursor over new audit rows. |
| `vault:reindex --all` | hourly | Reconciles every workspace vault from disk into the MySQL projection. |
| `vault:purge-trash` | daily 02:00 | Permanently deletes notes past the trash retention period. |
| `vault:prune-revisions --days=30` | daily 02:15 | Prunes derived revision snapshots (Markdown files are never touched). |
| `audit:prune --days=90` | daily 02:30 | Enforces audit-log retention. |

Trial expiry is not scheduled because the product has no trial concept; add it to
this table and to `routes/console.php` if one is introduced. The individual
commands below remain available for one-off runs with different options.

## External content embeds

External embeds are disabled by default. To enable them, set a comma-separated
allowlist of HTTPS hostnames in the production `.env`:

```dotenv
JOTTER_EXTERNAL_EMBED_DOMAINS=youtube.com,miro.com
```

Matching accepts an exact hostname or a dot-boundary subdomain (for example,
`www.youtube.com` matches `youtube.com`, while `evil-youtube.com` does not).
Only standalone HTTPS URLs on their own Markdown line are eligible. Jotter does
not fetch external content server-side; authenticated previews generate an
`iframe` with `sandbox="allow-scripts"`, `referrerpolicy="no-referrer"`, and
`loading="lazy"`, without `allow-same-origin`. URLs in fenced code remain code.

Published static sites and the WYSIWYG editor intentionally render these URLs as
ordinary links, so publishing never creates an external iframe.

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

One-off reconcile of a single workspace vault (the scheduler runs `--all` hourly):

```sh
php artisan vault:reindex --workspace=1
```

Trash purge (scheduled daily; the default retention is 30 days via
`JOTTER_TRASH_RETENTION_DAYS`). Use `--days=N` or `--batch=N` for a one-off
retention or batch-size override:

```sh
php artisan vault:purge-trash
```

Audit log prune (scheduled daily with `--days=90`):

```sh
php artisan audit:prune --days=90
```

Revision snapshot prune (scheduled daily with `--days=30`). Revision history is
stored in MySQL; the Markdown files in the vault are never removed by this
command:

```sh
php artisan vault:prune-revisions --days=90
```

Usage-analytics rollup (scheduled every 5 minutes). It advances an `audit_log`
cursor in batches, is safe to rerun, and keeps the workspace rollups when
`audit:prune` removes the source rows:

```sh
php artisan analytics:rollup --batch=500
```

The analytics API and dashboard read only the durable rollups; they do not
aggregate the raw audit table during a request. Read tracking is disabled by
default. To opt in to one `note.viewed` audit event after each successful,
authorized authenticated note-detail read, set:

```dotenv
JOTTER_ANALYTICS_RECORD_READS=true
```

The remaining analytics settings are `JOTTER_ANALYTICS_ROLLUP_BATCH` (default
`500`) and `JOTTER_ANALYTICS_STALE_DAYS` (default `30`). “Most active” reflects
recorded activity such as edits and other audit events; it should not be read
as “most viewed” unless read tracking has explicitly been enabled and the
rollup command has processed those events.

The notification digest runs every minute from the scheduler. The command is
bounded by the per-recipient `--limit`, uses an idempotent delivery ledger, and is
safe to run repeatedly. It hands mail work to `JobDispatcher`; the scheduled
`notifications:process-deliveries` command then sends the pending deliveries, so
no SMTP mail is sent inline in a web request.

```sh
php artisan notifications:send-digest --limit=100
php artisan notifications:process-deliveries --limit=50
```

Configure a Laravel mail transport for external delivery. With the default
`log` mailer, Jotter records a structured skip event and preserves request
behavior without attempting external delivery.

Keep vault directories outside the web root. Notes are never served as static files from `public/`.

## Installable PWA shell (#356)

The release includes `public/manifest.webmanifest`, `public/service-worker.js`, and
`public/offline.html` at the document root. The frontend build synchronizes these
files from `frontend/public/` before compiling the hashed assets.

The service worker is intentionally a shell cache, not offline note storage. It
does not cache authenticated HTML, note content, attachments, API responses,
search results, WebDAV responses, or Sanctum endpoints. Navigations use the
network first and show the generic offline page only when the network is
unavailable.

When shell behavior or static asset delivery changes, bump the `CACHE_NAME`
version in `frontend/public/service-worker.js` before running the release build.
The activation handler removes older shell caches. Service workers require
HTTPS in production; `localhost` is the only intended insecure development
exception.

## PDF exports (#354)

PDF artifacts are private and must live outside the document root. Configure the
storage directory and retention in production:

```dotenv
JOTTER_PDF_STORAGE_PATH=/var/lib/jotter/pdf-exports
JOTTER_PDF_RETENTION_HOURS=24
JOTTER_PDF_PROCESS_BATCH=10
```

Ensure PHP can create and remove files in that directory. The scheduler runs the
bounded worker every minute; invoke it manually with a different batch size when
needed:

```sh
php artisan pdf:process-exports --limit=10
```

`POST /api/workspaces/{workspace}/pdf-exports` snapshots only notes visible to
the requester and returns an export id. Poll
`GET /api/workspaces/{workspace}/pdf-exports/{export}`; download only after
`status=ready` using its `/download` endpoint. Do not expose the storage path or
mount it under `public/`.

## Per-note public sharing (#355)

Public links are opaque token routes and never contain a workspace slug, workspace
id, note id, or note path:

- `GET /share/{token}` renders the selected note only.
- `GET /share/{token}/attachments/{path}` serves only registered attachments from
  the shared note's workspace and revalidates the active token on every request.
- `GET /share-assets/publish.css`, `/share-assets/publish-theme.js`, and the
  allowlisted font routes serve the existing published-page assets.

Invalid, expired, revoked, or deleted-note links return 404 rather than exposing
authorization state. Shared attachments use `Cache-Control: no-store`. To rotate
a link, revoke the active share from the note controls and create a new one; the
plaintext token is returned only by that creation response. Public rendering does
not enumerate the workspace, show a sidebar/tree/search/backlinks, hydrate external
embeds, or follow wikilinks into other notes.
