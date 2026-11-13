# Hosted operations (SSH + Artisan)

Runbook for operating hosted installations ("Cadernia" or any operator brand)
on shared PHP hosting. Every step is an Artisan command run over SSH with the
host's PHP CLI from the installation's Laravel root; there is no admin UI for
these actions and no queue worker. See `docs/deployment.md` for installing the
release ZIP and `docs/architecture.md` ("Hosted mode") for the model.

```sh
ssh user@host
cd ~/domains/acme.example.com/public_html/acme    # the Laravel root of ONE installation
php artisan jotter:doctor                          # must be green before anything else
```

## E-mail

Transactional mail (welcome, password reset, notifications, trial reminder,
trial ended) is sent synchronously from the request or from the scheduler; no
worker is needed. Configure SMTP in `.env` (Resend and Postmark examples are in
`.env.example`), then confirm:

```sh
php artisan jotter:doctor          # MAIL_MAILER must not be `log`
php artisan tinker --execute="Mail::raw('smoke', fn(\$m) => \$m->to('you@example.com')->subject('SMTP smoke'));"
```

If an operator deliberately sets `QUEUE_CONNECTION=database`, the scheduler
drains it every minute with `queue:work --stop-when-empty --max-time=50`; the
default stays synchronous.

## LGPD settings for a hosted service

A service offered to others has legal duties that a personal self-hosted
installation does not. Set these in `.env` (defaults keep self-hosting unchanged):

```dotenv
JOTTER_BRAND_TERMS_URL=https://example.com/termos
JOTTER_BRAND_PRIVACY_URL=https://example.com/privacidade
JOTTER_TERMS_VERSION=2026-11-09          # change it whenever a text changes
JOTTER_ACCESS_LOG_RETENTION_DAYS=184     # Marco Civil art. 15: 6 months of access records
SESSION_SECURE_COOKIE=true
SESSION_ENCRYPT=true
LOG_STACK=daily
LOG_DAILY_DAYS=14
JOTTER_2FA_REQUIRED_FOR_ADMINS=true      # administrators need two-step verification
```

`php artisan jotter:doctor` warns about each one that is missing
(`session_security`, `log_retention`, `access_log_retention`). The texts
themselves are drafts in `docs/legal/` and need a lawyer's review; the
contracts and processes they depend on are tracked in `BACKLOG.md`
("LGPD compliance on shared hosting", block 2).

## Two-step verification

Everyone who signs in with a password can turn on two-step verification in
**Your profile → Two-step verification**: scan the QR code with an authenticator
app, confirm a code, and keep the ten recovery codes it shows once. Sign-in then
asks for a 6-digit code (or a recovery code) after the password. With
`JOTTER_2FA_REQUIRED_FOR_ADMINS=true`, administrators must set it up before
Administration answers; `jotter:doctor` (`admin_two_factor`) lists those without it.

Someone who lost both the phone and the recovery codes: **confirm who is asking
first** (this is the way around the second factor), then

```sh
php artisan user:two-factor-reset maria@example.com
```

The secret and recovery codes are encrypted with `APP_KEY`. A backup restored into
an installation with a different `APP_KEY` cannot read them: reset two-step
verification for those accounts afterwards.

## Provision a customer

One command creates the tenant, its first workspace and vault directory
(`VAULT_BASE_PATH/<workspace-slug>`), the owner administrator with a random
20-character password, the owner membership, a 14-day trial with 5 seats, the
starter templates (`_templates/`) in the chosen locale, and sends the welcome
e-mail.

```sh
php artisan tenant:provision \
  --tenant-name="Acme Ltda" --tenant-slug=acme \
  --workspace-name="Acme Docs" --workspace-slug=acme-docs \
  --admin-email=owner@acme.example --admin-name="Ana Owner" \
  --trial-days=14 --seats=5 --locale=pt-BR
```

The password is printed **once** on the terminal (or in the `--json` report).
It is not stored in clear text, logged, or written to the audit log; hand it to
the customer through a secure channel. The welcome e-mail never contains it.

- Exit code `0`: provisioned. `1`: invalid input. `2`: the tenant slug already
  exists — nothing is changed; manage it with `tenant:plan` / `tenant:show`.
