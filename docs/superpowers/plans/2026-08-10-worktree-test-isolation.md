# Worktree Test Isolation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make `scripts/jt.ps1 test` create Docker Compose resources unique to its worktree.

**Architecture:** A small PowerShell helper derives a Docker-safe project name from the absolute worktree path and temporarily sets `COMPOSE_PROJECT_NAME`. The `test` verb wraps its current bootstrap and test sequence in that scope; all other verbs remain unchanged.

**Tech Stack:** Windows PowerShell 5.1, .NET SHA-256, Docker Compose, Laravel, Vitest.

## Global Constraints

- Do not read, copy, log, or synchronize `.env` secrets between worktrees.
- Keep the isolation exclusive to the `test` verb.
- Restore a caller-provided `COMPOSE_PROJECT_NAME` after success and failure.
- Do not change the behavior of `up`, `down`, `artisan`, `composer`, `npm`, `e2e`, or `release`.

---

### Task 1: Add the red regression harness

**Files:**
- Create: `scripts/tests/jt-compose-project-name.tests.ps1`
- Test: `scripts/tests/jt-compose-project-name.tests.ps1`

**Interfaces:** Consumes `Get-TestComposeProjectName -RepositoryPath <string>` and `Invoke-WithTestComposeProject -RepositoryPath <string> -Action <scriptblock>` from `scripts/jt-compose.ps1`.

- [ ] **Step 1: Write the failing script**

```powershell
$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot '..\jt-compose.ps1')
function Assert-True([bool]$condition, [string]$message) { if (-not $condition) { throw $message } }
$first = Get-TestComposeProjectName -RepositoryPath 'C:\work\jotter-a'
$same = Get-TestComposeProjectName -RepositoryPath 'C:\work\jotter-a'
$second = Get-TestComposeProjectName -RepositoryPath 'C:\work\jotter-b'
Assert-True ($first -eq $same) 'The same path must have a stable project name.'
Assert-True ($first -ne $second) 'Different paths must have different project names.'
Assert-True ($first -match '^jotter-test-[0-9a-f]{12}$') 'The project name must be Docker-safe.'
$env:COMPOSE_PROJECT_NAME = 'caller-project'
Invoke-WithTestComposeProject -RepositoryPath 'C:\work\jotter-a' -Action { Assert-True ($env:COMPOSE_PROJECT_NAME -eq $first) 'The action must receive its test project.' }
Assert-True ($env:COMPOSE_PROJECT_NAME -eq 'caller-project') 'The caller project must be restored after success.'
try { Invoke-WithTestComposeProject -RepositoryPath 'C:\work\jotter-a' -Action { throw 'expected failure' } } catch { Assert-True ($_.Exception.Message -eq 'expected failure') 'The action error must be preserved.' }
Assert-True ($env:COMPOSE_PROJECT_NAME -eq 'caller-project') 'The caller project must be restored after failure.'
Remove-Item Env:COMPOSE_PROJECT_NAME
```

- [ ] **Step 2: Verify red**

Run: `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/tests/jt-compose-project-name.tests.ps1`

Expected: failure because `scripts/jt-compose.ps1` does not exist.

- [ ] **Step 3: Commit the test**

Run: `git add scripts/tests/jt-compose-project-name.tests.ps1; git commit -m "test(dev): cover worktree Compose isolation"`

### Task 2: Isolate the PowerShell test Compose project

**Files:**
- Create: `scripts/jt-compose.ps1`
- Modify: `scripts/jt.ps1`
- Test: `scripts/tests/jt-compose-project-name.tests.ps1`

**Interfaces:** `Get-TestComposeProjectName` returns `jotter-test-<12 lowercase hex characters>`. `Invoke-WithTestComposeProject` runs an action under that name and restores the prior process environment.

- [ ] **Step 1: Implement the helper**

```powershell
function Get-TestComposeProjectName {
    param([Parameter(Mandatory = $true)][string]$RepositoryPath)
    $path = [IO.Path]::GetFullPath($RepositoryPath).TrimEnd('\', '/').ToLowerInvariant()
    $sha256 = [Security.Cryptography.SHA256]::Create()
    try { $hash = -join ($sha256.ComputeHash([Text.Encoding]::UTF8.GetBytes($path)) | ForEach-Object { $_.ToString('x2') }) } finally { $sha256.Dispose() }
    "jotter-test-$($hash.Substring(0, 12))"
}
function Invoke-WithTestComposeProject {
    param([Parameter(Mandatory = $true)][string]$RepositoryPath, [Parameter(Mandatory = $true)][scriptblock]$Action)
    $previous = [Environment]::GetEnvironmentVariable('COMPOSE_PROJECT_NAME', 'Process')
    [Environment]::SetEnvironmentVariable('COMPOSE_PROJECT_NAME', (Get-TestComposeProjectName -RepositoryPath $RepositoryPath), 'Process')
    try { & $Action } finally { [Environment]::SetEnvironmentVariable('COMPOSE_PROJECT_NAME', $previous, 'Process') }
}
```

- [ ] **Step 2: Load and use the helper in `jt.ps1`**

Add `. (Join-Path $PSScriptRoot 'jt-compose.ps1')` after the strict-mode setup. Wrap the existing `test` switch branch, without changing its internal command order:

```powershell
'test' {
    Invoke-WithTestComposeProject -RepositoryPath $RootDir -Action {
        Invoke-Bootstrap
        Initialize-TestDatabase
        Invoke-Compose -Arguments @(
            'run', '--rm', '-e', 'DB_DATABASE=jotter_testing', 'app',
            'php', 'artisan', 'migrate:fresh', '--seed', '--force'
        )
        Invoke-Compose -Arguments (@('run', '--rm', '-e', 'DB_DATABASE=jotter_testing', 'app', 'php', 'artisan', 'test') + $VerbArgs)
        Invoke-Compose -Arguments @('--profile', 'dev', 'run', '--rm', '--no-deps', 'node', 'npm', 'test')
    }
}
```

- [ ] **Step 3: Verify green**

Run: `powershell -NoProfile -ExecutionPolicy Bypass -File scripts/tests/jt-compose-project-name.tests.ps1`

Expected: exit code `0`.

- [ ] **Step 4: Prove Compose receives the isolated project**

Run: `. .\scripts\jt-compose.ps1; $env:COMPOSE_PROJECT_NAME = Get-TestComposeProjectName -RepositoryPath (Get-Location).Path; docker compose -f compose.yaml config --volumes; Remove-Item Env:COMPOSE_PROJECT_NAME`

Expected: the configured volume begins with `jotter-test-`, not `jotter_`.

- [ ] **Step 5: Commit the production change**

Run: `git add scripts/jt.ps1 scripts/jt-compose.ps1; git commit -m "fix(dev): isolate test Compose projects by worktree"`

### Task 3: Validate a complete isolated test run

**Files:**
- Modify: none
- Test: `scripts/tests/jt-compose-project-name.tests.ps1`, Laravel suite, Vitest suite

**Interfaces:** Consumes the scoped `test` verb from Task 2.

- [ ] **Step 1: Run full verification**

Run: `.\scripts\jt.ps1 test`

Expected: it creates a `jotter-test-*` MySQL resource and completes without MySQL error 1045.

- [ ] **Step 2: Inspect scope and repository state**

Run: `docker compose -f compose.yaml ps; git diff main...HEAD --check; git status --short --branch`

Expected: the development `jotter` project remains separate, and no `.env` or generated files are staged.
