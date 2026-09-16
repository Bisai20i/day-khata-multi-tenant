# Universal Agent Operating Contract

## 1. Core Operating Protocol
- This repository uses a file-backed state workflow located in `workflow/` (or `.workflow/`).
- State is preserved in markdown files, **not in conversation history**.
- Keep your working context lean. Always start fresh sessions (`/clear` or new thread) for distinct tasks.
- Always read `workflow/memory.md` before taking action to review stack conventions and rules.
- State is tracked in `workflow/todo.md` (task queue) and `workflow/progress.md` (active state).

---

## 2. Hard Token Preservation & Command Guardrails (STRICT)

### A. The Safe Command Execution Rule
1. **NEVER execute raw builds, test suites, or unconstrained artisan commands.**
   - Commands with unpredictable output (e.g. `npm run build`, `php artisan test`, `composer install`) MUST run through the safe runner:
     - **Windows PowerShell:** `powershell -File workflow/bin/safe-run.ps1 -Command "<command>"`
     - **Linux / macOS / WSL:** `bash workflow/bin/safe-run.sh "<command>"`
   - The runner logs all output to a temporary file and only surfaces the tail (last 30 lines) upon failure. On success, it outputs a single line (`[SUCCESS]`).
2. **Forbidden Commands (Will hang or crash the session):**
   - NEVER run `npm run dev` or `php artisan serve` (interactive/blocking dev servers).
   - NEVER run bare `php artisan route:list`. Always filter: `php artisan route:list --path=api/v1` or `--name=orders`.
   - NEVER read `storage/logs/laravel.log` directly. Always tail: `Get-Content storage/logs/laravel.log -Tail 50` or `tail -n 50 storage/logs/laravel.log`.

### B. Targeted File Inspection
1. Do NOT dump files larger than 150 lines into context.
2. Use targeted line ranges (`StartLine` and `EndLine`) or CodeGraph symbol lookups rather than reading whole files.
3. Use `php -l <file.php>` as the first-line syntax validator. It costs 5 tokens and runs in milliseconds.

---

## 3. Parallel Execution & Non-Colliding Boundaries

When multiple agents (e.g., Claude Code and Antigravity CLI) work simultaneously in the repository:
1. **Strict File Ownership:** No worker agent may touch files outside its assigned task boundary in `workflow/todo.md`.
2. **Protected Shared Files:** The following files can ONLY be modified by the `@ORCHESTRATOR` (Parent/Lead):
   - `routes/web.php` and `routes/api.php`
   - `config/*.php`
   - `composer.json` and `package.json`
   - `workflow/todo.md` and `workflow/plans/*`
3. **No Destructive Git Commands:** Worker agents are STRICTLY FORBIDDEN from running global git resets (`git restore .`, `git reset`, `git checkout -- .`). If a worker fails, it may only discard changes to its own specific target file.

---

## 4. Roles & Operating Tiers
Consult `workflow/ROLES.md` for exact behavioral rules when acting as:
- **`@ORCHESTRATOR`**: Planning, task decomposition, shared-file wiring, state management.
- **`@SCAFFOLDER`**: Fast boilerplate, file generation, Artisan make commands.
- **`@BUILDER`**: Surgical implementation (≤ 3 files, ≤ 8 turns).
- **`@BUILDER-HIGH`**: Deep reasoning, complex algorithms, state machines.
- **`@REVIEWER`**: Git diff verification, test execution, definition of done sign-off.
