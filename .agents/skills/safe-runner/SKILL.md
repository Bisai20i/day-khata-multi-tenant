---
name: safe-runner
description: >-
  Executes terminal commands with automatic output truncation and log capture
  to prevent token limit exhaustion in AI sessions. Use when running tests,
  builds, Pint formatting, syntax checks, or artisan commands.
---

# Safe Command Runner

This skill ensures commands with verbose or unpredictable output never flood the model's context window with hundreds of terminal lines.

## How to Execute

### On Windows (PowerShell)
```powershell
powershell -File .agents/bin/safe-run.ps1 -Command "<your-command>"
```
Examples:
- Syntax validation: `powershell -File .agents/bin/safe-run.ps1 -Command "php -l app/Models/Tenant.php"`
- Pint styling check: `powershell -File .agents/bin/safe-run.ps1 -Command "vendor/bin/pint --test"`
- Pint auto-fix: `powershell -File .agents/bin/safe-run.ps1 -Command "vendor/bin/pint app/Http/Controllers/Tenant"`

### On Linux / macOS / WSL (Bash)
```bash
bash .agents/bin/safe-run.sh "<your-command>"
```

## Behavior
1. **On Success (Exit Code 0):** Prints a single line summary with duration (e.g., `[SUCCESS] Completed in 0.82s`) and only the last 3 lines of output.
2. **On Failure (Exit Code ≠ 0):** Prints `[FAILED]` with the exit code and displays only the tail 35 lines of `.workflow_cmd.log`, allowing the agent to diagnose errors without token context blowout.
3. **Log Storage:** The full verbatim output is always captured in `.workflow_cmd.log` for targeted inspection if needed.
