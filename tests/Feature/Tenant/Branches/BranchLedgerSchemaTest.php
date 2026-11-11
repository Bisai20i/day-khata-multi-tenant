<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionLedgerBranchTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

/**
 * Each BR03 migration with the tables it touches. `index` is the explicit
 * index name, `columns` the exact column list it must cover, and `existing`
 * marks the HR tables whose branch_id was nullable and FK-less before BR03
 * (their rollback restores that shape instead of dropping the column).
 *
 * @return array<string, array<string, array{index: string|null, columns: list<string>, existing: bool}>>
 */
function ledgerBranchMigrations(): array
{
    return [
        '2026_11_10_120000_add_branch_id_to_journal_vouchers' => [
            'journal_vouchers' => ['index' => 'jv_branch_date_idx', 'columns' => ['branch_id', 'date'], 'existing' => false],
        ],
        '2026_11_10_120100_add_branch_columns_to_journal_voucher_lines' => [
            'journal_voucher_lines' => ['index' => 'jvl_branch_account_idx', 'columns' => ['branch_id', 'account_id'], 'existing' => false],
        ],
        '2026_11_10_120200_add_branch_id_to_receipts_and_payments' => [
            'receipts' => ['index' => 'receipts_branch_date_idx', 'columns' => ['branch_id', 'date'], 'existing' => false],
            'payments' => ['index' => 'payments_branch_date_idx', 'columns' => ['branch_id', 'date'], 'existing' => false],
            'capital_purchase_settlements' => ['index' => 'cap_settle_branch_date_idx', 'columns' => ['branch_id', 'date'], 'existing' => false],
        ],
        '2026_11_10_120300_add_branch_id_to_hr_and_asset_tables' => [
            'hr_staff' => ['index' => null, 'columns' => ['branch_id'], 'existing' => true],
            'hr_payroll_runs' => ['index' => null, 'columns' => ['branch_id'], 'existing' => true],
            'hr_payroll_payments' => ['index' => null, 'columns' => ['branch_id'], 'existing' => true],
            'hr_staff_advances' => ['index' => null, 'columns' => ['branch_id'], 'existing' => true],
            'hr_payroll_lines' => ['index' => 'hr_lines_branch_idx', 'columns' => ['branch_id'], 'existing' => false],
            'fixed_assets' => ['index' => 'fixed_assets_branch_idx', 'columns' => ['branch_id'], 'existing' => false],
        ],
    ];
}

/**
 * Every table that gets a branch_id, flattened.
 *
 * @return array<string, array{index: string|null, columns: list<string>, existing: bool}>
 */
function ledgerBranchTables(): array
{
    $tables = [];

    foreach (ledgerBranchMigrations() as $group) {
        $tables = [...$tables, ...$group];
    }

    return $tables;
}

/**
 * @return array<string, object>
 */
function loadLedgerBranchMigrations(): array
{
    $loaded = [];

    foreach (array_keys(ledgerBranchMigrations()) as $name) {
        $loaded[$name] = require database_path("migrations/tenant/{$name}.php");
    }

    $name = '2026_11_10_120400_add_branch_columns_to_stock_transfers';
    $loaded[$name] = require database_path("migrations/tenant/{$name}.php");

    return $loaded;
}

/**
 * Insert $count rows the way a pre-branch release would have written them:
 * every required column without a default gets a type-appropriate dummy value.
 * The caller switches foreign keys off. The tenant database is its own SQLite
 * connection, outside the central test transaction, so that switch takes
 * effect and the dummy parent ids never need real parent rows.
 */
