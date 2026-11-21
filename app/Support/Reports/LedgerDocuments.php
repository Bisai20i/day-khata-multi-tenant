<?php

namespace App\Support\Reports;

use App\Models\CapitalPurchase;
use App\Models\CapitalSale;
use App\Models\FixedAsset;
use App\Models\FixedAssetDepreciation;
use App\Models\JournalVoucher;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Receipt;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\StockTransfer;
use App\Support\Branches\BranchContext;
use Closure;
use Illuminate\Database\Eloquent\Model;

/**
 * Batched "source document" lookup for ledger lines: given every voucher id a
 * ledger shows, finds the module record (sale, purchase, return, receipt, ...)
 * that posted each one, with ONE query per owning table instead of up to 15
 * per line.
 *
 * The lookup list and its precedence are the ones the per-line
 * AccountController::documentFor() used: the first list entry that owns a
 * voucher wins, and a voucher no entry owns (a standalone Journal, Opening
 * Balance, Closing Entry, Roll Forward) is simply absent from the result.
 */
final class LedgerDocuments
{
    /** Ids per whereIn, comfortably below SQLite's and MySQL's bound-parameter limits. */
    private const CHUNK_SIZE = 500;

    /**
     * @param  list<int>  $voucherIds
     * @return array<int, array{reference: ?string, label: string, url: ?string}>
     */
    public static function forVouchers(array $voucherIds): array
    {
        $pending = [];

        foreach ($voucherIds as $voucherId) {
            $pending[(int) $voucherId] = true;
        }

        if ($pending === []) {
            return [];
        }

        // Unrestricted (plan 12, BR07 carry-forward f): a voucher line the
        // user may see can belong to a document filed in a branch they cannot
        // (a receipt in B2 settling a B1 invoice); the label is still shown.
        return BranchContext::unrestricted(function () use ($pending): array {
            /** @var array<int, array{reference: ?string, label: string, url: ?string}> $resolved */
            $resolved = [];

            foreach (self::lookups() as [$modelClass, $column, $resolve, $eagerLoad]) {
                $unresolved = array_keys(array_diff_key($pending, $resolved));

                if ($unresolved === []) {
                    break;
                }

                foreach (array_chunk($unresolved, self::CHUNK_SIZE) as $slice) {
                    $records = $modelClass::query()
                        ->with($eagerLoad)
                        ->whereIn($column, $slice)
                        ->orderBy('id')
                        ->get();

                    if (in_array('journalVoucher', $eagerLoad, true)) {
                        JournalVoucher::loadSeriesBranches($records->map(fn (Model $record) => $record->getRelation('journalVoucher'))->filter());
                    }

                    foreach ($records as $record) {
                        $voucherId = (int) $record->getAttribute($column);

                        if (isset($resolved[$voucherId])) {
                            continue;
                        }

                        $resolved[$voucherId] = self::normalise($resolve($record));
                    }
                }
            }

            return $resolved;
        });
    }

    /**
     * Empty-string references mean "no number to show".
     *
     * @param  array{reference?: ?string, label: string, url: ?string}  $document
     * @return array{reference: ?string, label: string, url: ?string}
     */
    private static function normalise(array $document): array
    {
        $reference = $document['reference'] ?? null;

        return [
            'reference' => is_string($reference) && trim($reference) !== '' ? $reference : null,
            'label' => $document['label'],
            'url' => $document['url'],
        ];
    }

    /**
     * Same order as the old documentFor(); each entry is
     * [model class, voucher column, resolver, relations the resolver reads].
     *
     * @return list<array{0: class-string<Model>, 1: string, 2: Closure(Model): array{reference?: ?string, label: string, url: ?string}, 3: list<string>}>
     */
    private static function lookups(): array
    {
        return [
            [Sale::class, 'journal_voucher_id', fn (Sale $m) => [
                'reference' => $m->invoice_number,
                'label' => 'Sale '.($m->invoice_number ?? "#{$m->id}"),
                'url' => route('tenant.sales.print', $m),
            ], []],
            [Purchase::class, 'journal_voucher_id', fn (Purchase $m) => [
                'reference' => $m->bill_number,
                'label' => 'Purchase '.($m->bill_number ?: "#{$m->id}"),
                'url' => route('tenant.purchases.print', $m),
            ], []],
            // documentNumber() reads journalVoucher (and its seriesBranch) for a posted return with no stored credit-note number.
            [SalesReturn::class, 'journal_voucher_id', fn (SalesReturn $m) => [
                'reference' => $m->documentNumber(),
                'label' => $m->documentNumber(),
                'url' => route('tenant.sales-returns.print', $m),
            ], ['journalVoucher']],
            [PurchaseReturn::class, 'journal_voucher_id', fn (PurchaseReturn $m) => [
                'reference' => $m->documentNumber(),
                'label' => $m->documentNumber(),
                'url' => route('tenant.purchase-returns.print', $m),
            ], []],
            [CapitalSale::class, 'journal_voucher_id', fn (CapitalSale $m) => [
                'reference' => $m->documentNumber(),
                'label' => 'Capital Sale '.$m->documentNumber(),
                'url' => route('tenant.capital-sales.print', $m),
            ], []],
            [CapitalPurchase::class, 'journal_voucher_id', fn (CapitalPurchase $m) => [
                'reference' => $m->bill_number,
                'label' => 'Capital Purchase '.($m->bill_number ?: "#{$m->id}"),
                'url' => route('tenant.capital-purchases.print', $m),
            ], []],
            [Receipt::class, 'journal_voucher_id', fn (Receipt $m) => [
                'reference' => null,
                'label' => "Receipt #{$m->id}",
                'url' => route('tenant.receipts.print', $m),
            ], []],
            [Payment::class, 'journal_voucher_id', fn (Payment $m) => [
                'reference' => null,
                'label' => "Payment #{$m->id}",
                'url' => null,
            ], []],
            [FixedAsset::class, 'journal_voucher_id', fn (FixedAsset $m) => [
                'reference' => null,
                'label' => "Fixed Asset {$m->asset_code}",
                'url' => null,
            ], []],
            [FixedAsset::class, 'disposal_journal_voucher_id', fn (FixedAsset $m) => [
                'reference' => null,
                'label' => "Fixed Asset disposal {$m->asset_code}",
                'url' => null,
            ], []],
            [FixedAssetDepreciation::class, 'journal_voucher_id', fn (FixedAssetDepreciation $m) => [
                'reference' => null,
                'label' => 'Depreciation '.($m->fixedAsset?->asset_code ?? "#{$m->fixed_asset_id}"),
                'url' => null,
            ], ['fixedAsset']],
            // A transfer between branches (BR18) owns its dispatch, receipt
            // and cancellation vouchers; each links to the transfer's print.
            [StockTransfer::class, 'journal_voucher_id', fn (StockTransfer $m) => [
                'reference' => null,
                'label' => "Stock transfer #{$m->id} (dispatch)",
                'url' => route('tenant.stock-transfers.print', $m),
            ], []],
            [StockTransfer::class, 'receipt_journal_voucher_id', fn (StockTransfer $m) => [
                'reference' => null,
                'label' => "Stock transfer #{$m->id} (receipt)",
                'url' => route('tenant.stock-transfers.print', $m),
            ], []],
            [StockTransfer::class, 'reversal_journal_voucher_id', fn (StockTransfer $m) => [
                'reference' => null,
                'label' => "Stock transfer #{$m->id} (cancelled)",
                'url' => route('tenant.stock-transfers.print', $m),
            ], []],
        ];
    }
}
