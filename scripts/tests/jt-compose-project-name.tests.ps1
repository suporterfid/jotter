$ErrorActionPreference = 'Stop'

. (Join-Path $PSScriptRoot '..\jt-compose.ps1')

function Assert-True {
    param(
        [Parameter(Mandatory = $true)][bool]$Condition,
        [Parameter(Mandatory = $true)][string]$Message
    )

    if (-not $Condition) {
        throw $Message
    }
}

$first = Get-TestComposeProjectName -RepositoryPath 'C:\work\jotter-a'
$same = Get-TestComposeProjectName -RepositoryPath 'C:\work\jotter-a'
$second = Get-TestComposeProjectName -RepositoryPath 'C:\work\jotter-b'

Assert-True ($first -eq $same) 'The same path must have a stable project name.'
Assert-True ($first -ne $second) 'Different paths must have different project names.'
Assert-True ($first -match '^jotter-test-[0-9a-f]{12}$') 'The project name must be Docker-safe.'

$env:COMPOSE_PROJECT_NAME = 'caller-project'
Invoke-WithTestComposeProject -RepositoryPath 'C:\work\jotter-a' -Action {
    Assert-True ($env:COMPOSE_PROJECT_NAME -eq $first) 'The action must receive its test project.'
}
Assert-True ($env:COMPOSE_PROJECT_NAME -eq 'caller-project') 'The caller project must be restored after success.'

try {
    Invoke-WithTestComposeProject -RepositoryPath 'C:\work\jotter-a' -Action { throw 'expected failure' }
} catch {
    Assert-True ($_.Exception.Message -eq 'expected failure') 'The action error must be preserved.'
}
Assert-True ($env:COMPOSE_PROJECT_NAME -eq 'caller-project') 'The caller project must be restored after failure.'

Remove-Item Env:COMPOSE_PROJECT_NAME
