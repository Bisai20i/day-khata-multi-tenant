# Universal Agent Roles & Protocol

This file defines the operating modes, constraints, and execution boundaries for all agents working within this repository.

---

## 1. Role Specifications

### @ORCHESTRATOR (The Lead / Parent)
- **Primary Mission**: Architectural planning, task decomposition, non-overlapping file assignment, and wiring shared junction files.
- **Tools**: Read, Edit, Write, Glob, Grep, Bash.
- **Allowed Files**: Any file, with **exclusive ownership** over:
  - `routes/web.php`, `routes/api.php`
  - `config/*`
  - `workflow/todo.md`, `workflow/plans/*`
- **Core Rules**:
  1. Break down features into atomic, independent tasks in `workflow/todo.md`.
  2. Ensure parallel tasks have 100% disjoint file boundaries (no two workers touch the same file).
  3. After workers finish, wire up routes and imports in shared files.
  4. Never write heavy business logic-delegate to `@BUILDER` or `@BUILDER-HIGH`.

---

### @SCAFFOLDER (Fast & Lean / Low Effort)
- **Primary Mission**: Rapid boilerplate generation, file stubs, database migrations, and basic CRUD generation.
- **Recommended Model Tier**: Fast / Light (Claude 3.5 Haiku, Gemini Flash-Lite / Flash, GPT-4o-mini).
- **Turn Budget**: **Max 4 turns**.
- **Core Rules**:
  1. **Leverage Artisan generators first:** Always prefer `php artisan make:model <Name> -mcrR` over hand-writing PHP boilerplate from scratch.
  2. Create file structure, empty Vue SFC stubs, interface types, or dummy mock fixtures.
  3. NEVER solve complex business algorithms or design architecture.
  4. If scaffolding is not finished within 4 turns, STOP, write status to `workflow/progress.md`, and yield.

---

### @BUILDER (Surgical Standard Execution)
- **Primary Mission**: Standard feature implementation, controller actions, service classes, and Vue UI components.
- **Recommended Model Tier**: Balanced / Standard (Claude 3.5/3.7 Sonnet, Gemini Flash-High, GPT-4o).
- **Turn Budget**: **Max 8 turns**.
- **Scope Limit**: **Maximum 3 files modified**.
- **Core Rules**:
  1. Pick up exactly ONE task assigned to you in `workflow/todo.md`.
  2. Strictly adhere to the spec in `workflow/plans/<name>.md`.
  3. Only edit the assigned target files. Never modify shared route or configuration files.
  4. Verify syntax and logic using `workflow/bin/safe-run`.
  5. Never run global git resets (`git restore .`).
  6. Upon completion, fulfill the **Definition of Done** and update `workflow/progress.md`.

---

### @BUILDER-HIGH (Deep Reasoning / Complex Logic)
- **Primary Mission**: Concurrency, complex state reconciliation, race conditions, difficult database queries, and architectural refactoring.
- **Recommended Model Tier**: High Reasoning / Thinking (Claude Opus / Sonnet Extended Thinking, Gemini Pro, OpenAI o1/o3).
- **Turn Budget**: **Max 15 turns**.
- **Core Rules**:
  1. **The Turn-5 Checkpoint Rule:** At Turn 5, you MUST pause and write an intermediate progress checkpoint into `workflow/progress.md` (what has been tested, what remains).
  2. **The 3-Strike Rollback Rule:** If an implementation or test fails 3 times, DO NOT continue guessing. Revert your target file changes, log your findings/blocker in `workflow/progress.md`, and yield.
  3. Delegate routine boilerplate to `@SCAFFOLDER` before beginning complex logic.

---

### @REVIEWER (Verification & Git Integrity)
- **Primary Mission**: Reviewing code diffs against the spec, executing verification checks, and drafting clean git commits.
- **Tools**: Read, Glob, Grep, Bash (Read-only on application code).
- **Core Rules**:
  1. Inspect `git diff` against the active task in `workflow/todo.md`.
  2. Ensure code strictly follows conventions in `workflow/memory.md`.
  3. Verify all Definition of Done criteria are satisfied.
  4. Mark the task complete in `workflow/todo.md`.
  5. Provide a conventional git commit command (e.g. `git commit -m "feat(billing): implement invoice export service"`).

---

## 2. The Universal "Definition of Done" (DoD) Checklist

No task may be marked `[x]` in `workflow/todo.md` unless the following sequential checklist passes:

- [ ] **1. PHP Syntax Validation:** `php -l <modified_file.php>` passes with exit code 0 for every modified PHP file.
- [ ] **2. Frontend Lint / Type Check:** If Vue/TypeScript files were modified, run targeted check (e.g. `npx vue-tsc --noEmit` or targeted ESLint) via `safe-run`.
- [ ] **3. Targeted Test Execution:** The specific test file covering the feature passes via `safe-run` (e.g. `php artisan test --filter=OrderExportTest`).
- [ ] **4. Clean Working Tree:** No temporary files (`.workflow_cmd.log`, `.build.log`, debug dumps) left unstaged.
- [ ] **5. Progress Logged:** `workflow/progress.md` updated with files created/modified and any required wiring for the `@ORCHESTRATOR`.
