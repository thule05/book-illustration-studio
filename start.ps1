$ErrorActionPreference = 'Stop'

$repoRoot = $PSScriptRoot
$xamppRoot = Split-Path (Split-Path $repoRoot -Parent) -Parent
$php = Join-Path $xamppRoot 'php\php.exe'

if (-not (Test-Path -LiteralPath $php)) {
    throw "PHP was not found at $php"
}

Write-Host 'Book Illustration Studio: http://127.0.0.1:8000/frontend/'
Write-Host 'MySQL must be running and database/schema.sql must be imported.'

& $php -S 127.0.0.1:8000 -t $repoRoot
