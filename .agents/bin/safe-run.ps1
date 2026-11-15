<#
.SYNOPSIS
Enhanced Safe Command Runner for Windows PowerShell.
Truncates output to prevent token window overflow in AI agent transcripts.

.PARAMETER Command
The command to execute (captures remaining arguments if quotes split).

.PARAMETER TailLines
Number of lines to show from log tail on failure (default: 35).

.PARAMETER LogFile
Log file path (default: .workflow_cmd.log).
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

$cmdString = $Command -join " "
$stopwatch = [System.Diagnostics.Stopwatch]::StartNew()

try {
    # Execute the command and capture all streams into log file
    $process = Start-Process -FilePath "powershell.exe" -ArgumentList "-NoProfile", "-NonInteractive", "-Command", $cmdString -Wait -NoNewWindow -PassThru -RedirectStandardOutput "$LogFile" -RedirectStandardError "$LogFile.err"
    $exitCode = $process.ExitCode

    if (Test-Path "$LogFile.err") {
        Get-Content "$LogFile.err" | Add-Content "$LogFile"
        Remove-Item "$LogFile.err" -Force -ErrorAction SilentlyContinue
    }
} catch {
    Invoke-Expression "$cmdString *>`"$LogFile`""
    $exitCode = $LASTEXITCODE
    if ($null -eq $exitCode) {
        $exitCode = if ($?) { 0 } else { 1 }
    }
}

$stopwatch.Stop()
$elapsed = [math]::Round($stopwatch.Elapsed.TotalSeconds, 2)

if ($exitCode -eq 0) {
    Write-Host "[SUCCESS] Completed in ${elapsed}s (Exit Code: 0)" -ForegroundColor Green
    if (Test-Path $LogFile) {
        Get-Content $LogFile -Tail 3 | ForEach-Object { Write-Host "  $_" -ForegroundColor DarkGray }
    }
    exit 0
} else {
    Write-Host "[FAILED] Failed in ${elapsed}s (Exit Code: $exitCode)" -ForegroundColor Red
    Write-Host "--- Tail of Log ($LogFile, last $TailLines lines) ---" -ForegroundColor Yellow
    if (Test-Path $LogFile) {
        Get-Content $LogFile -Tail $TailLines | ForEach-Object { Write-Host "  $_" -ForegroundColor Yellow }
    }
    Write-Host "------------------------------------------------------" -ForegroundColor Yellow
    exit $exitCode
}
