---
paths:
  - '**'
---

# General

## Subagents never run tests, builds, or migrations
Worker/reviewer subagents write and update Pest/JS tests but never execute `php artisan test`, `vendor/bin/pest`, `phpunit`, `npm run build`, `npm run dev`, `npm test`, `php artisan migrate*`/`tenants:*`/`db:*`, or `tinker`, and never touch a database. The user (or the coordinating top-level session, when explicitly asked) runs those and reports results back. Allowed self-checks: `php -l <file>`, `vendor/bin/pint <files>`, `git status`/`git diff -- <files>`, and pure-arithmetic checks that don't boot Laravel. Also: no state-changing git commands from workers (`add`, `commit`, `reset`, `checkout`, `clean`, etc.) — only the coordinating session commits, in logical per-task commits with explicit file lists, never `git add -A`/`git add .`.

## Cap subagent transcripts at ~150k tokens by splitting task scope, not by hoping agents notice
A subagent handed a whole multi-checkbox task file (e.g. a full T-file covering 9-11 items across a whole module) has ballooned past 450k tokens in its own transcript. That's a coordinator problem, not something an agent reliably self-polices.

Whoever spawns subagents (the coordinating session):
- Never hand one agent a task file with more than ~2-3 checkboxes / one cohesive sub-feature. Split a big task file into several smaller agent invocations up front, each scoped to only the files/checkboxes it needs. The checkbox list in the task file stays the source of truth for what's left.
- Sequence chunks that touch the same files serially (not in parallel) to avoid collisions.

Every subagent prompt should also say:
- Read minimally: Grep/Glob for the exact symbol/file needed instead of reading whole files, directories, or vendor/node_modules wholesale.
- Don't re-read a file already read this session unless it changed on disk.
- If your own transcript feels like it's approaching ~150k tokens, stop immediately: tick whatever checkboxes are genuinely done, write the final report now, and explicitly list what's left undone for a continuation agent, rather than trying to power through everything in one giant transcript.
