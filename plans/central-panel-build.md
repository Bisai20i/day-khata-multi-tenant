# Central panel build plan

**Status as of 2026-09-03**: scope locked with the user, written, not yet started. Read `goal.md`
roadmap item 11 and `mem.md` for what's actually landed before trusting this doc's "Status" lines —
same discipline as `plans/complete-system-build.md`.

## Why this exists

The central (platform-admin) panel was built minimally alongside the tenant-side rewrite — just enough
to create/suspend/delete tenants and prove the `platform` guard + impersonation mechanism worked. Manual
browser testing found it's not usable for day-to-day platform operations: impersonate 404s, there's no
way to see who's inside a tenant, and there's no system-settings surface at all. This doc is the full
build plan, scoped and decided with the user (see "Locked decisions" below), not a speculative wishlist.

## What exists today (verified against actual code, not assumed — see audit below for detail)

Auth (login, 2FA) and Tenant CRUD-minus-edit (create/list/show/suspend/resume/delete/impersonate) are
built. Everything else in this doc — audit log, settings, admin roles, trial/grace tracking,
provisioning-failure visibility, domain management, dashboard metrics, search/pagination — does not
exist yet. Full per-file detail is in the "Audit" section at the bottom of this doc (unchanged from the
first pass, kept for reference).

## Locked decisions (from user sign-off, 2026-09-03)

- **Full scope approved**: Phases A through E below are all in scope for this effort — nothing deferred
  except billing/subscriptions (see Non-goals).
- **Platform admin roles**: simple `owner`/`support` split, mirroring the tenant-side `admin`/`staff`
  placeholder pattern already used elsewhere in this app. Not a granular per-action permission system —
  that's real complexity this doesn't need yet.
- **Grace period behavior**: surfaced only, never auto-deletes. A suspended tenant past its grace period
  shows up flagged on the dashboard/tenant list; deletion stays a deliberate manual admin action. Matches
  this app's standing "no silent destructive automation" posture for money-math software.
- **Non-goal, unchanged**: billing/plans/subscriptions. Legacy's own migration plan explicitly reserved
  schema room but declined to build this; same call holds here.

## Shared schema — design once, before any phase starts