- An existing user with the same e-mail is reused as owner and keeps its
  password (`"created": false` in the report).
- `--trial-days=0` creates an `active` tenant without a trial; `--seats=0`
  means unlimited; `--no-welcome-email` skips the message.
- Audit: `tenant.provisioned` (no password).

## Change a plan

```sh
php artisan tenant:show acme                      # current state, seats used, workspaces
php artisan tenant:plan acme --status=active --name=Team --seats=10
php artisan tenant:plan acme --trial-days=7       # extend or restart a trial
php artisan tenant:plan acme --status=past_due    # writes answer 402, reading continues
php artisan tenant:plan acme --status=read_only
```

Billing happens outside the engine; these commands mirror its outcome. Every
change is audited (`tenant.plan_changed`). The daily scheduler task
`tenant:expire-trials` e-mails owners 3 days before a trial ends and again when
it ends (moving the tenant to `read_only`), each at most once per tenant.

## Export a tenant (LGPD portability)

```sh
php artisan tenant:export acme --to=/home/user/exports/acme.zip
```

The ZIP contains, per workspace, `workspaces/<slug>/vault/` (every file on
disk: notes, attachments, `_templates`) and `workspaces/<slug>/backup.json`
(the same JSON document `GET /api/workspaces/{id}/export?format=json`
produces), plus `tenant.json` (tenant, plan, workspaces, memberships, users
tied to the tenant). Never write it under the document root; the command
refuses to. Audit: `tenant.exported`.

## Remove a tenant

At the end of the retention period agreed with the customer (LGPD art. 16):

1. `php artisan tenant:plan acme --status=read_only` during the notice period.
2. See what would go, changing nothing:

```sh
php artisan tenant:delete acme --dry-run
```

3. Export and delete in one step (nothing is deleted if the export fails):

```sh
php artisan tenant:delete acme --export-to=/home/user/exports/acme.zip
```

`tenant:delete` asks for confirmation (`--force` skips it; `--json` for scripts) and then:

- deletes, through `user:delete`'s rules, every account that belongs **only** to this
  tenant. Administrators, and people with a membership in another tenant, only lose
  their memberships here;
- keeps the tenant's **access records** (sign-in, sign-out, OAuth tokens issued, MCP
  connections, with IP) for the Marco Civil window (`JOTTER_ACCESS_LOG_RETENTION_DAYS`),
  detached from the tenant, so `audit:prune` removes them later. Every other audit row
  of the tenant is deleted;
- deletes the tenant row; foreign keys cascade to workspaces, notes, revisions,
  comments, tokens, grants, groups and projections;
- removes each workspace's vault folder from disk, but only when it lies inside
  `VAULT_BASE_PATH`. A folder elsewhere is listed as "not removed" for you to check;
- for each workspace on **GitHub**, first commits the removal of every file in its
  repository, then prints and audits (`vault.remote_erased`, kept after the tenant goes)
  the advice to **archive or delete the repository**: Git history still holds the
  content, and Jotter never rewrites it. The customer controls the repository, so send
  them this advice. An unreachable repository (revoked token, uninstalled App) does not
  block the deletion; it is reported as `unreachable`;
- records `tenant.deleted` (slug and counts, no personal data).

It refuses to delete the installation's only tenant. Instance backups taken before
keep the tenant until they rotate out (`JOTTER_BACKUP_KEEP`), as the privacy policy
says. They are never used to bring it back: the deletion is written to the erasure log,
which travels inside every later backup, and `jotter:restore` deletes the tenant again
after any restore. **Rotate your off-server copies within 30 days** (the window the
privacy policy states), and keep the erasure log longer than any copy
(`JOTTER_ERASURE_LOG_DAYS`, 400). Keep the exported ZIP as the record of what existed for as long as your contract
requires, and no longer.

## Account deletion requests

People ask for their own account to be deleted from **Your profile → Your data**
(they can also download everything Jotter keeps about them there). Active
administrators are e-mailed, and the request shows in **Administration → Users →
Account deletion requests**. Carry it out there, or over SSH:

```sh
php artisan user:delete maria@example.com
```

The privacy policy promises 30 days. `jotter:doctor` warns (`deletion_requests`)
when a request is older than 15 days.

## Starter templates

