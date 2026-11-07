#requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot 'jt-compose.ps1')

$RootDir = Split-Path -Parent $PSScriptRoot
Set-Location $RootDir

$ComposeFiles = @('-f', 'compose.yaml')
if ($env:JOTTER_CI -eq '1' -or $env:CI -eq 'true' -or $env:GITHUB_ACTIONS -eq 'true') {
    $ComposeFiles += @('-f', 'compose.ci.yaml')
}

function Invoke-Compose {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Arguments)

    & docker compose @ComposeFiles @Arguments
    if ($LASTEXITCODE -ne 0) {
        throw "docker compose failed with exit code $LASTEXITCODE"
    }
}

function Test-ComposeCommand {
    param([string[]]$Arguments)

    $previousErrorActionPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & docker compose @ComposeFiles @Arguments *> $null
        return $LASTEXITCODE -eq 0
    } finally {
        $ErrorActionPreference = $previousErrorActionPreference
    }
}

function Initialize-Env {
    if (-not (Test-Path '.env')) {
        Copy-Item '.env.example' '.env'
    }

    $values = @{}
    Get-Content '.env' | ForEach-Object {
        if ($_ -match '^([^#=]+)=(.*)$') {
            $values[$Matches[1]] = $Matches[2]
        }
    }

    if ($values['APP_KEY'] -and $values['DB_PASSWORD'] -and $values['MYSQL_ROOT_PASSWORD']) {
        return
    }

    # Generate development-only secrets in PowerShell rather than passing a
    # quoted PHP program through `docker compose run`. Windows PowerShell can
    # strip the inner quotes from `php -r`, yielding invalid PHP before the
    # Docker toolchain can bootstrap.
    $rng = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try {
        [byte[]]$appKeyBytes = New-Object byte[] 32
        [byte[]]$databasePasswordBytes = New-Object byte[] 24
        [byte[]]$mysqlRootPasswordBytes = New-Object byte[] 24
        $rng.GetBytes($appKeyBytes)
        $rng.GetBytes($databasePasswordBytes)
        $rng.GetBytes($mysqlRootPasswordBytes)

        $replacements = @{
            APP_KEY = "base64:$([Convert]::ToBase64String($appKeyBytes))"
            DB_PASSWORD = ([BitConverter]::ToString($databasePasswordBytes)).Replace('-', '').ToLowerInvariant()
            MYSQL_ROOT_PASSWORD = ([BitConverter]::ToString($mysqlRootPasswordBytes)).Replace('-', '').ToLowerInvariant()
        }
    } finally {
        $rng.Dispose()
    }

    $updated = Get-Content '.env' | ForEach-Object {
        if ($_ -match '^([^#=]+)=') {
            $name = $Matches[1]
            if ($replacements.ContainsKey($name)) {
                return "$name=$($replacements[$name])"
            }
        }
        return $_
    }
    $utf8WithoutBom = New-Object System.Text.UTF8Encoding($false)
    [System.IO.File]::WriteAllLines((Resolve-Path '.env').Path, $updated, $utf8WithoutBom)
}

function Install-Dependencies {
    if (-not (Test-ComposeCommand @('run', '--rm', '--no-deps', 'app', 'test', '-f', 'vendor/autoload.php'))) {
        Invoke-Compose -Arguments @('run', '--rm', '--no-deps', 'app', 'composer', 'install', '--no-interaction', '--prefer-dist')
    }

    Invoke-Compose -Arguments @('--profile', 'dev', 'run', '--rm', '--no-deps', 'node', 'npm', 'ci')
}

function Invoke-Bootstrap {
    Initialize-Env
    Invoke-Compose -Arguments @('up', '-d', '--build', '--wait', 'mysql')
    Install-Dependencies
    Invoke-Compose -Arguments @('--profile', 'dev', 'run', '--rm', '--no-deps', 'node', 'npm', 'run', 'build')
    Invoke-Compose -Arguments @('run', '--rm', 'app', 'php', 'artisan', 'migrate', '--force', '--seed')
}

