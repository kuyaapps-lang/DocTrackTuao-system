$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $PSScriptRoot
$BackupDir = Join-Path $ProjectRoot "storage\dev-db-backups"
$MySqlDump = "C:\xampp\mysql\bin\mysqldump.exe"
$Database = "doctrack_tuao"

function Test-DumpHeader {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,

        [Parameter(Mandatory = $true)]
        [string]$DatabaseName
    )

    $Header = Get-Content -Path $Path -TotalCount 20

    return (($Header -match "^(-- )?(MariaDB|MySQL) dump").Count -gt 0) -and
        (($Header -match "Database:\s+$([regex]::Escape($DatabaseName))").Count -gt 0)
}

function Test-DumpCompletionMarker {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path
    )

    $Tail = Get-Content -Path $Path -Tail 20

    return ($Tail -match "^-- Dump completed on ").Count -gt 0
}

function Test-GitIgnored {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path
    )

    Push-Location $ProjectRoot
    try {
        & git check-ignore --quiet -- $Path
        return $LASTEXITCODE -eq 0
    } finally {
        Pop-Location
    }
}

if (-not (Test-Path $MySqlDump)) {
    throw "mysqldump.exe not found at $MySqlDump"
}

New-Item -ItemType Directory -Path $BackupDir -Force | Out-Null

$Timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$BackupFile = Join-Path $BackupDir "${Database}_${Timestamp}.sql"

Write-Host "`n=== DocTrack Database Backup ===" -ForegroundColor Cyan
Write-Host "Database: $Database"
Write-Host "Output:   $BackupFile"

& $MySqlDump `
    -h 127.0.0.1 `
    -P 3306 `
    --protocol=TCP `
    -u root `
    --routines `
    --triggers `
    --single-transaction `
    --default-character-set=utf8mb4 `
    $Database `
    --result-file="$BackupFile"

if ($LASTEXITCODE -ne 0) {
    throw "Database backup failed."
}

if (-not (Test-Path $BackupFile)) {
    throw "Backup file was not created."
}

$Size = (Get-Item $BackupFile).Length

if ($Size -le 0) {
    throw "Backup file is empty."
}

if (-not (Test-DumpHeader -Path $BackupFile -DatabaseName $Database)) {
    throw "Backup file does not contain the expected dump header for $Database."
}

if (-not (Test-DumpCompletionMarker -Path $BackupFile)) {
    throw "Backup file does not contain a dump completion marker."
}

$Sha256 = (Get-FileHash -Path $BackupFile -Algorithm SHA256).Hash

if (-not (Test-GitIgnored -Path $BackupFile)) {
    throw "Backup file is not ignored by Git. Refusing to treat it as verified."
}

Write-Host "`nBackup completed successfully." -ForegroundColor Green
Write-Host "File: $BackupFile"
Write-Host "Size: $Size bytes"
Write-Host "SHA-256: $Sha256"
Write-Host "Git ignored: yes"
Write-Host "Warning: SQL backups may contain sensitive document, user, audit, and token-related data." -ForegroundColor Yellow
