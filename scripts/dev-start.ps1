param(
    [switch]$SkipSeed
)

$ErrorActionPreference = "Stop"

Write-Host "`n=== DocTrack Development Start ===" -ForegroundColor Cyan

Write-Host "`nChecking Git status..." -ForegroundColor Yellow
git status --short

if ($LASTEXITCODE -ne 0) {
    throw "Git status failed."
}

$changes = git status --porcelain

if ($changes) {
    Write-Host "`nWorking tree has local changes." -ForegroundColor Red
    Write-Host "Commit, stash, or restore them before pulling." -ForegroundColor Yellow
    exit 1
}

Write-Host "`nPulling latest code..." -ForegroundColor Yellow
git pull origin main

if ($LASTEXITCODE -ne 0) {
    throw "Git pull failed."
}

Write-Host "`nRunning migrations..." -ForegroundColor Yellow
php artisan migrate --force

if ($LASTEXITCODE -ne 0) {
    throw "Migration failed."
}

if (-not $SkipSeed) {
    Write-Host "`nPreparing development users/offices..." -ForegroundColor Yellow
    php artisan db:seed --class=DevelopmentSeeder

    if ($LASTEXITCODE -ne 0) {
        throw "Development seeding failed."
    }
}

Write-Host "`nClearing Laravel caches..." -ForegroundColor Yellow
php artisan optimize:clear

Write-Host "`nDevelopment environment is ready." -ForegroundColor Green
