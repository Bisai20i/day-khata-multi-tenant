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
 * - a negative row for a posted credit or debit note, dated on the note,
 *   carrying the note's OWN stored `tds_amount` - the exact amount its
 *   voucher reversed (C6);
 * - a negative row for a cancellation, dated on its Reversal voucher (C5).
 *
 * Only `status = 'posted'` returns appear: a pending request has no money
 * effect at all and a rejected one never had any.
 */
class TdsReportController extends Controller
{
    public function index(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;

        $salesRows = $this->salesRows($from, $to, $storeId);
        $purchaseRows = $this->purchaseRows($from, $to, $storeId);

        $salesTotal = $this->sumTds($salesRows);
        $purchasesTotal = $this->sumTds($purchaseRows);

        return Inertia::render('Tenant/Reports/TdsReport', [
            'sales' => $salesRows,
            'purchases' => $purchaseRows,
            'salesTotal' => $salesTotal->toString(),
            'purchasesTotal' => $purchasesTotal->toString(),
            'grandTotal' => $salesTotal->plus($purchasesTotal)->toString(),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
        ]);
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function salesRows(string $from, string $to, ?int $storeId): Collection
    {
        $issued = Sale::query()
            ->with(['customer:id,name', 'tdsAccount:id,name'])
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereNotNull('tds_account_id')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->get()
            ->map(fn (Sale $sale) => [
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
            ->get()
            ->map(fn (Sale $sale) => [
                'entry' => 'cancelled',
                'date' => $this->cancelDate($sale),
                'document_number' => $sale->invoice_number,
                'party' => $sale->buyer_name ?? $sale->customer?->name,
                'tds_account' => $sale->tdsAccount?->name,
                'base_total' => Money::of($sale->total)->negated()->toString(),
                'tds_rate' => null,
                'tds_amount' => Money::of($sale->tds_amount)->negated()->toString(),
            ]);

        $returns = SalesReturn::query()
            ->with(['sale:id,invoice_number,customer_id,buyer_name,tds_account_id', 'sale.customer:id,name', 'sale.tdsAccount:id,name'])
            ->where('status', 'posted')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->get()
            ->map(fn (SalesReturn $return) => [
                'entry' => 'credit_note',
                'date' => $return->date->toDateString(),
                'document_number' => $return->credit_note_number,
                'party' => $return->sale?->buyer_name ?? $return->sale?->customer?->name,
                'tds_account' => $return->sale?->tdsAccount?->name,
                'base_total' => Money::of($return->total)->negated()->toString(),
                'tds_rate' => null,
                'tds_amount' => Money::of($return->tds_amount)->negated()->toString(),
            ]);

        return $this->withoutZeroTds($issued->concat($returns)->concat($cancelled));
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function purchaseRows(string $from, string $to, ?int $storeId): Collection
    {
        $issued = Purchase::query()
            ->with(['supplier:id,name', 'tdsAccount:id,name'])
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereNotNull('tds_account_id')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->get()
            ->map(fn (Purchase $purchase) => [
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
            ->get()
            ->map(fn (Purchase $purchase) => [
                'entry' => 'cancelled',
                'date' => $this->cancelDate($purchase),
                'document_number' => $purchase->bill_number,
                'party' => $purchase->supplier?->name,
                'tds_account' => $purchase->tdsAccount?->name,
                'base_total' => Money::of($purchase->total)->negated()->toString(),
                'tds_rate' => $purchase->tds_rate,
                'tds_amount' => Money::of($purchase->tds_amount ?? '0.00')->negated()->toString(),
            ]);

        $returns = PurchaseReturn::query()
            ->with(['purchase:id,bill_number,supplier_id,tds_account_id,tds_rate', 'purchase.supplier:id,name', 'purchase.tdsAccount:id,name'])
            ->where('status', 'posted')
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->get()
            ->map(fn (PurchaseReturn $return) => [
                'entry' => 'debit_note',
                'date' => $return->date->toDateString(),
                'document_number' => $return->debit_note_number,
                'party' => $return->purchase?->supplier?->name,
                'tds_account' => $return->purchase?->tdsAccount?->name,
                'base_total' => Money::of($return->total)->negated()->toString(),
                'tds_rate' => $return->purchase?->tds_rate,
                'tds_amount' => Money::of($return->tds_amount)->negated()->toString(),
            ]);

        return $this->withoutZeroTds($issued->concat($returns)->concat($cancelled));
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
     * month-to-date if no fiscal year exists yet.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(Request $request): array
    {
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
