# Universal Agent Operating Contract
**Day Khata Multi-Tenant (Nepali Cloud Accounting & ERP SaaS)**

This document is the authoritative operating contract for all AI assistants working in this repository: **Antigravity CLI (AGY)**, **Claude Code**, **Cursor**, and any other autonomous agent.

---

## 1. Project Context & Living State

Day Khata is a high-reliability, multi-tenant accounting and billing platform for Nepali businesses. Correctness, financial integrity, and strict tenant isolation take absolute precedence over implementation speed.

| Document | Purpose |
|---|---|
| `mem.md` | **Living State & Architecture:** Current database schema, implemented modules, verified decisions, and active gotchas. Read this to understand what is built. |
| `goal.md` | **Vision & Roadmap:** High-level architectural roadmap and product milestones. |
| `plans/*.md` | **Feature Specifications:** Detailed architecture and design specs for specific modules. |
| `todo/*.md` | **Execution Queue & Status:** Task queue, status board (`todo/START.md`), and contracts (`todo/CONTRACTS.md`). |
| `.ai/rules/` | **Glob-Scoped Rules:** Granular constraints governed by `.ai/rules/index.md`. |
| `CLAUDE.md` | **Laravel Boost Guidelines:** Specific conventions for Laravel 12 & Vue 3. |

---

## 2. The 5 Golden Rules of Token Conservation

1. **Safe-Run Execution:** Never execute raw commands that produce unbounded or multi-page terminal output. Always wrap risky or verbose commands using the safe runner:
   - **Windows:** `powershell -File .agents/bin/safe-run.ps1 -Command "<command>"`
   - **Linux / macOS / WSL:** `bash .agents/bin/safe-run.sh "<command>"`
2. **Disjoint File Ownership:** Parallel workers operate on strictly non-overlapping sets of files. No worker agent may ever touch a file outside its assigned task boundary.
3. **The ~150k Token Transcript Cap:** Subagents balloon their context when given oversized scopes. Tasks must be broken into small, atomic chunks (≤ 3 owned files, ≤ 3 checkboxes). If a subagent approaches ~150k tokens, it must stop, log completed items, report progress, and yield.
4. **Targeted Inspection (No Context Dumps):** Do not dump files > 150 lines into context. Use CodeGraph (`codegraph explore`), targeted grep, or line-scoped file reads (`StartLine`/`EndLine`).
5. **No Destructive Global Git Resets:** Workers are strictly forbidden from running `git restore .`, `git reset --hard`, or `git checkout -- .`. If an agent fails, it may only discard changes to its specifically assigned target files.

---

## 3. The Coordinator vs. Worker Architecture

Development in this repo follows a strict two-tier hierarchy:

### Tier 1: The Coordinator (@ORCHESTRATOR)
- **Role:** Architectural planning, task decomposition into atomic sub-tasks, dispatching workers, reviewing diffs, running gate checks, and applying wiring in shared files.
- **Exclusive Ownership over Shared/Wiring Files:**
  - `routes/tenant*.php` and `routes/web.php` / `routes/api.php`
  - `resources/js/lib/nav-items.js`
  - `config/**/*.php`
  - `composer.json` and `package.json`
  - `mem.md`, `goal.md`, and master status tracking.
- **Rules:** The coordinator never writes heavy feature logic directly; it reviews combined diffs and makes logical, per-task git commits with explicit file lists (never `git add -A`).

### Dual-Speed Execution Model
To ensure maximum speed without sacrificing safety or architectural integrity:
- **Fast-Path (Direct Surgical Fixes):**
  - **Triggers:** Isolated UI tweaks, text/copy changes, styling/CSS, single-component fixes, or minor bug corrections (≤ 2 files).
  - **Execution:** The Coordinator executes the fix directly in 1 turn using targeted file tools (`read_file`, `edit_file`) followed by syntax verification (`php -l` or pint if applicable). Zero subagent overhead.
- **Deep-Path (Worker Dispatch):**
  - **Triggers:** Financial/ledger math, database migrations, state machines, concurrency, or multi-file features (3+ files).
  - **Execution:** The Coordinator plans, decomposes into disjoint file ownership, and dispatches Tier 2 subagents (`@SCAFFOLDER`, `@BUILDER`, `@BUILDER-HIGH`, `@REVIEWER`).

### Tier 2: Subagents (@SCAFFOLDER, @BUILDER, @BUILDER-HIGH, @REVIEWER)
Workers never touch shared junction files. If a worker needs a route registered, a nav item added, or a config changed, it specifies the exact change as a **Cross-File Request** in its final report.

