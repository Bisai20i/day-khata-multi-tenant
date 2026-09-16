param (
    [Parameter(Mandatory=$true)]
    [string]$Command
)

$logFile = ".workflow_cmd.log"

# Run command and redirect all output (stdout and stderr) to log file
Invoke-Expression "$Command *>`"$logFile`""
$exitCode = $LASTEXITCODE

if ($null -eq $exitCode) {
    if ($?) { $exitCode = 0 } else { $exitCode = 1 }
}

if ($exitCode -eq 0) {
    Write-Host "[SUCCESS] Command completed with exit code 0." -ForegroundColor Green
    if (Test-Path $logFile) {
        Get-Content $logFile -Tail 3
    }
    exit 0
} else {
    Write-Host "[FAILED] Command failed with exit code $exitCode." -ForegroundColor Red
    Write-Host "--- Tail of Log ($logFile) ---" -ForegroundColor Yellow
    if (Test-Path $logFile) {
        Get-Content $logFile -Tail 35
    }
    Write-Host "-------------------------------" -ForegroundColor Yellow
    exit $exitCode
}
