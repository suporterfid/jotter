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

There is deliberately no destructive command. To remove a customer:

1. `php artisan tenant:export acme --to=...` and deliver the archive.
2. `php artisan tenant:plan acme --status=read_only` during the notice period.
3. After the retention period agreed with the customer, delete the rows and the
   vault directory manually and record the action out of band. `audit_log`
   rows reference the tenant with `ON DELETE RESTRICT`, so they must go first
   (keep the exported archive as the record of what existed):

```sh
php artisan tinker --execute="
  \$t = App\\Models\\Tenant::where('slug','acme')->firstOrFail();
  App\\Models\\AuditLog::where('tenant_id', \$t->id)->delete();
  \$t->delete();
"
rm -rf "$VAULT_BASE_PATH/acme-docs"
```

Cascading foreign keys then remove workspaces, memberships, notes, and related
projections; the local user account survives (it may belong to other tenants)
and can be deactivated from the admin UI.

## Starter templates

`resources/templates/<locale>/_templates/` ships ADR, meeting notes, daily,
runbook, and PRD templates in `en` and `pt-BR`. `tenant:provision` installs them;
for an existing workspace build the ZIP and upload it through the SPA import
dialog (or `POST /api/workspaces/{id}/import`):

```sh
php artisan templates:pack --locale=pt-BR --to=/tmp/templates-pt-BR.zip
```

Existing files under `_templates/` are never overwritten by `tenant:provision`.

## Health and diagnostics

- `php artisan jotter:doctor [--json]` — installation self-check.
- `GET /api/health` — public liveness probe (`status`, `version`, scheduler heartbeat).
- `php artisan tenant:show <slug> --json` — plan, seats, workspaces.
