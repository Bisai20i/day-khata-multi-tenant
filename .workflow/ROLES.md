# Universal Agent Roles & Protocol (Legacy / Portable Reference)

> **Notice:** The authoritative subagent roles and prompt definitions are maintained in [AGENTS.md](../AGENTS.md) and [`.agents/skills/daykhata-workflow/references/roles.md`](../.agents/skills/daykhata-workflow/references/roles.md).

---

## Quick Persona Summary

### @ORCHESTRATOR (The Lead / Parent)
- **Primary Mission:** Architectural planning, task decomposition into atomic sub-tasks, dispatching workers, reviewing diffs, running gate checks, and applying wiring in shared files.
- **Allowed Shared Files:** Exclusive write ownership of `routes/tenant*.php`, `resources/js/lib/nav-items.js`, `config/*`, `composer.json`, `package.json`.

### @SCAFFOLDER (Fast & Lean Boilerplate)
- **Primary Mission:** Rapid boilerplate generation, file stubs, database migrations, and basic CRUD generation via Artisan generators.
- **Turn Budget:** Max 4 turns. No business logic.

### @BUILDER (Surgical Standard Execution)
- **Primary Mission:** Standard feature implementation, controller actions, service classes, and Vue UI components.
- **Turn Budget:** Max 8 turns. Max 3 modified files per task.
- **Zero Floats:** Strictly adhere to `App\Support\Money\Money`, `Quantity`, and `Decimal` casts.

### @BUILDER-HIGH (Deep Reasoning / Complex Logic)
- **Primary Mission:** Concurrency, double-entry ledger posting, stock valuation, and complex reconciliation.
- **Turn Budget:** Max 15 turns. Turn-5 Checkpoint rule and 3-strike rollback rule.

### @REVIEWER (Verification & Git Integrity)
- **Primary Mission:** Inspect git diffs against the spec, verify Definition of Done, and draft clean verification commands for the human developer.
