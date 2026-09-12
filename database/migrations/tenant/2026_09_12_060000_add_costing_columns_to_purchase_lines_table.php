<?php

use App\Support\Money\Money;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Gives every purchase line the four facts a return, a stock valuation and a
 * VAT/TDS report need, instead of re-deriving them by division later (audit
 * P0-3, P0-17):
 *
 * - `account_id`: the expense/asset account this line actually debited at
 *   posting. The item's `account_id` can be re-pointed afterwards, and a debit
 *   note that credits today's account when the bill debited yesterday's leaves
 *   both accounts permanently wrong.
 * - `net_value`: the line's value after its own discount AND its share of the
 *   header discount, excluding VAT. This is the rupee figure C10 wants on the
 *   stock movement (`value`) and the base every C6 return share is cut from.
 * - `vat_amount` / `tds_amount`: the line's exact share of the document's VAT
 *   and withheld TDS, allocated largest-remainder so the line shares always sum
 *   back to the document totals to the paisa.
 *
 * The backfill reproduces what the OLD posting code actually booked (header
 * discount against the vatable subtotal only), so historical rows keep matching
 * their vouchers; it is idempotent because it only touches rows whose
 * `net_value` is still null.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->foreignId('account_id')->nullable()->after('item_unit_id')->constrained('accounts')->nullOnDelete();
            $table->decimal('net_value', 15, 2)->nullable()->after('line_total');
            $table->decimal('vat_amount', 15, 2)->nullable()->after('net_value');
            $table->decimal('tds_amount', 15, 2)->nullable()->after('vat_amount');
        });

        $this->backfill();
    }

    public function down(): void
    {
        Schema::table('purchase_lines', function (Blueprint $table) {
            $table->dropConstrainedForeignId('account_id');
            $table->dropColumn(['net_value', 'vat_amount', 'tds_amount']);
        });
    }

    /**
     * Fills the four new columns for every purchase posted before this
     * migration. Re-running it is a no-op: only lines still missing
     * `net_value` are considered.
     */
    private function backfill(): void
    {
        $fallbackAccountId = DB::table('accounts')->where('code', 'EXE8')->value('id');

        $purchaseIds = DB::table('purchase_lines')
            ->whereNull('net_value')
            ->distinct()
            ->pluck('purchase_id');

        foreach ($purchaseIds as $purchaseId) {
            $purchase = DB::table('purchases')->where('id', $purchaseId)->first();

            if (! $purchase) {
                continue;
            }

            $lines = DB::table('purchase_lines')->where('purchase_id', $purchaseId)->orderBy('id')->get()->all();

            if ($lines === []) {
                continue;
            }

            $netValues = $this->netValues($purchase, $lines);
            $vatShares = $this->sharesOf(
                Money::of((string) $purchase->vat_amount),
                array_map(
                    static fn (int $index): Money => $lines[$index]->vatable ? $netValues[$index] : Money::zero(),
                    array_keys($lines)
                )
            );
            $tdsShares = $this->sharesOf(Money::of((string) $purchase->tds_amount), $netValues);
            $accountIds = $this->accountIds($purchase, $lines, $fallbackAccountId);

            foreach ($lines as $index => $line) {
                DB::table('purchase_lines')->where('id', $line->id)->update([
                    'account_id' => $accountIds[$index],
                    'net_value' => $netValues[$index]->toString(),
                    'vat_amount' => $vatShares[$index]->toString(),
                    'tds_amount' => $tdsShares[$index]->toString(),
                ]);
            }
        }
    }

    /**
     * The old posting code stored `taxable_amount` as the vatable subtotal
     * minus the whole header discount, so the discount it actually applied is
     * recoverable exactly, whatever its type was. It only ever touched vatable
     * lines, which is why the allocation weights below are vatable-only.
     *
     * @param  list<object>  $lines
     * @return list<Money>
     */
    private function netValues(object $purchase, array $lines): array
    {
        $vatableTotals = array_map(
            static fn (object $line): Money => $line->vatable ? Money::of((string) $line->line_total) : Money::zero(),
            $lines
        );
        $vatableSubtotal = Money::sum($vatableTotals);
        $headerDiscount = $vatableSubtotal->minus(Money::of((string) $purchase->taxable_amount));

        if (! $headerDiscount->isPositive() || ! $vatableSubtotal->isPositive()) {
            return array_map(static fn (object $line): Money => Money::of((string) $line->line_total), $lines);
        }

        $shares = $headerDiscount->allocate($vatableTotals);

        return array_map(
            static fn (int $index): Money => Money::of((string) $lines[$index]->line_total)->minus($shares[$index]),
            array_keys($lines)
        );
    }

    /**
     * Splits a document-level amount over the given weights, returning all
     * zeroes when there is nothing to split or nothing to split it over.
     *
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

    /**
     * The account each line debited. The item's current account is the right
     * answer whenever it still appears among the voucher's debits; when it does
     * not (the item was re-pointed since), and the voucher happens to carry
     * exactly one candidate debit account, that one is used instead.
     *
     * @param  list<object>  $lines
     * @return list<int|null>
     */
    private function accountIds(object $purchase, array $lines, ?int $fallbackAccountId): array
    {
        $itemAccounts = DB::table('items')
            ->whereIn('id', array_map(static fn (object $line): int => (int) $line->item_id, $lines))
            ->pluck('account_id', 'id');

        $debitAccountIds = DB::table('journal_voucher_lines')
            ->where('journal_voucher_id', $purchase->journal_voucher_id)
            ->where('debit', '>', 0)
            ->pluck('account_id')
            ->unique();

        $excluded = collect([
            DB::table('accounts')->where('code', 'ASA23')->value('id'),
            DB::table('suppliers')->where('id', $purchase->supplier_id)->value('account_id'),
            $purchase->tds_account_id,
        ])->filter()->all();

        $candidates = $debitAccountIds->reject(fn ($id) => in_array($id, $excluded, false))->values();
        $soleCandidate = $candidates->count() === 1 ? (int) $candidates->first() : null;

        return array_map(function (object $line) use ($itemAccounts, $debitAccountIds, $soleCandidate, $fallbackAccountId): ?int {
            $accountId = $itemAccounts[$line->item_id] ?? $fallbackAccountId;

            if ($accountId !== null && $debitAccountIds->contains($accountId)) {
                return (int) $accountId;
            }

            return $soleCandidate ?? ($accountId === null ? null : (int) $accountId);
        }, $lines);
    }
};
