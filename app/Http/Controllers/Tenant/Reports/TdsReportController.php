<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Models\FiscalYear;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Store;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchStock;
use App\Support\Money\Money;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * TDS (Tax Deducted at Source) for a filing period: every rupee withheld on
 * a sale (a credit this business can claim) and every rupee withheld on a
 * purchase (a liability owed to the tax authority), as **movements in the
 * period they actually happened in** rather than as a net figure hung on the
 * original invoice.
 *
 * That is the audit fix (P0-20). The old report netted a return's TDS back
 * into the original purchase's period, so a return filed in Ashadh silently
 * rewrote Jestha - a month that may already have been filed - and it
 * re-derived the reversed share as `tds x return.total / total`, which
 * drifts by a paisa from what the credit note's voucher really posted once a
 * note has several lines and a header discount.
 *
 * So each document contributes one row per event:
 *
 * - the invoice itself, positive, dated on the invoice (posted AND cancelled
 *   invoices alike: a cancelled bill was still withheld against in its own
 *   month);
 * - a negative row for a credit or debit note, dated on the note, carrying
 *   the note's OWN stored `tds_amount` - the exact amount its voucher
 *   reversed (C6); posted AND cancelled notes alike, so cancelling a note
 *   later never rewrites the month it was issued in;
 * - a negative row for a cancelled invoice, dated on its Reversal voucher
 *   (C5), and a positive row for a cancelled note, dated on its Reversal
 *   voucher, restoring the TDS the note had reversed.
 *
 * Pending and rejected returns never appear: a pending request has no money
 * effect at all and a rejected one never had any.
 *
 * TDS payable (deducted by us on purchases) and TDS receivable (deducted
 * from us on sales) are reported as separate totals, never netted.
 *
 * **Branches** (plan 12, BR14). The branch filter (`branch`, `branches[]`,
 * BranchFilter) narrows every row group on the document's own branch_id,
 * the same predicate the VAT books use: this is a register of documents, a
 * return inherits its original's branch and a cancellation belongs to the
 * cancelled document, so every event lands in the document's branch. In a
 * multi-branch tenant each row also names its branch (`branch`). A tenant
 * that is not multi-branch, or a view of every branch, runs exactly the old
 * queries and gets exactly the old rows.
 */
