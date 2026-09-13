[CmdletBinding()]
param(
    [ValidateSet('Offline', 'Provision', 'Run', 'Cleanup')]
    [string]$Mode = 'Offline',
    [string]$Php = 'C:\xampp\php\php.exe'
)

$ErrorActionPreference = 'Stop'
$projectRoot = [IO.Path]::GetFullPath((Join-Path $PSScriptRoot '..'))
if ([Environment]::OSVersion.Platform -ne [PlatformID]::Win32NT) {
    throw 'This entry point requires Windows and NTFS private-directory ACLs.'
}
if (-not (Test-Path -LiteralPath $Php -PathType Leaf)) { throw 'PHP executable missing.' }

function Read-PrivateText([string]$Prompt) {
    $secureValue = Read-Host $Prompt -AsSecureString
    $privatePointer = [Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureValue)
    try { [Runtime.InteropServices.Marshal]::PtrToStringBSTR($privatePointer) }
    finally {
        [Runtime.InteropServices.Marshal]::ZeroFreeBSTR($privatePointer)
        $secureValue.Dispose()
    }
}

$tempParent = [IO.Path]::GetFullPath([IO.Path]::GetTempPath()).TrimEnd('\')
$privateLeaf = 'doctrack-10b-' + [Guid]::NewGuid().ToString('N')
$privateDirectory = Join-Path $tempParent $privateLeaf
$process = $null
$processStarted = $false
$inputData = $null
$preserveBarriers = $false
try {
    # No credentials/results are stored here. Restrict even the barrier metadata.
    $directory = New-Item -ItemType Directory -Path $privateDirectory
    $acl = New-Object Security.AccessControl.DirectorySecurity
    $acl.SetAccessRuleProtection($true, $false)
    $identity = [Security.Principal.WindowsIdentity]::GetCurrent().User
    $rule = New-Object Security.AccessControl.FileSystemAccessRule($identity, 'FullControl', 'ContainerInherit,ObjectInherit', 'None', 'Allow')
    $acl.AddAccessRule($rule)
    Set-Acl -LiteralPath $directory.FullName -AclObject $acl

    $keyBytes = New-Object byte[] 32
    $random = [Security.Cryptography.RandomNumberGenerator]::Create()
    try { $random.GetBytes($keyBytes) } finally { $random.Dispose() }
    $inputData = @{
        mode = $Mode.ToLowerInvariant()
        private_directory = $privateDirectory
        config = @{
            opt_in = 'PROCESS10B'; environment = 'testing'; host = '127.0.0.1'; port = 3306
            database = 'doctrack_10b_disposable'; username = 'doctrack10b_runner'
            password = 'offline-placeholder'; app_key = 'base64:' + [Convert]::ToBase64String($keyBytes)
        }
    }
    if ($Mode -ne 'Offline') {
        $inputData.config.password = Read-PrivateText 'Dedicated DocTrack 10B runner password (never the application password)'
    }
    if ($Mode -in @('Provision', 'Cleanup')) {
        $inputData.admin_user = Read-PrivateText 'Provisioning administrator account (used only for explicit provisioning/cleanup)'
        $inputData.admin_password = Read-PrivateText 'Provisioning administrator password'
        $expectedConfirmation = if ($Mode -eq 'Provision') { 'PROVISION EMPTY PROCESS10B' } else { 'DROP DISPOSABLE PROCESS10B' }
        $inputData.confirmation = Read-Host "Type $expectedConfirmation"
        if ($inputData.confirmation -cne $expectedConfirmation) { throw 'Confirmation did not match.' }
    }

    $start = New-Object Diagnostics.ProcessStartInfo
    $start.FileName = [IO.Path]::GetFullPath($Php)
    $start.WorkingDirectory = $projectRoot
    $start.UseShellExecute = $false
    $start.CreateNoWindow = $true
    $start.RedirectStandardInput = $true
    $start.RedirectStandardOutput = $true
    $start.RedirectStandardError = $true
    $start.EnvironmentVariables['APP_ENV'] = 'testing'
    $start.Arguments = '-d display_errors=0 -d log_errors=0 tests/Concurrency/Support/Worker.php'
    if ($Mode -eq 'Run') {
        $start.EnvironmentVariables['PROCESS10B_OPT_IN'] = 'PROCESS10B'
        $start.Arguments = '-d display_errors=0 -d log_errors=0 vendor/bin/phpunit -c phpunit.concurrency.xml --do-not-cache-result'
    }
    $process = New-Object Diagnostics.Process
    $process.StartInfo = $start
    if (-not $process.Start()) { throw 'Unable to start owned PHP process.' }
    $processStarted = $true
    $outputTask = $process.StandardOutput.ReadToEndAsync()
    $errorTask = $process.StandardError.ReadToEndAsync()
    $process.StandardInput.Write(($inputData | ConvertTo-Json -Depth 8 -Compress))
    $process.StandardInput.Close()
    $inputData = $null
    $parentDeadline = [DateTime]::UtcNow.AddMinutes(5)
    while (-not $process.WaitForExit(1000) -and [DateTime]::UtcNow -lt $parentDeadline) { }
    if (-not $process.HasExited) {
        # Parent PHP has per-worker barriers/timeouts; do not kill unrelated php.exe processes.
        $preserveBarriers = $true
        $process.Kill()
        throw '10B parent exceeded its deadline. Barriers retained; worker exit and disposable rows require review before reuse.'
    }
    Write-Output $outputTask.GetAwaiter().GetResult()
    $safeError = $errorTask.GetAwaiter().GetResult()
    if ($safeError) { Write-Output $safeError }
    if ($process.ExitCode -ne 0) {
        $preserveBarriers = $true
        throw '10B checks did not pass. Remaining barriers retained; no concurrency result may be claimed.'
    }
}
finally {
    $inputData = $null
    if ($process) {
        if ($processStarted -and -not $process.HasExited) {
            $preserveBarriers = $true
            $process.Kill()
            $process.WaitForExit(5000) | Out-Null
        }
        $process.Dispose()
    }
    # Verify the exact created directory remains beneath the intended temp parent
    # before recursive cleanup. Never accept an input path or follow a reparse point.
    $resolvedPrivate = [IO.Path]::GetFullPath($privateDirectory)
    if ([IO.Path]::GetDirectoryName($resolvedPrivate) -ne $tempParent -or
        [IO.Path]::GetFileName($resolvedPrivate) -ne $privateLeaf) {
        throw 'Refusing cleanup outside the owned temporary directory.'
    }
    if ((Test-Path -LiteralPath $resolvedPrivate) -and -not $preserveBarriers) {
        $entry = Get-Item -LiteralPath $resolvedPrivate
        if ($entry.Attributes -band [IO.FileAttributes]::ReparsePoint) { throw 'Refusing reparse-point cleanup.' }
        Remove-Item -LiteralPath $resolvedPrivate -Recurse -Force
    }
    elseif ($preserveBarriers) {
        Write-Warning "10B retained private barrier directory: $resolvedPrivate"
    }
}
