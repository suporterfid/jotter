# Release ZIP Validation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a separate `release:verify` command that scans an already-built release ZIP for secrets before CI uploads it or a developer distributes it.

**Architecture:** Existing `release` verbs remain artifact producers. New parallel `release:verify` functions in the Bash and PowerShell wrappers require a non-empty ZIP, then run only `ReleaseZipSecurityTest` in the Dockerized Laravel application with the bind-mounted artifact path. CI calls the new wrapper command after its build step, and the documentation describes the two-command release gate.

**Tech Stack:** Bash, PowerShell 5.1, Docker Compose V2, Laravel 12/PHPUnit 11, GitHub Actions, Markdown.

## Global Constraints

- Preserve `release` as the only command that builds or replaces `dist/jotter-release.zip` and its checksum.
- `release:verify` must not publish, upload, or rebuild an artifact.
- Both wrappers require the same non-empty relative artifact: `dist/jotter-release.zip`.
- Both wrappers pass `JOTTER_RELEASE_ZIP=/var/www/html/dist/jotter-release.zip` to the `app` container and execute `php artisan test --filter=ReleaseZipSecurityTest`.
- A missing ZIP fails before invoking the test runner; a scan failure returns a non-zero command exit.
- CI upload remains after successful `release` and `release:verify` steps only.
- Run PHP, Composer, Node, npm, MySQL, tests, and builds only through `scripts/jt.sh` or `scripts/jt.ps1`.
- Do not commit `.env`, credentials, private keys, `vendor/`, `node_modules/`, `public/build/`, or `dist/`.

---

## File Structure

- Modify: `scripts/jt.sh` — Bash help, verification function, and verb dispatch.
- Modify: `scripts/jt.ps1` — PowerShell help and equivalent verification case.
- Modify: `.github/workflows/ci.yml` — replace the inline Artisan scan with the wrapper command after the build.
- Modify: `README.md` — describe `release:verify` in the command reference.
- Modify: `docs/deployment.md` — document the local build-then-verify sequence.

### Task 1: Implement parity release verification commands

**Files:**
- Modify: `scripts/jt.sh:67-130`
- Modify: `scripts/jt.ps1:134-212`
- Test: `tests/Feature/ReleaseZipSecurityTest.php` (existing focused test executed through the wrappers)

**Interfaces:**
- Consumes: an existing repository file at `dist/jotter-release.zip` and the `app` Compose service bind-mounted at `/var/www/html`.
- Produces: `./scripts/jt.sh release:verify` and `.\scripts\jt.ps1 release:verify`, each returning success only when `ReleaseZipSecurityTest` passes.

- [ ] **Step 1: Run the missing-artifact acceptance check to verify the command is unavailable**

Run in a checkout without `dist/jotter-release.zip`:

~~~sh
./scripts/jt.sh release:verify
~~~

Expected: FAIL with `Unknown verb: release:verify`; no Compose test container is started.

Run the Windows equivalent:

~~~powershell
.\scripts\jt.ps1 release:verify
~~~

Expected: FAIL with `Unknown verb: release:verify`; no Compose test container is started.

- [ ] **Step 2: Add the Bash `cmd_release_verify` function and dispatch entry**

In `scripts/jt.sh`, add `release:verify` to the usage text, then define this function adjacent to `cmd_release`:

~~~bash
cmd_release_verify() {
  ensure_env
  local zip_path='dist/jotter-release.zip'

  if [[ ! -s "$zip_path" ]]; then
    echo "Release zip is missing or empty: $zip_path. Run ./scripts/jt.sh release first." >&2
    return 1
  fi

  compose run --rm \
    -e JOTTER_RELEASE_ZIP=/var/www/html/dist/jotter-release.zip \
    app php artisan test --filter=ReleaseZipSecurityTest
  echo "Release ZIP security verification passed."
}
~~~

Add this case arm:

~~~bash
release:verify) cmd_release_verify ;;
~~~

The function does not call `bootstrap`: the focused feature test does not query application data, and `release` creates `.env` through `ensure_env`.

- [ ] **Step 3: Add the PowerShell `release:verify` case with the same contract**

In `scripts/jt.ps1`, add `release:verify` to `Show-Usage`, then add this switch case next to `release`:

