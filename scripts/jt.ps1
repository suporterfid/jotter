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

Verbs: up, down, test, e2e, artisan, composer, npm, release, release:verify, release:doctor
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
        Invoke-WithTestComposeProject -RepositoryPath $RootDir -Action {
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
    }
    'e2e' {
        Invoke-Bootstrap
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
    { $_ -in @('help', '-h', '--help') } { Show-Usage }
    default { Write-Error "Unknown verb: $Verb"; Show-Usage; exit 1 }
}
