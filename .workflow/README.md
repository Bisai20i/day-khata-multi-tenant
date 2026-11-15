# Multi-Agent Development Workflow (Day Khata Edition)

A token-optimized, multi-agent development architecture designed to prevent session limit exhaustion, eliminate context pollution, and enable seamless, non-colliding parallel execution across **Antigravity CLI (AGY)**, **Claude Code**, and **Cursor**.

---

## 🏛️ Architecture Overview

The system is organized into three clean layers:

```text
├── AGENTS.md                  # Universal root contract: rules, forbidden commands, money invariants, DoD
├── CLAUDE.md                  # Laravel Boost guidelines + root pointer to AGENTS.md
├── mem.md & goal.md           # Living state, completed features, and architectural roadmap
├── plans/                     # Architectural specifications and feature plans
├── todo/                      # Modular task queues (todo/CONTRACTS.md, task files)
├── .agents/                   # Native Antigravity (AGY) customizations
│   ├── skills/
│   │   ├── daykhata-workflow/ # Multi-agent coordination skill, roles, and DoD
│   │   └── safe-runner/       # Output-truncating command runner skill
│   ├── bin/
│   │   ├── safe-run.ps1       # Windows PowerShell safe runner (logs to .workflow_cmd.log)
│   │   └── safe-run.sh        # Bash safe runner (Linux / macOS / WSL)
│   └── rules/                 # Workspace rules discovered by Antigravity
├── .claude/                   # Claude Code subagents (.claude/agents/) and skills
└── .workflow/                 # Backward-compatible reference and runner aliases
```

---

## 🚀 Key Commands

### Safe Command Execution
Never run raw test suites or unbounded build commands. Use `safe-run` to log output to `.workflow_cmd.log` and display only the tail on failure:

- **Windows PowerShell:**
  ```powershell
  powershell -File .agents/bin/safe-run.ps1 -Command "php -l <file.php>"
  powershell -File .agents/bin/safe-run.ps1 -Command "vendor/bin/pint <files>"
  ```
- **Bash / Linux / WSL:**
  ```bash
  bash .agents/bin/safe-run.sh "<command>"
  ```

---

## 🛡️ The 5 Golden Rules of Token Conservation

1. **Safe-Run Only:** Risky or verbose commands run through `safe-run`.
2. **Disjoint File Boundaries:** Parallel workers edit only assigned files (≤ 3 files).
3. **The ~150k Token Transcript Cap:** Tasks split into small slices (≤ 3 checkboxes) to prevent context blowout.
4. **Targeted Inspection:** No raw dumps of files > 150 lines; use CodeGraph or line ranges.
5. **No Destructive Resets:** Workers may never run global git resets (`git restore .`, `git reset`).
