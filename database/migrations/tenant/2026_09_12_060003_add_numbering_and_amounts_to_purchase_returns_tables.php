<?php

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two things the debit-note side was missing.
 *
 * Numbering (CONTRACTS C7): a purchase return's number was derived from its
 * voucher number at display time, so a later cancellation that consumed a
 * voucher number silently renumbered nothing while leaving a gap in the printed
 * series. The number is now stored on the row the moment it is posted, together
 * with the fiscal year it belongs to, and nothing re-derives it afterwards.
 *
 * Amounts (CONTRACTS C6): the TDS share a return reverses was computed on the
 * fly and thrown away, and the per-line VAT and TDS shares did not exist at
 * all, so a second partial return had nothing to subtract from and lost a paisa
 * (audit P0-3). `net_value`, `vat_amount` and `tds_amount` now record exactly
 * what each return line credited.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->foreignId('fiscal_year_id')->nullable()->after('journal_voucher_id')
                ->constrained('fiscal_years')->nullOnDelete();
            $table->string('debit_note_number', 40)->nullable()->after('fiscal_year_id');
            $table->decimal('tds_amount', 15, 2)->default(0)->after('vat_amount');
        });

        Schema::table('purchase_return_lines', function (Blueprint $table) {
            $table->decimal('net_value', 15, 2)->nullable()->after('line_total');
            $table->decimal('vat_amount', 15, 2)->nullable()->after('net_value');
            $table->decimal('tds_amount', 15, 2)->nullable()->after('vat_amount');
        });

        $this->backfill();

        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->unique(['fiscal_year_id', 'debit_note_number'], 'purchase_returns_debit_note_number_unique');
        });
    }

    public function down(): void
    {
        Schema::table('purchase_returns', function (Blueprint $table) {
            $table->dropUnique('purchase_returns_debit_note_number_unique');
            $table->dropConstrainedForeignId('fiscal_year_id');
            $table->dropColumn(['debit_note_number', 'tds_amount']);
        });

        Schema::table('purchase_return_lines', function (Blueprint $table) {
            $table->dropColumn(['net_value', 'vat_amount', 'tds_amount']);
        });
    }

    /**
     * Idempotent: only returns whose `debit_note_number` is still null are
     * touched, and each return's lines are filled in the same pass.
     */
    private function backfill(): void
    {
        // The tenant's own debit-note prefix, not a hardcoded "PR": the
        // Settings screen lets a tenant choose one and checks it stays
        // distinct from the other series, which is meaningless if the stored
        // numbers ignore it.
        $prefix = DB::table('company_settings')->value('purchase_return_prefix') ?: 'PR';

        $returns = DB::table('purchase_returns')
            ->whereNull('debit_note_number')
            ->orderBy('id')
            ->get(['id', 'purchase_id', 'journal_voucher_id', 'taxable_amount', 'nontaxable_amount', 'vat_amount', 'total']);

        foreach ($returns as $return) {
            $voucher = DB::table('journal_vouchers')->where('id', $return->journal_voucher_id)->first();
            $purchase = DB::table('purchases')->where('id', $return->purchase_id)->first();

            if (! $voucher || ! $purchase) {
                continue;
            }

            $tdsShare = $this->tdsShare($purchase, $return);

            DB::table('purchase_returns')->where('id', $return->id)->update([
                'fiscal_year_id' => $voucher->fiscal_year_id,
                'debit_note_number' => $prefix.'-'.$voucher->voucher_number,
                'tds_amount' => $tdsShare->toString(),
            ]);

            $this->backfillLines($return, $tdsShare);
        }
    }

    /**
     * What the old posting code credited back out of the TDS liability:
     * the withheld amount scaled by this return's share of the bill.
     */
    private function tdsShare(object $purchase, object $return): Money
    {
        $withheld = Money::of((string) $purchase->tds_amount);
        $purchaseTotal = Money::of((string) $purchase->total);

        if (! $withheld->isPositive() || ! $purchaseTotal->isPositive()) {
            return Money::zero();
        }

        return $withheld->multipliedByFraction(Money::of((string) $return->total), $purchaseTotal);
    }

    /**
     * Legacy return lines stored the amount BEFORE the original bill's header
     * discount was taken off (only the voucher carried the discounted figure),
     * so `net_value` is recovered by splitting the return's own stored taxable
     * and non-taxable totals, which were discounted, back over its lines.
     */
    private function backfillLines(object $return, Money $tdsShare): void
    {
        $lines = DB::table('purchase_return_lines as prl')
            ->join('purchase_lines as pl', 'pl.id', '=', 'prl.purchase_line_id')
            ->where('prl.purchase_return_id', $return->id)
            ->orderBy('prl.id')
            ->get(['prl.id', 'prl.line_total', 'pl.vatable'])
            ->all();

        if ($lines === []) {
            return;
        }

        $vatableWeights = array_map(
            static fn (object $line): Money => $line->vatable ? Money::of((string) $line->line_total) : Money::zero(),
            $lines
        );
        $nonVatableWeights = array_map(
            static fn (object $line): Money => $line->vatable ? Money::zero() : Money::of((string) $line->line_total),
            $lines
        );

        $taxableShares = $this->sharesOf(Money::of((string) $return->taxable_amount), $vatableWeights);
        $nonTaxableShares = $this->sharesOf(Money::of((string) $return->nontaxable_amount), $nonVatableWeights);

        $netValues = array_map(
            static fn (int $index): Money => $taxableShares[$index]->plus($nonTaxableShares[$index]),
            array_keys($lines)
        );

        $vatShares = $this->sharesOf(
            Money::of((string) $return->vat_amount),
            array_map(
                static fn (int $index): Money => $lines[$index]->vatable ? $netValues[$index] : Money::zero(),
                array_keys($lines)
            )
        );
        $tdsShares = $this->sharesOf($tdsShare, $netValues);

        foreach ($lines as $index => $line) {
            DB::table('purchase_return_lines')->where('id', $line->id)->update([
                'net_value' => $netValues[$index]->toString(),
                'vat_amount' => $vatShares[$index]->toString(),
                'tds_amount' => $tdsShares[$index]->toString(),
            ]);
        }
    }

    /**
     * @param  list<Money>  $weights
     * @return list<Money>
     */
    private function sharesOf(Money $amount, array $weights): array
    {
        if ($amount->isZero() || ! Money::sum($weights)->isPositive()) {
            return array_map(static fn (): Money => Money::zero(), $weights);
        }

        return $amount->allocate($weights);
    }
};
