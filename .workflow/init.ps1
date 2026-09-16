<#
.SYNOPSIS
Universal Agent Workflow Initializer
Sets up root entry points, auto-detects Laravel/Vue stack, updates .gitignore,
and configures Claude Code subagents.
#>

param (
    [string]$TargetDir = ""
)

# 1. Determine Project Root
if ([string]::IsNullOrWhiteSpace($TargetDir)) {
    # If run from inside workflow directory, root is parent; otherwise current dir
    if (Test-Path "AGENTS.md" -PathType Leaf) {
        $RootPath = (Get-Item $PSScriptRoot).Parent.FullName
        $WorkflowFolderName = (Get-Item $PSScriptRoot).Name
    } else {
        $RootPath = (Get-Location).Path
        $WorkflowFolderName = if (Test-Path "$RootPath/.workflow") { ".workflow" } else { "workflow" }
    }
} else {
    $RootPath = (Resolve-Path $TargetDir).Path
    $WorkflowFolderName = if (Test-Path "$RootPath/.workflow") { ".workflow" } else { "workflow" }
}

Write-Host "==========================================================" -ForegroundColor Cyan
Write-Host "   INITIALIZING UNIVERSAL AGENT WORKFLOW                 " -ForegroundColor Cyan
Write-Host "   Project Root: $RootPath" -ForegroundColor Gray
Write-Host "   Workflow Dir: $WorkflowFolderName" -ForegroundColor Gray
Write-Host "==========================================================" -ForegroundColor Cyan

# 2. Create Universal Root Pointers
$PointerContent = @"
# Universal AI Agent Instructions
Read and strictly adhere to `$WorkflowFolderName/AGENTS.md` for all workflow rules,
token preservation guardrails, and command policies.
"@

$RootPointers = @("AGENTS.md", "CLAUDE.md", ".cursorrules")
foreach ($file in $RootPointers) {
    $dest = Join-Path $RootPath $file
    Set-Content -Path $dest -Value $PointerContent -Force
    Write-Host "[OK] Created root pointer: $file" -ForegroundColor Green
}

# 3. Auto-Detect Laravel & Vue Stack
Write-Host "`nDetecting project stack..." -ForegroundColor Yellow
$StackDetails = @()

$composerPath = Join-Path $RootPath "composer.json"
if (Test-Path $composerPath) {
    $composer = Get-Content $composerPath -Raw | ConvertFrom-Json
    $laravelVer = $composer.require.'laravel/framework'
    if ($laravelVer) { $StackDetails += "- Backend: Laravel ($laravelVer)" }
    if ($composer.'require-dev'.'pestphp/pest') { $StackDetails += "- Test Runner: Pest PHP" }
    elseif ($composer.'require-dev'.'phpunit/phpunit') { $StackDetails += "- Test Runner: PHPUnit" }
    if ($composer.'require-dev'.'laravel/pint') { $StackDetails += "- Code Style: Laravel Pint" }
}

$packagePath = Join-Path $RootPath "package.json"
if (Test-Path $packagePath) {
    $pkg = Get-Content $packagePath -Raw | ConvertFrom-Json
    $allDeps = @{}
    if ($pkg.dependencies) { $pkg.dependencies.PSObject.Properties | ForEach-Object { $allDeps[$_.Name] = $_.Value } }
    if ($pkg.devDependencies) { $pkg.devDependencies.PSObject.Properties | ForEach-Object { $allDeps[$_.Name] = $_.Value } }

    if ($allDeps.ContainsKey('@inertiajs/vue3')) { $StackDetails += "- Frontend Driver: Inertia.js Vue 3" }
    elseif ($allDeps.ContainsKey('vue')) { $StackDetails += "- Frontend: Vue.js ($($allDeps['vue']))" }
    if ($allDeps.ContainsKey('tailwindcss')) { $StackDetails += "- Styling: Tailwind CSS" }
    if ($allDeps.ContainsKey('typescript')) { $StackDetails += "- Language: TypeScript" }
}

if ($StackDetails.Count -gt 0) {
    Write-Host "Detected Stack:" -ForegroundColor Cyan
    $StackDetails | ForEach-Object { Write-Host "  $_" -ForegroundColor Gray }
}

# 4. Update .gitignore with Workflow Artifacts
$gitignorePath = Join-Path $RootPath ".gitignore"
$ignorePatterns = @(
    "",
    "# --- Agent Workflow Artifacts ---",
    ".workflow_cmd.log",
    ".build.log",
    ".test.log",
    "workflow/progress.md.bak",
    ".workflow/progress.md.bak"
)

if (Test-Path $gitignorePath) {
    $currentIgnore = Get-Content $gitignorePath -Raw
    $toAdd = @()
    foreach ($pat in $ignorePatterns) {
        if ($pat -ne "" -and -not $pat.StartsWith("#") -and -not ($currentIgnore -match [regex]::Escape($pat))) {
            $toAdd += $pat
        }
    }
    if ($toAdd.Count -gt 0) {
        Add-Content -Path $gitignorePath -Value ($ignorePatterns -join "`r`n")
        Write-Host "[OK] Added temporary workflow artifacts to .gitignore" -ForegroundColor Green
    } else {
        Write-Host "[OK] .gitignore already contains workflow patterns" -ForegroundColor Gray
    }
}

# 5. Install Claude Code Subagents
$claudeAgentsDest = Join-Path $RootPath ".claude/agents"
$templateSource = Join-Path $PSScriptRoot "templates/claude-agents"

if (Test-Path $templateSource) {
    if (-not (Test-Path $claudeAgentsDest)) {
        New-Item -ItemType Directory -Force -Path $claudeAgentsDest | Out-Null
    }
    Copy-Item -Path "$templateSource/*" -Destination $claudeAgentsDest -Recurse -Force
    Write-Host "[OK] Installed Claude Code subagents into .claude/agents/" -ForegroundColor Green
}

Write-Host "`n==========================================================" -ForegroundColor Green
Write-Host "   WORKFLOW READY TO USE!                                " -ForegroundColor Green
Write-Host "==========================================================" -ForegroundColor Green
Write-Host "Next steps:" -ForegroundColor Yellow
Write-Host "1. Review $WorkflowFolderName/memory.md to verify your conventions."
Write-Host "2. Review $WorkflowFolderName/PROMPTS.md for ready-to-use prompts."
Write-Host "3. Start Claude Code or Antigravity CLI and begin with @ORCHESTRATOR!"