function insertLegacyLedgerRows(string $table, int $count): void
{
    $columns = array_values(array_filter(
        Schema::getColumns($table),
        fn (array $column) => ! $column['auto_increment'] && ! $column['nullable'] && $column['default'] === null,
    ));

    foreach (range(1, $count) as $number) {
        $row = [];

        foreach ($columns as $column) {
            $type = strtolower($column['type_name']);
            $name = $column['name'];

            $row[$name] = match (true) {
                str_contains($type, 'datetime'), str_contains($type, 'timestamp') => '2026-01-01 00:00:00',
                $type === 'date' => '2026-01-01',
                str_contains($type, 'bool'), $type === 'tinyint' => 1,
                // A per-row number, so composite unique keys built from FK
                // columns (hr_payroll_lines run + staff) never collide.
                str_contains($type, 'int') => $number,
                str_contains($type, 'json') => '{}',
                str_contains($type, 'decimal'), str_contains($type, 'numeric'), str_contains($type, 'float'), str_contains($type, 'double'), str_contains($type, 'real') => '0',
                default => "legacy-{$table}-{$name}-{$number}",
            };
        }

        DB::table($table)->insert($row);
    }
}

function ledgerBranchMainId(): int
{
    return (int) DB::table('branches')->where('is_main', 1)->value('id');
}

function ledgerBranchColumn(string $table, string $name): ?array
{
    return collect(Schema::getColumns($table))->firstWhere('name', $name);
}

function ledgerBranchHasForeignKey(string $table, string $column = 'branch_id', string $parent = 'branches'): bool
{
    foreach (Schema::getForeignKeys($table) as $foreignKey) {
        if ($foreignKey['columns'] === [$column] && $foreignKey['foreign_table'] === $parent) {
            return true;
        }
    }

    return false;
}

/**
 * Whether the table has an index covering exactly these columns (by name when
 * a name is given, otherwise by column list).
 *
 * @param  list<string>  $columns
 */
function ledgerBranchHasIndex(string $table, ?string $name, array $columns): bool
{
    foreach (Schema::getIndexes($table) as $index) {
        if ($index['columns'] === $columns && ($name === null || $index['name'] === $name)) {
            return true;
        }
    }

    return false;
}

test('a fresh tenant has a NOT NULL indexed branch_id with a foreign key on every ledger, HR and asset table', function () {
    $tenant = provisionLedgerBranchTenant('branch-ledger-fresh.tenant-test');

    $tenant->run(function () {
        foreach (ledgerBranchTables() as $table => $spec) {
            $column = ledgerBranchColumn($table, 'branch_id');

            expect($column)->not->toBeNull()
                ->and($column['nullable'])->toBeFalse()
                ->and(ledgerBranchHasIndex($table, $spec['index'], $spec['columns']))->toBeTrue()
                ->and(ledgerBranchHasForeignKey($table))->toBeTrue();

            if ($spec['index'] !== null) {
                expect(strlen($spec['index']))->toBeLessThanOrEqual(64);
            }
        }
    });

    $tenant->delete();
});

test('a fresh tenant has the nullable counterparty branch on ledger lines', function () {
    $tenant = provisionLedgerBranchTenant('branch-ledger-counterparty.tenant-test');

    $tenant->run(function () {
        $column = ledgerBranchColumn('journal_voucher_lines', 'counterparty_branch_id');

        expect($column)->not->toBeNull()
            ->and($column['nullable'])->toBeTrue()
            ->and($column['default'])->toBeNull()
            ->and(ledgerBranchHasForeignKey('journal_voucher_lines', 'counterparty_branch_id'))->toBeTrue();
    });

    $tenant->delete();
});

