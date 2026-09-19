param(
    [Parameter(Mandatory = $true)]
    [string]$BackupFile
)

$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $PSScriptRoot
$SafetyDir = Join-Path $ProjectRoot "storage\dev-db-backups"
$MySql = "C:\xampp\mysql\bin\mysql.exe"
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

function Confirm-DumpFile {
    param(
        [Parameter(Mandatory = $true)]
        [string]$Path,

        [Parameter(Mandatory = $true)]
        [string]$DatabaseName,

        [Parameter(Mandatory = $true)]
        [string]$Label
    )

    if (-not (Test-Path $Path)) {
        throw "$Label file was not found: $Path"
    }

    $Size = (Get-Item $Path).Length

    if ($Size -le 0) {
        throw "$Label file is empty: $Path"
    }

    if (-not (Test-DumpHeader -Path $Path -DatabaseName $DatabaseName)) {
        throw "$Label file does not contain the expected dump header for $DatabaseName."
    }

    if (-not (Test-DumpCompletionMarker -Path $Path)) {
        throw "$Label file does not contain a dump completion marker."
    }

    $Sha256 = (Get-FileHash -Path $Path -Algorithm SHA256).Hash

    return [pscustomobject]@{
        Path = $Path
        Size = $Size
        Sha256 = $Sha256
    }
}

if (-not (Test-Path $MySql)) {
    throw "mysql.exe not found at $MySql"
}

if (-not (Test-Path $MySqlDump)) {
    throw "mysqldump.exe not found at $MySqlDump"
}

$BackupFile = (Resolve-Path $BackupFile).Path
$SourceInfo = Confirm-DumpFile -Path $BackupFile -DatabaseName $Database -Label "Source backup"

Write-Host "`n=== DocTrack Database Restore ===" -ForegroundColor Cyan
Write-Host "Target DB: $Database"
Write-Host "Source:    $BackupFile"
Write-Host "Source size: $($SourceInfo.Size) bytes"
Write-Host "Source SHA-256: $($SourceInfo.Sha256)"
Write-Host "Warning: SQL backups may contain sensitive document, user, audit, and token-related data." -ForegroundColor Yellow

Write-Host "`nWARNING:" -ForegroundColor Yellow
Write-Host "The current $Database database will be replaced by the backup." -ForegroundColor Yellow

$Confirmation = Read-Host "Type RESTORE to continue"

if ($Confirmation -ne "RESTORE") {
    Write-Host "Restore cancelled." -ForegroundColor Yellow
    exit 0
}

New-Item -ItemType Directory -Path $SafetyDir -Force | Out-Null

$Timestamp = Get-Date -Format "yyyyMMdd_HHmmss"
$SafetyBackup = Join-Path $SafetyDir "${Database}_before_restore_${Timestamp}.sql"

Write-Host "`nCreating safety backup first..." -ForegroundColor Yellow

& $MySqlDump `
    -u root `
    --routines `
    --triggers `
    --single-transaction `
    --default-character-set=utf8mb4 `
    $Database `
    --result-file="$SafetyBackup"

if ($LASTEXITCODE -ne 0) {
    throw "Safety backup failed. Restore stopped."
}

$SafetyInfo = Confirm-DumpFile -Path $SafetyBackup -DatabaseName $Database -Label "Safety backup"

Write-Host "Safety backup created:" -ForegroundColor Green
Write-Host $SafetyBackup
Write-Host "Safety backup size: $($SafetyInfo.Size) bytes"
Write-Host "Safety backup SHA-256: $($SafetyInfo.Sha256)"

Write-Host "`nDropping and recreating development database..." -ForegroundColor Yellow

& $MySql -u root -e "DROP DATABASE IF EXISTS $Database; CREATE DATABASE $Database CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

if ($LASTEXITCODE -ne 0) {
    throw "Unable to recreate database."
}

Write-Host "`nRestoring backup..." -ForegroundColor Yellow

Get-Content -Raw $BackupFile | & $MySql -u root $Database

if ($LASTEXITCODE -ne 0) {
    throw "Database restore failed."
}

Write-Host "`nClearing Laravel caches..." -ForegroundColor Yellow
Set-Location $ProjectRoot
php artisan optimize:clear

Write-Host "`nRestore completed successfully." -ForegroundColor Green
Write-Host "Database: $Database"
Write-Host "Source:   $BackupFile"

