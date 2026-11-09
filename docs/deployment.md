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
different from `log`, `MAIL_FROM_ADDRESS` set, `APP_INSTANCE_SLUG`, and the scheduler heartbeat (a
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
| `semantic:index --limit=100` | every 5 minutes | Embeds changed notes of workspaces that opted in to semantic search; clears the index while `JOTTER_SEMANTIC_ENABLED=false` (see `docs/mcp.md`). |
| `workspace:storage --measure` | hourly at :30 | Re-measures each vault on disk for the storage quotas; between runs, writes keep the running total. |
| `jotter:backup` | daily 01:30, only when `JOTTER_BACKUP_DAILY=true` | Writes one ZIP with every Jotter table and vault file to `JOTTER_BACKUP_PATH` and keeps the newest `JOTTER_BACKUP_KEEP` (see *Backup and restore*). |
| `oauth:prune` | daily 02:45 | Deletes expired OAuth codes and tokens, and idle OAuth clients without active grants. |
| `tenant:expire-trials` | daily 03:00 | Hosted mode only: e-mails owners 3 days before a trial ends, moves trials past `trial_ends_at` to `read_only` (audited), and e-mails them once more. No-op for self-hosted tenants. |
| `queue:work --stop-when-empty --max-time=50` | every minute, only when `QUEUE_CONNECTION=database` | Drains a database queue without a daemon. Mail is synchronous by default, so this is normally not registered. |

The individual commands below remain available for one-off runs with different
options. Hosted-mode plan state is changed with `php artisan tenant:plan <slug>`
and inspected with `php artisan tenant:show <slug>` (see `docs/architecture.md`,
"Hosted mode").

## Concurrency and long-running work

Jotter runs with no daemon and no worker process (spec §4). Everything that is not a web
request is a bounded batch started by the single `schedule:run` cron entry. Here is what
can run at the same time, and what happens with big jobs.

**Background work**

- `JobDispatcher` (`JOTTER_JOB_DISPATCHER=local`) only records a job; it does not run it.
  The scheduled commands in the table above are the executors: each run picks up a
  bounded batch (100 digest notifications, 50 e-mails, 10 PDF exports
  (`jotter.pdf.process_batch_size`), 100 notes for semantic search), and the next minute
  continues where it stopped.
- Every scheduled command uses `withoutOverlapping(...)`: if a run is still going when cron
  fires again, the new run is skipped, never doubled. The lock lives in the cache store
  (`CACHE_STORE=database` by default) and expires after the minutes shown in
  `routes/console.php` (10 for minute jobs, 55 for the hourly reindex), so a run killed
  by the host does not block the job forever.
- No application job implements `ShouldQueue` today, and mail is sent synchronously. The
  `queue:work --stop-when-empty --max-time=50` entry only runs when
  `QUEUE_CONNECTION=database`. It drains whatever an operator or package queues and exits;
  it is not a resident worker.
- CLI commands run without PHP's web `max_execution_time` (the CLI default is unlimited),
  so heavy operations belong there: `vault:import`, `vault:reindex`, `tenant:export`,
  `jotter:backup`, `jotter:restore`.

**Web requests and writes**

- Request concurrency is the host's limit on PHP processes ("entry processes" on
  CloudLinux/cPanel, often 20–30). A request that runs long, such as a large import, holds
  one of those processes until it ends.
- Writes to the same note are serialized by a per-note lock: 10 s TTL, up to 5 s wait,
  stored in the cache. The MCP write tools, the editor's `base_sha256` saves, and WebDAV
  writes with `If-Match` take it. A busy lock answers 423 (WebDAV, REST) or a retryable tool
  error (MCP). Writes without a precondition are last-writer-wins, and the previous
  version stays in the revision history.
- MCP is limited per token (`JOTTER_MCP_CALLS_PER_MINUTE=120`, `…_WRITES_PER_MINUTE=30`,
  `…_WRITES_PER_DAY=500`); `/oauth/register` allows 10 per hour per IP.

**Large imports**

- The web import (`POST /api/workspaces/{id}/import`, the "Import" dialog) accepts a ZIP of
  up to **50 MB**. It then extracts and reindexes **inside the request**. Two host limits
  apply first: `upload_max_filesize` / `post_max_size` (often 2–64 MB on shared hosting),
  then `max_execution_time` (often 30–60 s).
- If the request is cut short, the files extracted so far stay in the vault, and nothing
  is half-written inside a single file. The index catches up at the next hourly
  `vault:reindex`, or right away with `php artisan vault:reindex --workspace=<id>`.
  Running the same import again is safe: existing notes are skipped unless you tick
  "overwrite".