test('a fresh tenant has the new stock transfer branch and dispatch columns', function () {
    $tenant = provisionLedgerBranchTenant('branch-ledger-transfers.tenant-test');

    $tenant->run(function () {
        foreach (['from_branch_id' => 'stock_transfers_from_branch_idx', 'to_branch_id' => 'stock_transfers_to_branch_idx'] as $name => $indexName) {
            $column = ledgerBranchColumn('stock_transfers', $name);

            expect($column)->not->toBeNull()
                ->and($column['nullable'])->toBeFalse()
                ->and(ledgerBranchHasIndex('stock_transfers', $indexName, [$name]))->toBeTrue()
                ->and(ledgerBranchHasForeignKey('stock_transfers', $name))->toBeTrue()
                ->and(strlen($indexName))->toBeLessThanOrEqual(64);
        }

        foreach (['dispatched_at', 'received_at', 'received_by', 'journal_voucher_id'] as $name) {
            $column = ledgerBranchColumn('stock_transfers', $name);

            expect($column)->not->toBeNull()
                ->and($column['nullable'])->toBeTrue();
        }

        expect(ledgerBranchHasForeignKey('stock_transfers', 'received_by', 'users'))->toBeTrue()
            ->and(ledgerBranchHasForeignKey('stock_transfers', 'journal_voucher_id', 'journal_vouchers'))->toBeTrue();

        $onDelete = collect(Schema::getForeignKeys('stock_transfers'))
            ->mapWithKeys(fn (array $foreignKey) => [$foreignKey['columns'][0] => strtolower((string) $foreignKey['on_delete'])]);

        // A posted voucher can never be deleted out from under its transfer.
        expect($onDelete['journal_voucher_id'])->toBe('restrict')
            ->and($onDelete['received_by'])->toBe('set null');
    });

    $tenant->delete();
});

test('stock transfer status is a plain string so in_transit and received need no schema change', function () {
    $tenant = provisionLedgerBranchTenant('branch-ledger-status.tenant-test');

    $tenant->run(function () {
        $status = ledgerBranchColumn('stock_transfers', 'status');
        expect($status)->not->toBeNull()
            ->and(strtolower($status['type_name']))->toContain('varchar');

        Schema::withoutForeignKeyConstraints(function () {
            insertLegacyLedgerRows('stock_transfers', 1);

            foreach (['in_transit', 'received'] as $value) {
                DB::table('stock_transfers')->update(['status' => $value]);

                expect(DB::table('stock_transfers')->value('status'))->toBe($value);
            }
        });
    });

    $tenant->delete();
});

test('rows that existed before the columns end up on the Main branch', function () {
    $tenant = provisionLedgerBranchTenant('branch-ledger-backfill.tenant-test');

    $tenant->run(function () {
        $migrations = loadLedgerBranchMigrations();
        $mainId = ledgerBranchMainId();
        expect($mainId)->toBeGreaterThan(0);

        Schema::withoutForeignKeyConstraints(function () use ($migrations, $mainId) {
            // Rebuild the pre-branch shape: roll the columns off, then write legacy rows.
            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
            }

            foreach (ledgerBranchTables() as $table => $spec) {
                if ($spec['existing']) {
                    // HR tables keep their old nullable, FK-less column.
                    expect(ledgerBranchColumn($table, 'branch_id')['nullable'])->toBeTrue()
                        ->and(ledgerBranchHasForeignKey($table))->toBeFalse();
                } else {
                    expect(Schema::hasColumn($table, 'branch_id'))->toBeFalse();
                }

                insertLegacyLedgerRows($table, 2);
            }

            insertLegacyLedgerRows('stock_transfers', 2);

            // Pre-existing nullable HR columns hold NULL until the migration runs.
            expect(DB::table('hr_staff')->whereNull('branch_id')->count())->toBe(2);

            foreach ($migrations as $migration) {
                $migration->up();
            }

            foreach (ledgerBranchTables() as $table => $spec) {
                expect(DB::table($table)->count())->toBe(2)
                    ->and(DB::table($table)->where('branch_id', $mainId)->count())->toBe(2)
                    ->and(ledgerBranchColumn($table, 'branch_id')['nullable'])->toBeFalse()
                    ->and(ledgerBranchHasIndex($table, $spec['index'], $spec['columns']))->toBeTrue()
                    ->and(ledgerBranchHasForeignKey($table))->toBeTrue();
            }

            // Ledger lines land on Main with no counterparty.
            expect(DB::table('journal_voucher_lines')->whereNull('counterparty_branch_id')->count())->toBe(2);

            // Transfers land on Main at both ends, with nothing dispatched or received.
            expect(DB::table('stock_transfers')->count())->toBe(2)
                ->and(DB::table('stock_transfers')->where('from_branch_id', $mainId)->where('to_branch_id', $mainId)->count())->toBe(2)
                ->and(DB::table('stock_transfers')->whereNull('dispatched_at')->whereNull('received_at')->whereNull('received_by')->whereNull('journal_voucher_id')->count())->toBe(2);
        });
    });

    $tenant->delete();
});