Several phases touch the same two central tables. Per this project's established convention
(`plans/complete-system-build.md`'s Phase 0 note: "coordinator pre-stubs shared files"), these are
designed once here, up front, not left for whichever agent gets there first.

**`tenants` gains** (new migration, central DB): `suspended_at` (nullable timestamp, set by `suspend()`,
cleared by `resume()`), `trial_ends_at` (nullable timestamp, set at creation from
`platform_settings.default_trial_days`). No stored `grace_period_ends_at` — computed at read time from
`suspended_at + platform_settings.grace_period_days` (avoids a redundant column that can drift out of
sync with the settings value).

**`platform_admins` gains** (new migration, central DB): `role` (string, `owner`|`support`, default
`owner` — the existing seeded admin and anyone created before this migration stays `owner`, so nobody
loses access), `is_active` (boolean, default true). Deactivation is a UI action that flips `is_active`
and blocks login (checked in the `platform` guard's login flow) — **never a hard delete**, since
`platform_admin_activity_logs` rows reference `platform_admin_id` and a hard delete would orphan audit
history for actions that admin actually took.

**New `platform_admin_activity_logs` table** (central DB): `id`, `platform_admin_id` (FK
`platform_admins`), `tenant_id` (nullable FK `tenants` — some actions like settings changes aren't
tenant-scoped), `action` (string — `tenant.create`/`tenant.update`/`tenant.suspend`/`tenant.resume`/
`tenant.delete`/`tenant.impersonate`/`settings.update`/`platform_admin.create`/`platform_admin.deactivate`),
`metadata` (nullable json), `created_at` only (append-only — no `updated_at`, no update/delete path
exposed anywhere in the app layer). `App\Models\PlatformAdminActivityLog::record(string $action, ?Tenant
$tenant = null, array $metadata = [])` is the one write path every controller action below calls into —
actor always read from `Auth::guard('platform')->id()`, never passed in, so it can't be spoofed.

**New `platform_settings` table** (central DB): a **single row** (id fixed at 1, `firstOrCreate`
accessor `PlatformSetting::current()`), real typed columns — not a generic key/value table. This project's
"no speculative abstractions" rule applies here: we know exactly what settings this needs, so a generic
KV framework would be building configurability nobody asked for. Columns: `mail_mailer`, `mail_host`,
`mail_port`, `mail_username`, `mail_password` (encrypted cast), `mail_encryption`, `mail_from_address`,
`mail_from_name`, `platform_name`, `support_email`, `default_trial_days` (int, e.g. 14),
`default_grace_period_days` (int, e.g. 30).

**Gate**: `Gate::define('platform-owner', fn (PlatformAdmin $admin) => $admin->role === 'owner')`,
registered in `AuthServiceProvider` (or wherever this app's existing gates live — check before adding a
new registration point). Applied to: tenant delete, platform-admin management, settings changes.
`support` can do everything else (view, impersonate, suspend/resume, view users/audit log).

---

## Phase A — Fix the bug, close the gaps already hit in manual testing

**Status: built 2026-09-03, not yet run through the user's `php artisan test` / committed.** All 4 items
below are done — see `mem.md`'s top entry for the full breakdown (the impersonate port-drop fix, the
`platform_admin_activity_logs` table + `PlatformAdminActivityLog::record()` wired into every sensitive
`TenantController` action with the FK-ordering detail handled correctly in `destroy()`, tenant edit,
tenant users view, and the filterable activity-log page). Test command:
`php artisan test --compact tests/Feature/Central/Tenants/ImpersonationTest.php
tests/Feature/Central/Tenants/TenantDeletionTest.php tests/Feature/Central/Tenants/TenantProvisioningTest.php
tests/Feature/Central/Tenants/TenantSuspensionTest.php tests/Feature/Central/Tenants/TenantUpdateTest.php
tests/Feature/Central/Tenants/TenantUserControllerTest.php tests/Feature/Central/ActivityLogControllerTest.php`.

1. **Fix impersonate 404.** `TenantController::impersonate()` currently does
   `parse_url(config('app.url'), PHP_URL_SCHEME)` — grabs only the scheme, silently drops the port. With
   `APP_URL=http://localhost:8000` the generated signed URL points at port 80, not 8000, which is why it
   404s. Fix: also read `PHP_URL_PORT` and append it to the forced root URL when present. Add a
   regression test asserting the generated `Inertia::location()` target includes the port when `APP_URL`
   has a non-default one.
2. **Tenant users view.** `TenantController::users(Tenant $tenant)` (or a small nested
   `TenantUserController`), runs `$tenant->run(fn () => User::with('role')->get())`, renders a read-only
   list (name, email, role) — new section on the tenant Show page or its own route/page.
3. **Tenant edit.** Add `edit`/`update` routes + controller methods for `company_name`/`contact_email`
   only (domain editing is Phase D, item 13 — a different, riskier concern).
4. **Persisted audit log.** The `platform_admin_activity_logs` table from the shared schema above, plus
   `PlatformAdminActivityLog::record()` wired into every existing sensitive action
   (`store`/`update`/`suspend`/`resume`/`destroy`/`impersonate`), replacing the current `Log::info()`
   stopgap in `impersonate()`. New `Central\ActivityLogController@index` (filterable by tenant/action/
   admin) + `Central/ActivityLog/Index.vue`.

## Phase B — System settings

5. **Platform settings page.** `PlatformSetting` model + `Central\Settings\PlatformSettingController`
   (`edit`/`update`, single page — mirrors the existing `Tenant/Admin/SettingsController` pattern already
   in this app) + `Central/Settings/Edit.vue`. Gated `platform-owner`.
6. **Runtime application of mail settings.** A settings page is cosmetic unless the app actually mails
   through it — register a boot-time hook (e.g. `AppServiceProvider::boot()`, guarded so it only applies
   in central context / doesn't affect tenant requests) that reads `PlatformSetting::current()` and
   `Config::set()`s the `mail.*` config before any mail is sent. "Send test email" action on the settings
   page: a minimal `App\Mail\TestMail` Mailable sent to the current platform admin's own email.
7. **Grace period.** `suspended_at` set in `suspend()`, cleared in `resume()` (shared schema above).
   Tenant list/show and the dashboard (Phase E) flag any tenant where
   `now() > suspended_at->addDays($settings->grace_period_days)` — **surface only, never auto-delete**,
   per the locked decision above.
8. **Mailables built on top of settings**: welcome email to the new tenant admin (fires once
   provisioning actually succeeds — the `CreateTenantFirstAdmin` job's completion, not `store()`, since
   provisioning is async), suspension notice (on `suspend()`), provisioning-failed alert to platform
   admins (ties into Phase D item 12). Sequenced after items 5–6 land, not bundled with them.

## Phase C — Platform admin management

9. **Schema**: `role`/`is_active` columns on `platform_admins` (shared schema above).
10. **`Central\PlatformAdmins\PlatformAdminController`** — index/create/store/edit/update, plus a
    `deactivate` action (never a hard delete — see shared schema note on why). Login flow checks
    `is_active` and rejects deactivated admins with a clear message, not a generic auth failure.
11. **UI**: `Central/PlatformAdmins/{Index,Create,Edit}.vue`. All management actions gated
    `platform-owner`; `support` admins can view the list but not create/edit/deactivate others.

## Phase D — Tenant lifecycle hardening

12. **Trial tracking**: `trial_ends_at` set at creation from `platform_settings.default_trial_days`
    (shared schema above). Surfaced on tenant list/show + dashboard ("trials expiring this week").
    No auto-action on expiry — same posture as grace period.
13. **Provisioning failure visibility.** The create pipeline is async/queued; today a failed job leaves
    a tenant stuck at `Provisioning` forever with zero visibility. Rather than parsing `failed_jobs`
    payloads (fragile — tenant id isn't a queryable column there), wrap the pipeline with a failure
    listener that writes directly to `platform_admin_activity_logs` with `action='provisioning.failed'`,
    an explicit `tenant_id`, and the exception message in `metadata`. Tenant Show page surfaces this and
    offers a manual "retry provisioning" action that re-dispatches the job pipeline.
14. **Domain management.** `stancl/tenancy` already supports multiple domains per tenant — there's just
    no UI. New nested routes `central.tenants.domains.store/destroy`, small add/list UI on the tenant
    Show page.
15. **Stronger delete confirmation.** Upgrade the existing modal-confirm to require typing the exact
    company name before the Delete button enables — proportionate given this triggers a real
    `DROP DATABASE`. Backend authorization is already correct (`auth:platform` + route-model-bound
    tenant); this is a UX safety upgrade, not a security fix.

## Phase E — Dashboard & search

16. **Real dashboard.** The dashboard is currently an inline closure in `routes/central-auth.php`
    (`Route::get('/admin', function () {...})`) rather than a controller — move it to a proper
    `Central\DashboardController@index` as part of this work. Metrics: tenant counts by status, created
    this week/month, trials expiring within 7 days (Phase D), tenants past grace period (Phase B), most
    recent activity log entries (Phase A, last 10).
17. **Tenant list search + pagination.** `Tenant::with('domains')->latest()->get()` has no pagination
    today — fine at current scale, will break as tenant count grows. Add search by company
    name/domain/email + standard pagination; check whether a pagination UI component already exists
    elsewhere in this app before building a new one.

## Non-goals (unchanged, matches legacy's own scoping)

- **Billing/subscriptions/metering** — legacy's own migration plan explicitly reserved schema room but
  declined to build this; same call holds here.
- **Read-only "view tenant data without impersonating" mode**, **per-tenant unique DB credentials**,
  **IP allow-listing for platform admins** — real future hardening options, not requested, not built now.

## Working rules for this effort (same discipline as `plans/complete-system-build.md`)

- Coordinator designs and edits every shared file (the two migrations above, the Gate registration,
  route file structure) **before** forking any agent — never two agents editing `tenants`/
  `platform_admins` migrations or `routes/central-tenants.php` in parallel.
- Each phase's items are independent enough to parallelize *within* the phase once shared schema exists,
  but **phases run in order** (A → B → C → D → E) since B/C/D all read `platform_settings`/`role`/
  `platform_admin_activity_logs` that Phase A/B/C create.
- Money-adjacent logic here is thin (no ledger postings), but grace-period/trial date math and the
  audit-log write path still get hand-traced by whoever verifies each phase, not just agent-self-reported.
- **Coordinator never runs `php artisan test`/`npm run build`** — the user runs those and reports back,
  same as every prior phase in this project.
- Update `mem.md` and this doc's phase "Status" lines after each phase completes, not after every
  individual item.

## Suggested build order

Phase A first (fixes what's already been hit, self-contained, smallest). Phase B second (the other
explicit ask, and Phase D/E's dashboard widgets depend on `platform_settings` existing). C, D, E follow
in that order per the dependency note above.

---

## Audit (reference — what was found when this doc was first drafted, 2026-09-03)

- **Auth**: `platform_admins` table/guard, login, 2FA (setup/challenge/confirm/destroy), logout,
  `throttle:5,1` on login.
- **Tenant CRUD**: create (async provisioning pipeline), list (no pagination/search), show, suspend,
  resume, delete (hard delete, cascades to DB drop). No edit/update route exists.
- **Impersonation**: signed short-lived URL, logged via `Log::info()` only. Confirmed 404 bug: port
  dropped from the forced root URL (see Phase A item 1).
- **Dashboard**: a static placeholder — "Signed in as X" and a link to Tenants, defined as an inline
  closure in `routes/central-auth.php`, not a controller.
- **System settings**: none. Mail hardcoded to `.env` (`MAIL_MAILER=log`), zero Mailable classes exist.
- **Platform admin management**: none — no `role` column, only tinker/seeder can create one.
- Legacy's `../day_khata/migration_plan/04-data-schema-provisioning.md` §1 planned a
  `platform_admin_impersonation_logs` table from the start — never built; today's `Log::info()` is
  exactly the stopgap that doc anticipated replacing. `02-security-hardening.md` §4 recommends
  destructive central actions go through queued, audit-logged jobs — the audit-logging half is this
  plan's Phase A item 4; queuing suspend/delete themselves is not in scope (flagged as a future
  hardening option, not required — current synchronous path is fine once audited and confirmed).