| Persona | Purpose & Model Tier | Limits & Rules |
|---|---|---|
| **@SCAFFOLDER** | Fast boilerplate generation (`php artisan make:...`, empty Vue SFC stubs, migration skeletons). Light tier (`flash`, `haiku`). | Max 4 turns. No business logic or complex algorithms. |
| **@BUILDER** | Standard feature implementation, controllers, models, Vue components. Standard tier (`flash-high`, `sonnet`). | Max 8 turns, ≤ 3 modified files. Surgical implementation. |
| **@BUILDER-HIGH** | Deep reasoning: concurrency, state machines, ledger/costing math, complex reconciliation. Reasoning tier (`pro`, `opus`). | Max 15 turns. Turn-5 progress checkpoint. 3-strike rule: if an approach fails 3 times, revert to clean state and yield. |
| **@REVIEWER** | Diff inspection, DoD validation, Pint/php -l checks, drafting human verification steps. Read-only on app code. | Inspects `git diff`, checks contracts, verifies no banned float math or rogue commands. |

---

## 4. Strict Execution Guardrails

### ❌ Strictly FORBIDDEN for Autonomous Agents
- `php artisan test`, `vendor/bin/pest`, `phpunit` (Agents write tests, but NEVER run test suites).
- `npm run build`, `npm run dev`, `npm test`, `node --test`.
- `php artisan migrate*`, `tenants:*`, `db:*`, `tinker`, or any command connecting to a live database.
- Long-running dev servers (`php artisan serve`, `npm run dev`).
- State-changing git commands (`git commit`, `git add -A`, `git push`, `git rebase`, `git reset`, `git switch`).

### ✅ ALLOWED Syntax & Verification Checks
- `php -l <file.php>` (Instant, zero-token syntax validation).
- `vendor/bin/pint <your_files>` (Code style formatting).
- `git status` and `git diff -- <your_files>`.
- Pure-arithmetic CLI checks via `php -r` or `node -e` requiring only `vendor/autoload.php` (never booting Laravel or touching SQLite/MySQL).

---

## 5. Non-Negotiable Domain & Architectural Invariants

### A. Money & Quantity Math (Contracts C1–C3)
- **Zero Float Arithmetic:** In all code handling currency amounts, item quantities, or exchange rates:
  - **Backend:** Strictly use `App\Support\Money\Money` (scale 2, NPR) and `App\Support\Money\Quantity` (scale 4).
  - **Frontend:** Strictly use `resources/js/lib/money.js` helpers.
  - **Banned:** `(float)`, `round()`, `floatval()`, `number_format()` on floats, `.toFixed()`, `parseFloat()`, `Number()` math.
  - **Single Sanctioned Rounding:** `Money::round()` and `Quantity::round()` using `RoundingMode::HalfUp`.
  - **Model Casts:** Replace all `'decimal:N'` casts with `App\Casts\Decimal::class.':N'`.

### B. Multi-Tenancy & Database Policies
- **Tenancy Engine:** `stancl/tenancy` v3.10 multi-database mode (physical DB-per-tenant). SQLite in local/tests, MySQL 8 in production.
- **Additive Migrations Only:** Never edit an existing migration. Always create a new migration in `database/migrations/tenant/` with a working `down()`.
- **Backfill Required:** Always backfill existing rows before adding `NOT NULL` or `UNIQUE` constraints.
- **MySQL Compatibility Traps:**
  - Foreign key columns pointing to `tenants.id` must use `$table->string('tenant_id')`, NEVER `foreignId()`.
  - Index names must not exceed 64 characters.
  - All data migrations must be idempotent.

### C. Permissions & Authorization
- **Every Tenant Route Must Have a Catalog Gate:** Every authenticated route in `routes/tenant*.php` must use `->middleware('can:<permission>')` using keys from `config/permissions.php`.
- **No Direct Role Checks:** Never use `role:` middleware or `$user->role === 'admin'`. Always evaluate `$user->can(...)` or `usePermissions().can(...)`.
- **Navigation:** Every sidebar item in `resources/js/lib/nav-items.js` must specify its `permission`.

### D. Frontend Standards
- **File Length Target:** Keep Vue and JS files around ~500 lines (hard ceiling of ~750 lines). Extract composables (`resources/js/composables`) or child components when approaching 750 lines.
- **Design System:** Purple / Inter, flat corners (`--radius-*`), centralized shadows (`--shadow-*`), tight spacing.

---

## 6. Universal Definition of Done (DoD) Checklist

A task or feature cannot be marked completed or committed unless:

- [ ] **1. PHP Syntax Clean:** `php -l <modified_file>` exits 0 for every modified PHP file.
- [ ] **2. Code Styling:** `vendor/bin/pint <modified_files>` passes.
- [ ] **3. Invariants Verified:** Grep confirms zero `(float)`, `round(`, `.toFixed(`, or `'decimal:` on monetary/quantity paths.
- [ ] **4. Test Coverage Written:** Comprehensive Pest feature/unit test written in `tests/` covering new behavior (written, not executed by agent).
- [ ] **5. Clean Working Tree:** No temporary logs (`.workflow_cmd.log`), dumps, or orphaned files left behind.
- [ ] **6. Human Verification Clear:** Exact verification commands prepared for the developer (e.g. `php artisan test --filter=...`).
