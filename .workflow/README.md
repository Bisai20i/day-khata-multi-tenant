# Portable Agentic Development Workflow (Laravel + Vue Edition)

A token-optimized, multi-agent development architecture designed to prevent session limit exhaustion, eliminate context pollution, and enable seamless, non-colliding parallel execution across **Claude Code**, **Antigravity CLI (AGY)**, **Cursor**, and other AI coding assistants.

---

## ⚠️ Status in this repo

This directory was dropped in as a generic starting template but never bootstrapped - this project
already had a more mature, project-specific version of the same idea before `init.ps1` was ever run.
**Do not run `init.ps1` here**: it would overwrite the Laravel-Boost-managed root `CLAUDE.md` and
replace working infrastructure with generic placeholders. As of 2026-09-16, the real system is:

| Generic template concept | Actual location in this repo |
| --- | --- |
| `workflow/todo.md` (task queue) | `todo/START.md`, `todo/CONTRACTS.md`, `todo/T##-*.md` - a real, project-tuned coordinator/parallel-subagent protocol with file ownership, forbidden commands, and a live status board |
| `workflow/memory.md` (stack + invariants) | `mem.md` (living state) and `goal.md` (direction/roadmap) |
| `workflow/plans/*.md` | `plans/*.md` |
| Root `CLAUDE.md` pointer | `CLAUDE.md` is auto-managed by Laravel Boost - don't overwrite it |
| Cross-cutting durable rules (golden rules, protected files, etc.) | `.ai/rules/` (via Boost's `record-rule`) |
| `templates/claude-agents/*` personas | `.claude/agents/builder.md`, `builder-high.md`, `scaffolder.md` - adapted to this project's actual conventions (money/decimal rules, migration rules, no-test-execution policy) |

`todo.md`, `memory.md`, `progress.md`, `PROMPTS.md`, `plans/template.md`, and `templates/` were removed
from this directory for that reason (still recoverable from git history if ever needed). `AGENTS.md`,
`ROLES.md`, `bin/safe-run.*`, and `init.ps1` are left as-is as a portable reference for bootstrapping
a *different*, greenfield repo - they don't apply here.

---

## 📁 Directory Structure

```text
.workflow/
├── AGENTS.md                  # Master AI operating contract and token preservation rules
├── ROLES.md                   # Persona definitions (@orchestrator, @scaffolder, @builder, @builder-high, @reviewer)
├── PROMPTS.md                 # Copy-paste prompts for every phase of development
├── memory.md                  # Project stack, architectural invariants, and protected shared files
├── todo.md                    # Active task queue (lean, tagged with execution tier)
├── progress.md                # Ephemeral live execution breadcrumbs
├── plans/                     # Feature specifications & technical plans
│   └── template.md            # Plan template for new features
├── bin/
│   ├── safe-run.ps1           # Windows PowerShell output-truncating wrapper
│   └── safe-run.sh            # Linux / macOS / WSL command wrapper
├── templates/
│   └── claude-agents/         # Ready-to-use subagents for Claude Code (.claude/agents/)
└── init.ps1                   # 1-click bootstrap script for any repository
```

---

## 🚀 How to Use in a New Project

1. **Copy the directory:**
   Copy `.workflow` (or `workflow`) into the root of your new project.

2. **Run the bootstrap script:**
   Open PowerShell in your project and run:
   ```powershell
   powershell -File .workflow/init.ps1
   ```
   This automatically:
   - Creates root entry points (`AGENTS.md`, `CLAUDE.md`, `.cursorrules`)
   - Auto-detects your Laravel & Vue versions from `composer.json` & `package.json`
   - Adds temporary log artifacts to `.gitignore`
   - Installs pre-configured subagents into `.claude/agents/`

3. **Verify `memory.md`:**
   Inspect `memory.md` to ensure any special project rules are documented.

---

## 🔄 The Operating Cycle

### 1. Plan (The Architect)
* Use **Claude Code** (or AGY) with the planning prompt from `PROMPTS.md`.
* Produces `plans/[feature].md` and populates `todo.md`.
* **Action:** Run `/clear` immediately after planning to reset context.

### 2. Build (The Workers)
* **Backend:** Use **AGY** with CodeGraph for fast PHP/Laravel symbol exploration.
* **Frontend:** Use **Claude Code** for Vue 3 UI/TypeScript components.
* Tasks are strictly file-partitioned: **no two workers ever touch the same file**.

### 3. Wire (The Orchestrator)
* The lead agent registers routes in `routes/web.php` or `routes/api.php` and connects components.

### 4. Review & Verify (The Reviewer)
* Runs the 5-point **Definition of Done** via `safe-run`.
* Inspects `git diff` and commits.

---

## 🛡️ The 5 Golden Rules of Token Conservation

1. **Safe-Run Only:** Never execute raw `npm run build` or full `php artisan test`. Always use `safe-run.ps1` to prevent 400-line error dumps.
2. **The 3-File Rule:** A single task should touch at most 3 files. Break larger tasks into smaller steps.
3. **The 8-Turn Limit:** If a builder doesn't finish within 8 turns, log status to `progress.md` and yield. Never loop.
4. **Targeted Inspection:** Never dump files > 150 lines. Use line ranges or CodeGraph.
5. **Fresh Starts:** Always `/clear` after a task or planning phase is committed.
