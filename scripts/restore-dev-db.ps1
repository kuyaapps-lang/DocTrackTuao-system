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

if (-not (Test-Path $MySql)) {
    throw "mysql.exe not found at $MySql"
}

if (-not (Test-Path $MySqlDump)) {
    throw "mysqldump.exe not found at $MySqlDump"
}

$BackupFile = (Resolve-Path $BackupFile).Path

Write-Host "`n=== DocTrack Database Restore ===" -ForegroundColor Cyan
Write-Host "Target DB: $Database"
Write-Host "Source:    $BackupFile"

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

Write-Host "Safety backup created:" -ForegroundColor Green
Write-Host $SafetyBackup

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

