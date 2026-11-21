<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Enums\VoucherType;
use App\Exports\AgedPartyExport;
use App\Exports\CreditorsExport;
use App\Exports\DebtorsExport;
use App\Exports\PurchaseReturnRegisterExport;
use App\Exports\PurchaseVatBookExport;
use App\Exports\SalesReturnRegisterExport;
use App\Exports\SalesVatBookExport;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Area;
use App\Models\CapitalPurchase;
use App\Models\CapitalSale;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\JournalVoucher;
use App\Models\JournalVoucherLine;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Store;
use App\Models\Supplier;
use App\Support\AreaFilter;
use App\Support\AsOfDate;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchStock;
use App\Support\Branches\PartyBranchBalances;
use App\Support\Money\Money;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;

/**
 * Every filing-grade listing over the sale and purchase side of the books:
 * the two registers, the two VAT books, the two return registers, the two
 * ageing reports and the two ledger-based party lists.
 *
 * Three rules the 2026-09-11 audit forced on all of them (P0-20):
 *
 * 1. **Capital documents are real VAT documents.** A capital sale credits
 *    Output VAT on LIA20 and a capital purchase debits Input VAT on ASA23
 *    exactly like an ordinary bill, so both belong in the VAT books and in
 *    the VAT summary. They used to be excluded, which is why the report
 *    never tied to the ledger.
 * 2. **Cancellations move, they do not vanish.** A cancelled bill is still
 *    a bill that was issued in its own month: filtering it out rewrote a
 *    month that may already have been filed. Instead every cancelled
 *    document contributes two rows - the original, positive, in the period
 *    it was issued, and a negative "Cancelled" row in the period its
 *    Reversal voucher is dated (C5). Net across both periods is zero, and
 *    each period matches the ledger movement for that period exactly.
 * 3. **Only `status = 'posted'` returns count** (C6). A pending return
 *    request reserves quantity and nothing else, and is never shown or
 *    numbered as a credit note.
 *
 * Money never passes through a float here. Row amounts are the stored
 * DECIMAL columns read back as strings through App\Casts\Decimal, totals
 * are `Money::sum()` over exactly the rows on screen (so a total can never
 * disagree with the list above it), and ledger balances are summed in SQL
 * as scaled integers because SQLite gives a DECIMAL column REAL affinity.
 */
class SalesPurchaseReportController extends Controller
{
    /** Ageing buckets, in days outstanding. */
    private const AGING_BUCKETS = ['current', 'days31_60', 'days61_90', 'days90Plus'];

    /** Debtors/Creditors/ageing filters: a bad year, date or store is a 422, not a silent fallback. */
    private const PARTY_REPORT_INPUT_RULES = [
        'fiscal_year_id' => ['nullable', 'integer', 'exists:fiscal_years,id'],
        'as_of' => ['nullable', 'date_format:Y-m-d'],
        'store_id' => ['nullable', 'integer', 'exists:stores,id'],
    ];

