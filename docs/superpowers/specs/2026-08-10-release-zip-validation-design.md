# Release ZIP Validation Design

**Issue:** #341
**Date:** 2026-08-10

## Goal

Provide an explicit, reproducible validation step for the distributable ZIP so a release artifact is scanned for secrets and private keys before CI uploads it or a developer hands it off.

## Scope

- Add a `release:verify` verb to both `scripts/jt.sh` and `scripts/jt.ps1`.
- Require an existing `dist/jotter-release.zip`; `release:verify` does not build or replace it.
- Run only `ReleaseZipSecurityTest`, with `JOTTER_RELEASE_ZIP` set to the artifact's absolute in-container path.
- Change CI to call `release`, then `release:verify`, and only upload after both succeed.
- Document the local `release` then `release:verify` sequence.

## Command Contract

`release` remains the artifact producer. It builds `dist/jotter-release.zip`, writes its SHA-256 checksum, and validates that checksum.

`release:verify` is the artifact gate. It:

1. Resolves `dist/jotter-release.zip` from the repository root.
2. Fails with a clear message if the file is absent.
3. Starts only the dependencies needed by the Laravel test runner, using the existing bootstrap/test-database flow where required.
4. Supplies the repository-mounted artifact path through `JOTTER_RELEASE_ZIP`.
5. Executes `php artisan test --filter=ReleaseZipSecurityTest`.

The test must no longer be skipped in this path. A missing explicit artifact path remains a test failure, preserving the test's current safety property.

## CI Flow

The release segment is ordered as follows:

1. Build the release artifact with `jt release`.
2. Validate it with `jt release:verify`.
3. Upload the ZIP and checksum.

Because CI stops on a non-zero command, an unsafe artifact cannot reach the upload step.

## Platform Parity

The Bash and PowerShell wrappers expose the same verb, prerequisite, failure behavior, test filter, and success message. Each passes the location as `/var/www/html/dist/jotter-release.zip`, the path visible inside the bind-mounted `app` container.

## Documentation

The README command list identifies `release:verify` as the release-security check. Deployment guidance instructs developers to run `release:verify` immediately after `release` and before deployment or distribution.

## Non-Goals

- Rebuilding the ZIP during verification.
- Publishing releases from the wrapper scripts.
- Broadening the archive scan beyond `ReleaseZipSecurityTest`.
- Changing archive contents or the existing checksum contract.

## Verification

- A focused wrapper-level regression check covers the new command's required artifact path and test invocation where practical.
- Run `release`, then `release:verify`; the security test must run and pass rather than be skipped.
- Confirm `release:verify` fails before invoking tests if the ZIP is missing.
- Run the project test suite appropriate to the changed scripts and documentation.
