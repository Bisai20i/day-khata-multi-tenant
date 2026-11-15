# Universal Agent Operating Contract (Legacy / Portable Reference)

> **Notice:** The authoritative master operating contract for this repository is located at the project root: [AGENTS.md](../AGENTS.md).
>
> In this repository:
> - **Living State & Architecture:** [`mem.md`](../mem.md) and [`goal.md`](../goal.md)
> - **Active Feature Tasks:** [`todo/`](../todo/) and [`todo/CONTRACTS.md`](../todo/CONTRACTS.md)
> - **Architectural Plans:** [`plans/`](../plans/)
> - **Durable Rules:** [`.ai/rules/`](../.ai/rules/) and [`.agents/rules/`](../.agents/rules/)
> - **Safe Execution Runner:** [`.agents/bin/safe-run.ps1`](../.agents/bin/safe-run.ps1) and [`.workflow/bin/safe-run.ps1`](bin/safe-run.ps1)

---

## 1. Core Operating Protocol
1. Follow the **Coordinator vs. Subagent** hierarchy defined in [AGENTS.md](../AGENTS.md).
2. Never execute raw, unbounded test suites, builds, or migrations from autonomous agents.
3. Obey exact-decimal money rules (C1-C3): zero float arithmetic on money, quantity, or rate.
4. Keep working contexts lean: break tasks into ≤ 3 files and ≤ 3 checkboxes (~150k token transcript cap).
5. Only the Coordinator modifies shared junction files (`routes/tenant*.php`, `nav-items.js`, `config/*`).