~~~powershell
'release:verify' {
    Initialize-Env
    $zipPath = 'dist/jotter-release.zip'
    if (-not (Test-Path $zipPath -PathType Leaf) -or (Get-Item $zipPath).Length -eq 0) {
        throw "Release zip is missing or empty: $zipPath. Run .\scripts\jt.ps1 release first."
    }

    Invoke-Compose -Arguments @(
        'run', '--rm',
        '-e', 'JOTTER_RELEASE_ZIP=/var/www/html/dist/jotter-release.zip',
        'app', 'php', 'artisan', 'test', '--filter=ReleaseZipSecurityTest'
    )
    Write-Output 'Release ZIP security verification passed.'
}
~~~

- [ ] **Step 4: Run the missing-artifact checks to verify the new failure behavior**

With no ZIP in `dist/`, run:

~~~sh
./scripts/jt.sh release:verify
~~~

Expected: non-zero exit and `Release zip is missing or empty: dist/jotter-release.zip. Run ./scripts/jt.sh release first.`

On Windows, run:

~~~powershell
.\scripts\jt.ps1 release:verify
~~~

Expected: terminating non-zero error that says to run `.\scripts\jt.ps1 release` first.

- [ ] **Step 5: Run the positive end-to-end verification path**

On the active platform, build then scan the same artifact:

~~~powershell
.\scripts\jt.ps1 release
.\scripts\jt.ps1 release:verify
~~~

Expected: `ReleaseZipSecurityTest` reports one passing test with no skipped tests, followed by `Release ZIP security verification passed.`

On Bash-capable CI or Linux:

~~~sh
./scripts/jt.sh release
./scripts/jt.sh release:verify
~~~

Expected: the same test and success message. The produced `dist/` files remain untracked.

- [ ] **Step 6: Commit the wrapper implementation**

~~~bash
git add scripts/jt.sh scripts/jt.ps1
git commit -m "feat(release): add zip security verification command"
~~~

### Task 2: Route CI and developer documentation through the verification gate

**Files:**
- Modify: `.github/workflows/ci.yml:46-53`
- Modify: `README.md:46-53`
- Modify: `docs/deployment.md:3-15`
- Test: CI release-step ordering and the end-to-end wrapper commands from Task 1

**Interfaces:**
- Consumes: `release:verify` from Task 1.
- Produces: CI and local instructions that run `release` before `release:verify`, with artifact upload after the security gate.

- [ ] **Step 1: Replace CI's inline test invocation with the wrapper command**

Replace the `Inspect release artifact for secrets` step's environment block and Artisan command with:

~~~yaml
      - name: Verify release artifact for secrets
        run: ./scripts/jt.sh release:verify
~~~

Leave the build step immediately before it and `Upload release artifact` immediately after it. Do not add `if: always()` to verification or upload.

- [ ] **Step 2: Update the command reference and deployment instructions**

In `README.md`, add:

~~~markdown
- `release:verify` — scan an existing release ZIP for secrets and private keys
~~~

In `docs/deployment.md`, show both commands for Bash and PowerShell, then state that `release:verify` must pass before the ZIP is deployed or shared:

~~~sh
./scripts/jt.sh release
./scripts/jt.sh release:verify
~~~

~~~powershell
.\scripts\jt.ps1 release
.\scripts\jt.ps1 release:verify
~~~

- [ ] **Step 3: Inspect CI release order**

Run:

~~~powershell
rg -n -A 3 -B 2 "Build shared-hosting release|Verify release artifact for secrets|Upload release artifact" .github/workflows/ci.yml
~~~

Expected: build, verify, and upload appear in that order; verification invokes only `./scripts/jt.sh release:verify`.

- [ ] **Step 4: Run the release validation path and regression suite**

Run:

~~~powershell
.\scripts\jt.ps1 release
.\scripts\jt.ps1 release:verify
.\scripts\jt.ps1 test
~~~

Expected: the focused scan runs without being skipped, the ZIP has no violations, and Laravel plus frontend tests pass. Treat a skipped `ReleaseZipSecurityTest` in the verify command as a failure requiring investigation.

- [ ] **Step 5: Check the staged change set and commit CI/docs**

~~~bash
git diff --check
git status --short
git add .github/workflows/ci.yml README.md docs/deployment.md
git commit -m "ci(release): verify artifact before upload"
~~~

Expected: no whitespace errors; only the workflow and documentation files are staged for this commit.

## Final Verification

- [ ] `./scripts/jt.sh release:verify` rejects a missing or empty ZIP before starting the test runner.
- [ ] `.\scripts\jt.ps1 release:verify` rejects a missing or empty ZIP before starting the test runner.
- [ ] `release` followed by `release:verify` executes `ReleaseZipSecurityTest` and shows no skipped test.
- [ ] The CI release upload step follows, rather than precedes, the verification step.
- [ ] `git diff --check` is clean and `dist/` remains untracked.