class TdsReportController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;
        $branchFilter = BranchFilter::fromRequest($request);

        $salesRows = $this->salesRows($from, $to, $storeId, $branchFilter);
        $purchaseRows = $this->purchaseRows($from, $to, $storeId, $branchFilter);

        $salesTotal = $this->sumTds($salesRows);
        $purchasesTotal = $this->sumTds($purchaseRows);

        return Inertia::render('Tenant/Reports/TdsReport', [
            'sales' => $salesRows,
            'purchases' => $purchaseRows,
            'salesTotal' => $salesTotal->toString(),
            'purchasesTotal' => $purchasesTotal->toString(),
            // Legacy prop kept for existing consumers; not shown on the page.
            'grandTotal' => $salesTotal->plus($purchasesTotal)->toString(),
            // Kept apart on purpose, as the legacy report does: TDS this
            // business deducted (purchases) must be deposited in full, while
            // TDS customers deducted from it (sales) is an advance income-tax
            // credit claimed on the annual return. Netting one against the
            // other would understate the deposit due.
            'tdsPayable' => $purchasesTotal->toString(),
            'tdsReceivable' => $salesTotal->toString(),
            'stores' => BranchStock::storeOptions($branchFilter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
            'branchReport' => $branchFilter->payload(),
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function salesRows(string $from, string $to, ?int $storeId, BranchFilter $branchFilter): Collection
    {
        $issued = Sale::query()
            ->with(['customer:id,name', 'tdsAccount:id,name'])
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereNotNull('tds_account_id')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'sales.branch_id'))
            ->get()
            ->map(fn (Sale $sale) => [
                ...$branchFilter->rowBranch($sale->branch_id),
                'entry' => 'invoice',
                'date' => $sale->date->toDateString(),
                'document_number' => $sale->invoice_number,
                'party' => $sale->buyer_name ?? $sale->customer?->name,
                'tds_account' => $sale->tdsAccount?->name,
                'base_total' => $sale->total,
                // Sales carry no `tds_rate` column: unlike a purchase, TDS on
                // a sale is typed directly as a rupee amount, never derived
                // from a rate x base calculation, so there is nothing to
                // display here.
                'tds_rate' => null,
                'tds_amount' => $sale->tds_amount,
            ]);

        $cancelled = $this->cancelledInPeriod(Sale::query()->with(['customer:id,name', 'tdsAccount:id,name']), $from, $to)
            ->whereNotNull('tds_account_id')
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'sales.branch_id'))
            ->get()
            ->map(fn (Sale $sale) => [
                ...$branchFilter->rowBranch($sale->branch_id),
                'entry' => 'cancelled',
                'date' => $this->cancelDate($sale),
                'document_number' => $sale->invoice_number,
                'party' => $sale->buyer_name ?? $sale->customer?->name,
                'tds_account' => $sale->tdsAccount?->name,
                'base_total' => Money::of($sale->total)->negated()->toString(),
                'tds_rate' => null,
                'tds_amount' => Money::of($sale->tds_amount)->negated()->toString(),
            ]);

        $returnWith = ['sale:id,invoice_number,customer_id,buyer_name,tds_account_id', 'sale.customer:id,name', 'sale.tdsAccount:id,name'];

        $returns = SalesReturn::query()
            ->with($returnWith)
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'sales_returns.branch_id'))
            ->get()
            ->map(fn (SalesReturn $return) => $this->salesReturnRow($return, $return->date->toDateString(), 'credit_note', $branchFilter));

        $cancelledReturns = $this->cancelledInPeriod(SalesReturn::query()->with($returnWith), $from, $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'sales_returns.branch_id'))
            ->get()
            ->map(fn (SalesReturn $return) => $this->salesReturnRow($return, $this->cancelDate($return), 'credit_note_cancelled', $branchFilter));

        return $this->withoutZeroTds($issued->concat($returns)->concat($cancelled)->concat($cancelledReturns));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function purchaseRows(string $from, string $to, ?int $storeId, BranchFilter $branchFilter): Collection
    {
        $issued = Purchase::query()
            ->with(['supplier:id,name', 'tdsAccount:id,name'])
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereNotNull('tds_account_id')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'purchases.branch_id'))
            ->get()
            ->map(fn (Purchase $purchase) => [
                ...$branchFilter->rowBranch($purchase->branch_id),
                'entry' => 'invoice',
                'date' => $purchase->date->toDateString(),
                'document_number' => $purchase->bill_number,
                'party' => $purchase->supplier?->name,
                'tds_account' => $purchase->tdsAccount?->name,
                'base_total' => $purchase->total,
                'tds_rate' => $purchase->tds_rate,
                'tds_amount' => $purchase->tds_amount ?? '0.00',
            ]);

        $cancelled = $this->cancelledInPeriod(Purchase::query()->with(['supplier:id,name', 'tdsAccount:id,name']), $from, $to)
            ->whereNotNull('tds_account_id')
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'purchases.branch_id'))
            ->get()
            ->map(fn (Purchase $purchase) => [
                ...$branchFilter->rowBranch($purchase->branch_id),
                'entry' => 'cancelled',
                'date' => $this->cancelDate($purchase),
                'document_number' => $purchase->bill_number,
                'party' => $purchase->supplier?->name,
                'tds_account' => $purchase->tdsAccount?->name,
                'base_total' => Money::of($purchase->total)->negated()->toString(),
                'tds_rate' => $purchase->tds_rate,
                'tds_amount' => Money::of($purchase->tds_amount ?? '0.00')->negated()->toString(),
            ]);

        $returnWith = ['purchase:id,bill_number,supplier_id,tds_account_id,tds_rate', 'purchase.supplier:id,name', 'purchase.tdsAccount:id,name'];

        $returns = PurchaseReturn::query()
            ->with($returnWith)
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'purchase_returns.branch_id'))
            ->get()
            ->map(fn (PurchaseReturn $return) => $this->purchaseReturnRow($return, $return->date->toDateString(), 'debit_note', $branchFilter));

        $cancelledReturns = $this->cancelledInPeriod(PurchaseReturn::query()->with($returnWith), $from, $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'purchase_returns.branch_id'))
            ->get()
            ->map(fn (PurchaseReturn $return) => $this->purchaseReturnRow($return, $this->cancelDate($return), 'debit_note_cancelled', $branchFilter));

        return $this->withoutZeroTds($issued->concat($returns)->concat($cancelled)->concat($cancelledReturns));
    }

    /**
     * A credit note's TDS row. Issuing the note reverses TDS (negative); a
     * later cancellation of the note restores it (positive), dated on the
     * note's Reversal voucher, so the note's issue month stays as filed.
     *
     * @return array<string, mixed>
     */
    private function salesReturnRow(SalesReturn $return, string $date, string $entry, BranchFilter $branchFilter): array
    {
        $isCancellation = $entry === 'credit_note_cancelled';
        $total = Money::of($return->total);
        $tds = Money::of($return->tds_amount ?? '0.00');

        return [
            ...$branchFilter->rowBranch($return->branch_id),
            'entry' => $entry,
            'date' => $date,
            'document_number' => $return->credit_note_number,
            'party' => $return->sale?->buyer_name ?? $return->sale?->customer?->name,
            'tds_account' => $return->sale?->tdsAccount?->name,
            'base_total' => ($isCancellation ? $total : $total->negated())->toString(),
            'tds_rate' => null,
            'tds_amount' => ($isCancellation ? $tds : $tds->negated())->toString(),
        ];
    }

    /**
     * A debit note's TDS row: the purchase-side mirror of salesReturnRow().
     *
     * @return array<string, mixed>
     */
    private function purchaseReturnRow(PurchaseReturn $return, string $date, string $entry, BranchFilter $branchFilter): array
    {
        $isCancellation = $entry === 'debit_note_cancelled';
        $total = Money::of($return->total);
        $tds = Money::of($return->tds_amount ?? '0.00');

        return [
            ...$branchFilter->rowBranch($return->branch_id),
            'entry' => $entry,
            'date' => $date,
            'document_number' => $return->debit_note_number,
            'party' => $return->purchase?->supplier?->name,
            'tds_account' => $return->purchase?->tdsAccount?->name,
            'base_total' => ($isCancellation ? $total : $total->negated())->toString(),
            'tds_rate' => $return->purchase?->tds_rate,
            'tds_amount' => ($isCancellation ? $tds : $tds->negated())->toString(),
        ];
    }

    /**
     * Rows with no TDS effect at all are noise on a TDS report, so they are
     * dropped - but only on an exact zero. The old `<= 0.01` filter was a
     * float tolerance that quietly hid a real one paisa withholding.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function withoutZeroTds(Collection $rows): Collection
    {
        return $rows
            ->reject(fn (array $row) => Money::of($row['tds_amount'])->isZero())
            ->sortBy([['date', 'asc'], ['entry', 'asc'], ['document_number', 'asc']])
            ->values();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     */
    private function sumTds(Collection $rows): Money
    {
        return Money::sum($rows->map(fn (array $row) => Money::of($row['tds_amount'])));
    }

    /**
     * Documents whose cancellation lands in the period: the Reversal
     * voucher's own date (C5), falling back to `cancelled_at` for rows
     * cancelled before the Reversal voucher type existed.
     *
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    private function cancelledInPeriod($query, string $from, string $to)
    {
        return $query
            ->where('status', 'cancelled')
            ->with('reversalJournalVoucher:id,date')
            ->where(function (Builder $outer) use ($from, $to) {
                $outer
                    ->whereHas(
                        'reversalJournalVoucher',
                        fn (Builder $voucher) => $voucher->whereDate('date', '>=', $from)->whereDate('date', '<=', $to),
                    )
                    ->orWhere(fn (Builder $legacy) => $legacy
                        ->whereNull('reversal_journal_voucher_id')
                        ->whereDate('cancelled_at', '>=', $from)
                        ->whereDate('cancelled_at', '<=', $to));
            });
    }

    private function cancelDate(Model $document): string
    {
        return $document->reversalJournalVoucher?->date?->toDateString()
            ?? $document->cancelled_at?->toDateString()
            ?? $document->date->toDateString();
    }

    /**
     * Defaults to the current open fiscal year's date range when no
     * explicit `from`/`to` query params are given, falling back to
     * month-to-date if no fiscal year exists yet. A lone `from` or `to` is
     * rejected (422), never silently widened to the fiscal year.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(Request $request): array
    {
        // Strict Y-m-d, as every other report: a malformed, reversed or
        // half-supplied range, or an unknown store, is a validation error the
        // page shows, not a database exception.
        $request->validate([
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
        ]);

        $from = $request->string('from')->toString();
        $to = $request->string('to')->toString();

        if ($from !== '' && $to !== '') {
            return [$from, $to];
        }

        $fiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        if ($fiscalYear) {
            return [$fiscalYear->start_date->toDateString(), $fiscalYear->end_date->toDateString()];
        }

        return [now()->startOfMonth()->toDateString(), now()->toDateString()];
    }
}
