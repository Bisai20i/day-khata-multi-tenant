<?php

use App\Models\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

afterEach(function () {
    tenancy()->end();
});

function provisionBranchDocumentsTenant(string $domain): Tenant
{
    $tenant = Tenant::create(['company_name' => 'Acme Co']);
    $tenant->domains()->create(['domain' => $domain]);

    return $tenant;
}

/**
 * Each BR02 migration with the tables it touches and their index names.
 *
 * @return array<string, array<string, string>>
 */
function branchDocumentMigrations(): array
{
    return [
        '2026_11_10_110000_add_branch_id_to_sales_and_purchase_documents' => [
            'sales' => 'sales_branch_date_idx',
            'purchases' => 'purchases_branch_date_idx',
            'sales_returns' => 'sales_returns_branch_date_idx',
            'purchase_returns' => 'purchase_returns_branch_date_idx',
            'quotations' => 'quotations_branch_date_idx',
        ],
        '2026_11_10_110100_add_branch_id_to_capital_documents' => [
            'capital_purchases' => 'cap_purchases_branch_date_idx',
            'capital_sales' => 'cap_sales_branch_date_idx',
        ],
        '2026_11_10_110200_add_branch_id_to_stock_documents' => [
            'stock_adjustments' => 'stock_adj_branch_date_idx',
            'stock_conversions' => 'stock_conv_branch_date_idx',
        ],
        '2026_11_10_110300_add_branch_id_to_manufacturing_documents' => [
            'material_issue_notes' => 'mat_issue_branch_date_idx',
            'production_entries' => 'prod_entries_branch_date_idx',
        ],
    ];
}

/**
 * @return array<string, object>
 */
function loadBranchDocumentMigrations(): array
{
    $loaded = [];

    foreach (array_keys(branchDocumentMigrations()) as $name) {
        $loaded[$name] = require database_path("migrations/tenant/{$name}.php");
    }

    return $loaded;
}

/**
 * Insert $count rows the way a pre-branch release would have written them:
 * every required column without a default gets a type-appropriate dummy value
 * (foreign keys are switched off by the caller, so parents need not exist).
 */
function insertLegacyDocumentRows(string $table, int $count): void
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
                $name === 'number' => $number,
                str_contains($type, 'datetime'), str_contains($type, 'timestamp') => '2026-01-01 00:00:00',
                $type === 'date' => '2026-01-01',
                str_contains($type, 'int'), str_contains($type, 'bool') => 1,
                str_contains($type, 'json') => '{}',
                str_contains($type, 'decimal'), str_contains($type, 'numeric'), str_contains($type, 'float'), str_contains($type, 'double'), str_contains($type, 'real') => '0',
                default => "legacy-{$table}-{$name}-{$number}",
            };
        }

        DB::table($table)->insert($row);
    }
}

function branchDocumentMainId(): int
{
    return (int) DB::table('branches')->where('is_main', 1)->value('id');
}

function branchDocumentHasForeignKey(string $table): bool
{
    foreach (Schema::getForeignKeys($table) as $foreignKey) {
        if ($foreignKey['columns'] === ['branch_id'] && $foreignKey['foreign_table'] === 'branches') {
            return true;
        }
    }

    return false;
}

test('a fresh tenant has a NOT NULL indexed branch_id with a foreign key on every document table', function () {
    $tenant = provisionBranchDocumentsTenant('branch-docs-fresh.tenant-test');

    $tenant->run(function () {
        foreach (branchDocumentMigrations() as $tables) {
            foreach ($tables as $table => $indexName) {
                $column = collect(Schema::getColumns($table))->firstWhere('name', 'branch_id');

                expect($column)->not->toBeNull()
                    ->and($column['nullable'])->toBeFalse()
                    ->and(Schema::hasIndex($table, $indexName))->toBeTrue()
                    ->and(branchDocumentHasForeignKey($table))->toBeTrue()
                    ->and(strlen($indexName))->toBeLessThanOrEqual(64);
            }
        }
    });

    $tenant->delete();
});

