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

Verbs: up, down, test, e2e, artisan, composer, npm, release, release:verify
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
        Invoke-Compose -Arguments @('--profile', 'tools', 'run', '--rm', '--build', 'release')

        $zipPath = 'dist/jotter-release.zip'
        $checksumPath = 'dist/jotter-release.zip.sha256'
        if (-not (Test-Path $zipPath) -or -not (Test-Path $checksumPath)) {
            throw 'Release zip or checksum was not produced.'
        }

        $expected = ((Get-Content $checksumPath -Raw).Trim() -split '\s+')[0]
        $actual = (Get-FileHash -Algorithm SHA256 $zipPath).Hash.ToLowerInvariant()
        if ($actual -ne $expected.ToLowerInvariant()) {
            throw 'Release checksum validation failed.'
        }
        Write-Output 'Release written to dist/jotter-release.zip'
    }
    'release:verify' {
        $zipPath = 'dist/jotter-release.zip'
        if (-not (Test-Path $zipPath -PathType Leaf) -or (Get-Item $zipPath).Length -eq 0) {
            throw "Release zip is missing or empty: $zipPath. Run .\scripts\jt.ps1 release first."
        }

        Initialize-Env
        Invoke-Compose -Arguments @(
            'run', '--rm',
            '-e', 'JOTTER_RELEASE_ZIP=/var/www/html/dist/jotter-release.zip',
            'app', 'php', 'artisan', 'test', '--filter=ReleaseZipSecurityTest'
        )
        Write-Output 'Release ZIP security verification passed.'
    }
    { $_ -in @('help', '-h', '--help') } { Show-Usage }
    default { Write-Error "Unknown verb: $Verb"; Show-Usage; exit 1 }
}
