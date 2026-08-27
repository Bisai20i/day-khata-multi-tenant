# MySQL credential roles (production)

Dev runs SQLite (`DB_CONNECTION=sqlite`), which has no concept of database users or grants at all —
so this document, and the accompanying `mysql-grants.sql`, describe the production-only credential
split. Nothing here is wired into application code yet (see "Not built yet" below); this is the
design record to build against once a real MySQL deployment target exists.

## Which roles this rewrite actually needs

`../day_khata/migration_plan/02-security-hardening.md` §7 describes three MySQL roles
(`tenant_provisioner`, `tenant_ddl_owner`, and a runtime app user). Only **two** of those apply here:

| Role | Used by | Grants |
|---|---|---|
| `tenant_provisioner` | Tenant provisioning only — the `CreateDatabase`/`MigrateDatabase`/`SeedDatabase`/`CreateTenantFirstAdmin` job pipeline (`app/Providers/TenancyServiceProvider.php`) | `CREATE`/`DROP DATABASE` plus full schema DDL (`CREATE`/`ALTER`/`DROP TABLE`, needed for `php artisan tenants:migrate`), scoped to a wildcard tenant-DB naming pattern (`` `tenant_%`.* ``) so a compromised provisioning process is limited to the tenant-DB namespace, not the whole server |
| Runtime app DB user | The application itself, every ordinary per-request Eloquent query against a tenant's database | DML + `SELECT` only — no DDL, no cross-database grants. Limits the blast radius of an application-level SQLi/RCE to data manipulation, not schema or database destruction |

**The security doc's third role, `tenant_ddl_owner` (for `CREATE VIEW`/`TRIGGER` DEFINER-pinning),
does not apply to this rewrite.** Verified directly against the codebase (`grep -rn "CREATE TRIGGER\|
CREATE VIEW\|DB::statement" database/ app/` returns nothing): this rewrite deliberately never uses
MySQL triggers or views anywhere — `goal.md`'s locked-in architecture decision requires every
migration and query to go through Eloquent/the query builder/Schema builder specifically so it stays
portable between SQLite (dev) and MySQL (prod). The fiscal-year engine that legacy implemented with a
MySQL trigger/view/session-variable mechanism was rebuilt Eloquent-portable instead (see `mem.md`'s
ledger-engine section) for exactly this reason. There is no DEFINER-pinned DDL to protect.

## For MVP, a single shared runtime user is an acceptable default

Per §7's own text: "a single shared runtime app DB user across all tenant databases... is an
acceptable, simpler-to-operate default" for MVP. Per-tenant unique DB credentials are a valid future
hardening step if a specific client's compliance requirements demand it later — not built now, and
not part of this pass either.

## Not built yet: wiring these into `.env`/`config/database.php`

The actual dual-connection-switching application code (using `tenant_provisioner` credentials only
inside the provisioning job pipeline, and the least-privilege runtime user for everything else) is
**not implemented in this pass**. It can't be meaningfully written or tested against SQLite — there's
no equivalent concept of a restricted DB role to verify against locally. Once a real MySQL deployment
target exists, the next actual code step is:

- Add `DB_PROVISIONER_USERNAME` / `DB_PROVISIONER_PASSWORD` env vars (distinct from the existing
  `DB_USERNAME` / `DB_PASSWORD`, which becomes the least-privilege runtime user's credentials).
- Have `CreateDatabase`/`MigrateDatabase`/`SeedDatabase`/`CreateTenantFirstAdmin` temporarily
  reconfigure the tenant connection to use the provisioner credentials for the duration of the job
  (stancl/tenancy's tenant connection config is built from `config('tenancy.database.*')` — this
  needs to be verified against the installed version's config-resolution mechanism before writing the
  override, not guessed).
- Run `mysql-grants.sql` (this directory) once against the production MySQL server to create both
  roles before the first tenant is provisioned there.

Flagged in `mem.md` as a real, deliberately deferred follow-up — not silently dropped.
