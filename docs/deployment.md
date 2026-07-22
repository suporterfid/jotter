# Shared-hosting deployment

Build the artifact with Docker:

```sh
./scripts/jt.sh release
```

On PowerShell:

```powershell
.\scripts\jt.ps1 release
```

The command writes `dist/jotter-release.zip` and `dist/jotter-release.zip.sha256`.

## Deploy

1. Verify the SHA-256 checksum.
2. Extract the zip. It contains a top-level `app/` directory.
3. Create `app/.env` from `app/.env.example` and provide unique production values. Never upload a development `.env`.
4. Point the domain's document root at `app/public/`.
5. Ensure `storage/` and `bootstrap/cache/` are writable by PHP.
6. Run `php artisan migrate --force` using the host's PHP 8.2+ CLI facility.
7. Keep debug mode off and use HTTPS.

The artifact includes production `vendor/` dependencies and compiled `public/build/` assets. It excludes tests, frontend sources, container files, development tooling, and secrets.

The bounded `vault:reindex` cron entry described by the product spec will be documented when that command is implemented in its later PR. PR0 does not provide a placeholder command.