- For vaults over a few thousand notes or tens of MB, import over SSH instead:
  `php artisan vault:import <workspace> archive.zip [--overwrite] [--source=obsidian|notion]`.
  It has no time limit and reindexes at the end.
- Storage quotas (when enabled) are checked against the extracted size; see *Workspace
  storage quotas*.

## Self sign-up with approval

Off by default; accounts are created by administrators. To let people ask for an account:

```dotenv
JOTTER_SIGNUP_ENABLED=true
JOTTER_SIGNUP_ALLOWED_DOMAINS=           # optional: example.com,example.org
JOTTER_SIGNUP_DEFAULT_WORKSPACE=         # optional: workspace slug added on approval
JOTTER_SIGNUP_DEFAULT_ROLE=viewer        # viewer or editor
JOTTER_SIGNUP_REQUESTS_PER_HOUR=5        # per IP
```

- The sign-in screen gains **Create an account** (name, e-mail, password). The account is
  created **inactive**, and every active administrator gets an e-mail. Nobody can sign in
  until an administrator approves the request.
- Administration → Users lists pending requests apart, with **Approve** and **Reject**.
  Approving turns the account on, adds the default workspace membership when configured,
  and e-mails the person. Rejecting e-mails them and **deletes** the request, so their
  data is not kept.
- The form answers the same way whether or not the e-mail already has an account, so it
  cannot be used to find out who has one. A pending person who signs in with the right
  password is told that approval is pending; a wrong password gets the usual error.
- When `JOTTER_BRAND_TERMS_URL` or `JOTTER_BRAND_PRIVACY_URL` is set, the form requires
  accepting them and records `terms_accepted_at`.
- Only with `JOTTER_AUTH_PROVIDER=local`: OIDC and GrandpaSSOn manage their own accounts.
- Audit events: `signup.requested`, `signup.approved`, `signup.rejected`.

## Deleting an account (LGPD)

Administration → Users → **Delete**, or `php artisan user:delete <e-mail> [--force]`.

- **Removed:** the account, its workspace memberships (including those stored under an SSO
  UUID or the e-mail), manual tokens, OAuth grants and their tokens, note ACL entries,
  linked identities, sessions, notifications, preferences, watches and group memberships.
- **Kept, anonymised:** comments stay in the workspace, signed "Deleted user". Revision and
  audit entries keep their dates but name `deleted-user`, and the e-mail is removed from
  audit metadata. Notes and attachments belong to the workspace and are not touched.
- You cannot delete your own account or the last active administrator.
- With OIDC or GrandpaSSOn, a deleted person who signs in again gets a new, empty account.
  Block them at the provider as well.
- Backups made before the deletion still contain the account until they rotate out
  (`JOTTER_BACKUP_KEEP`).
- People correct their own name under **Edit profile** (local sign-in only; with an SSO
  provider the name comes from the provider). Audit event: `user.profile_updated`.

## Workspace storage quotas

Each workspace has a storage quota, so one vault cannot fill the hosting account.

```dotenv
JOTTER_WORKSPACE_QUOTA_MB=1024          # default per workspace (1 GiB); 0 = unlimited
JOTTER_WORKSPACE_QUOTA_WARN_PERCENT=80  # banner and jotter:doctor warning from here
```

- **Only growth is refused.** A write that would take the vault past its quota answers
  **507 Insufficient Storage**: new notes, larger edits, attachments, and imports (the whole
  archive must fit before anything is written). MCP write tools return a readable error.
  Editing without growing and deleting always work, so a full workspace can still be
  cleaned up.
- Everyone in the workspace sees a banner from 80%, and another when it is full.
  Administrators see usage per workspace under Administration → Workspaces and can set
  a workspace's own quota there. Leave it empty for the default, or set 0 for unlimited.
- From SSH:
  ```sh
  php artisan workspace:storage                  # usage of every workspace
  php artisan workspace:storage brain --quota=5120
  php artisan workspace:storage brain --quota=default
  php artisan workspace:storage --measure        # re-measure now (also hourly)
  ```
- Usage is the size of every file in the vault, including `.trash/` and `_resources/`.
  Purging the trash (`vault:purge-trash`, nightly) frees space at the next measurement.
- **Upgrading:** a workspace that is already over 1 GiB is full right after the upgrade:
  reads, edits that do not grow it, and deletes still work. Check
  `php artisan workspace:storage --measure` after deploying, and raise the quota where
  needed.

## Backup and restore

Jotter keeps two things: the **vaults** (`.md` files and attachments on disk, the source
of truth) and the **MySQL database** (users, memberships, ACLs, comments, revisions,
tokens, OAuth grants, settings, plus a search index rebuilt from the vaults).
`jotter:backup` saves both in one ZIP, in pure PHP: no `mysqldump`, no `exec`, so it runs
from the same cron as everything else.

