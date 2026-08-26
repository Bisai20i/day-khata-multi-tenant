<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use Illuminate\Http\Request;
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

        $sales = Sale::query()
            ->with(['customer:id,name', 'journalVoucher:id,voucher_type,voucher_number'])
            ->whereBetween('date', [$from, $to])
            ->when($customerId, fn ($query) => $query->where('customer_id', $customerId))
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
            'from' => $from,
            'to' => $to,
            'customerId' => $customerId,
        ]);
    }

    public function purchaseRegister(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $supplierId = $request->integer('supplier_id') ?: null;

        $purchases = Purchase::query()
            ->with(['supplier:id,name', 'journalVoucher:id,voucher_type,voucher_number'])
            ->whereBetween('date', [$from, $to])
            ->when($supplierId, fn ($query) => $query->where('supplier_id', $supplierId))
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
            'from' => $from,
            'to' => $to,
            'supplierId' => $supplierId,
        ]);
    }

    public function salesVatBook(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);

        $sales = Sale::query()
            ->with(['customer:id,name', 'journalVoucher:id,voucher_number'])
            ->where('status', 'posted')
            ->whereBetween('date', [$from, $to])
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
            'from' => $from,
            'to' => $to,
        ]);
    }

    public function purchaseVatBook(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);

        $purchases = Purchase::query()
            ->with(['supplier:id,name', 'journalVoucher:id,voucher_number'])
            ->where('status', 'posted')
            ->whereBetween('date', [$from, $to])
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
            'from' => $from,
            'to' => $to,
        ]);
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
