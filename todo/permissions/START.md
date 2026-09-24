# START HERE: roles, permissions and module entitlements execution plan

Source of truth for design: `plans/roles-permissions-entitlements.md` (read it fully first). This folder holds
the 16 task chunks (P01 to P16). The audit plan in `todo/START.md` and `todo/T01`-`T14` is finished and
separate: do not mix them up. Where this file says "the audit rules", it means `todo/START.md` section
"Rules for every sub-agent".

## What to do when the user types `start`

You are the **coordinator**. You do not write feature code yourself except the coordinator-only files below.
Run the chunks in the order under "Execution order", one worker per chunk, review its output, then commit.

- `start` = continue from the first chunk not ticked on the status board.
- `start P05` = run only that chunk.
- `status` = report the board and any open gate, change nothing.

## Locked preferences (inherited, do not re-ask)

| Topic | Decision |
|---|---|
| Test runs and migrations | **Agents never run tests, builds or migrations.** They write and update Pest/JS tests only. The user runs `php artisan test`, `npm run build`, `php artisan migrate`, `php artisan tenants:migrate`. |
| Commits | **Agents never commit.** The coordinator commits on `development` per chunk, naming files explicitly (never `git add -A`). No push. |
| Allowed self-checks | `php -l <file>`, `vendor/bin/pint <files>`, `git status`, `git diff -- <files>`. Do not boot Laravel, so **do not run `php artisan route:list`**: read the `routes/*.php` files with Grep/Read instead. |
| Subagent size | Max ~150k tokens per agent, so one chunk (2-3 checkboxes) per agent. Same-file chunks run serially. See `.ai/rules/general.md`. |
| Vue/JS files | ~500 lines each, hard cap ~750 (`.ai/rules/js.md`). Extract composables or child components. |
| Writing style | No em dashes in docs, code comments or UI text. Explanatory "why" docblocks like the surrounding code. |
| Migrations | Additive, idempotent, backfilled, working `down()`, SQLite and MySQL safe, no doctrine/dbal (`.ai/rules/migrations.md`). Never edit an applied migration. |
| Money rules | Untouched by this work. Do not add float math anywhere. |

## Coordinator-only files (workers request changes in their report)

`routes/*.php`, `resources/js/lib/nav-items.js`, `config/**`, `bootstrap/providers.php`,
`bootstrap/app.php`, `composer.json`, `package.json`, `todo/permissions/START.md` (status board), `mem.md`,
`goal.md`. New route files a chunk needs are created by the coordinator from the chunk's request.

## Test infrastructure decisions (existing suite must keep passing)

About 45 existing test files create tenants with `Tenant::create([...])` and users with no `role_id`, and many
hit routes that will become gated. To keep them green without rewriting them all:

1. **Tenants created without an explicit `enabled_modules` default to ALL modules** (`config('permissions.default_modules')`
   via a `Tenant::creating` hook, P02). The central "create tenant" form always sends an explicit selection.
   Old DB rows with `null` stay fail-closed (core only) until the P02 backfill fills them.
2. **`UserFactory` makes a role-less user an owner** and a user given a `role_id` role-governed (P03), so
   existing role-less test users keep full access while role-based tests exercise real grants.
3. **Test helpers in `tests/Pest.php`** (P03): `roleWithPermissions(array $keys, string $name = 'Test role'): Role` and
   `userWithPermissions(array $keys): User`. New tests use these, never hand-rolled roles.
4. Tests that reference the old `staff` slug are updated in the chunk whose routes they hit (or P13 for
   provisioning), using the helpers above. Never delete or skip a test to make it pass.

## Execution order

```
Phase A (serial):   P01 -> P02 -> P03 -> P04
Phase B:            P09 tasks 1-2 (plumbing) -> P05 -> P06 -> P07 -> P08 -> P09 task 3 (route audit)
Phase C:            P10 || P11 -> P12 -> P13 -> P15      (P10 may run parallel to P11, all others serial)
Phase D:            P14 -> P16
```

Within each of P05-P08: checkbox 1 is coordinator route wiring and must finish before the worker starts
checkboxes 2 and 3.

## Reserved migration prefixes

P02 (central `database/migrations`) `2026_09_25_010000+`, P03 (tenant `database/migrations/tenant`)
`2026_09_25_020000+`, P16 `2026_09_25_160000+`.

## Agent prompt template