```sh
php artisan jotter:backup                 # storage/app/private/backups/jotter-backup-<timestamp>.zip
php artisan jotter:backup --to=/home/u/backups/jotter.zip
php artisan jotter:backup --no-vaults     # database only (small, quick)
```

```dotenv
JOTTER_BACKUP_DAILY=true                  # schedule it every night at 01:30
JOTTER_BACKUP_KEEP=7                      # newest archives kept in the folder
JOTTER_BACKUP_PATH=                       # default storage/app/private/backups
```

- The ZIP holds password hashes, token hashes and every note. It is written outside the
  document root (the command refuses `public/`). **Copy it off the server**, for
  example by downloading it over SFTP or with the host's remote backup: a backup on the
  same disk does not survive that disk.
- Left out on purpose: sessions, cache, queue tables, password-reset tokens, and the
  semantic-search index (`note_embeddings`), which `semantic:index` rebuilds.
- Only this installation's tables are read (those with `DB_PREFIX`), never GrandpaSSOn's
  tables that may share the database.
- `jotter:doctor` reports the newest archive; with `JOTTER_BACKUP_DAILY=true` it warns
  when that archive is more than 48 hours old.
- cPanel's own backups (Files + MySQL) remain a good second copy. `jotter:backup` adds an
  archive you can restore with one command, onto any host.

### Restore (runbook)

1. Deploy the **same or a newer** Jotter release, with `.env` pointing at the target
   database (an empty one is fine), then run `php artisan migrate --force`.
2. Upload the archive outside the document root and check it:
   `php artisan jotter:restore /path/jotter-backup-….zip` prints its date and size and
   asks for confirmation. Add `--force` to skip the question.
3. If the vaults live somewhere else on the new host, add
   `--vault-root=/home/<user>/vaults`: each vault goes to `<root>/<original folder>` and
   the workspace paths are updated.
4. After the restore:
   ```sh
   php artisan migrate --force          # when the code is newer than the backup
   php artisan vault:reindex --all      # rebuild the projection from the restored files
   php artisan semantic:index           # only if semantic search is on
   php artisan jotter:doctor
   ```

The restore replaces every table found in the archive. The database part runs in one
transaction, so a corrupt archive leaves the database as it was. Vault files are written
over existing files, and files that only exist on the target are kept. A backup made by
newer code (with migrations this code does not have) is refused. Upgrade the code first.

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

## OAuth for MCP web clients (Claude web, ChatGPT)

Off by default. To let Claude web/Desktop/mobile and ChatGPT connect by URL:

```dotenv
JOTTER_OAUTH_ENABLED=true
# Claude web + loopback clients by default; append ChatGPT's callback from its connector screen.
JOTTER_OAUTH_REDIRECT_ALLOWLIST=https://claude.ai/api/mcp/auth_callback,http://localhost:*/*,http://127.0.0.1:*/*
```

- `APP_URL` must be the public HTTPS origin: it becomes the OAuth issuer and the MCP
  resource (`<APP_URL>/api/mcp`).
- `/.well-known/oauth-*` and `/oauth/*` are ordinary Laravel routes; the stock
  `public/.htaccess` already sends them to `index.php` (AutoSSL's
  `.well-known/acme-challenge/` folder does not interfere). If a WAF or IP block sits in
  front of the site, allow Anthropic's egress range `160.79.104.0/21` and OpenAI's
  published ranges on `/api/mcp`, `/.well-known/*` and `/oauth/*`.
- The daily `oauth:prune` job (scheduler table above) keeps the OAuth tables small.
- Clients that identify themselves with a metadata document URL (CIMD) need outbound
  HTTPS from the server, so the server can fetch that URL. `jotter:doctor` tests it
  (`JOTTER_OAUTH_CIMD_PROBE_URL`, `https://claude.ai/` by default). Where the host blocks
  outbound connections, set `JOTTER_OAUTH_CIMD_ENABLED=false`; clients then use dynamic
  registration.
- With `JOTTER_AUTH_PROVIDER=grandpasson`, a membership's subject may be the person's
  GrandpaSSOn user UUID, their e-mail, or their local user id. Each sign-in links the
  UUID to the local account (`identities`, provider `grandpasson`), so manual MCP tokens
  and OAuth tokens both reach memberships stored under the UUID. Someone who has not
  signed in since this release gets the link on their next sign-in.
- Any sign-in mode works on the consent screen. With `JOTTER_AUTH_PROVIDER=grandpasson`
  the broker returns to `APP_URL`, and Jotter resumes the pending consent from there
  (for ten minutes after the client started the connection).
- Administrators see and revoke every connection under Administration → **Connected
  apps**; each person sees their own under the sidebar's link icon.

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