function Initialize-TestDatabase {
    $values = @{}
    Get-Content '.env' | ForEach-Object {
        if ($_ -match '^([^#=]+)=(.*)$') {
            $values[$Matches[1]] = $Matches[2]
        }
    }

    $rootPassword = $values['MYSQL_ROOT_PASSWORD']
    $databaseUser = $values['DB_USERNAME']
    if (-not $rootPassword -or -not $databaseUser) {
        throw 'MYSQL_ROOT_PASSWORD and DB_USERNAME must be configured before running tests.'
    }

    $escapedUser = $databaseUser.Replace("'", "''")
    $sql = "CREATE DATABASE IF NOT EXISTS jotter_testing; GRANT ALL PRIVILEGES ON jotter_testing.* TO '$escapedUser'@'%';"

    Invoke-Compose -Arguments @(
        'exec',
        '-T',
        '-e', "MYSQL_PWD=$rootPassword",
        'mysql',
        'mysql',
        '-uroot',
        '-e', $sql
    )
}

function Show-Usage {
@'
Jotter Docker toolchain

Usage: .\scripts\jt.ps1 <verb> [args...]

Verbs: up, down, test, e2e, artisan, composer, npm, release, release:verify, release:doctor,
       ci [--allow-dirty] [--dry-run], ci:install-hook
'@ | Write-Output
}

$Verb = if ($args.Count -gt 0) { $args[0] } else { 'help' }
$VerbArgs = if ($args.Count -gt 1) { $args[1..($args.Count - 1)] } else { @() }

