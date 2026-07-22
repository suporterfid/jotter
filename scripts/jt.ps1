#requires -Version 5.1
Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

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

    & docker compose @ComposeFiles @Arguments *> $null
    return $LASTEXITCODE -eq 0
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

    Invoke-Compose @('build', 'app')
    $script = @'
echo "APP_KEY=base64:".base64_encode(random_bytes(32)).PHP_EOL;
echo "DB_PASSWORD=".bin2hex(random_bytes(24)).PHP_EOL;
echo "MYSQL_ROOT_PASSWORD=".bin2hex(random_bytes(24)).PHP_EOL;
'@
    $generated = & docker compose @ComposeFiles run --rm --no-deps -T app php -r $script
    if ($LASTEXITCODE -ne 0) {
        throw 'Unable to generate development credentials.'
    }

    $replacements = @{}
    $generated | Where-Object { $_ -match '^(APP_KEY|DB_PASSWORD|MYSQL_ROOT_PASSWORD)=(.+)$' } | ForEach-Object {
        $replacements[$Matches[1]] = $Matches[2]
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
        Invoke-Compose @('run', '--rm', '--no-deps', 'app', 'composer', 'install', '--no-interaction', '--prefer-dist')
    }

    if (-not (Test-ComposeCommand @('--profile', 'dev', 'run', '--rm', '--no-deps', 'node', 'test', '-d', 'node_modules'))) {
        Invoke-Compose @('--profile', 'dev', 'run', '--rm', '--no-deps', 'node', 'npm', 'ci')
    }
}

function Invoke-Bootstrap {
    Initialize-Env
    Invoke-Compose @('up', '-d', '--build', '--wait', 'mysql')
    Install-Dependencies
    Invoke-Compose @('--profile', 'dev', 'run', '--rm', '--no-deps', 'node', 'npm', 'run', 'build')
    Invoke-Compose @('run', '--rm', 'app', 'php', 'artisan', 'migrate', '--force')
}

function Show-Usage {
@'
Jotter Docker toolchain

Usage: .\scripts\jt.ps1 <verb> [args...]

Verbs: up, down, test, e2e, artisan, composer, npm, release
'@ | Write-Output
}

$Verb = if ($args.Count -gt 0) { $args[0] } else { 'help' }
$VerbArgs = if ($args.Count -gt 1) { $args[1..($args.Count - 1)] } else { @() }

switch ($Verb) {
    'up' {
        Invoke-Bootstrap
        Invoke-Compose @('up', '-d', '--build', '--wait', 'app')
        Write-Output 'Jotter is available at http://localhost:8080'
    }
    'down' {
        Initialize-Env
        Invoke-Compose (@('down') + $VerbArgs)
    }
    'test' {
        Invoke-Bootstrap
        Invoke-Compose (@('run', '--rm', 'app', 'php', 'artisan', 'test') + $VerbArgs)
        Invoke-Compose (@('--profile', 'dev', 'run', '--rm', '--no-deps', 'node', 'npm', 'test', '--') + $VerbArgs)
    }
    'e2e' {
        Invoke-Bootstrap
        Invoke-Compose @('up', '-d', '--build', '--wait', 'app')
        Invoke-Compose (@('--profile', 'dev', 'run', '--rm', 'node', 'npm', 'run', 'e2e', '--') + $VerbArgs)
    }
    'artisan' {
        Initialize-Env
        Invoke-Compose (@('run', '--rm', 'app', 'php', 'artisan') + $VerbArgs)
    }
    'composer' {
        Initialize-Env
        Invoke-Compose (@('run', '--rm', '--no-deps', 'app', 'composer') + $VerbArgs)
    }
    'npm' {
        Initialize-Env
        Invoke-Compose (@('--profile', 'dev', 'run', '--rm', '--no-deps', 'node', 'npm') + $VerbArgs)
    }
    'release' {
        Initialize-Env
        New-Item -ItemType Directory -Force -Path 'dist' | Out-Null
        Invoke-Compose @('--profile', 'tools', 'run', '--rm', '--build', 'release')

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
    { $_ -in @('help', '-h', '--help') } { Show-Usage }
    default { Write-Error "Unknown verb: $Verb"; Show-Usage; exit 1 }
}
