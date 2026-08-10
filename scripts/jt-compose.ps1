function Get-TestComposeProjectName {
    param(
        [Parameter(Mandatory = $true)]
        [string]$RepositoryPath
    )

    $normalizedPath = [IO.Path]::GetFullPath($RepositoryPath).TrimEnd('\', '/').ToLowerInvariant()
    $sha256 = [Security.Cryptography.SHA256]::Create()
    try {
        $hash = -join ($sha256.ComputeHash([Text.Encoding]::UTF8.GetBytes($normalizedPath)) | ForEach-Object {
            $_.ToString('x2')
        })
    } finally {
        $sha256.Dispose()
    }

    return "jotter-test-$($hash.Substring(0, 12))"
}

function Invoke-WithTestComposeProject {
    param(
        [Parameter(Mandatory = $true)]
        [string]$RepositoryPath,

        [Parameter(Mandatory = $true)]
        [scriptblock]$Action
    )

    $previousProjectName = [Environment]::GetEnvironmentVariable('COMPOSE_PROJECT_NAME', 'Process')
    $testProjectName = Get-TestComposeProjectName -RepositoryPath $RepositoryPath
    [Environment]::SetEnvironmentVariable('COMPOSE_PROJECT_NAME', $testProjectName, 'Process')

    try {
        & $Action
    } finally {
        [Environment]::SetEnvironmentVariable('COMPOSE_PROJECT_NAME', $previousProjectName, 'Process')
    }
}
