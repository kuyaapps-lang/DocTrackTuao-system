$ErrorActionPreference = "Stop"

$ProjectRoot = Split-Path -Parent $PSScriptRoot
$BackupDir = Join-Path $ProjectRoot "storage\dev-db-backups"
$MySqlDump = "C:\xampp\mysql\bin\mysqldump.exe"
$Database = "doctrack_tuao"

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

Write-Host "`nBackup completed successfully." -ForegroundColor Green
Write-Host "File: $BackupFile"
Write-Host "Size: $Size bytes"