> You are a senior Laravel/Vue engineer executing `todo/permissions/<TASK FILE>` in
> `D:\Projects\day-khata\day-khata-multi-tenant` (branch `development`). This is production multi-tenant
> accounting software, so security and correctness beat speed. Read `todo/permissions/START.md` (locked
> preferences, coordinator-only files, test infrastructure), then `plans/roles-permissions-entitlements.md`,
> then your task file, and `todo/permissions/ROUTE-MAP.md` once it exists. Also read the `.ai/rules` files
> whose globs cover the files you touch. Read minimally: Grep/Glob for exact symbols. Verify Laravel and
> package APIs against installed source in `vendor/` before using them. Obey "Rules for every sub-agent" in
> `todo/START.md` (ownership, forbidden commands, shared working tree, tick your checkboxes). Stop and report
> if your transcript nears ~150k tokens. Finish with a final report under 800 words: what changed per
> checkbox, files created or modified, tests added or updated (not run), migrations added, cross-file
> requests, open questions, anything unfinished.

## Gates (coordinator, after every chunk, plus user stops)

Every chunk: read the report; `git status` + `git diff --stat`; ownership check (every modified file belongs
to the chunk or is a listed cross-file request); `php -l` and `vendor/bin/pint` on changed PHP; apply
cross-file requests yourself; commit with explicit file lists; tick the board.

- **After P04 (Phase A): STOP.** Ask the user to run: `php artisan migrate`, `php artisan tenants:migrate` on a
  dev tenant, `php artisan test tests/Unit tests/Feature/Central` and paste the result. Fix before Phase B.
- **After P09 task 3 (Phase B): STOP.** Ask the user to run `php artisan test`, `npm run build`, then the
  browser checklist below. The route-audit test must be green: it is the proof no route was forgotten.
- **After P15 (Phase C): STOP.** Ask the user to run the full suite and build again.
- **After P16 (Phase D):** update `mem.md` (architecture: catalog, entitlements column, roles JSON, Gate,
  owner model, escalation guard) and `goal.md`, commit, give the user a final summary.

### Browser checklist (user, after Phase B and again after Phase C)

- Owner sees every entitled menu item and can open every entitled page.
- A Cashier role sees only sales/POS items and gets 403 typing a purchases or reports URL.
- Turn a module off in the central panel: its menu items vanish and its URLs 403, even for the owner.
  Turn it back on: role grants are intact.
- Central `/tenants/{id}` "Modules" section saves and shows in the central activity log.
- Owner edits a role: permissions from non-entitled modules are not offered.
- A user with `users.manage` cannot assign a role broader than their own, nor edit the owner.
- Impersonating from central lands as the owner and respects entitlements.

## Deployment checklist (production, after all phases are green)

1. Back up central and every tenant database.
2. Deploy code with maintenance mode on.
3. `php artisan migrate` (central `enabled_modules`, backfilled with all modules for existing tenants).
4. Run the P14 dry-run command, review the owner chosen per tenant, fix outliers.
5. `php artisan tenants:migrate` (tenant schema plus role and owner backfill). It is not automatic.
6. Smoke test one owner, one manager and one cashier on a production tenant.
7. Maintenance mode off. Keep the database backups for a full release cycle before P16's table drop.

Rollback: every migration has a working `down()`. Until P16 the old `role` alias and the old
`permissions`/`permission_role` tables still exist, so reverting the code and running `down()` is safe.

## Status board

| Chunk | Title | Executor | Status |
|---|---|---|---|
| P01 | Catalog and route map | worker + coordinator | pending |
| P02 | Central entitlements storage | worker | pending |
| P03 | Tenant schema, backfill, test infra | worker | pending |
| P04 | Gate and effective permissions | worker + coordinator | pending |
| P09 | Frontend plumbing, nav, route audit | worker + coordinator | pending |
| P05 | Sales side enforcement | coordinator + worker | pending |
| P06 | Purchase and accounting enforcement | coordinator + worker | pending |
| P07 | Masters and inventory enforcement | coordinator + worker | pending |
| P08 | Reports and admin enforcement | coordinator + worker | pending |
| P10 | Central modules UI | worker | pending |
| P11 | Tenant roles UI | worker | pending |
| P12 | Users page and ownership transfer | worker | pending |
| P13 | Provisioning and role templates | worker | pending |
| P15 | Central owner reassignment | worker | pending |
| P14 | Existing-tenant dry run and parity | worker + coordinator | pending |
| P16 | Cleanup | worker + coordinator | pending |
