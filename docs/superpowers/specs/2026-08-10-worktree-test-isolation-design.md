# Worktree Test Isolation Design

## Objective

Make `scripts/jt.ps1 test` safe and repeatable from multiple Jotter worktrees that share a Docker host. A test run must never reuse another worktree's MySQL volume or require its secrets.

## Root Cause

Docker Compose currently uses the fixed project name `jotter`. Its named `mysql_data` volume is therefore shared across every worktree. Each new worktree creates its own `.env` secrets, which do not match the root password used when the shared volume was initialized. The root-only test-database bootstrap then fails with MySQL error 1045.

## Selected Design

The `test` verb will set `COMPOSE_PROJECT_NAME` for the lifetime of the script process to a deterministic, Docker-safe value derived from the absolute repository path. Every Compose call issued by that test run will consequently receive isolated containers, networks, and named volumes.

The other verbs retain their current project name and behavior. A repeated test run from the same worktree resolves to the same isolated project, so it can reuse caches and its own MySQL volume. Different worktrees resolve to different names.

## Components

### `Get-TestComposeProjectName`

Returns `jotter-test-<hash>`, where `<hash>` is a stable short SHA-256 digest of the normalized absolute repository path. The function uses only lowercase ASCII, digits, and hyphens, satisfying Docker Compose project-name rules. The hash avoids collisions created by similarly named worktrees and avoids exposing path details in Docker resource names.

### `Use-TestComposeProject`

Sets `COMPOSE_PROJECT_NAME` just before the `test` bootstrap. It preserves the previous process environment value and restores it in `finally`, including when the test command fails. This prevents a caller's shell environment from being changed after the script exits and keeps the isolation scoped to the test verb.

### Test flow

The `test` switch case wraps its existing bootstrap, database setup, Laravel test, and Vitest test calls with `Use-TestComposeProject`. No database SQL, credentials, or production development project resources are shared between worktrees.

## Error Handling

If a Compose call fails, the existing `Invoke-Compose` error is preserved. The `finally` block restores `COMPOSE_PROJECT_NAME` before the error is returned. The failure does not trigger any deletion of containers, volumes, or user `.env` files.

## Verification

1. Add a PowerShell regression test that imports the helper behavior and proves two different absolute paths produce different valid project names, while the same path is stable.
2. Verify the test wrapper restores a pre-existing `COMPOSE_PROJECT_NAME` value after both success and failure paths.
3. Run `scripts/jt.ps1 test` from a fresh linked worktree while the existing development `jotter` MySQL volume is present. The command must create a `jotter-test-*` MySQL resource and complete without error 1045.
4. Run the normal backend and frontend suites after the isolated bootstrap.

## Non-Goals

- Changing `up`, `down`, `artisan`, `composer`, `npm`, `e2e`, or `release` project names.
- Copying, reading, or synchronizing credentials from another worktree.
- Cleaning up test volumes automatically; retaining the worktree-scoped volume supports repeatable local runs and Docker-managed cleanup.
