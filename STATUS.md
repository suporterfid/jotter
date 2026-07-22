# Status

## Done in PR0 scaffold

- Laravel 12 application skeleton on PHP 8.2
- Minimal Vue 3/Vite landing screen
- Docker Compose development and CI environments with MySQL 8
- Docker-only `jt` commands for setup, tests, E2E, tools, and release
- Bootstrap-admin Artisan command and test
- Frontend unit test and Playwright smoke test
- Shared-hosting release zip, checksum, and artifact secret test
- CI workflow and baseline documentation

## In progress

Runtime verification is delegated to the Docker-based PR checks because Docker is unavailable in the scaffold authoring environment.

## Next

PR1 is the data model and migrations from §5. It must not begin until PR0 is reviewed, merged, and green.