test('running the migrations again changes nothing and repairs a missing index or foreign key', function () {
    $tenant = provisionLedgerBranchTenant('branch-ledger-idempotent.tenant-test');

    $tenant->run(function () {
        $migrations = loadLedgerBranchMigrations();
        $mainId = ledgerBranchMainId();

        Schema::withoutForeignKeyConstraints(function () use ($migrations, $mainId) {
            foreach ([...array_keys(ledgerBranchTables()), 'stock_transfers'] as $table) {
                insertLegacyLedgerRows($table, 1);
            }

            foreach ($migrations as $migration) {
                $migration->up();
                $migration->up();
            }

            foreach (ledgerBranchTables() as $table => $spec) {
                expect(DB::table($table)->count())->toBe(1)
                    ->and(DB::table($table)->where('branch_id', $mainId)->count())->toBe(1)
                    ->and(ledgerBranchHasIndex($table, $spec['index'], $spec['columns']))->toBeTrue();
            }

            // A half-applied earlier run on the huge table: column there, FK and index not.
            Schema::table('journal_voucher_lines', function ($blueprint) {
                $blueprint->dropForeign(['branch_id']);
            });
            Schema::table('journal_voucher_lines', function ($blueprint) {
                $blueprint->dropIndex('jvl_branch_account_idx');
            });
            Schema::table('journal_voucher_lines', function ($blueprint) {
                $blueprint->dropForeign(['counterparty_branch_id']);
            });
            expect(ledgerBranchHasIndex('journal_voucher_lines', 'jvl_branch_account_idx', ['branch_id', 'account_id']))->toBeFalse()
                ->and(ledgerBranchHasForeignKey('journal_voucher_lines'))->toBeFalse()
                ->and(ledgerBranchHasForeignKey('journal_voucher_lines', 'counterparty_branch_id'))->toBeFalse();

            // Same for a pre-existing HR table and for a stock transfer end.
            Schema::table('hr_staff', function ($blueprint) {
                $blueprint->dropForeign(['branch_id']);
            });
            Schema::table('stock_transfers', function ($blueprint) {
                $blueprint->dropForeign(['to_branch_id']);
            });
            Schema::table('stock_transfers', function ($blueprint) {
                $blueprint->dropIndex('stock_transfers_to_branch_idx');
            });

            foreach ($migrations as $migration) {
                $migration->up();
            }

            expect(ledgerBranchHasIndex('journal_voucher_lines', 'jvl_branch_account_idx', ['branch_id', 'account_id']))->toBeTrue()
                ->and(ledgerBranchHasForeignKey('journal_voucher_lines'))->toBeTrue()
                ->and(ledgerBranchHasForeignKey('journal_voucher_lines', 'counterparty_branch_id'))->toBeTrue()
                ->and(ledgerBranchHasForeignKey('hr_staff'))->toBeTrue()
                ->and(ledgerBranchHasForeignKey('stock_transfers', 'to_branch_id'))->toBeTrue()
                ->and(ledgerBranchHasIndex('stock_transfers', 'stock_transfers_to_branch_idx', ['to_branch_id']))->toBeTrue()
                ->and(DB::table('journal_voucher_lines')->where('branch_id', $mainId)->count())->toBe(1)
                ->and(DB::table('hr_staff')->where('branch_id', $mainId)->count())->toBe(1);
        });
    });

    $tenant->delete();
});

