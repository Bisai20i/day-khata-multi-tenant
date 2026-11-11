<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Area master plus the nullable area on every customer and supplier, so the
 * Debtors / Creditors lists and the receivable / payable ageing reports can
 * be filtered and grouped by area.
 *
 * The area lives on the party (customers / suppliers), not on its ledger
 * Account: every report that needs it is built from those two tables, and a
 * hand-made account under Sundry Debtors is not a customer anyway.
 *
 * restrictOnDelete: an area that still has parties cannot be removed (the
 * controller says so in plain words first; this is the database backstop).
 * Idempotent, so a half-applied run can be re-run.
 */
return new class extends Migration
{
    /** @var list<string> */
    private const PARTY_TABLES = ['customers', 'suppliers'];

    public function up(): void
    {
        if (! Schema::hasTable('areas')) {
            Schema::create('areas', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150)->unique();
                $table->timestamps();
            });
        }

        foreach (self::PARTY_TABLES as $partyTable) {
            if (Schema::hasColumn($partyTable, 'area_id')) {
                continue;
            }

            Schema::table($partyTable, function (Blueprint $table) {
                $table->foreignId('area_id')
                    ->nullable()
                    ->after('address')
                    ->constrained('areas')
                    ->restrictOnDelete();
            });
        }
    }

    public function down(): void
    {
        foreach (self::PARTY_TABLES as $partyTable) {
            if (! Schema::hasColumn($partyTable, 'area_id')) {
                continue;
            }

            // Foreign key first (its index is the column's own index), then the column.
            Schema::table($partyTable, function (Blueprint $table) {
                $table->dropForeign(['area_id']);
            });

            Schema::table($partyTable, function (Blueprint $table) {
                $table->dropColumn('area_id');
            });
        }

        Schema::dropIfExists('areas');
    }
};
