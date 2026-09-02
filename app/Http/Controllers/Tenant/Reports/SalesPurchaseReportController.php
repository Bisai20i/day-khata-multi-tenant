<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Supplier;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Read-only, filterable listings over Sale/Purchase - no new tables. The
 * two VAT books are the Nepali tax-filing documents (posted rows only,
 * cancelled invoices don't belong in a filing); the two registers are
 * audit views that show every row including cancellations, with totals
 * computed over posted rows only so a cancelled sale never inflates them.
 */
class SalesPurchaseReportController extends Controller
{
    public function salesRegister(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $customerId = $request->integer('customer_id') ?: null;
        $storeId = $request->integer('store_id') ?: null;

        $sales = Sale::query()
            ->with(['customer:id,name', 'journalVoucher:id,voucher_type,voucher_number'])
            ->whereBetween('date', [$from, $to])
            ->when($customerId, fn ($query) => $query->where('customer_id', $customerId))
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $posted = $sales->where('status', 'posted');

        return Inertia::render('Tenant/Reports/SalesRegister', [
            'sales' => $sales->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'date' => $sale->date->toDateString(),
                'voucher_number' => $sale->journalVoucher?->voucher_number,
                'invoice_type' => $sale->invoice_type,
                'customer' => $sale->customer?->name,
                'taxable_amount' => (float) $sale->taxable_amount,
                'nontaxable_amount' => (float) $sale->nontaxable_amount,
                'vat_amount' => (float) $sale->vat_amount,
                'total' => (float) $sale->total,
                'payment_mode' => $sale->payment_mode,
                'status' => $sale->status,
            ])->values(),
            'totals' => [
                'taxable_amount' => (float) $posted->sum('taxable_amount'),
                'nontaxable_amount' => (float) $posted->sum('nontaxable_amount'),
                'vat_amount' => (float) $posted->sum('vat_amount'),
                'total' => (float) $posted->sum('total'),
            ],
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'customerId' => $customerId,
            'storeId' => $storeId,
        ]);
    }

    public function purchaseRegister(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $supplierId = $request->integer('supplier_id') ?: null;
        $storeId = $request->integer('store_id') ?: null;

        $purchases = Purchase::query()
            ->with(['supplier:id,name', 'journalVoucher:id,voucher_type,voucher_number'])
            ->whereBetween('date', [$from, $to])
            ->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $posted = $purchases->where('status', 'posted');

        return Inertia::render('Tenant/Reports/PurchaseRegister', [
            'purchases' => $purchases->map(fn (Purchase $purchase) => [
                'id' => $purchase->id,
                'date' => $purchase->date->toDateString(),
                'voucher_number' => $purchase->journalVoucher?->voucher_number,
                'bill_number' => $purchase->bill_number,
                'pan_number' => $purchase->pan_number,
                'supplier' => $purchase->supplier?->name,
                'taxable_amount' => (float) $purchase->taxable_amount,
                'nontaxable_amount' => (float) $purchase->nontaxable_amount,
                'vat_amount' => (float) $purchase->vat_amount,
                'total' => (float) $purchase->total,
                'payment_mode' => $purchase->payment_mode,
                'status' => $purchase->status,
            ])->values(),
            'totals' => [
                'taxable_amount' => (float) $posted->sum('taxable_amount'),
                'nontaxable_amount' => (float) $posted->sum('nontaxable_amount'),
                'vat_amount' => (float) $posted->sum('vat_amount'),
                'total' => (float) $posted->sum('total'),
            ],
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'supplierId' => $supplierId,
            'storeId' => $storeId,
        ]);
    }

    public function salesVatBook(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;

        $sales = Sale::query()
            ->with(['customer:id,name', 'journalVoucher:id,voucher_number'])
            ->where('status', 'posted')
            ->whereBetween('date', [$from, $to])
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return Inertia::render('Tenant/Reports/SalesVatBook', [
            'rows' => $sales->values()->map(fn (Sale $sale, int $index) => [
                'sn' => $index + 1,
                'date' => $sale->date->toDateString(),
                'voucher_number' => $sale->journalVoucher?->voucher_number,
                'customer' => $sale->customer?->name,
                'taxable_amount' => (float) $sale->taxable_amount,
                'vat_amount' => (float) $sale->vat_amount,
                'nontaxable_amount' => (float) $sale->nontaxable_amount,
                'total' => (float) $sale->total,
            ]),
            'totals' => [
                'taxable_amount' => (float) $sales->sum('taxable_amount'),
                'vat_amount' => (float) $sales->sum('vat_amount'),
                'nontaxable_amount' => (float) $sales->sum('nontaxable_amount'),
                'total' => (float) $sales->sum('total'),
            ],
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
        ]);
    }

    public function purchaseVatBook(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;

        $purchases = Purchase::query()
            ->with(['supplier:id,name', 'journalVoucher:id,voucher_number'])
            ->where('status', 'posted')
            ->whereBetween('date', [$from, $to])
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        return Inertia::render('Tenant/Reports/PurchaseVatBook', [
            'rows' => $purchases->values()->map(fn (Purchase $purchase, int $index) => [
                'sn' => $index + 1,
                'date' => $purchase->date->toDateString(),
                'voucher_number' => $purchase->journalVoucher?->voucher_number,
                'supplier' => $purchase->supplier?->name,
                'pan_number' => $purchase->pan_number,
                'taxable_amount' => (float) $purchase->taxable_amount,
                'vat_amount' => (float) $purchase->vat_amount,
                'nontaxable_amount' => (float) $purchase->nontaxable_amount,
                'total' => (float) $purchase->total,
            ]),
            'totals' => [
                'taxable_amount' => (float) $purchases->sum('taxable_amount'),
                'vat_amount' => (float) $purchases->sum('vat_amount'),
                'nontaxable_amount' => (float) $purchases->sum('nontaxable_amount'),
                'total' => (float) $purchases->sum('total'),
            ],
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
        ]);
    }

    /**
     * Ages every still-open `credit`-mode sale by days elapsed between its
     * invoice date and `as_of`, bucketed Current (0-30) / 31-60 / 61-90 /
     * 90+, grouped by customer.
     *
     * Outstanding is `Sale::outstandingAmount()` - total minus non-cancelled
     * returns minus non-cancelled Receipt allocations against it. Until the
     * Payment/Receipt module (2026-09-02), this app had no dedicated
     * "receive payment against invoice" feature, so a credit sale settled
     * via a generic Journal Voucher would keep aging here forever - that
     * gap is now closed as long as the payment is recorded through Receipt,
     * not a raw Journal Voucher (which still bypasses this, same as before).
     */
    public function agedReceivables(Request $request): Response
    {
        $asOf = Carbon::parse($request->string('as_of')->toString() ?: now()->toDateString());
        $storeId = $request->integer('store_id') ?: null;

        $sales = Sale::query()
            ->with('customer:id,name')
            ->where('payment_mode', 'credit')
            ->where('status', 'posted')
            ->where('date', '<=', $asOf->toDateString())
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->get();

        $byCustomer = [];

        foreach ($sales as $sale) {
            $outstanding = $sale->outstandingAmount();

            if ($outstanding <= 0.01) {
                continue;
            }

            $bucket = $this->agingBucket((int) $sale->date->diffInDays($asOf));
            $customerId = $sale->customer_id;

            $byCustomer[$customerId] ??= $this->emptyAgingRow($sale->customer?->name ?? '—');
            $byCustomer[$customerId][$bucket] = round($byCustomer[$customerId][$bucket] + $outstanding, 2);
            $byCustomer[$customerId]['total'] = round($byCustomer[$customerId]['total'] + $outstanding, 2);
        }

        $rows = collect($byCustomer)->sortBy('party')->values()->all();

        return Inertia::render('Tenant/Reports/AgedReceivables', [
            'rows' => $rows,
            'totals' => $this->sumAgingTotals($rows),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'asOf' => $asOf->toDateString(),
            'storeId' => $storeId,
        ]);
    }

    /**
     * Exact mirror of agedReceivables() for `credit`-mode purchases /
     * suppliers, via Purchase::outstandingAmount() / PurchaseReturn /
     * Payment instead of Sale::outstandingAmount() / SalesReturn / Receipt.
     */
    public function agedPayables(Request $request): Response
    {
        $asOf = Carbon::parse($request->string('as_of')->toString() ?: now()->toDateString());
        $storeId = $request->integer('store_id') ?: null;

        $purchases = Purchase::query()
            ->with('supplier:id,name')
            ->where('payment_mode', 'credit')
            ->where('status', 'posted')
            ->where('date', '<=', $asOf->toDateString())
            ->when($storeId, fn ($query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->get();

        $bySupplier = [];

        foreach ($purchases as $purchase) {
            $outstanding = $purchase->outstandingAmount();

            if ($outstanding <= 0.01) {
                continue;
            }

            $bucket = $this->agingBucket((int) $purchase->date->diffInDays($asOf));
            $supplierId = $purchase->supplier_id;

            $bySupplier[$supplierId] ??= $this->emptyAgingRow($purchase->supplier?->name ?? '—');
            $bySupplier[$supplierId][$bucket] = round($bySupplier[$supplierId][$bucket] + $outstanding, 2);
            $bySupplier[$supplierId]['total'] = round($bySupplier[$supplierId]['total'] + $outstanding, 2);
        }

        $rows = collect($bySupplier)->sortBy('party')->values()->all();

        return Inertia::render('Tenant/Reports/AgedPayables', [
            'rows' => $rows,
            'totals' => $this->sumAgingTotals($rows),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'asOf' => $asOf->toDateString(),
            'storeId' => $storeId,
        ]);
    }

    /**
     * @return array{party: string, current: float, days31_60: float, days61_90: float, days90Plus: float, total: float}
     */
    private function emptyAgingRow(string $party): array
    {
        return [
            'party' => $party,
            'current' => 0.0,
            'days31_60' => 0.0,
            'days61_90' => 0.0,
            'days90Plus' => 0.0,
            'total' => 0.0,
        ];
    }

    private function agingBucket(int $daysOutstanding): string
    {
        return match (true) {
            $daysOutstanding <= 30 => 'current',
            $daysOutstanding <= 60 => 'days31_60',
            $daysOutstanding <= 90 => 'days61_90',
            default => 'days90Plus',
        };
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{current: float, days31_60: float, days61_90: float, days90Plus: float, total: float}
     */
    private function sumAgingTotals(array $rows): array
    {
        return [
            'current' => round((float) array_sum(array_column($rows, 'current')), 2),
            'days31_60' => round((float) array_sum(array_column($rows, 'days31_60')), 2),
            'days61_90' => round((float) array_sum(array_column($rows, 'days61_90')), 2),
            'days90Plus' => round((float) array_sum(array_column($rows, 'days90Plus')), 2),
            'total' => round((float) array_sum(array_column($rows, 'total')), 2),
        ];
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
