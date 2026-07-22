# Status

## Done — PR0 scaffold

- Laravel 12 application skeleton on PHP 8.2
- Minimal Vue 3/Vite landing screen
- Docker Compose development and CI environments with MySQL 8
- Docker-only `jt` commands for setup, tests, E2E, tools, and release
- Bootstrap-admin Artisan command and test
- Frontend unit test and Playwright smoke test
- Shared-hosting release zip, checksum, and artifact secret test
- CI workflow and baseline documentation

## In progress — PR1 data model

- Idempotent initial tenant/workspace projection and state schema
- Focused Eloquent models and relationships
- Configured, repeat-safe default tenant/workspace seeding without users or credentials
- Data-model, scoping, relationship, and seeder feature tests
- Schema/projection documentation

Runtime red/green evidence requires MySQL 8 in Docker CI; this environment supports static checks only.

## Next — PR2 vault storage

After PR1 is reviewed, merged, and green, implement the path-safe disk Markdown storage service and incremental projection updates from §7.1. Do not begin PR2 as part of PR1.
