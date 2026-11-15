<#
.SYNOPSIS
Enhanced Safe Command Runner (Legacy Path Alias).
Redirects to .agents/bin/safe-run.ps1 or executes with enhanced safety.
#>

[CmdletBinding()]
param (
    [Parameter(Mandatory=$true, Position=0, ValueFromRemainingArguments=$true)]
    [string[]]$Command,

    [Parameter(Mandatory=$false)]
    [int]$TailLines = 35,

    [Parameter(Mandatory=$false)]
    [string]$LogFile = ".workflow_cmd.log"
)

$target = Join-Path $PSScriptRoot "../../.agents/bin/safe-run.ps1"
if (Test-Path $target) {
    & $target -Command $Command -TailLines $TailLines -LogFile $LogFile
    exit $LASTEXITCODE
}

$cmdString = $Command -join " "
$stopwatch = [System.Diagnostics.Stopwatch]::StartNew()
try {
    $process = Start-Process -FilePath "powershell.exe" -ArgumentList "-NoProfile", "-NonInteractive", "-Command", $cmdString -Wait -NoNewWindow -PassThru -RedirectStandardOutput "$LogFile" -RedirectStandardError "$LogFile.err"
    $exitCode = $process.ExitCode
    if (Test-Path "$LogFile.err") {
        Get-Content "$LogFile.err" | Add-Content "$LogFile"
        Remove-Item "$LogFile.err" -Force -ErrorAction SilentlyContinue
    }
} catch {
    Invoke-Expression "$cmdString *>`"$LogFile`""
    $exitCode = $LASTEXITCODE
    if ($null -eq $exitCode) { $exitCode = if ($?) { 0 } else { 1 } }
}
$stopwatch.Stop()
$elapsed = [math]::Round($stopwatch.Elapsed.TotalSeconds, 2)

if ($exitCode -eq 0) {
    Write-Host "[SUCCESS] Completed in ${elapsed}s (Exit Code: 0)" -ForegroundColor Green
    if (Test-Path $LogFile) { Get-Content $LogFile -Tail 3 }
    exit 0
} else {
    Write-Host "[FAILED] Failed in ${elapsed}s (Exit Code: $exitCode)" -ForegroundColor Red
    Write-Host "--- Tail of Log ($LogFile, last $TailLines lines) ---" -ForegroundColor Yellow
    if (Test-Path $LogFile) { Get-Content $LogFile -Tail $TailLines }
    Write-Host "------------------------------------------------------" -ForegroundColor Yellow
    exit $exitCode
}
