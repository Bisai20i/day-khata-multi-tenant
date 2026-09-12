<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Stored credit-note identity plus the shared cancellation columns
     * (CONTRACTS C5/C7).
     *
     * A credit note's number used to be derived at display time from its
     * journal voucher, which meant a cancellation that consumed a voucher
     * number silently renumbered nothing but left a hole in the printed
     * series (audit P0-15). The number is now stored on the row, unique
     * within its fiscal year, and set once at posting. Pending and rejected
     * requests keep NULL in both columns: they are not credit notes and
     * must never be titled or numbered as one. Both MySQL and SQLite allow
     * repeated NULLs in a unique index, so the pair stays uniquely indexed
     * without excluding those rows by hand.
     *
     * `tds_amount` is the TDS this return actually reversed. It was derived
     * from the sale's total at posting time and thrown away; storing it is
     * what lets the last-returned-quantity rule (C6) subtract exactly what
     * earlier returns already credited, and what lets the TDS report read
     * the reversal instead of re-deriving it.
     */
    public function up(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->foreignId('fiscal_year_id')->nullable()->after('journal_voucher_id')->constrained()->nullOnDelete();
            $table->string('credit_note_number', 40)->nullable()->after('fiscal_year_id');
            $table->decimal('tds_amount', 15, 2)->default(0)->after('vat_amount');
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('cancelled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('cancel_reason')->nullable();
            $table->foreignId('reversal_journal_voucher_id')->nullable()->constrained('journal_vouchers')->nullOnDelete();
        });

        $this->backfillCreditNoteNumbers();

        Schema::table('sales_returns', function (Blueprint $table) {
            $table->unique(['fiscal_year_id', 'credit_note_number'], 'sales_returns_credit_note_number_unique');
        });
    }

    /**
     * Every already-posted (or since-cancelled) return gets the number its
     * print view has always shown, "{prefix}-{voucher_number}", and the
     * fiscal year of its own voucher - so nothing reprints differently than
     * it did before this column existed. The prefix comes from
     * `company_settings.sale_return_prefix` (added one migration earlier,
     * default "SR", which is the literal the old print view hardcoded), so a
     * tenant that renamed its credit-note series keeps that name on its
     * history too. Idempotent: only rows still missing a number are touched,
     * and a return that never posted is skipped.
     */
    private function backfillCreditNoteNumbers(): void
    {
        $prefix = trim((string) DB::table('company_settings')->value('sale_return_prefix'));

        if ($prefix === '') {
            $prefix = 'SR';
        }

        DB::table('sales_returns')
            ->whereNull('credit_note_number')
            ->whereNotNull('journal_voucher_id')
            ->whereIn('status', ['posted', 'cancelled'])
            ->chunkById(200, function ($returns) use ($prefix) {
                $vouchers = DB::table('journal_vouchers')
                    ->whereIn('id', collect($returns)->pluck('journal_voucher_id')->filter()->all())
                    ->get(['id', 'fiscal_year_id', 'voucher_number'])
                    ->keyBy('id');

                foreach ($returns as $salesReturn) {
                    $voucher = $vouchers->get($salesReturn->journal_voucher_id);

                    if (! $voucher) {
                        continue;
                    }

                    DB::table('sales_returns')->where('id', $salesReturn->id)->update([
                        'fiscal_year_id' => $voucher->fiscal_year_id,
                        'credit_note_number' => "{$prefix}-{$voucher->voucher_number}",
                    ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('sales_returns', function (Blueprint $table) {
            $table->dropUnique('sales_returns_credit_note_number_unique');
            $table->dropConstrainedForeignId('reversal_journal_voucher_id');
            $table->dropConstrainedForeignId('cancelled_by');
            $table->dropConstrainedForeignId('fiscal_year_id');
            $table->dropColumn(['credit_note_number', 'tds_amount', 'cancelled_at', 'cancel_reason']);
        });
    }
};