test('rows that existed before the column end up on the Main branch', function () {
    $tenant = provisionBranchDocumentsTenant('branch-docs-backfill.tenant-test');

    $tenant->run(function () {
        $migrations = loadBranchDocumentMigrations();
        $mainId = branchDocumentMainId();
        expect($mainId)->toBeGreaterThan(0);

        Schema::withoutForeignKeyConstraints(function () use ($migrations, $mainId) {
            // Rebuild the pre-branch shape: roll the columns off, then write legacy rows.
            foreach ($migrations as $migration) {
                $migration->down();
            }

            foreach (branchDocumentMigrations() as $tables) {
                foreach (array_keys($tables) as $table) {
                    expect(Schema::hasColumn($table, 'branch_id'))->toBeFalse();
                    insertLegacyDocumentRows($table, 2);
                }
            }

            foreach ($migrations as $migration) {
                $migration->up();
            }

            foreach (branchDocumentMigrations() as $tables) {
                foreach ($tables as $table => $indexName) {
                    expect(DB::table($table)->count())->toBe(2)
                        ->and(DB::table($table)->where('branch_id', $mainId)->count())->toBe(2)
                        ->and(Schema::hasIndex($table, $indexName))->toBeTrue()
                        ->and(branchDocumentHasForeignKey($table))->toBeTrue();
                }
            }
        });
    });

    $tenant->delete();
});

test('running the migrations again changes nothing and repairs a missing index or foreign key', function () {
    $tenant = provisionBranchDocumentsTenant('branch-docs-idempotent.tenant-test');

    $tenant->run(function () {
        $migrations = loadBranchDocumentMigrations();
        $mainId = branchDocumentMainId();

        Schema::withoutForeignKeyConstraints(function () use ($migrations, $mainId) {
            foreach (branchDocumentMigrations() as $tables) {
                foreach (array_keys($tables) as $table) {
                    insertLegacyDocumentRows($table, 1);
                }
            }

            foreach ($migrations as $migration) {
                $migration->up();
                $migration->up();
            }

            foreach (branchDocumentMigrations() as $tables) {
                foreach ($tables as $table => $indexName) {
                    expect(DB::table($table)->count())->toBe(1)
                        ->and(DB::table($table)->where('branch_id', $mainId)->count())->toBe(1)
                        ->and(Schema::hasIndex($table, $indexName))->toBeTrue();
                }
            }

            // A half-applied earlier run: the column is there but the FK and index are not.
            Schema::table('sales', function ($blueprint) {
                $blueprint->dropForeign(['branch_id']);
            });
            Schema::table('sales', function ($blueprint) {
                $blueprint->dropIndex('sales_branch_date_idx');
            });
            expect(Schema::hasIndex('sales', 'sales_branch_date_idx'))->toBeFalse()
                ->and(branchDocumentHasForeignKey('sales'))->toBeFalse();

            $migrations['2026_11_10_110000_add_branch_id_to_sales_and_purchase_documents']->up();

            expect(Schema::hasIndex('sales', 'sales_branch_date_idx'))->toBeTrue()
                ->and(branchDocumentHasForeignKey('sales'))->toBeTrue()
                ->and(DB::table('sales')->where('branch_id', $mainId)->count())->toBe(1);
        });
    });

    $tenant->delete();
});

test('rolling back removes the columns and indexes, keeps the documents, and can run twice', function () {
    $tenant = provisionBranchDocumentsTenant('branch-docs-rollback.tenant-test');

    $tenant->run(function () {
        $migrations = loadBranchDocumentMigrations();
        $mainId = branchDocumentMainId();

        Schema::withoutForeignKeyConstraints(function () use ($migrations, $mainId) {
            foreach (branchDocumentMigrations() as $tables) {
                foreach (array_keys($tables) as $table) {
                    insertLegacyDocumentRows($table, 1);
                }
            }

            foreach ($migrations as $migration) {
                $migration->down();
                $migration->down();
            }

            foreach (branchDocumentMigrations() as $tables) {
                foreach ($tables as $table => $indexName) {
                    expect(Schema::hasColumn($table, 'branch_id'))->toBeFalse()
                        ->and(Schema::hasIndex($table, $indexName))->toBeFalse()
                        ->and(DB::table($table)->count())->toBe(1);
                }
            }

            // And forward again: the surviving rows land on Main.
            foreach ($migrations as $migration) {
                $migration->up();
            }

            foreach (branchDocumentMigrations() as $tables) {
                foreach (array_keys($tables) as $table) {
                    expect(DB::table($table)->where('branch_id', $mainId)->count())->toBe(1);
                }
            }
        });
    });

    $tenant->delete();
});

test('the migrations refuse to run without a Main branch', function () {
    $tenant = provisionBranchDocumentsTenant('branch-docs-no-main.tenant-test');

    $tenant->run(function () {
        $migrations = loadBranchDocumentMigrations();

        Schema::withoutForeignKeyConstraints(function () {
            DB::table('branches')->where('is_main', 1)->delete();
        });

        foreach ($migrations as $migration) {
            expect(fn () => $migration->up())->toThrow(RuntimeException::class, 'No Main branch exists');
        }
    });

    $tenant->delete();
});