test('rolling back removes the new columns, restores the old HR shape, keeps every row, and can run twice', function () {
    $tenant = provisionLedgerBranchTenant('branch-ledger-rollback.tenant-test');

    $tenant->run(function () {
        $migrations = loadLedgerBranchMigrations();
        $mainId = ledgerBranchMainId();

        Schema::withoutForeignKeyConstraints(function () use ($migrations, $mainId) {
            foreach ([...array_keys(ledgerBranchTables()), 'stock_transfers'] as $table) {
                insertLegacyLedgerRows($table, 1);
            }

            foreach (array_reverse($migrations) as $migration) {
                $migration->down();
                $migration->down();
            }

            foreach (ledgerBranchTables() as $table => $spec) {
                expect(DB::table($table)->count())->toBe(1);

                if ($spec['existing']) {
                    // The HR migration owns these columns: still there, nullable, no FK, still indexed, value kept.
                    expect(ledgerBranchColumn($table, 'branch_id')['nullable'])->toBeTrue()
                        ->and(ledgerBranchHasForeignKey($table))->toBeFalse()
                        ->and(ledgerBranchHasIndex($table, null, ['branch_id']))->toBeTrue()
                        ->and(DB::table($table)->where('branch_id', $mainId)->count())->toBe(1);
                } else {
                    expect(Schema::hasColumn($table, 'branch_id'))->toBeFalse()
                        ->and(ledgerBranchHasIndex($table, $spec['index'], $spec['columns']))->toBeFalse();
                }
            }

            expect(Schema::hasColumn('journal_voucher_lines', 'counterparty_branch_id'))->toBeFalse()
                ->and(DB::table('stock_transfers')->count())->toBe(1);

            foreach (['from_branch_id', 'to_branch_id', 'dispatched_at', 'received_at', 'received_by', 'journal_voucher_id'] as $column) {
                expect(Schema::hasColumn('stock_transfers', $column))->toBeFalse();
            }

            // And forward again: the surviving rows land on Main.
            foreach ($migrations as $migration) {
                $migration->up();
            }

            foreach (array_keys(ledgerBranchTables()) as $table) {
                expect(DB::table($table)->where('branch_id', $mainId)->count())->toBe(1);
            }

            expect(DB::table('stock_transfers')->where('from_branch_id', $mainId)->where('to_branch_id', $mainId)->count())->toBe(1);
        });
    });

    $tenant->delete();
});

test('payroll runs are hardened to a NOT NULL branch with a foreign key that defaults to Main', function () {
    $tenant = provisionLedgerBranchTenant('branch-ledger-payroll-runs.tenant-test');

    $tenant->run(function () {
        $mainId = ledgerBranchMainId();
        $column = ledgerBranchColumn('hr_payroll_runs', 'branch_id');

        expect($column)->not->toBeNull()
            ->and($column['nullable'])->toBeFalse()
            ->and(ledgerBranchHasForeignKey('hr_payroll_runs'))->toBeTrue()
            ->and(ledgerBranchHasIndex('hr_payroll_runs', null, ['branch_id']))->toBeTrue();

        // A run written without a branch lands on Main through the default.
        Schema::withoutForeignKeyConstraints(function () {
            insertLegacyLedgerRows('hr_payroll_runs', 1);
        });

        expect(DB::table('hr_payroll_runs')->where('branch_id', $mainId)->count())->toBe(1);
    });

    $tenant->delete();
});

test('orphan branch ids in the old FK-less HR columns are remapped to Main before the foreign key is added', function () {
    $tenant = provisionLedgerBranchTenant('branch-ledger-hr-orphans.tenant-test');

    $tenant->run(function () {
        $migration = require database_path('migrations/tenant/2026_11_10_120300_add_branch_id_to_hr_and_asset_tables.php');
        $mainId = ledgerBranchMainId();
        $existing = ['hr_staff', 'hr_payroll_runs', 'hr_payroll_payments', 'hr_staff_advances'];

        Schema::withoutForeignKeyConstraints(function () use ($migration, $mainId, $existing) {
            $migration->down();

            foreach ($existing as $table) {
                insertLegacyLedgerRows($table, 2);

                // One row points at a branch that never existed, the other is NULL.
                $firstId = DB::table($table)->min('id');
                DB::table($table)->where('id', $firstId)->update(['branch_id' => 9999]);

                expect(DB::table($table)->where('branch_id', 9999)->count())->toBe(1)
                    ->and(DB::table($table)->whereNull('branch_id')->count())->toBe(1);
            }

            $migration->up();

            foreach ($existing as $table) {
                expect(DB::table($table)->count())->toBe(2)
                    ->and(DB::table($table)->where('branch_id', $mainId)->count())->toBe(2)
                    ->and(ledgerBranchColumn($table, 'branch_id')['nullable'])->toBeFalse()
                    ->and(ledgerBranchHasForeignKey($table))->toBeTrue();
            }
        });
    });

    $tenant->delete();
});

