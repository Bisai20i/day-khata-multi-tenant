---
paths:
  - 'database/migrations/**'
---

# Migrations

## Migrations: additive, backfilled, and idempotent
Never edit an already-applied migration - add a new one. Every migration needs a working `down()`. Backfill existing rows before adding a NOT NULL or unique constraint (no doctrine/dbal here, so `->change()` on a column needing a real default won't work - write the backfill explicitly). Data migrations must be idempotent and work on both SQLite (tests/dev) and MySQL (production) - no MySQL-only DDL (triggers, views, stored procedures, native enums). If backfilling a money/quantity column from a raw decimal read, run it through `Money::round()`/`Quantity::round()` first - SQLite gives decimal columns REAL affinity, so old float-written rows read back as e.g. `404984.71000000002` and the strict parser rejects them.

## Data migrations never read config or live catalogs
Backfills freeze their lists as literal constants (see RoleBackfill::GRANTABLE_AT_ROLLOUT / STAFF_PARITY and the enabled_modules backfill). Reading config/permissions.php or PermissionCatalog inside a migration makes its result depend on when it runs, so already-migrated and newly-migrated tenants would diverge.

## MySQL traps SQLite tests do not catch
Tests run on SQLite, which ignores these; production MySQL fails on them. (1) FK column type must match the parent: tenants.id is a string, so use string('tenant_id') + foreign(), never foreignId('tenant_id'). (2) Index names max 64 chars: pass an explicit short name to multi-column unique()/index(). (3) A unique index leading with an FK column replaces the FK's own index, so in down() drop the foreign key BEFORE dropUnique (then dropColumn, or re-add the FK if the column stays). Check with a fresh migrate + full rollback on MySQL/MariaDB before shipping.
