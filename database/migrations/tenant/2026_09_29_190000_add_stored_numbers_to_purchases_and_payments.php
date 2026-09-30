<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Purchases and payments get a stored number (flags G-18, PUR-05). Both
 * used to be derived at print time from the current prefix and the voucher
 * number, falling back to "{prefix}-{id}", so changing the purchase prefix
 * silently renumbered every bill already printed. The number is now written
 * once, at posting, in the format they have always printed in (decision:
 * freeze today's format), and is unique within its fiscal year.
 *
 * Payments also record their fiscal year, taken from their voucher, which
 * the per-year uniqueness needs.
 *
 * The backfill is idempotent (it only fills rows still null) and uses only
 * portable SQL, so it runs the same on SQLite and MySQL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchases', function (Blueprint $table) {
            $table->string('purchase_number')->nullable()->after('fiscal_year_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('fiscal_year_id')->nullable()->after('journal_voucher_id')
                ->constrained('fiscal_years')->nullOnDelete();
            $table->string('payment_number')->nullable()->after('fiscal_year_id');
        });

        $this->backfill();

        Schema::table('purchases', function (Blueprint $table) {
            $table->unique(['fiscal_year_id', 'purchase_number'], 'purchases_year_number_unique');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->unique(['fiscal_year_id', 'payment_number'], 'payments_year_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropUnique('payments_year_number_unique');
            $table->dropConstrainedForeignId('fiscal_year_id');
            $table->dropColumn('payment_number');
        });

        Schema::table('purchases', function (Blueprint $table) {
            $table->dropUnique('purchases_year_number_unique');
            $table->dropColumn('purchase_number');
        });
    }

    private function backfill(): void
    {
        // The prefix a bill printed with is the one in force today: prefixes
        // were never snapshotted, so this is exactly what a reprint showed.
        $purchasePrefix = DB::table('company_settings')->orderBy('id')->value('purchase_prefix') ?? 'PU';

        DB::table('purchases')
            ->join('journal_vouchers', 'journal_vouchers.id', '=', 'purchases.journal_voucher_id')
            ->whereNull('purchases.purchase_number')
            ->orderBy('purchases.id')
            ->select(['purchases.id', 'journal_vouchers.voucher_number'])
            ->get()
            ->each(fn ($row) => DB::table('purchases')->where('id', $row->id)->update([
                'purchase_number' => "{$purchasePrefix}-{$row->voucher_number}",
            ]));

        DB::table('payments')
            ->join('journal_vouchers', 'journal_vouchers.id', '=', 'payments.journal_voucher_id')
            ->whereNull('payments.payment_number')
            ->orderBy('payments.id')
            ->select(['payments.id', 'journal_vouchers.voucher_number', 'journal_vouchers.fiscal_year_id'])
            ->get()
            ->each(fn ($row) => DB::table('payments')->where('id', $row->id)->update([
                'fiscal_year_id' => $row->fiscal_year_id,
                'payment_number' => "PMT-{$row->voucher_number}",
            ]));
    }
};