test('capital purchase settlements get a branch with a foreign key and a (branch_id, date) index', function () {
    $tenant = provisionLedgerBranchTenant('branch-ledger-cap-settlements.tenant-test');

    $tenant->run(function () {
        $column = ledgerBranchColumn('capital_purchase_settlements', 'branch_id');

        expect($column)->not->toBeNull()
            ->and($column['nullable'])->toBeFalse()
            ->and(ledgerBranchHasForeignKey('capital_purchase_settlements'))->toBeTrue()
            ->and(ledgerBranchHasIndex('capital_purchase_settlements', 'cap_settle_branch_date_idx', ['branch_id', 'date']))->toBeTrue();
    });

    $tenant->delete();
});

test('existing stock transfers take each end\'s branch from its store', function () {
    $tenant = provisionLedgerBranchTenant('branch-ledger-transfer-stores.tenant-test');

    $tenant->run(function () {
        $migration = require database_path('migrations/tenant/2026_11_10_120400_add_branch_columns_to_stock_transfers.php');
        $mainId = ledgerBranchMainId();

        Schema::withoutForeignKeyConstraints(function () use ($migration, $mainId) {
            $migration->down();

            $secondBranchId = DB::table('branches')->insertGetId([
                'name' => 'Pokhara',
                'code' => 'PKR',
                'is_main' => false,
                'is_active' => true,
                'sort_order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $mainStoreId = DB::table('stores')->insertGetId([
                'name' => 'Main Godown',
                'branch_id' => $mainId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $branchStoreId = DB::table('stores')->insertGetId([
                'name' => 'Pokhara Godown',
                'branch_id' => $secondBranchId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $transfer = fn (int $fromStoreId, int $toStoreId) => DB::table('stock_transfers')->insertGetId([
                'date' => '2026-01-01',
                'from_store_id' => $fromStoreId,
                'to_store_id' => $toStoreId,
                'created_by' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $outbound = $transfer($mainStoreId, $branchStoreId);
            $inbound = $transfer($branchStoreId, $mainStoreId);
            $local = $transfer($branchStoreId, $branchStoreId);
            // A store that no longer exists keeps Main from the default.
            $orphan = $transfer(999999, $mainStoreId);

            $migration->up();
            $migration->up();

            $ends = fn (int $id) => (array) DB::table('stock_transfers')->where('id', $id)->first(['from_branch_id', 'to_branch_id']);

            expect(array_map('intval', $ends($outbound)))->toBe(['from_branch_id' => $mainId, 'to_branch_id' => $secondBranchId])
                ->and(array_map('intval', $ends($inbound)))->toBe(['from_branch_id' => $secondBranchId, 'to_branch_id' => $mainId])
                ->and(array_map('intval', $ends($local)))->toBe(['from_branch_id' => $secondBranchId, 'to_branch_id' => $secondBranchId])
                ->and(array_map('intval', $ends($orphan)))->toBe(['from_branch_id' => $mainId, 'to_branch_id' => $mainId]);
        });
    });

    $tenant->delete();
});

test('the migrations refuse to run without a Main branch', function () {
    $tenant = provisionLedgerBranchTenant('branch-ledger-no-main.tenant-test');

    $tenant->run(function () {
        $migrations = loadLedgerBranchMigrations();

        Schema::withoutForeignKeyConstraints(function () {
            DB::table('branches')->where('is_main', 1)->delete();
        });

        foreach ($migrations as $migration) {
            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'No Main branch exists');
        }
    });

    $tenant->delete();
});