    public function salesRegister(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $customerId = $request->integer('customer_id') ?: null;
        $storeId = $request->integer('store_id') ?: null;
        $paymentMode = $this->resolvePaymentMode($request);
        $search = $request->string('search')->trim()->toString();
        $branchFilter = BranchFilter::fromRequest($request);

        $sales = Sale::query()
            ->with(['customer:id,name', 'journalVoucher:id,voucher_type,voucher_number'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'sales.branch_id'))
            ->when($customerId, fn (Builder $query) => $query->where('customer_id', $customerId))
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->when($paymentMode, fn (Builder $query) => $query->where('payment_mode', $paymentMode))
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('invoice_number', 'like', "%{$search}%")
                        ->orWhere('buyer_name', 'like', "%{$search}%")
                        ->orWhere('chalani_number', 'like', "%{$search}%")
                        ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $rows = $sales->map(function (Sale $sale) use ($branchFilter) {
            $subtotal = Money::of($sale->taxable_amount)->plus(Money::of($sale->nontaxable_amount))->toString();

            return [
                'id' => $sale->id,
                'date' => $sale->date->toDateString(),
                'invoice_number' => $sale->invoice_number,
                'invoice_type' => $sale->invoice_type,
                'chalani_number' => $sale->chalani_number,
                'customer' => $sale->buyer_name ?? $sale->customer?->name,
                'subtotal' => $subtotal,
                'nontaxable_amount' => $sale->nontaxable_amount,
                'taxable_amount' => $sale->taxable_amount,
                'vat_amount' => $sale->vat_amount,
                'round_off' => $sale->round_off,
                'total' => $sale->total,
                'payment_mode' => $sale->payment_mode,
                'status' => $sale->status,
                ...$branchFilter->rowBranch($sale->branch_id),
            ];
        })->values();

        $posted = $rows->filter(fn (array $row) => $row['status'] === 'posted')->values();

        return Inertia::render('Tenant/Reports/SalesRegister', [
            'sales' => $rows,
            'totals' => $this->totalsFor($posted, ['subtotal', 'nontaxable_amount', 'taxable_amount', 'vat_amount', 'round_off', 'total']),
            'search' => $search,
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'stores' => BranchStock::storeOptions($branchFilter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'customerId' => $customerId,
            'storeId' => $storeId,
            'paymentMode' => $paymentMode,
            'branchReport' => $branchFilter->payload(),
        ]);
    }

    /**
     * Trading purchases only, by design (audit T15-7).
     *
     * Legacy's purchaseRegularReport() has no purchaseType='capital' exclusion, so a capital
     * purchase recorded as a purchase_records row is included in legacy's Purchase Register
     * total. That appears to be a legacy oversight rather than intended behavior. Decision:
     * keep this report scoped to trading purchases (Purchase model) only; CapitalPurchase
     * rows remain visible via the Purchase VAT Book instead of being unioned in here.
     */
    public function purchaseRegister(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $supplierId = $request->integer('supplier_id') ?: null;
        $storeId = $request->integer('store_id') ?: null;
        $paymentMode = $this->resolvePaymentMode($request);
        $search = $request->string('search')->trim()->toString();
        $branchFilter = BranchFilter::fromRequest($request);

        $purchases = Purchase::query()
            ->with(['supplier:id,name,tpin', 'journalVoucher:id,voucher_type,voucher_number,series_branch_id'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'purchases.branch_id'))
            ->when($supplierId, fn (Builder $query) => $query->where('supplier_id', $supplierId))
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->when($paymentMode, fn (Builder $query) => $query->where('payment_mode', $paymentMode))
            ->when($search !== '', function (Builder $query) use ($search) {
                $query->where(function (Builder $q) use ($search) {
                    $q->where('bill_number', 'like', "%{$search}%")
                        ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$search}%"));
                });
            })
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        JournalVoucher::loadSeriesBranches($purchases->pluck('journalVoucher'));

        $rows = $purchases->map(function (Purchase $purchase) use ($branchFilter) {
            $subtotal = Money::of($purchase->taxable_amount)->plus(Money::of($purchase->nontaxable_amount))->toString();

            return [
                'id' => $purchase->id,
                'date' => $purchase->date->toDateString(),
                'voucher_number' => $purchase->journalVoucher?->displayNumber(),
                'bill_number' => $purchase->bill_number,
                'pan_number' => $purchase->pan_number ?: $purchase->supplier?->tpin,
                'supplier' => $purchase->supplier?->name,
                'subtotal' => $subtotal,
                'nontaxable_amount' => $purchase->nontaxable_amount,
                'taxable_amount' => $purchase->taxable_amount,
                'vat_amount' => $purchase->vat_amount,
                'round_off' => $purchase->round_off,
                'total' => $purchase->total,
                'payment_mode' => $purchase->payment_mode,
                'status' => $purchase->status,
                ...$branchFilter->rowBranch($purchase->branch_id),
            ];
        })->values();

        $posted = $rows->filter(fn (array $row) => $row['status'] === 'posted')->values();

        return Inertia::render('Tenant/Reports/PurchaseRegister', [
            'purchases' => $rows,
            'totals' => $this->totalsFor($posted, ['subtotal', 'nontaxable_amount', 'taxable_amount', 'vat_amount', 'round_off', 'total']),
            'search' => $search,
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'stores' => BranchStock::storeOptions($branchFilter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'supplierId' => $supplierId,
            'storeId' => $storeId,
            'paymentMode' => $paymentMode,
            'branchReport' => $branchFilter->payload(),
        ]);
    }

    public function salesVatBook(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;
        $branchFilter = BranchFilter::fromRequest($request);

        return Inertia::render('Tenant/Reports/SalesVatBook', [
            ...$this->salesVatBookPayload($from, $to, $storeId, $branchFilter),
            'stores' => BranchStock::storeOptions($branchFilter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'branchReport' => $branchFilter->payload(),
        ]);
    }

    /**
     * Same filtered rows/totals as salesVatBook(), streamed as a real .xlsx
     * via the exact same builder so the export can never drift from what is
     * on screen and always honours the applied from/to/store_id/branch
     * filters.
     */
    public function salesVatBookExport(Request $request)
    {
        [$from, $to] = $this->resolveDateRange($request);
        $branchFilter = BranchFilter::fromRequest($request);
        $data = $this->salesVatBookPayload($from, $to, $request->integer('store_id') ?: null, $branchFilter);

        return Excel::download(
            new SalesVatBookExport($data['rows'], $data['totals'], $branchFilter->isActive(), $branchFilter->isActive() ? $branchFilter->label() : null, $from, $to),
            "sales-vat-book-{$from}-to-{$to}.xlsx",
        );
    }

    public function purchaseVatBook(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;
        $branchFilter = BranchFilter::fromRequest($request);

        return Inertia::render('Tenant/Reports/PurchaseVatBook', [
            ...$this->purchaseVatBookPayload($from, $to, $storeId, $branchFilter),
            'stores' => BranchStock::storeOptions($branchFilter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'branchReport' => $branchFilter->payload(),
        ]);
    }

    /**
     * Same filtered rows/totals as purchaseVatBook(), streamed as a real
     * .xlsx - see salesVatBookExport()'s own docblock for the reasoning.
     */
    public function purchaseVatBookExport(Request $request)
    {
        [$from, $to] = $this->resolveDateRange($request);
        $branchFilter = BranchFilter::fromRequest($request);
        $data = $this->purchaseVatBookPayload($from, $to, $request->integer('store_id') ?: null, $branchFilter);

        return Excel::download(
            new PurchaseVatBookExport($data['rows'], $data['totals'], $branchFilter->isActive(), $branchFilter->isActive() ? $branchFilter->label() : null, $from, $to),
            "purchase-vat-book-{$from}-to-{$to}.xlsx",
        );
    }

    public function salesReturnRegister(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;
        $branchFilter = BranchFilter::fromRequest($request);

        return Inertia::render('Tenant/Reports/SalesReturnRegister', [
            ...$this->salesReturnRegisterPayload($from, $to, $storeId, $branchFilter),
            'stores' => BranchStock::storeOptions($branchFilter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'branchReport' => $branchFilter->payload(),
        ]);
    }

    public function salesReturnRegisterExport(Request $request)
    {
        [$from, $to] = $this->resolveDateRange($request);
        $branchFilter = BranchFilter::fromRequest($request);
        $data = $this->salesReturnRegisterPayload($from, $to, $request->integer('store_id') ?: null, $branchFilter);

        return Excel::download(
            new SalesReturnRegisterExport($data['rows'], $data['totals'], $branchFilter->isActive(), $branchFilter->isActive() ? $branchFilter->label() : null, $from, $to),
            "sales-return-register-{$from}-to-{$to}.xlsx",
        );
    }

    public function purchaseReturnRegister(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;
        $branchFilter = BranchFilter::fromRequest($request);

        return Inertia::render('Tenant/Reports/PurchaseReturnRegister', [
            ...$this->purchaseReturnRegisterPayload($from, $to, $storeId, $branchFilter),
            'stores' => BranchStock::storeOptions($branchFilter, Store::where('is_active', true))->orderBy('name')->get(['id', 'name']),
            'branchReport' => $branchFilter->payload(),
        ]);
    }

    public function purchaseReturnRegisterExport(Request $request)
    {
        [$from, $to] = $this->resolveDateRange($request);
        $branchFilter = BranchFilter::fromRequest($request);
        $data = $this->purchaseReturnRegisterPayload($from, $to, $request->integer('store_id') ?: null, $branchFilter);

        return Excel::download(
            new PurchaseReturnRegisterExport($data['rows'], $data['totals'], $branchFilter->isActive(), $branchFilter->isActive() ? $branchFilter->label() : null, $from, $to),
            "purchase-return-register-{$from}-to-{$to}.xlsx",
        );
    }

    /**
     * Sales VAT book: one row per tax invoice issued in the period (ordinary
     * and capital alike), plus a negative row for every invoice whose
     * cancellation Reversal voucher is dated in the period.
     *
     * Columns are the ones an IRD sales book has to carry: the stored
     * `invoice_number` (never re-derived from the current prefix), the buyer
     * name and PAN as snapshotted at posting (falling back to the live
     * customer for rows issued before C7 added the snapshot), taxable,
     * exempt, VAT and total.
     *
     * Branches (BR14): $branchFilter narrows every row group on the
     * document's own branch_id, and in a multi-branch tenant each row names
     * its branch (`branch`). Null (the default, e.g. a caller that has not
     * adopted the filter) adds no predicate and no key: the rows are then
     * what BranchAccessScope alone lets the user see, exactly as before.
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, string>, from: string, to: string, storeId: int|null}
     */
    public function salesVatBookPayload(string $from, string $to, ?int $storeId, ?BranchFilter $branchFilter = null): array
    {
        $rows = $this->salesVatBookRows($from, $to, $storeId, $branchFilter ?? BranchFilter::inactive());

        return [
            'rows' => $rows,
            'totals' => [
                ...$this->totalsFor($rows, ['subtotal', 'nontaxable_amount', 'taxable_amount', 'vat_amount', 'capital_amount', 'round_off', 'total']),
                'count' => $rows->count(),
            ],
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
        ];
    }

    /**
     * Branches: as salesVatBookPayload().
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, string>, from: string, to: string, storeId: int|null}
     */
    public function purchaseVatBookPayload(string $from, string $to, ?int $storeId, ?BranchFilter $branchFilter = null): array
    {
        $rows = $this->purchaseVatBookRows($from, $to, $storeId, $branchFilter ?? BranchFilter::inactive());

        return [
            'rows' => $rows,
            'totals' => [
                ...$this->totalsFor($rows, ['subtotal', 'nontaxable_amount', 'taxable_amount', 'vat_amount', 'capital_amount', 'fixed_asset_vat_amount', 'round_off', 'total', 'tds_amount']),
                'count' => $rows->count(),
            ],
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
        ];
    }

    /**
     * Branches: as salesVatBookPayload(), on the return's own branch_id (a
     * return inherits its original's branch, so this is the sale's branch).
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, string>, from: string, to: string, storeId: int|null}
     */
    public function salesReturnRegisterPayload(string $from, string $to, ?int $storeId, ?BranchFilter $branchFilter = null): array
    {
        $rows = $this->salesReturnRegisterRows($from, $to, $storeId, $branchFilter ?? BranchFilter::inactive());

        return [
            'rows' => $rows,
            'totals' => [
                ...$this->totalsFor($rows, ['subtotal', 'nontaxable_amount', 'taxable_amount', 'vat_amount', 'round_off', 'total']),
                'count' => $rows->count(),
            ],
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
        ];
    }

    /**
     * Branches: as salesReturnRegisterPayload().
     *
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, string>, from: string, to: string, storeId: int|null}
     */
    public function purchaseReturnRegisterPayload(string $from, string $to, ?int $storeId, ?BranchFilter $branchFilter = null): array
    {
        $rows = $this->purchaseReturnRegisterRows($from, $to, $storeId, $branchFilter ?? BranchFilter::inactive());

        return [
            'rows' => $rows,
            'totals' => [
                ...$this->totalsFor($rows, ['subtotal', 'nontaxable_amount', 'taxable_amount', 'vat_amount', 'round_off', 'total']),
                'count' => $rows->count(),
            ],
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
        ];
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    private function salesVatBookRows(string $from, string $to, ?int $storeId, BranchFilter $branchFilter): Collection
    {
        $issued = Sale::query()
            ->with(['customer:id,name,tpin'])
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'sales.branch_id'))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (Sale $sale) => $this->withBranch($this->saleVatRow($sale, $sale->date->toDateString(), 'issued'), $sale, $branchFilter));

        $cancelled = $this->cancelledInPeriod(Sale::query()->with(['customer:id,name,tpin']), $from, $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'sales.branch_id'))
            ->get()
            ->map(fn (Sale $sale) => $this->withBranch($this->saleVatRow($sale, $this->cancelDate($sale), 'cancelled'), $sale, $branchFilter));

        $capitalIssued = CapitalSale::query()
            ->with(['customer:id,name,tpin'])
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'capital_sales.branch_id'))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (CapitalSale $sale) => $this->withBranch($this->capitalSaleVatRow($sale, $sale->date->toDateString(), 'issued'), $sale, $branchFilter));

        $capitalCancelled = $this->cancelledInPeriod(CapitalSale::query()->with(['customer:id,name,tpin']), $from, $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'capital_sales.branch_id'))
            ->get()
            ->map(fn (CapitalSale $sale) => $this->withBranch($this->capitalSaleVatRow($sale, $this->cancelDate($sale), 'cancelled'), $sale, $branchFilter));

        return $this->numbered($issued->concat($capitalIssued)->concat($cancelled)->concat($capitalCancelled));
    }

    /**
     * @return array<string, mixed>
     */
    private function saleVatRow(Sale $sale, string $date, string $entry): array
    {
        $sign = $entry === 'cancelled' ? -1 : 1;
        $taxable = $this->signed($sale->taxable_amount, $sign);
        $nontaxable = $this->signed($sale->nontaxable_amount, $sign);
        $subtotal = Money::of($taxable)->plus(Money::of($nontaxable))->toString();

        return [
            'kind' => 'sale',
            'entry' => $entry,
            'id' => $sale->id,
            'date' => $date,
            'issued_on' => $sale->date->toDateString(),
            'invoice_number' => $sale->invoice_number,
            'invoice_type' => $sale->invoice_type,
            'buyer_name' => $sale->buyer_name ?? $sale->customer?->name,
            'buyer_pan' => $sale->buyer_pan ?? $sale->customer?->tpin,
            'subtotal' => $subtotal,
            'nontaxable_amount' => $nontaxable,
            'taxable_amount' => $taxable,
            'vat_amount' => $this->signed($sale->vat_amount, $sign),
            'capital_amount' => Money::zero()->toString(),
            'round_off' => $this->signed($sale->round_off, $sign),
            'total' => $this->signed($sale->total, $sign),
            'capital' => false,
        ];
    }

    /**
     * A capital document stores no round-off column, so its Round Off is
     * whatever closes Sub Total + VAT to the stored Total (normally 0.00).
     * Derived from the same signed strings, so the row adds up by
     * construction and the book's column totals tie to its Total.
     */
    private function derivedRoundOff(string $total, string $subtotal, string $vat): string
    {
        return Money::of($total)->minus($subtotal)->minus($vat)->toString();
    }

    /**
     * @return array<string, mixed>
     */
    private function capitalSaleVatRow(CapitalSale $sale, string $date, string $entry): array
    {
        $sign = $entry === 'cancelled' ? -1 : 1;
        $taxable = $this->signed($sale->taxable_amount, $sign);
        $nontaxable = $this->signed($sale->nontaxable_amount, $sign);
        $subtotal = Money::of($taxable)->plus(Money::of($nontaxable))->toString();
        $vat = $this->signed($sale->vat_amount, $sign);
        $total = $this->signed($sale->total, $sign);

        return [
            'kind' => 'capital_sale',
            'entry' => $entry,
            'id' => $sale->id,
            'date' => $date,
            'issued_on' => $sale->date->toDateString(),
            'invoice_number' => $sale->invoice_number,
            'invoice_type' => 'capital',
            'buyer_name' => $sale->buyer_name ?? $sale->customer?->name,
            'buyer_pan' => $sale->buyer_pan ?? $sale->customer?->tpin,
            'subtotal' => $subtotal,
            'nontaxable_amount' => $nontaxable,
            'taxable_amount' => $taxable,
            'vat_amount' => $vat,
            'capital_amount' => $this->signed($sale->taxable_amount, $sign),
            'round_off' => $this->derivedRoundOff($total, $subtotal, $vat),
            'total' => $total,
            'capital' => true,
        ];
    }

    /**
     * Purchase VAT book: the supplier's OWN bill number (never our internal
     * voucher number - audit P1) and the supplier's PAN from
     * `purchases.pan_number`, falling back to `suppliers.tpin`, plus a
     * dedicated capital column so the taxable total splits the way the IRD
     * purchase book asks for.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function purchaseVatBookRows(string $from, string $to, ?int $storeId, BranchFilter $branchFilter): Collection
    {
        $issued = Purchase::query()
            ->with(['supplier:id,name,tpin', 'lines.account.group'])
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'purchases.branch_id'))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (Purchase $purchase) => $this->withBranch($this->purchaseVatRow($purchase, $purchase->date->toDateString(), 'issued'), $purchase, $branchFilter));

        $cancelled = $this->cancelledInPeriod(Purchase::query()->with(['supplier:id,name,tpin', 'lines.account.group']), $from, $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'purchases.branch_id'))
            ->get()
            ->map(fn (Purchase $purchase) => $this->withBranch($this->purchaseVatRow($purchase, $this->cancelDate($purchase), 'cancelled'), $purchase, $branchFilter));

        $capitalIssued = CapitalPurchase::query()
            ->with(['supplier:id,name,tpin'])
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'capital_purchases.branch_id'))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (CapitalPurchase $purchase) => $this->withBranch($this->capitalPurchaseVatRow($purchase, $purchase->date->toDateString(), 'issued'), $purchase, $branchFilter));

        $capitalCancelled = $this->cancelledInPeriod(CapitalPurchase::query()->with(['supplier:id,name,tpin']), $from, $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'capital_purchases.branch_id'))
            ->get()
            ->map(fn (CapitalPurchase $purchase) => $this->withBranch($this->capitalPurchaseVatRow($purchase, $this->cancelDate($purchase), 'cancelled'), $purchase, $branchFilter));

        return $this->numbered($issued->concat($capitalIssued)->concat($cancelled)->concat($capitalCancelled));
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseVatRow(Purchase $purchase, string $date, string $entry): array
    {
        $sign = $entry === 'cancelled' ? -1 : 1;
        $taxable = $this->signed($purchase->taxable_amount, $sign);
        $nontaxable = $this->signed($purchase->nontaxable_amount, $sign);
        $subtotal = Money::of($taxable)->plus(Money::of($nontaxable))->toString();

        return [
            'kind' => 'purchase',
            'entry' => $entry,
            'id' => $purchase->id,
            'date' => $date,
            'issued_on' => $purchase->date->toDateString(),
            'bill_number' => $purchase->bill_number,
            'supplier' => $purchase->supplier?->name,
            'supplier_pan' => $purchase->pan_number ?: $purchase->supplier?->tpin,
            'subtotal' => $subtotal,
            'nontaxable_amount' => $nontaxable,
            'taxable_amount' => $taxable,
            'vat_amount' => $this->signed($purchase->vat_amount, $sign),
            'capital_amount' => Money::zero()->toString(),
            // T15-3: the slice of the vat_amount above that sits on a line
            // posted to a Fixed Assets account - an asset bought as one line
            // inside an otherwise-ordinary Purchase (e.g. an office chair on
            // a supply invoice), as opposed to a whole CapitalPurchase
            // document (already its own "capital" bucket above). Purely
            // informational: it is a breakdown OF vat_amount, never
            // subtracted from it, so gross/capital/reconciliation keep
            // tying to the ledger exactly as before.
            'fixed_asset_vat_amount' => $this->signed($this->fixedAssetVatAmount($purchase)->toString(), $sign),
            'round_off' => $this->signed($purchase->round_off, $sign),
            'total' => $this->signed($purchase->total, $sign),
            // TDS withheld from the supplier on this bill (it goes to the IRD,
            // not to the supplier). Informational, like the fixed-asset VAT: it
            // is part of `total`, never subtracted from it. A cancelled bill
            // gives it back, so it is negative on the cancellation row.
            'tds_amount' => $this->signed($purchase->tds_amount ?? '0', $sign),
            'capital' => false,
        ];
    }

    /**
     * The portion of this purchase's input VAT that belongs to a line whose
     * resolved account (PurchaseLine::account(), the account the line
     * actually debited at posting - not the item's current, possibly
     * re-pointed, account_id) sits under the "Fixed Assets" account group.
     * Reuses the exact classification FixedAsset::post() relies on when it
     * files every registered asset's own ledger account directly under that
     * group (see that method's docblock: "no subgroup - that group has
     * none").
     */
    private function fixedAssetVatAmount(Purchase $purchase): Money
    {
        $lines = $purchase->relationLoaded('lines')
            ? $purchase->lines
            : $purchase->lines()->with('account.group')->get();

        return Money::sum(
            $lines
                ->filter(fn (PurchaseLine $line) => $line->account?->group?->name === 'Fixed Assets')
                ->map(fn (PurchaseLine $line) => Money::of($line->vat_amount))
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function capitalPurchaseVatRow(CapitalPurchase $purchase, string $date, string $entry): array
    {
        $sign = $entry === 'cancelled' ? -1 : 1;
        $taxable = $this->signed($purchase->taxable_amount, $sign);
        $nontaxable = $this->signed($purchase->nontaxable_amount, $sign);
        $subtotal = Money::of($taxable)->plus(Money::of($nontaxable))->toString();
        $vat = $this->signed($purchase->vat_amount, $sign);
        $total = $this->signed($purchase->total, $sign);

        return [
            'kind' => 'capital_purchase',
            'entry' => $entry,
            'id' => $purchase->id,
            'date' => $date,
            'issued_on' => $purchase->date->toDateString(),
            'bill_number' => $purchase->bill_number,
            'supplier' => $purchase->supplier?->name,
            'supplier_pan' => $purchase->supplier_pan ?: $purchase->supplier?->tpin,
            'subtotal' => $subtotal,
            'nontaxable_amount' => $nontaxable,
            'taxable_amount' => $taxable,
            'vat_amount' => $vat,
            'capital_amount' => $purchase->type === 'capital' ? $this->signed($purchase->taxable_amount, $sign) : Money::zero()->toString(),
            // A whole CapitalPurchase document is already its own "capital"
            // bucket above, so it never contributes to the fixed-asset
            // breakdown of an ordinary Purchase's vat_amount.
            'fixed_asset_vat_amount' => Money::zero()->toString(),
            'round_off' => $this->derivedRoundOff($total, $subtotal, $vat),
            'total' => $total,
            // A CapitalPurchase document carries no TDS.
            'tds_amount' => Money::zero()->toString(),
            'capital' => $purchase->type === 'capital',
        ];
    }

    /**
     * Credit-note book. Only `status = 'posted'` notes appear (C6): a
     * pending request has no money effect and is not a credit note, and a
     * rejected one never had any. A note that was itself cancelled shows as
     * issued in its own period and again, negative, in the period its
     * Reversal voucher is dated.
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function salesReturnRegisterRows(string $from, string $to, ?int $storeId, BranchFilter $branchFilter): Collection
    {
        $issued = SalesReturn::query()
            ->with(['sale:id,invoice_number,customer_id,buyer_name,buyer_pan', 'sale.customer:id,name,tpin'])
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'sales_returns.branch_id'))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (SalesReturn $return) => $this->withBranch($this->salesReturnRow($return, $return->date->toDateString(), 'issued'), $return, $branchFilter));

        $cancelled = $this->cancelledInPeriod(
            SalesReturn::query()->with(['sale:id,invoice_number,customer_id,buyer_name,buyer_pan', 'sale.customer:id,name,tpin']),
            $from,
            $to,
        )
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'sales_returns.branch_id'))
            ->get()
            ->map(fn (SalesReturn $return) => $this->withBranch($this->salesReturnRow($return, $this->cancelDate($return), 'cancelled'), $return, $branchFilter));

        return $this->numbered($issued->concat($cancelled));
    }

    /**
     * @return array<string, mixed>
     */
    private function salesReturnRow(SalesReturn $return, string $date, string $entry): array
    {
        $sign = $entry === 'cancelled' ? -1 : 1;
        $taxable = $this->signed($return->taxable_amount, $sign);
        $nontaxable = $this->signed($return->nontaxable_amount, $sign);
        $subtotal = Money::of($taxable)->plus(Money::of($nontaxable))->toString();

        return [
            'kind' => 'sales_return',
            'entry' => $entry,
            'id' => $return->id,
            'date' => $date,
            'issued_on' => $return->date->toDateString(),
            'credit_note_number' => $return->credit_note_number,
            'invoice_number' => $return->sale?->invoice_number,
            'buyer_name' => $return->sale?->buyer_name ?? $return->sale?->customer?->name,
            'buyer_pan' => $return->sale?->buyer_pan ?? $return->sale?->customer?->tpin,
            'subtotal' => $subtotal,
            'nontaxable_amount' => $nontaxable,
            'taxable_amount' => $taxable,
            'vat_amount' => $this->signed($return->vat_amount, $sign),
            'tds_amount' => $this->signed($return->tds_amount, $sign),
            'round_off' => $this->signed($return->round_off ?? '0', $sign),
            'total' => $this->signed($return->total, $sign),
        ];
    }

    /**
     * Debit-note book - the purchase-side mirror of
     * salesReturnRegisterRows().
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function purchaseReturnRegisterRows(string $from, string $to, ?int $storeId, BranchFilter $branchFilter): Collection
    {
        $with = ['purchase:id,bill_number,supplier_id,pan_number', 'purchase.supplier:id,name,tpin'];

        $issued = PurchaseReturn::query()
            ->with($with)
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'purchase_returns.branch_id'))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (PurchaseReturn $return) => $this->withBranch($this->purchaseReturnRow($return, $return->date->toDateString(), 'issued'), $return, $branchFilter));

        $cancelled = $this->cancelledInPeriod(PurchaseReturn::query()->with($with), $from, $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->tap(fn (Builder $query) => $branchFilter->applyToDocuments($query, 'purchase_returns.branch_id'))
            ->get()
            ->map(fn (PurchaseReturn $return) => $this->withBranch($this->purchaseReturnRow($return, $this->cancelDate($return), 'cancelled'), $return, $branchFilter));

        return $this->numbered($issued->concat($cancelled));
    }

    /**
     * Appends the document's branch name to a book row in a multi-branch
     * tenant (BranchFilter::rowBranch(), no query); a single-branch row keeps
     * its exact shape.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    private function withBranch(array $row, Model $document, BranchFilter $branchFilter): array
    {
        return [...$row, ...$branchFilter->rowBranch($document->getAttribute('branch_id'))];
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseReturnRow(PurchaseReturn $return, string $date, string $entry): array
    {
        $sign = $entry === 'cancelled' ? -1 : 1;
        $taxable = $this->signed($return->taxable_amount, $sign);
        $nontaxable = $this->signed($return->nontaxable_amount, $sign);
        $subtotal = Money::of($taxable)->plus(Money::of($nontaxable))->toString();

        return [
            'kind' => 'purchase_return',
            'entry' => $entry,
            'id' => $return->id,
            'date' => $date,
            'issued_on' => $return->date->toDateString(),
            'debit_note_number' => $return->debit_note_number,
            'bill_number' => $return->purchase?->bill_number,
            'supplier' => $return->purchase?->supplier?->name,
            'supplier_pan' => $return->purchase?->pan_number ?: $return->purchase?->supplier?->tpin,
            'subtotal' => $subtotal,
            'nontaxable_amount' => $nontaxable,
            'taxable_amount' => $taxable,
            'vat_amount' => $this->signed($return->vat_amount, $sign),
            'tds_amount' => $this->signed($return->tds_amount, $sign),
            'round_off' => $this->signed($return->round_off ?? '0', $sign),
            'total' => $this->signed($return->total, $sign),
        ];
    }

    /**
     * Ages each customer's ledger balance as of `as_of` (receipts and
     * returns applied FIFO to the oldest entries), bucketed Current (0-30) /
     * 31-60 / 61-90 / 91+. See agedPartyPayload() for the basis and the row
     * contract.
     */
    public function agedReceivables(Request $request): Response
    {
        $request->validate($this->agedReportRules(Customer::class));

        return Inertia::render('Tenant/Reports/AgedReceivables', $this->agedPartyPayload($request, Customer::class));
    }

    public function agedReceivablesPdf(Request $request)
    {
        $request->validate($this->agedReportRules(Customer::class));

        return $this->agedPartyPdf($this->agedPartyPayload($request, Customer::class), 'Aged Receivables', 'Customer', 'aged-receivables');
    }

    public function agedReceivablesExport(Request $request)
    {
        $request->validate($this->agedReportRules(Customer::class));

        return $this->agedPartyExport($this->agedPartyPayload($request, Customer::class), 'Aged Receivables', 'Customer', 'aged-receivables');
    }

    /**
     * Exact mirror of agedReceivables() for suppliers, ageing the
     * credit-normal payable ledger (payments and purchase returns applied
     * FIFO to the oldest bills).
     */
    public function agedPayables(Request $request): Response
    {
        $request->validate($this->agedReportRules(Supplier::class));

        return Inertia::render('Tenant/Reports/AgedPayables', $this->agedPartyPayload($request, Supplier::class));
    }

    public function agedPayablesPdf(Request $request)
    {
        $request->validate($this->agedReportRules(Supplier::class));

        return $this->agedPartyPdf($this->agedPartyPayload($request, Supplier::class), 'Aged Payables', 'Supplier', 'aged-payables');
    }

    public function agedPayablesExport(Request $request)
    {
        $request->validate($this->agedReportRules(Supplier::class));

        return $this->agedPartyExport($this->agedPartyPayload($request, Supplier::class), 'Aged Payables', 'Supplier', 'aged-payables');
    }

    /**
     * Ledger-based Debtors list: every ledger account filed under the Sundry
     * Debtors subgroup (customers' own accounts, plus any non-party account
     * or sub-account a user filed there) that carries a non-zero balance in
     * the selected fiscal year. Unlike the ageing report this owes nothing to
     * invoices. With no area filter its total is the Sundry Debtors line of
     * the Balance Sheet and the dashboard's Debtors figure for the same year
     * and branch filter, as legacy's debtors() sums the whole subgroup.
     */
    public function debtors(Request $request): Response
    {
        $request->validate(self::PARTY_REPORT_INPUT_RULES);

        return Inertia::render('Tenant/Reports/Debtors', $this->partyBalancePayload($request, Customer::class, creditNormal: false));
    }

    public function debtorsPdf(Request $request)
    {
        $request->validate(self::PARTY_REPORT_INPUT_RULES);

        $data = $this->partyBalancePayload($request, Customer::class, creditNormal: false, withBranchColumns: false);

        return $this->partyBalancePdf($data, 'Debtors', 'Customer', creditNormal: false);
    }

    public function debtorsExport(Request $request)
    {
        $request->validate(self::PARTY_REPORT_INPUT_RULES);

        $data = $this->partyBalancePayload($request, Customer::class, creditNormal: false, withBranchColumns: false);

        return Excel::download(
            new DebtorsExport(
                $data['rows'],
                $data['total'],
                $data['groups'],
                $data['branchReport']['label'] ?? null,
                $data['fiscalYears']->firstWhere('id', $data['fiscalYearId'])?->name,
                $data['areaLabel'],
            ),
            $this->partyBalanceFileName('debtors', $data['fiscalYearId'], 'xlsx'),
        );
    }

    /**
     * Ledger-based Creditors list - the payable mirror of debtors().
     */
    public function creditors(Request $request): Response
    {
        $request->validate(self::PARTY_REPORT_INPUT_RULES);

        return Inertia::render('Tenant/Reports/Creditors', $this->partyBalancePayload($request, Supplier::class, creditNormal: true));
    }

    public function creditorsPdf(Request $request)
    {
        $request->validate(self::PARTY_REPORT_INPUT_RULES);

        $data = $this->partyBalancePayload($request, Supplier::class, creditNormal: true, withBranchColumns: false);

        return $this->partyBalancePdf($data, 'Creditors', 'Supplier', creditNormal: true);
    }

    public function creditorsExport(Request $request)
    {
        $request->validate(self::PARTY_REPORT_INPUT_RULES);

        $data = $this->partyBalancePayload($request, Supplier::class, creditNormal: true, withBranchColumns: false);

        return Excel::download(
            new CreditorsExport(
                $data['rows'],
                $data['total'],
                $data['groups'],
                $data['branchReport']['label'] ?? null,
                $data['fiscalYears']->firstWhere('id', $data['fiscalYearId'])?->name,
                $data['areaLabel'],
            ),
            $this->partyBalanceFileName('creditors', $data['fiscalYearId'], 'xlsx'),
        );
    }

    /**
     * @param  array<string, mixed>  $data  partyBalancePayload() output
     */
    private function partyBalancePdf(array $data, string $title, string $partyLabel, bool $creditNormal)
    {
        $fiscalYear = $data['fiscalYearId'] === null ? null : FiscalYear::find($data['fiscalYearId']);

        abort_if($fiscalYear === null, 404);

        $today = Carbon::today()->toDateString();

        $pdf = Pdf::loadView('pdf.party-balances', [
            'company' => CompanySetting::current(),
            'documentNumber' => "{$title} - {$fiscalYear->name}",
            'documentDate' => $today,
            'dateAd' => $today,
            'dateBs' => NepaliCalendar::formatBs($today),
            'fiscalYearName' => $fiscalYear->name,
            'branchFilterLabel' => $data['branchReport']['label'] ?? null,
            'title' => $title,
            'partyLabel' => $partyLabel,
            'creditNormal' => $creditNormal,
            'rows' => $data['rows']->all(),
            'total' => $data['total'],
            'groups' => $data['groups'],
            'areaLabel' => $data['areaLabel'],
        ]);

        return $pdf->stream(Str::lower($title).'-'.$this->reportFileSlug($fiscalYear).'.pdf');
    }

    /**
     * "debtors-2082-83.xlsx"; just "debtors.xlsx" when there is no fiscal
     * year yet (the list is then empty, but the download still works).
     */
    private function partyBalanceFileName(string $prefix, ?int $fiscalYearId, string $extension): string
    {
        $fiscalYear = $fiscalYearId === null ? null : FiscalYear::find($fiscalYearId);

        return $prefix.($fiscalYear === null ? '' : '-'.$this->reportFileSlug($fiscalYear)).'.'.$extension;
    }

    /**
     * Mirrors AccountingReportController::reportFileSlug(): "2082/83" breaks the Content-Disposition header.
     */
    private function reportFileSlug(FiscalYear $fiscalYear): string
    {
        return Str::slug($fiscalYear->name) ?: (string) $fiscalYear->id;
    }

    /**
     * Validation for both ageing reports: a malformed date or an unknown
     * party is a 422, never a silent fallback.
     *
     * @param  class-string<Customer|Supplier>  $partyClass
     * @return array<string, list<string>>
     */
    private function agedReportRules(string $partyClass): array
    {
        $partyRule = $partyClass === Customer::class
            ? ['customer_id' => ['nullable', 'integer', 'exists:customers,id']]
            : ['supplier_id' => ['nullable', 'integer', 'exists:suppliers,id']];

        return [
            'as_of' => ['nullable', 'date_format:Y-m-d'],
            'hide_zero' => ['nullable', 'boolean'],
            ...$partyRule,
        ];
    }

    /**
     * The one payload behind both ageing reports (screen, PDF and Excel).
     * $partyClass picks the side: Customer ages the debit-normal receivable
     * ledger, Supplier the credit-normal payable ledger.
     *
     * Basis (legacy CustomerAgeingController / SupplierAgeingController):
     * the party's own ledger as of `as_of`, not its invoices. Every voucher
     * line on the party account dated on or before as_of in that date's
     * fiscal year is read; each voucher is netted per account first (so a
     * cash bill that debits and credits the party in one voucher ages
     * nothing). A net increase on the party's normal side is an ageable
     * entry, a net decrease (receipt, payment, return, journal credit) joins
     * the party's pool of credits. That pool is applied FIFO against the
     * entries, oldest first, and whatever each entry still holds is bucketed
     * by whole days from its voucher date to as_of. A receipt or return dated
     * after as_of therefore never reduces a bucket, which the old
     * invoice-based method (live outstandingAmount()) could not promise.
     *
     * - The year's Opening Balance voucher (an import or the year-end carry
     *   forward) has no true age, so what it still holds after FIFO is shown
     *   in "Opening", never dated. It is applied first.
     * - A cancelled document and its Reversal voucher, both on or before
     *   as_of and netting to zero on the account, are dropped as a pair so a
     *   cancellation does not settle some older entry. A pair cancelled
     *   after as_of stays, exactly as the ledger stood that day.
     * - A party whose credits exceed its entries (an advance) is left out of
     *   the rows; the sum of those excesses is `advances`. `hide_zero`
     *   (default on) drops parties whose balance nets to exactly zero.
     * - The row total equals the party's ledger balance as of the date,
     *   narrowed by the same BranchFilter on journal_voucher_lines.branch_id
     *   (plan 12, BR14), so it ties to the account ledger run to as_of.
     *
     * Row contract: `party`, `party_id`, `account_id`, `mobile_no`, `area`,
     * `oldest` (date of the oldest dated entry still open, null when only
     * the opening balance is open), `days` (whole days from `oldest` to
     * as_of), the five amount buckets and `total`.
     *
     * @param  class-string<Customer|Supplier>  $partyClass
     * @return array<string, mixed>
     */
    private function agedPartyPayload(Request $request, string $partyClass): array
    {
        $receivable = $partyClass === Customer::class;

        $asOf = $this->resolveAsOf($request);
        $asOfDate = Carbon::parse($asOf)->startOfDay();
        $partyId = $request->integer($receivable ? 'customer_id' : 'supplier_id') ?: null;
        $hideZero = $request->has('hide_zero') ? $request->boolean('hide_zero') : true;
        $areaFilter = AreaFilter::fromRequest($request);
        $groupByArea = AreaFilter::groupRequested($request);
        $branchFilter = BranchFilter::fromRequest($request);
        $fiscalYearId = $this->resolveFiscalYearIdForDate($asOf);

        $parties = $areaFilter->apply($partyClass::query())
            ->whereNotNull('account_id')
            ->when($partyId !== null, fn (Builder $query) => $query->whereKey($partyId))
            ->with('area:id,name')
            ->get(['id', 'name', 'account_id', 'area_id', 'mobile_no'])
            ->keyBy(fn (Model $party) => (int) $party->account_id);

        $ledger = $fiscalYearId === null || $parties->isEmpty()
            ? []
            : $this->agedLedgerEntries($parties->keys()->all(), $fiscalYearId, $asOf, ! $receivable, $branchFilter->lineBranchIds());

        $rows = [];
        $advances = Money::zero();

        foreach ($ledger as $accountId => $entry) {
            $balance = Money::sum(array_column($entry['debits'], 'amount'))->minus($entry['credit']);

            if ($balance->isNegative()) {
                $advances = $advances->plus($balance->negated());

                continue;
            }

            if ($balance->isZero() && $hideZero) {
                continue;
            }

            $rows[] = $this->agedPartyRow($parties->get($accountId), $entry, $asOfDate);
        }

        usort($rows, fn (array $a, array $b) => strcasecmp($a['party'], $b['party']));

        return [
            'rows' => $rows,
            'totals' => $this->sumAgingTotals($rows),
            'advances' => $advances->toString(),
            ...$this->areaReportProps($areaFilter, $groupByArea, $rows, ['opening', ...self::AGING_BUCKETS, 'total']),
            'parties' => fn () => $partyClass::query()->orderBy('name')->get(['id', 'name']),
            'partyId' => $partyId,
            'partyName' => $partyId === null ? null : $partyClass::query()->whereKey($partyId)->value('name'),
            'hideZero' => $hideZero,
            'asOf' => $asOf,
            'fiscalYearId' => $fiscalYearId,
            'branchReport' => $branchFilter->payload(),
        ];
    }

    /**
     * Each party account's vouchers in $fiscalYearId up to $asOf, netted per
     * voucher on the party's normal side and split into ageable entries (in
     * FIFO order: by date, the Opening Balance voucher first on its day, then
     * by voucher id) and one pool of credits. Sums are taken as scaled
     * integers in SQL, the same as ledgerBalances(), because SQLite reads a
     * DECIMAL column back as REAL. Account ids are chunked so a large party
     * list never exceeds a placeholder limit; a chunk holds whole accounts,
     * so per-account order is intact.
     *
     * @param  list<int>  $accountIds
     * @param  list<int>|null  $branchIds
     * @return array<int, array{debits: list<array{date: string, amount: Money, opening: bool}>, credit: Money}>
     */
    private function agedLedgerEntries(array $accountIds, int $fiscalYearId, string $asOf, bool $creditNormal, ?array $branchIds): array
    {
        $debit = $this->scaledMoneyExpression('journal_voucher_lines.debit');
        $credit = $this->scaledMoneyExpression('journal_voucher_lines.credit');
        $net = $creditNormal ? "SUM({$credit}) - SUM({$debit})" : "SUM({$debit}) - SUM({$credit})";
        $openingType = VoucherType::OpeningBalance->value;

        $byAccount = [];

        foreach (array_chunk($accountIds, 1000) as $chunk) {
            $vouchers = JournalVoucherLine::query()
                ->join('journal_vouchers', 'journal_vouchers.id', '=', 'journal_voucher_lines.journal_voucher_id')
                ->whereIn('journal_voucher_lines.account_id', $chunk)
                ->where('journal_vouchers.fiscal_year_id', $fiscalYearId)
                ->whereDate('journal_vouchers.date', '<=', $asOf)
                ->tap(fn (Builder $query) => BranchFilter::narrowLines($query, $branchIds))
                ->groupBy('journal_voucher_lines.account_id', 'journal_vouchers.id', 'journal_vouchers.date', 'journal_vouchers.voucher_type', 'journal_vouchers.reversal_of_id')
                ->selectRaw("journal_voucher_lines.account_id as account_id, journal_vouchers.id as voucher_id, journal_vouchers.date as voucher_date, journal_vouchers.voucher_type as voucher_type, journal_vouchers.reversal_of_id as reversal_of_id, {$net} as net_scaled")
                ->orderBy('journal_vouchers.date')
                ->orderByRaw('CASE WHEN journal_vouchers.voucher_type = ? THEN 0 ELSE 1 END', [$openingType])
                ->orderBy('journal_vouchers.id')
                ->toBase()
                ->get();

            foreach ($vouchers as $voucher) {
                $byAccount[(int) $voucher->account_id][(int) $voucher->voucher_id] = [
                    'date' => substr((string) $voucher->voucher_date, 0, 10),
                    'net' => Money::of(BigDecimal::of((int) $voucher->net_scaled)->dividedBy(100, 2, RoundingMode::Unnecessary)),
                    'opening' => $voucher->voucher_type === $openingType,
                    'reversal_of_id' => $voucher->reversal_of_id === null ? null : (int) $voucher->reversal_of_id,
                ];
            }
        }

        $entries = [];

        foreach ($byAccount as $accountId => $vouchers) {
            foreach (array_keys($vouchers) as $voucherId) {
                $originalId = $vouchers[$voucherId]['reversal_of_id'] ?? null;

                if ($originalId !== null && isset($vouchers[$originalId]) && $vouchers[$originalId]['net']->plus($vouchers[$voucherId]['net'])->isZero()) {
                    unset($vouchers[$originalId], $vouchers[$voucherId]);
                }
            }

            $debits = [];
            $credits = Money::zero();

            foreach ($vouchers as $voucher) {
                if ($voucher['net']->isPositive()) {
                    $debits[] = ['date' => $voucher['date'], 'amount' => $voucher['net'], 'opening' => $voucher['opening']];
                } elseif ($voucher['net']->isNegative()) {
                    $credits = $credits->plus($voucher['net']->negated());
                }
            }

            $entries[$accountId] = ['debits' => $debits, 'credit' => $credits];
        }

        return $entries;
    }

    /**
     * One party's ageing row: the credit pool applied FIFO to its entries,
     * the remainder bucketed (the Opening Balance remainder into `opening`).
     * Exact Money throughout; the buckets sum to the party's balance.
     *
     * @param  array{debits: list<array{date: string, amount: Money, opening: bool}>, credit: Money}  $entry
     * @return array<string, mixed>
     */
    private function agedPartyRow(Model $party, array $entry, CarbonInterface $asOfDate): array
    {
        $row = $this->emptyAgingRow($party);
        $buckets = array_fill_keys(['opening', ...self::AGING_BUCKETS], Money::zero());
        $unapplied = $entry['credit'];

        foreach ($entry['debits'] as $debit) {
            $applied = Money::min($unapplied, $debit['amount']);
            $unapplied = $unapplied->minus($applied);
            $open = $debit['amount']->minus($applied);

            if (! $open->isPositive()) {
                continue;
            }

            if ($debit['opening']) {
                $buckets['opening'] = $buckets['opening']->plus($open);

                continue;
            }

            $daysOutstanding = $this->daysOutstanding(Carbon::parse($debit['date'])->startOfDay(), $asOfDate);
            $bucket = $this->agingBucket($daysOutstanding);
            $buckets[$bucket] = $buckets[$bucket]->plus($open);

            if ($row['oldest'] === null) {
                $row['oldest'] = $debit['date'];
                $row['days'] = $daysOutstanding;
            }
        }

        foreach ($buckets as $key => $amount) {
            $row[$key] = $amount->toString();
        }

        $row['total'] = Money::sum($buckets)->toString();

        return $row;
    }

    /**
     * @param  array<string, mixed>  $data  agedPartyPayload() output
     */
    private function agedPartyPdf(array $data, string $title, string $partyLabel, string $filePrefix)
    {
        $asOf = $data['asOf'];

        $pdf = Pdf::loadView('pdf.aged-parties', [
            'company' => CompanySetting::current(),
            'documentNumber' => "{$title} - {$asOf}",
            'documentDate' => $asOf,
            'dateAd' => $asOf,
            'dateBs' => NepaliCalendar::formatBs($asOf),
            'fiscalYearName' => $data['fiscalYearId'] === null ? '' : (FiscalYear::query()->whereKey($data['fiscalYearId'])->value('name') ?? ''),
            'branchFilterLabel' => $data['branchReport']['label'] ?? null,
            'title' => $title,
            'partyLabel' => $partyLabel,
            'rows' => $data['rows'],
            'totals' => $data['totals'],
            'groups' => $data['groups'],
            'asOf' => $asOf,
            'areaLabel' => $data['areaLabel'],
            'partyName' => $data['partyName'],
            'advances' => $data['advances'],
        ])->setPaper('a4', 'landscape');

        return $pdf->stream("{$filePrefix}-{$asOf}.pdf");
    }

    /**
     * @param  array<string, mixed>  $data  agedPartyPayload() output
     */
    private function agedPartyExport(array $data, string $title, string $partyLabel, string $filePrefix)
    {
        return Excel::download(
            new AgedPartyExport(
                rows: $data['rows'],
                totals: $data['totals'],
                groups: $data['groups'],
                partyLabel: $partyLabel,
                asOf: $data['asOf'],
                branchLabel: $data['branchReport']['label'] ?? null,
                title: $title,
                partyName: $data['partyName'],
                areaLabel: $data['areaLabel'],
                advances: $data['advances'],
            ),
            "{$filePrefix}-{$data['asOf']}.xlsx",
        );
    }

    /**
     * Balances are scoped to one fiscal year's own voucher lines, never
     * summed across years. Audit P0-18: the next year's Opening Balance
     * voucher restates the same closing balances, so an all-years sum double
     * counts every party from the first year-end close onwards.
     *
     * Audit T15-8: legacy's debtors()/creditors() use HAVING SUM(...) > 0, so an
     * overpaid party (negative balance on its normal side) is silently dropped
     * there. This report keeps those rows - an overpayment is real money owed
     * back - but flags each one via `is_overpaid` so the difference from legacy
     * is visible, not silent. `balance` is signed on the party's normal side
     * (positive = Dr for a debtor, Cr for a creditor), so an overpaid debtor
     * holds a credit balance and an overpaid creditor a debit balance.
     *
     * Rows are ledger accounts, not party records (legacy reportsController
     * partyBalanceReport() and DashboardController::subgroupBalance()): every
     * account whose own subgroup is Sundry Debtors (Sundry Creditors for
     * $creditNormal), so a non-party account or sub-account a user filed
     * there is listed too and, with no area filter, the total is the Balance
     * Sheet's subgroup line. A row is keyed by its account: `id` and
     * `account_id` are both the account id, `party_id` is the customer or
     * supplier that owns the account (null for a non-party account). Name,
     * address, mobile and area come from that party, else from the account
     * (which has no area). A customer or supplier whose account was moved to
     * another subgroup is not listed, exactly as the Balance Sheet does not
     * count it under this line.
     *
     * Area filter: a specific area keeps only accounts owned by a party in
     * that area; "Unassigned" keeps parties with no area plus every
     * non-party account.
     *
     * Branches (plan 12, BR14): the balance is the party account's lines
     * narrowed by BranchFilter on journal_voucher_lines.branch_id (the
     * account itself is one company account). `by_branch=1` adds a
     * `by_branch` amount per branch column to every row, the columns and
     * their totals to `branchReport`, and `by_branch_totals` to each area
     * group; a row is then kept when any branch amount is non-zero even if
     * the branches net to zero. Off, the rows are exactly what they were.
     *
     * @param  class-string<Customer|Supplier>  $partyClass
     * @return array<string, mixed>
     */
    private function partyBalancePayload(Request $request, string $partyClass, bool $creditNormal, bool $withBranchColumns = true): array
    {
        $fiscalYears = FiscalYear::query()->orderByDesc('start_date')->get(['id', 'name', 'status']);
        $fiscalYearId = $request->integer('fiscal_year_id')
            ?: ($fiscalYears->firstWhere('status', FiscalYearStatus::Open)?->id ?? $fiscalYears->first()?->id);

        $areaFilter = AreaFilter::fromRequest($request);
        $groupByArea = AreaFilter::groupRequested($request);

        $subgroupName = $creditNormal ? 'Sundry Creditors' : 'Sundry Debtors';
        $inSubgroup = fn (Builder $subgroup) => $subgroup->where('name', $subgroupName);

        $accounts = Account::query()
            ->whereHas('subgroup', $inSubgroup)
            ->get(['id', 'name', 'address', 'phone']);
        $partiesByAccount = $partyClass::query()
            ->whereHas('account.subgroup', $inSubgroup)
            ->with('area:id,name')
            ->get(['id', 'name', 'account_id', 'address', 'mobile_no', 'area_id'])
            ->keyBy('account_id');

        $accounts = $accounts
            ->filter(fn (Account $account): bool => $this->accountInArea($areaFilter, $partiesByAccount->get($account->id)))
            ->values();

        $branchFilter = BranchFilter::fromRequest($request);
        $balances = $this->ledgerBalances($accounts->modelKeys(), $fiscalYearId, null, $creditNormal, $branchFilter->lineBranchIds());

        $rows = $accounts
            ->map(function (Account $account) use ($partiesByAccount, $balances): array {
                $party = $partiesByAccount->get($account->id);

                return [
                    'id' => $account->id,
                    'party_id' => $party?->getKey(),
                    'account_id' => $account->id,
                    'name' => $party?->name ?? $account->name,
                    'address' => $party?->address ?? $account->address,
                    'mobile_no' => $party?->mobile_no ?? $account->phone,
                    'area' => $party?->area?->name,
                    'balance' => ($balances[$account->id] ?? Money::zero())->toString(),
                ];
            })
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
            ->values();

        $branchColumns = null;
        $branchTotals = null;

        // The Excel files carry no branch columns, so they keep only rows with a
        // real balance instead of rows kept for a netting branch split.
        if ($withBranchColumns && $branchFilter->byBranch()) {
            $split = PartyBranchBalances::withBranchColumns(
                $branchFilter,
                $rows,
                $rows->mapWithKeys(fn (array $row): array => [$row['id'] => $row['account_id']])->all(),
                $fiscalYearId,
                $creditNormal,
            );
            [$rows, $branchColumns, $branchTotals] = [$split['rows'], $split['columns'], $split['totals']];
        }

        $rows = $rows
            ->filter(fn (array $row) => ! Money::of($row['balance'])->isZero()
                || collect($row['by_branch'] ?? [])->contains(fn (string $amount): bool => ! Money::of($amount)->isZero()))
            ->map(fn (array $row) => [
                ...$row,
                'is_overpaid' => Money::of($row['balance'])->isNegative(),
            ])
            ->values();

        $areaProps = $this->areaReportProps($areaFilter, $groupByArea, $rows->all(), ['balance']);
        $branchReport = $branchFilter->payload(supportsByBranch: true);

        if ($branchColumns !== null) {
            $areaProps['groups'] = PartyBranchBalances::groupsWithBranchTotals($areaProps['groups'], $branchColumns);
            $branchReport = $branchReport === null ? null : [...$branchReport, 'columns' => $branchColumns, 'totals' => $branchTotals];
        }

        return [
            'rows' => $rows,
            'total' => Money::sum($rows->map(fn (array $row) => Money::of($row['balance'])))->toString(),
            ...$areaProps,
            'branchReport' => $branchReport,
            'fiscalYears' => $fiscalYears,
            'fiscalYearId' => $fiscalYearId,
            'creditBalanceNote' => $creditNormal
                ? 'A supplier shown as Dr was overpaid (a debit balance) and owes money back; it is flagged Overpaid.'
                : 'A customer shown as Cr overpaid (a credit balance) and is owed money back; it is flagged Overpaid.',
        ];
    }

    /**
     * Whether a Debtors / Creditors account passes the area filter. The area
     * lives on the owning party; a non-party account has none, so it counts
     * as "Unassigned".
     */
    private function accountInArea(AreaFilter $areaFilter, ?Model $party): bool
    {
        if (! $areaFilter->isActive()) {
            return true;
        }

        $areaId = $party?->getAttribute('area_id');

        if ($areaFilter->isUnassigned()) {
            return $areaId === null;
        }

        return $areaId !== null && (int) $areaId === $areaFilter->areaId;
    }

    /**
     * The year containing $asOf (open or closed). An as-of date past the last
     * year falls back to the latest year that has started; before the first
     * year there is none.
     */
    private function resolveFiscalYearIdForDate(string $asOf): ?int
    {
        $fiscalYearId = FiscalYear::query()
            ->whereDate('start_date', '<=', $asOf)
            ->whereDate('end_date', '>=', $asOf)
            ->value('id')
            ?? FiscalYear::query()
                ->whereDate('start_date', '<=', $asOf)
                ->orderByDesc('start_date')
                ->value('id');

        return $fiscalYearId === null ? null : (int) $fiscalYearId;
    }

    /**
     * Whole days from $from to $asOfDate, never negative. Both are start-of-day.
     */
    private function daysOutstanding(CarbonInterface $from, CarbonInterface $asOfDate): int
    {
        return max(0, (int) $from->diffInDays($asOfDate, false));
    }

    /**
     * Net ledger balance per account, summed in SQL as a scaled integer.
     *
     * A plain `SUM(decimal)` comes back as a float on SQLite (a DECIMAL
     * column has REAL affinity there), which is exactly the precision loss
     * this rewrite removed everywhere else, so the sum is taken over
     * `ROUND(column * 100)` integers and divided back at the end - lossless
     * at the two decimals a Money holds.
     *
     * $branchIds (BranchFilter::lineBranchIds()) goes straight onto
     * journal_voucher_lines.branch_id; null adds nothing.
     *
     * @param  array<int, int>  $accountIds
     * @param  list<int>|null  $branchIds
     * @return array<int, Money>
     */
    private function ledgerBalances(array $accountIds, ?int $fiscalYearId, ?string $asOf, bool $creditNormal, ?array $branchIds = null): array
    {
        $ids = array_values(array_unique(array_map('intval', $accountIds)));

        if ($ids === [] || $fiscalYearId === null) {
            return [];
        }

        $debit = $this->scaledMoneyExpression('journal_voucher_lines.debit');
        $credit = $this->scaledMoneyExpression('journal_voucher_lines.credit');
        $expression = $creditNormal ? "SUM({$credit}) - SUM({$debit})" : "SUM({$debit}) - SUM({$credit})";

        $rows = JournalVoucherLine::query()
            ->join('journal_vouchers', 'journal_vouchers.id', '=', 'journal_voucher_lines.journal_voucher_id')
            ->whereIn('journal_voucher_lines.account_id', $ids)
            ->where('journal_vouchers.fiscal_year_id', $fiscalYearId)
            ->when($asOf !== null, fn (Builder $query) => $query->whereDate('journal_vouchers.date', '<=', $asOf))
            ->tap(fn (Builder $query) => BranchFilter::narrowLines($query, $branchIds))
            ->groupBy('journal_voucher_lines.account_id')
            ->selectRaw("journal_voucher_lines.account_id as account_id, {$expression} as net_scaled")
            ->pluck('net_scaled', 'account_id');

        $balances = [];

        foreach ($rows as $accountId => $netScaled) {
            $balances[(int) $accountId] = Money::of(
                BigDecimal::of((int) $netScaled)->dividedBy(100, 2, RoundingMode::Unnecessary)
            );
        }

        return $balances;
    }

    private function scaledMoneyExpression(string $column): string
    {
        $cast = (new JournalVoucherLine)->getConnection()->getDriverName() === 'sqlite' ? 'INTEGER' : 'SIGNED';

        return "CAST(ROUND({$column} * 100) AS {$cast})";
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyAgingRow(?Model $party): array
    {
        $zero = Money::zero()->toString();

        return [
            'party' => $party?->name ?? 'Unknown',
            'party_id' => $party?->getKey(),
            'account_id' => $party?->account_id,
            'mobile_no' => $party?->mobile_no,
            'oldest' => null,
            'days' => null,
            'area' => $party?->area?->name,
            'opening' => $zero,
            'current' => $zero,
            'days31_60' => $zero,
            'days61_90' => $zero,
            'days90Plus' => $zero,
            'total' => $zero,
        ];
    }

    /**
     * The area props every area-aware party report sends to its page: the
     * dropdown options, the active filter, and - only when "Group by area" is
     * on - the rows grouped by area with exact subtotals of `$measures`.
     *
     * @param  iterable<int, array<string, mixed>>  $rows
     * @param  list<string>  $measures
     * @return array<string, mixed>
     */
    private function areaReportProps(AreaFilter $areaFilter, bool $groupByArea, iterable $rows, array $measures): array
    {
        return [
            'areas' => Area::options(),
            'area' => $areaFilter->value,
            'areaLabel' => $areaFilter->isActive() ? $areaFilter->label() : null,
            'groupByArea' => $groupByArea,
            'groups' => $groupByArea ? AreaFilter::groupRows($rows, $measures) : null,
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
     * @param  array<int, array<string, string>>  $rows
     * @return array<string, string>
     */
    private function sumAgingTotals(array $rows): array
    {
        $totals = [];

        foreach (['opening', ...self::AGING_BUCKETS, 'total'] as $key) {
            $totals[$key] = Money::sum(array_map(fn (array $row) => Money::of($row[$key]), $rows))->toString();
        }

        return $totals;
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
                    ->whereHas('reversalJournalVoucher', fn (Builder $voucher) => $this->betweenDates($voucher, 'date', $from, $to))
                    ->orWhere(fn (Builder $legacy) => $this->betweenDates(
                        $legacy->whereNull('reversal_journal_voucher_id'),
                        'cancelled_at',
                        $from,
                        $to,
                    ));
            })
            ->orderBy('id');
    }

    private function cancelDate(Model $document): string
    {
        return $document->reversalJournalVoucher?->date?->toDateString()
            ?? $document->cancelled_at?->toDateString()
            ?? $document->date->toDateString();
    }

    /**
     * Inclusive on both ends, compared on the date part only, so a `to` of
     * 2026-06-30 always includes the 30th on SQLite and on MySQL alike (a
     * `whereBetween` against a datetime column silently drops the last day).
     *
     * @param  Builder<covariant Model>  $query
     * @return Builder<covariant Model>
     */
    private function betweenDates($query, string $column, string $from, string $to)
    {
        return $query->whereDate($column, '>=', $from)->whereDate($column, '<=', $to);
    }

    private function signed(string $amount, int $sign): string
    {
        $money = Money::of($amount);

        return ($sign < 0 ? $money->negated() : $money)->toString();
    }

    /**
     * Orders the four row groups of a book into one chronological list and
     * numbers them, so SN is stable between the screen and the export.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    private function numbered(Collection $rows): Collection
    {
        return $rows
            ->sortBy([['date', 'asc'], ['kind', 'asc'], ['id', 'asc']])
            ->values()
            ->map(fn (array $row, int $index) => ['sn' => $index + 1, ...$row]);
    }

    /**
     * Totals are summed over exactly the rows being rendered, in Money, so
     * the footer can never disagree with the list above it by a paisa.
     *
     * @param  Collection<int, array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return array<string, string>
     */
    private function totalsFor(Collection $rows, array $keys): array
    {
        $totals = [];

        foreach ($keys as $key) {
            $totals[$key] = Money::sum($rows->map(fn (array $row) => Money::of($row[$key] ?? '0')))->toString();
        }

        return $totals;
    }

    /**
     * @return 'cash'|'bank'|'partial'|'credit'|null
     */
    private function resolvePaymentMode(Request $request): ?string
    {
        $mode = $request->string('payment_mode')->toString();

        return in_array($mode, ['cash', 'bank', 'partial', 'credit'], true) ? $mode : null;
    }

    private function resolveAsOf(Request $request): string
    {
        return AsOfDate::resolve($request->input('as_of'));
    }

    /**
     * Defaults to the current open fiscal year's date range when no
     * explicit `from`/`to` query params are given, falling back to
     * month-to-date if no fiscal year exists yet.
     *
     * Strict, as UnifiedVatRegisterController: a malformed or reversed
     * range, a lone `from` or `to`, or an unknown store / customer / supplier
     * is a 422 the page shows, never a silent fallback, so a download name
     * can only ever carry two clean Y-m-d dates.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(Request $request): array
    {
        $request->validate([
            'from' => ['nullable', 'required_with:to', 'date_format:Y-m-d'],
            'to' => ['nullable', 'required_with:from', 'date_format:Y-m-d', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
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
