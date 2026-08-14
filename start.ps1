$ErrorActionPreference = 'Stop'

$repoRoot = $PSScriptRoot
$xamppRoot = Split-Path (Split-Path $repoRoot -Parent) -Parent
$htdocsRoot = Join-Path $xamppRoot 'htdocs'
$httpd = Join-Path $xamppRoot 'apache\bin\httpd.exe'
$mysqld = Join-Path $xamppRoot 'mysql\bin\mysqld.exe'
$apacheStart = Join-Path $xamppRoot 'apache_start.bat'
$mysqlStart = Join-Path $xamppRoot 'mysql_start.bat'

foreach ($requiredPath in @($httpd, $mysqld, $apacheStart, $mysqlStart)) {
    if (-not (Test-Path -LiteralPath $requiredPath)) {
        throw "Required XAMPP component was not found at $requiredPath"
    }
}

if (-not $repoRoot.StartsWith($htdocsRoot, [System.StringComparison]::OrdinalIgnoreCase)) {
    throw "The repository must be inside XAMPP htdocs: $htdocsRoot"
}

function Test-XamppProcess([string] $name, [string] $expectedPath) {
    return @(
        Get-Process -Name $name -ErrorAction SilentlyContinue |
            Where-Object {
                $_.Path -and
                $_.Path.Equals($expectedPath, [System.StringComparison]::OrdinalIgnoreCase)
            }
    ).Count -gt 0
}

if (-not (Test-XamppProcess 'mysqld' $mysqld)) {
    Start-Process -FilePath $mysqlStart -WindowStyle Hidden
}

if (-not (Test-XamppProcess 'httpd' $httpd)) {
    Start-Process -FilePath $apacheStart -WindowStyle Hidden
}

for ($attempt = 0; $attempt -lt 40; $attempt++) {
    $mysqlReady = Test-XamppProcess 'mysqld' $mysqld
    $apacheReady = Test-XamppProcess 'httpd' $httpd
    if ($mysqlReady -and $apacheReady) {
        break
    }
    Start-Sleep -Milliseconds 250
}

if (-not $mysqlReady) {
    throw 'XAMPP MySQL did not start. Check the XAMPP Control Panel for a port error.'
}
if (-not $apacheReady) {
    throw 'XAMPP Apache did not start. Check the XAMPP Control Panel for a port error.'
}

$relativeProjectPath = $repoRoot.Substring($htdocsRoot.Length).TrimStart('\', '/') -replace '\\', '/'
$appUrl = "http://localhost/$relativeProjectPath/frontend/"

Write-Host "Book Illustration Studio: $appUrl"
Write-Host 'XAMPP Apache and MySQL are running. Stop them from the XAMPP Control Panel.'