`resources/templates/<locale>/_templates/` ships ADR, meeting notes, daily,
runbook, and PRD templates in `en` and `pt-BR`. `tenant:provision` installs them;
for an existing workspace build the ZIP and upload it through the SPA import
dialog (or `POST /api/workspaces/{id}/import`):

```sh
php artisan templates:pack --locale=pt-BR --to=/tmp/templates-pt-BR.zip
```

Existing files under `_templates/` are never overwritten by `tenant:provision`.

## MCP tokens for customers

```sh
php artisan mcp:token owner@acme.example --tenant=acme --name="Ana — Claude Code"
```

Prints the token once with the `https://<host>/api/mcp` URL. Customers can
also issue their own from Administration → MCP tokens. Client setup:
`docs/mcp-clients.md`. Revoke from the same screen or by setting `revoked_at`.

## Demo vault

```sh
php artisan demo:seed <workspace-slug> [--tenant=<slug>] [--overwrite]
```

Writes 25 interlinked pt-BR notes (wiki, ADRs, meeting notes, runbooks) for
demos and screenshots; never touches other notes.

## Workspaces on GitHub

Setup is in `docs/deployment.md` ("GitHub repositories"); the customer guide is
`docs/guia-conectar-github.md`.

**Gradual launch.** Start with `JOTTER_GITHUB_BETA_TENANTS=acme,globex` (tenant slugs or
ids). Only those tenants can connect GitHub; the others get "not in beta" when they
try. Once the beta runs clean for a few weeks (no stuck flushes, no unexpected
conflicts, the doctor green), empty the variable to open it to everyone.

**Quota.** Each App installation (or PAT) gets its own write budget:
`JOTTER_GITHUB_WRITES_PER_HOUR=400` and `JOTTER_GITHUB_WRITES_PER_MINUTE=60`. A batch
of up to 100 files costs about 3 writes, so one tenant can commit about 130 batches an
hour. If GitHub turns out to count the quota per App instead of per installation
(live check V1 in `docs/20261113-jotter-github-vault-spike.md`), lower the hourly
budget or register a second App.

**Doctor.** `php artisan jotter:doctor` (`github_vault`) **fails** on:

- an App that is half configured, or a key file that is missing or unreadable;
- a store in `failed` (the workspace is read-only);
- changes that have not reached GitHub for 24 hours.

It **warns** about:

- a flush that is retrying;
- edits waiting more than 30 minutes (usually the write budget);
- a PAT that expires within 14 days;
- a repository larger than `JOTTER_GITHUB_REPO_WARN_KB`;
- no webhook delivery received yet;
- paths the sync ignores (symlinks, submodules, oversize files) or renamed (case twins, NFD names).

`--json` lists each store with its queue, its last flush, pull and webhook, and those paths.

**A flush is delayed.** The workspace banner says "Sending to GitHub is delayed".
Nothing is lost: saves stay in the journal and every read sees them.

1. `php artisan jotter:doctor` gives the reason. A rate limit clears by itself: Jotter
   waits 60 s, then 300 s, then 900 s. Other errors back off up to 30 minutes, and the
   tenant admins are notified after 24 hours.
2. `php artisan vault:flush --store=<id> --now` retries at once, ignoring the backoff.
3. If the token was revoked or the App uninstalled, the store is `failed` and the
   workspace read-only. The customer reconnects (new token, or reinstall the App), then
   runs "Test connection" in the Storage tab, which lifts the read-only state.
4. If the branch moved under Jotter, the next flush pulls first and re-applies the
   journal. When both sides changed the same note, the GitHub version keeps the
   original name and Jotter's version becomes a `(conflito GitHub …)` copy.

**External edits not showing up.** Check that `vault:pull` runs (the scheduler
heartbeat). Check the webhook's "Recent deliveries" on GitHub: a `401` means the secret
differs. `php artisan vault:pull --store=<id>` pulls at once.

**Erasure.** See *Remove a tenant*. Backups leave workspaces on GitHub out unless you
pass `jotter:backup --with-remote-vaults`.

## Health and diagnostics

- `php artisan jotter:doctor [--json]` — installation self-check.
- `GET /api/health` — public liveness probe (`status`, `version`, scheduler heartbeat).
- `php artisan tenant:show <slug> --json` — plan, seats, workspaces.