switch ($Verb) {
    'up' {
        Invoke-Bootstrap
        Invoke-Compose -Arguments @('up', '-d', '--build', '--wait', 'app')
        Write-Output 'Jotter is available at http://localhost:8080'
    }
    'down' {
        Initialize-Env
        Invoke-Compose -Arguments (@('down') + $VerbArgs)
    }
    'test' {
        $testAction = {
            Invoke-Bootstrap
            Initialize-TestDatabase
            Invoke-Compose -Arguments @(
                'run', '--rm', '-e', 'DB_DATABASE=jotter_testing', 'app',
                'php', 'artisan', 'migrate:fresh', '--seed', '--force'
            )
            Invoke-Compose -Arguments (@('run', '--rm', '-e', 'DB_DATABASE=jotter_testing', 'app', 'php', 'artisan', 'test') + $VerbArgs)
            # Test arguments target Laravel's test runner. Frontend tests use their
            # own Vitest invocation through the `npm` verb and must not receive
            # PHPUnit-only flags such as `--filter`.
            Invoke-Compose -Arguments @('--profile', 'dev', 'run', '--rm', '--no-deps', 'node', 'npm', 'test')
        }
        # Inside `ci` the Compose project is already isolated (jotter-ci-<hash>);
        # keep it so the final `down --volumes` cleans up the test containers too.
        if ($env:JOTTER_CI -eq '1' -and $env:COMPOSE_PROJECT_NAME) {
            & $testAction
        } else {
            Invoke-WithTestComposeProject -RepositoryPath $RootDir -Action $testAction
        }
    }
    'e2e' {
        Invoke-Bootstrap
        # The OAuth consent spec (frontend/e2e/oauth-consent.spec.ts) needs the
        # embedded OAuth server; it is off by default everywhere else.
        $env:JOTTER_OAUTH_ENABLED = 'true'
        Invoke-Compose -Arguments @(
            'run', '--rm', 'app',
            'php', 'artisan', 'migrate:fresh', '--seed', '--force'
        )
        Invoke-Compose -Arguments @('up', '-d', '--build', '--wait', 'app')
        try { Invoke-Compose -Arguments @('run', '--rm', 'app', 'php', 'artisan', 'platform:bootstrap-admin', 'admin@example.com', 'password12345') } catch {}
        Invoke-Compose -Arguments (@('--profile', 'dev', 'run', '--rm', 'node', 'npm', 'run', 'e2e', '--') + $VerbArgs)
    }
    'artisan' {
        Initialize-Env
        Invoke-Compose -Arguments (@('run', '--rm', 'app', 'php', 'artisan') + $VerbArgs)
    }
    'composer' {
        Initialize-Env
        Invoke-Compose -Arguments (@('run', '--rm', '--no-deps', 'app', 'composer') + $VerbArgs)
    }
    'npm' {
        Initialize-Env
        Invoke-Compose -Arguments (@('--profile', 'dev', 'run', '--rm', '--no-deps', 'node', 'npm') + $VerbArgs)
    }
    'release' {
        Initialize-Env
        New-Item -ItemType Directory -Force -Path 'dist' | Out-Null

        # Git tag when HEAD is exactly tagged, otherwise 0.0.0-<short sha>.
        $version = (& git describe --tags --exact-match 2>$null)
        if (-not $version) { $version = "0.0.0-$(& git rev-parse --short HEAD)" }
        $version = ($version -replace '[^A-Za-z0-9._-]', '-')
        $env:RELEASE_VERSION = $version

        $zipPath = "dist/jotter-release-$version.zip"
        $checksumPath = "$zipPath.sha256"
        Remove-Item -Force -ErrorAction SilentlyContinue $zipPath, $checksumPath
        Invoke-Compose -Arguments @('--profile', 'tools', 'run', '--rm', '--build', 'release')

        if (-not (Test-Path $zipPath) -or -not (Test-Path $checksumPath)) {
            throw 'Release zip or checksum was not produced.'
        }

        $expected = ((Get-Content $checksumPath -Raw).Trim() -split '\s+')[0]
        $actual = (Get-FileHash -Algorithm SHA256 $zipPath).Hash.ToLowerInvariant()
        if ($actual -ne $expected.ToLowerInvariant()) {
            throw 'Release checksum validation failed.'
        }
        Write-Output "Release written to $zipPath (version: $version)"
    }
    'release:verify' {
        $zipPath = if ($VerbArgs.Count -gt 0) { $VerbArgs[0] } else {
            $newest = Get-ChildItem -Path 'dist' -Filter 'jotter-release-*.zip' -File -ErrorAction SilentlyContinue |
                Sort-Object LastWriteTime -Descending | Select-Object -First 1
            if ($newest) { "dist/$($newest.Name)" } else { 'dist/jotter-release-<version>.zip' }
        }
        if (-not (Test-Path $zipPath -PathType Leaf) -or (Get-Item $zipPath).Length -eq 0) {
            throw "Release zip is missing or empty: $zipPath. Run .\scripts\jt.ps1 release first."
        }

        Initialize-Env
        Invoke-Compose -Arguments @(
            'run', '--rm',
            '-e', "JOTTER_RELEASE_ZIP=/var/www/html/$($zipPath -replace '\\', '/')",
            'app', 'php', 'artisan', 'test', '--filter=ReleaseZipSecurityTest'
        )
        Write-Output "Release ZIP security verification passed: $zipPath"
    }
    'release:doctor' {
        # Extract the newest (or given) release ZIP into dist/install-test and run
        # jotter:doctor inside that copy against the dev database. Mirrors jt.sh.
        $doctorArgs = @($VerbArgs)
        $zipPath = $null
        if ($doctorArgs.Count -gt 0 -and $doctorArgs[0] -like '*.zip') {
            $zipPath = $doctorArgs[0]
            $doctorArgs = if ($doctorArgs.Count -gt 1) { $doctorArgs[1..($doctorArgs.Count - 1)] } else { @() }
        }
        if (-not $zipPath) {
            $newest = Get-ChildItem -Path 'dist' -Filter 'jotter-release-*.zip' -File -ErrorAction SilentlyContinue |
                Sort-Object LastWriteTime -Descending | Select-Object -First 1
            if (-not $newest) { throw 'Release zip is missing. Run .\scripts\jt.ps1 release first.' }
            $zipPath = "dist/$($newest.Name)"
        }

        Initialize-Env
        $installDir = 'dist/install-test'

        $overrides = @(
            'APP_ENV=production',
            'APP_DEBUG=false',
            'APP_URL=https://install-test.example.invalid',
            'APP_INSTANCE_SLUG=install-test',
            'LOG_CHANNEL=single',
            'CACHE_STORE=database',
            'SESSION_DRIVER=database',
            'QUEUE_CONNECTION=sync',
            'MAIL_MAILER=log',
            "VAULT_BASE_PATH=/var/www/html/$installDir/vault"
        )
        $envLines = @(Get-Content '.env' | Where-Object { $_ -match '^(APP_KEY|DB_DATABASE|DB_USERNAME|DB_PASSWORD)=' })
        $envLines += @('DB_CONNECTION=mysql', 'DB_HOST=mysql', 'DB_PORT=3306') + $overrides
        Set-Content -Path "$installDir.env" -Value $envLines -NoNewline:$false

        # Extraction, .env placement, and cleanup happen inside the container so
        # file ownership never blocks a rerun from the host.
        $script = @'
set -e
rm -rf "$JOTTER_INSTALL_DIR"
mkdir -p "$JOTTER_INSTALL_DIR/vault"
unzip -q "$JOTTER_INSTALL_ZIP" -d "$JOTTER_INSTALL_DIR"
cp "$JOTTER_INSTALL_DIR.env" "$JOTTER_INSTALL_DIR/app/.env"
cd "$JOTTER_INSTALL_DIR/app"
php artisan schedule:run --no-ansi >/dev/null
php artisan jotter:doctor "$@"
'@
        $composeArgs = @('run', '--rm')
        foreach ($override in $overrides) { $composeArgs += @('-e', $override) }
        $composeArgs += @(
            '-e', "JOTTER_INSTALL_DIR=/var/www/html/$installDir",
            '-e', "JOTTER_INSTALL_ZIP=/var/www/html/$($zipPath -replace '\\', '/')",
            'app', 'sh', '-c', $script, 'sh'
        ) + $doctorArgs
        Invoke-Compose -Arguments $composeArgs
    }
    'ci' {
        # Full local CI, mirroring `./scripts/jt.sh ci`: composer install, up, test,
        # e2e, release, release:verify, then down --volumes, in an isolated
        # Compose project with the CI overrides. Summary: dist/ci/last-run.txt;
        # transcript: dist/ci/last-run.log; pass marker: dist/ci/passed-<sha>.
        foreach ($option in $VerbArgs) {
            if ($option -notin @('--allow-dirty', '--dry-run')) {
                throw "Unknown ci option: $option (use --allow-dirty or --dry-run)"
            }
        }
        $allowDirty = $VerbArgs -contains '--allow-dirty'
        $dryRun = $VerbArgs -contains '--dry-run'

        $sha = (& git rev-parse HEAD).Trim()
        $dirty = [bool](& git status --porcelain)
        if ($dirty -and -not $allowDirty) {
            throw "jt ci: the working tree has uncommitted changes, so the result would not describe $($sha.Substring(0, 12)). Commit or stash them, or rerun with --allow-dirty (no pass marker is written then)."
        }

        $projectName = (Get-TestComposeProjectName -RepositoryPath $RootDir) -replace '^jotter-test-', 'jotter-ci-'
        $steps = @('composer install --no-interaction --prefer-dist', 'up', 'test', 'e2e', 'release', 'release:verify')

        if ($dryRun) {
            Write-Output "jt ci (dry run): commit $($sha.Substring(0, 12)), project $projectName, dirty=$([int]$dirty)"
            foreach ($step in $steps) { Write-Output "step: $step" }
            Write-Output 'then: down --volumes --remove-orphans'
            break
        }

        $outDir = 'dist/ci'
        New-Item -ItemType Directory -Force -Path $outDir | Out-Null
        $log = Join-Path $outDir 'last-run.log'
        $summary = Join-Path $outDir 'last-run.txt'
        $marker = Join-Path $outDir "passed-$sha"
        Remove-Item -Force -ErrorAction SilentlyContinue $marker, $log

        $saved = @{}
        foreach ($name in @('JOTTER_CI', 'CI', 'COMPOSE_PROJECT_NAME')) {
            $saved[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
        }
        $env:JOTTER_CI = '1'
        $env:CI = 'true'
        $env:COMPOSE_PROJECT_NAME = $projectName

        $started = (Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ')
        $result = 'passed'
        $failedStep = ''
        $lines = @()

        Start-Transcript -Path $log | Out-Null
        try {
            foreach ($step in $steps) {
                $parts = $step -split ' '
                $label = $parts[0]
                Write-Output "==> jt ci: $step"
                $watch = [Diagnostics.Stopwatch]::StartNew()
                $ok = $true
                try { & $PSCommandPath @parts } catch { $ok = $false; Write-Output $_ }
                $lines += ('{0,-15} {1,-6} {2,4}s' -f $label, $(if ($ok) { 'ok' } else { 'FAIL' }), [int]$watch.Elapsed.TotalSeconds)
                if (-not $ok) {
                    $result = 'failed'
                    $failedStep = $label
                    Write-Output '--- storage/logs/laravel.log (tail) ---'
                    if (Test-Path 'storage/logs/laravel.log') { Get-Content 'storage/logs/laravel.log' -Tail 200 } else { Write-Output '(no laravel.log)' }
                    Write-Output '--- docker compose logs app (tail) ---'
                    $previousErrorActionPreference = $ErrorActionPreference
                    $ErrorActionPreference = 'Continue'
                    try { & docker compose @ComposeFiles logs --tail=200 app } finally { $ErrorActionPreference = $previousErrorActionPreference }
                    break
                }
            }
        } finally {
            try { & $PSCommandPath down --volumes --remove-orphans } catch { Write-Output "teardown failed: $_" }
            Stop-Transcript | Out-Null
            foreach ($name in $saved.Keys) {
                [Environment]::SetEnvironmentVariable($name, $saved[$name], 'Process')
            }
        }

        $branch = (& git rev-parse --abbrev-ref HEAD).Trim()
        $resultLine = if ($failedStep) { "$result (step: $failedStep)" } else { $result }
        $summaryLines = @(
            "result:   $resultLine",
            "commit:   $sha",
            "branch:   $branch",
            "dirty:    $(if ($dirty) { 'yes' } else { 'no' })",
            "started:  $started",
            "finished: $((Get-Date).ToUniversalTime().ToString('yyyy-MM-ddTHH:mm:ssZ'))",
            "project:  $projectName",
            ''
        ) + $lines
        Set-Content -Path $summary -Value $summaryLines
        $summaryLines | Write-Output

        if ($result -ne 'passed') {
            [Console]::Error.WriteLine("jt ci FAILED at $failedStep. Full log: $log")
            exit 1
        }
        if (-not $dirty) { Copy-Item $summary $marker }
        Write-Output "jt ci passed. Paste $summary into the PR."
    }
    'ci:install-hook' {
        & git config core.hooksPath .githooks
        if ($LASTEXITCODE -ne 0) { throw 'git config core.hooksPath failed' }
        Write-Output 'pre-push hook enabled (core.hooksPath=.githooks). Skip once with JOTTER_SKIP_CI=1 git push.'
    }
    { $_ -in @('help', '-h', '--help') } { Show-Usage }
    default { Write-Error "Unknown verb: $Verb"; Show-Usage; exit 1 }
}
