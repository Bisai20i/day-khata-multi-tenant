---
paths:
  - 'database/migrations/**'
---

# Migrations

## Migrations: additive, backfilled, and idempotent
Never edit an already-applied migration - add a new one. Every migration needs a working `down()`. Backfill existing rows before adding a NOT NULL or unique constraint (no doctrine/dbal here, so `->change()` on a column needing a real default won't work - write the backfill explicitly). Data migrations must be idempotent and work on both SQLite (tests/dev) and MySQL (production) - no MySQL-only DDL (triggers, views, stored procedures, native enums). If backfilling a money/quantity column from a raw decimal read, run it through `Money::round()`/`Quantity::round()` first - SQLite gives decimal columns REAL affinity, so old float-written rows read back as e.g. `404984.71000000002` and the strict parser rejects them.
