---
name: daykhata-workflow
description: >-
  Standard operating procedure for coordinating multi-agent development in Day Khata.
  Use when planning, decomposing tasks, launching subagents (scaffolder, builder, builder-high, reviewer),
  enforcing file boundaries, and conducting gate reviews.
---

# Day Khata Multi-Agent Development Workflow

This skill guides the AI assistant in operating as a disciplined **Coordinator** or specialized **Worker** in the Day Khata repository.

---

## 1. Operating Protocol Overview

Development follows a **Dual-Speed Triage Model**:

```mermaid
flowchart TD
    Issue["Incoming Issue / Task"] --> Triage{"Scope & Risk Assessment"}
    
    Triage -- "Surgical Fix (≤ 2 files)<br/>UI tweaks, copy, CSS, simple bug" --> Fast["⚡ Fast-Path (Direct Fix)<br/>1. Targeted read_file<br/>2. Direct edit_file<br/>3. Syntax check & immediate report<br/>⏱ Turnaround: ~30s"]
    
    Triage -- "Architectural / Multi-file<br/>Ledger math, DB migrations, 3+ files" --> Deep["🏗️ Deep-Path (Worker Dispatch)<br/>1. Plan & Scope (@orchestrator)<br/>2. Scaffold (@scaffolder)<br/>3. Surgical Build (@builder / @builder-high)<br/>4. Review & Gate (@reviewer)"]
```

---

## 2. Step-by-Step Execution Guide

### Fast-Path: Direct Surgical Fixes
For small bugs, label updates, styling adjustments, or single-file changes:
1. Coordinator locates target file using targeted search or direct path.
2. Applies edits directly with `edit_file`.
3. Verifies PHP syntax (`php -l`) or formatting if applicable.
4. Reports diff and verification instructions immediately (turnaround < 1 minute).

### Deep-Path: Multi-Agent Feature Development

### Stage 1: Plan & Scope (Coordinator)
1. **Context Ingestion:** Skim `mem.md` for living architecture and `plans/` for the relevant specification.
2. **Decompose into Small Slices:**
   - Break large tasks into atomic chunks of **≤ 3 files** and **≤ 3 checkboxes**.
   - Ensure parallel tasks have **100% disjoint file sets**.
   - Keep shared wiring files (`routes/tenant*.php`, `nav-items.js`, `config/*`) reserved exclusively for the Coordinator.

### Stage 2: Boilerplate Generation (@scaffolder)
When new models, migrations, or Vue views are needed:
1. Launch or act as `@scaffolder` using `flash` or lightweight tier.
2. Run generator commands:
   ```powershell
   powershell -File .agents/bin/safe-run.ps1 -Command "php artisan make:model <Name> -mcrR --no-interaction"
   ```
3. Create empty Vue SFC skeletons and TypeScript type stubs.
4. Yield to the Coordinator within 4 turns.

### Stage 3: Surgical Feature Implementation (@builder / @builder-high)
1. Dispatch `@builder` (standard) or `@builder-high` (complex financial math/concurrency).
2. Enforce strict boundaries:
   - Worker edits **only** assigned files.
   - Enforce exact-decimal rules (no float math, `Money`/`Quantity`/`Decimal` cast).
   - Write or update matching Pest tests in `tests/Feature/` or `tests/Unit/` without executing them.
   - Run syntax sanity check:
     ```powershell
     powershell -File .agents/bin/safe-run.ps1 -Command "php -l <modified_file.php>"
     ```
3. Worker finishes within turn budget and produces a concise report containing:
   - Checkboxes completed.
   - Files created / modified.
   - Tests written / updated.
   - Cross-file requests (routes/nav needed).

### Stage 4: Wire & Gate Review (Coordinator & @reviewer)
1. Review all modified files against [references/dod.md](references/dod.md).
2. Apply cross-file requests to shared files:
   - Register route in appropriate `routes/tenant-*.php` with `->middleware('can:<permission>')`.
   - Add sidebar entry to `resources/js/lib/nav-items.js`.
3. Run Pint style formatting:
   ```powershell
   powershell -File .agents/bin/safe-run.ps1 -Command "vendor/bin/pint <changed_files>"
   ```
4. Verify working tree is clean (`git status`).
5. Provide explicit verification commands for the human developer.

---

## 3. Related References
- [references/roles.md](references/roles.md) - Subagent prompt templates and turn budgets.
- [references/contracts.md](references/contracts.md) - C1-C10 invariant cheat sheet.
- [references/dod.md](references/dod.md) - 8-point Definition of Done checklist.
- [AGENTS.md](../../../AGENTS.md) - Master repository contract.
