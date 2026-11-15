# Definition of Done (DoD) Verification Checklist

Before any task is considered complete or approved for commit, the Coordinator or Reviewer must verify each of the following criteria:

---

### Step 1: File Boundaries & Ownership Audit
- [ ] Run `git status` and `git diff --stat`.
- [ ] Ensure **zero** unassigned files were modified by worker subagents.
- [ ] Verify no worker modified shared wiring files (`routes/tenant*.php`, `nav-items.js`, `config/*`).
- [ ] Check that cross-file requests from worker reports were applied cleanly by the Coordinator.

### Step 2: Syntax & Linter Validation
- [ ] Run PHP syntax checks:
  ```powershell
  powershell -File .agents/bin/safe-run.ps1 -Command "php -l <modified_file>"
  ```
- [ ] Run Pint style checks:
  ```powershell
  powershell -File .agents/bin/safe-run.ps1 -Command "vendor/bin/pint --test <modified_files>"
  ```

### Step 3: Exact Decimal Math Contract Check
- [ ] Grep modified files for forbidden float constructs:
  - `(float)`
  - `floatval(`
  - `round(` (outside sanctioned `Money::round()` / `Quantity::round()`)
  - `.toFixed(`
  - `parseFloat(`
  - `'decimal:` (in Eloquent model `$casts`)
- [ ] Confirm all amounts use `Money` / `Quantity` / `Decimal` cast / `money.js`.

### Step 4: Migration Safety Audit (If Migrations Added)
- [ ] Migration is placed in `database/migrations/tenant/` with a valid timestamp prefix.
- [ ] Existing rows backfilled before adding `NOT NULL` or `UNIQUE` constraints.
- [ ] `down()` method cleanly rolls back changes.
- [ ] SQLite and MySQL 8 compatible (e.g. `$table->string('tenant_id')` used instead of `foreignId`).

### Step 5: Route & Authorization Audit
- [ ] Any newly created tenant route carries `->middleware('can:<permission>')`.
- [ ] Permission key exists in `config/permissions.php`.
- [ ] Sidebar entry in `resources/js/lib/nav-items.js` includes the `permission` property.

### Step 6: Test Coverage Written
- [ ] Pest test file created or updated in `tests/Feature/` or `tests/Unit/`.
- [ ] Test reflects the actual expected business logic and contracts.
- [ ] No test skipped or deleted to mask regressions.

### Step 7: Clean Working Tree
- [ ] No `.workflow_cmd.log`, `.build.log`, or temporary scratch files left behind.
- [ ] Unrelated working tree changes preserved and untouched.

### Step 8: User Verification Handoff
- [ ] Formulate exact test commands for the human developer to run:
  - e.g. `php artisan test tests/Feature/Tenant/Sales/NewFeatureTest.php`
  - e.g. `npm run build`
