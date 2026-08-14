$ErrorActionPreference = 'Stop'

$repoRoot = $PSScriptRoot
$xamppRoot = Split-Path (Split-Path $repoRoot -Parent) -Parent
$php = Join-Path $xamppRoot 'php\php.exe'
$mysql = Join-Path $xamppRoot 'mysql\bin\mysql.exe'
$nodeCommand = Get-Command node -ErrorAction SilentlyContinue

if (-not (Test-Path -LiteralPath $php)) {
    throw "PHP was not found at $php"
}

if (-not (Test-Path -LiteralPath $mysql)) {
    throw "MySQL client was not found at $mysql"
}

if (-not $nodeCommand) {
    throw 'Node.js is required for frontend tests.'
}

$testDb = "book_illustration_studio_test_$PID"
if ($testDb -notmatch '^book_illustration_studio_test_[0-9]+$') {
    throw 'Unsafe test database name.'
}

$tempRoot = [System.IO.Path]::GetFullPath([System.IO.Path]::GetTempPath())
$testStorage = [System.IO.Path]::GetFullPath(
    (Join-Path $tempRoot "bis-test-storage-$PID")
)
if (-not $testStorage.StartsWith($tempRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw 'Unsafe test storage path.'
}
$sessionPath = [System.IO.Path]::GetTempPath().TrimEnd('\')
$port = 18765
$server = $null

try {
    $schema = (Get-Content -Raw (Join-Path $repoRoot 'database\schema.sql')).Replace(
        'book_illustration_studio',
        $testDb
    )
    $schema | & $mysql --host=127.0.0.1 --port=3306 --user=root
    if ($LASTEXITCODE -ne 0) {
        throw 'Could not create the isolated test database.'
    }

    New-Item -ItemType Directory -Path $testStorage -Force | Out-Null

    $env:DB_HOST = '127.0.0.1'
    $env:DB_PORT = '3306'
    $env:DB_NAME = $testDb
    $env:DB_USER = 'root'
    $env:DB_PASSWORD = ''
    $env:GEMINI_PROVIDER = 'mock'
    # The test server itself allows only one second. Each mocked generation is
    # deliberately slower, proving run-step.php extends its own execution budget.
    $env:MOCK_LATENCY_MS = '1100'
    $env:PIPELINE_EXECUTION_TIMEOUT_SECONDS = '5'
    # Keep stale recovery fast and deterministic in the isolated test server.
    $env:STEP_STALE_SECONDS = '300'
    $env:STORAGE_ROOT = $testStorage
    $env:SMOKE_BASE_URL = "http://127.0.0.1:$port"
    $env:SMOKE_PHP = $php

    $server = Start-Process `
        -FilePath $php `
        -ArgumentList '-d', "session.save_path=$sessionPath", '-d', 'max_execution_time=1', '-S', "127.0.0.1:$port" `
        -WorkingDirectory $repoRoot `
        -WindowStyle Hidden `
        -PassThru

    Start-Sleep -Milliseconds 500
    if ($server.HasExited) {
        throw 'The PHP test server did not start.'
    }

    & $nodeCommand.Source (Join-Path $repoRoot 'tests\frontend\state.test.js')
    if ($LASTEXITCODE -ne 0) {
        throw 'Frontend tests failed.'
    }

    & $php (Join-Path $repoRoot 'tests\backend\real-provider.php')
    if ($LASTEXITCODE -ne 0) {
        throw 'Real Gemini provider contract tests failed.'
    }

    & $php '-d' "session.save_path=$sessionPath" (Join-Path $repoRoot 'tests\backend\smoke.php')
    if ($LASTEXITCODE -ne 0) {
        throw 'Backend tests failed.'
    }
}
finally {
    if ($server -and -not $server.HasExited) {
        Stop-Process -Id $server.Id -Force
    }

    & $mysql --host=127.0.0.1 --port=3306 --user=root --execute="DROP DATABASE IF EXISTS $testDb" 2>$null

    if (Test-Path -LiteralPath $testStorage) {
        Remove-Item -LiteralPath $testStorage -Recurse -Force
    }
}
