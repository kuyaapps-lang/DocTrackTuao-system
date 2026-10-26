<#
.SYNOPSIS
Displays the current private LAN address and can start Laravel's temporary
development server on all interfaces.

.DESCRIPTION
Use -DevelopmentServer only for short, trusted-network testing. It serves HTTP
and therefore cannot provide live mobile-camera access on a LAN. For ordinary
operation use Caddy + Apache as documented in PORTABLE_LAN_SETUP.md.
#>
[CmdletBinding()]
param(
    [switch]$DevelopmentServer,
    [ValidateRange(1024, 65535)]
    [int]$Port = 8000
)

$ErrorActionPreference = 'Stop'
$projectRoot = $PSScriptRoot

function Get-PrivateIpv4Address {
    $candidates = Get-NetIPAddress -AddressFamily IPv4 -ErrorAction Stop |
        Where-Object {
            $_.IPAddress -notlike '127.*' -and
            $_.IPAddress -notlike '169.254.*' -and
            $_.AddressState -eq 'Preferred' -and
            (
                $_.IPAddress -like '10.*' -or
                $_.IPAddress -like '192.168.*' -or
                $_.IPAddress -match '^172\.(1[6-9]|2[0-9]|3[0-1])\.'
            )
        } |
        Sort-Object -Property InterfaceMetric

    return $candidates | Select-Object -First 1
}

$address = Get-PrivateIpv4Address
if ($null -eq $address) {
    throw 'No active private IPv4 address was found. Connect to a trusted Wi-Fi, Ethernet, or hotspot network, then run this script again.'
}

Write-Host ''
Write-Host 'DocTrack portable LAN status' -ForegroundColor Cyan
Write-Host "Active adapter: $($address.InterfaceAlias)"
Write-Host "Current LAN address: $($address.IPAddress)"
Write-Host "Temporary HTTP test URL: http://$($address.IPAddress):$Port"
Write-Host 'Production HTTPS URL: https://doctrack.home.arpa (after local DNS and Caddy are configured)'
Write-Host ''

if (-not $DevelopmentServer) {
    Write-Host 'Nothing was started. Add -DevelopmentServer only for short HTTP testing.' -ForegroundColor Yellow
    Write-Host 'For mobile live-camera scanning, use the Caddy HTTPS setup instead.' -ForegroundColor Yellow
    exit 0
}

if (-not (Test-Path (Join-Path $projectRoot 'artisan'))) {
    throw 'Could not find Laravel artisan in the script directory.'
}

Write-Warning 'This starts Laravel''s development server on every interface. Do not use it as the regular LAN service.'
Write-Warning 'Do not create a public firewall rule or use this on an untrusted network.'
Push-Location $projectRoot
try {
    & php artisan serve --host=0.0.0.0 --port=$Port
} finally {
    Pop-Location
}
