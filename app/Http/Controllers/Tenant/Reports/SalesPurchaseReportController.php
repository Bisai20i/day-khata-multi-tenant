<?php

namespace App\Http\Controllers\Tenant\Reports;

use App\Enums\FiscalYearStatus;
use App\Exports\CreditorsExport;
use App\Exports\DebtorsExport;
use App\Exports\PurchaseReturnRegisterExport;
use App\Exports\PurchaseVatBookExport;
use App\Exports\SalesReturnRegisterExport;
use App\Exports\SalesVatBookExport;
use App\Http\Controllers\Controller;
use App\Models\CapitalPurchase;
use App\Models\CapitalSale;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\JournalVoucherLine;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Store;
use App\Models\Supplier;
use App\Support\Money\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
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

    public function salesRegister(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $customerId = $request->integer('customer_id') ?: null;
        $storeId = $request->integer('store_id') ?: null;
        $paymentMode = $this->resolvePaymentMode($request);

        $sales = Sale::query()
            ->with(['customer:id,name', 'journalVoucher:id,voucher_type,voucher_number'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($customerId, fn (Builder $query) => $query->where('customer_id', $customerId))
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->when($paymentMode, fn (Builder $query) => $query->where('payment_mode', $paymentMode))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $rows = $sales->map(fn (Sale $sale) => [
            'id' => $sale->id,
            'date' => $sale->date->toDateString(),
            'invoice_number' => $sale->invoice_number,
            'invoice_type' => $sale->invoice_type,
            'customer' => $sale->buyer_name ?? $sale->customer?->name,
            'taxable_amount' => $sale->taxable_amount,
            'nontaxable_amount' => $sale->nontaxable_amount,
            'vat_amount' => $sale->vat_amount,
            'total' => $sale->total,
            'payment_mode' => $sale->payment_mode,
            'status' => $sale->status,
        ])->values();

        $posted = $rows->filter(fn (array $row) => $row['status'] === 'posted')->values();

        return Inertia::render('Tenant/Reports/SalesRegister', [
            'sales' => $rows,
            'totals' => $this->totalsFor($posted, ['taxable_amount', 'nontaxable_amount', 'vat_amount', 'total']),
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'customerId' => $customerId,
            'storeId' => $storeId,
            'paymentMode' => $paymentMode,
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

        $purchases = Purchase::query()
            ->with(['supplier:id,name,tpin', 'journalVoucher:id,voucher_type,voucher_number'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($supplierId, fn (Builder $query) => $query->where('supplier_id', $supplierId))
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->when($paymentMode, fn (Builder $query) => $query->where('payment_mode', $paymentMode))
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $rows = $purchases->map(fn (Purchase $purchase) => [
            'id' => $purchase->id,
            'date' => $purchase->date->toDateString(),
            'voucher_number' => $purchase->journalVoucher?->voucher_number,
            'bill_number' => $purchase->bill_number,
            'pan_number' => $purchase->pan_number ?: $purchase->supplier?->tpin,
            'supplier' => $purchase->supplier?->name,
            'taxable_amount' => $purchase->taxable_amount,
            'nontaxable_amount' => $purchase->nontaxable_amount,
            'vat_amount' => $purchase->vat_amount,
            'total' => $purchase->total,
            'payment_mode' => $purchase->payment_mode,
            'status' => $purchase->status,
        ])->values();

        $posted = $rows->filter(fn (array $row) => $row['status'] === 'posted')->values();

        return Inertia::render('Tenant/Reports/PurchaseRegister', [
            'purchases' => $rows,
            'totals' => $this->totalsFor($posted, ['taxable_amount', 'nontaxable_amount', 'vat_amount', 'total']),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'from' => $from,
            'to' => $to,
            'supplierId' => $supplierId,
            'storeId' => $storeId,
            'paymentMode' => $paymentMode,
        ]);
    }

    public function salesVatBook(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;

        return Inertia::render('Tenant/Reports/SalesVatBook', [
            ...$this->salesVatBookPayload($from, $to, $storeId),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Same filtered rows/totals as salesVatBook(), streamed as a real .xlsx
     * via the exact same builder so the export can never drift from what is
     * on screen and always honours the applied from/to/store_id filters.
     */
    public function salesVatBookExport(Request $request)
    {
        [$from, $to] = $this->resolveDateRange($request);
        $data = $this->salesVatBookPayload($from, $to, $request->integer('store_id') ?: null);

        return Excel::download(
            new SalesVatBookExport($data['rows'], $data['totals']),
            "sales-vat-book-{$from}-to-{$to}.xlsx",
        );
    }

    public function purchaseVatBook(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;

        return Inertia::render('Tenant/Reports/PurchaseVatBook', [
            ...$this->purchaseVatBookPayload($from, $to, $storeId),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Same filtered rows/totals as purchaseVatBook(), streamed as a real
     * .xlsx - see salesVatBookExport()'s own docblock for the reasoning.
     */
    public function purchaseVatBookExport(Request $request)
    {
        [$from, $to] = $this->resolveDateRange($request);
        $data = $this->purchaseVatBookPayload($from, $to, $request->integer('store_id') ?: null);

        return Excel::download(
            new PurchaseVatBookExport($data['rows'], $data['totals']),
            "purchase-vat-book-{$from}-to-{$to}.xlsx",
        );
    }

    public function salesReturnRegister(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;

        return Inertia::render('Tenant/Reports/SalesReturnRegister', [
            ...$this->salesReturnRegisterPayload($from, $to, $storeId),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function salesReturnRegisterExport(Request $request)
    {
        [$from, $to] = $this->resolveDateRange($request);
        $data = $this->salesReturnRegisterPayload($from, $to, $request->integer('store_id') ?: null);

        return Excel::download(
            new SalesReturnRegisterExport($data['rows'], $data['totals']),
            "sales-return-register-{$from}-to-{$to}.xlsx",
        );
    }

    public function purchaseReturnRegister(Request $request): Response
    {
        [$from, $to] = $this->resolveDateRange($request);
        $storeId = $request->integer('store_id') ?: null;

        return Inertia::render('Tenant/Reports/PurchaseReturnRegister', [
            ...$this->purchaseReturnRegisterPayload($from, $to, $storeId),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function purchaseReturnRegisterExport(Request $request)
    {
        [$from, $to] = $this->resolveDateRange($request);
        $data = $this->purchaseReturnRegisterPayload($from, $to, $request->integer('store_id') ?: null);

        return Excel::download(
            new PurchaseReturnRegisterExport($data['rows'], $data['totals']),
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
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, string>, from: string, to: string, storeId: int|null}
     */
    public function salesVatBookPayload(string $from, string $to, ?int $storeId): array
    {
        $rows = $this->salesVatBookRows($from, $to, $storeId);

        return [
            'rows' => $rows,
            'totals' => [
                ...$this->totalsFor($rows, ['taxable_amount', 'nontaxable_amount', 'vat_amount', 'capital_amount', 'total']),
                'count' => $rows->count(),
            ],
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
        ];
    }

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, string>, from: string, to: string, storeId: int|null}
     */
    public function purchaseVatBookPayload(string $from, string $to, ?int $storeId): array
    {
        $rows = $this->purchaseVatBookRows($from, $to, $storeId);

        return [
            'rows' => $rows,
            'totals' => [
                ...$this->totalsFor($rows, ['taxable_amount', 'nontaxable_amount', 'vat_amount', 'capital_amount', 'fixed_asset_vat_amount', 'total']),
                'count' => $rows->count(),
            ],
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
        ];
    }

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, string>, from: string, to: string, storeId: int|null}
     */
    public function salesReturnRegisterPayload(string $from, string $to, ?int $storeId): array
    {
        $rows = $this->salesReturnRegisterRows($from, $to, $storeId);

        return [
            'rows' => $rows,
            'totals' => [
                ...$this->totalsFor($rows, ['taxable_amount', 'nontaxable_amount', 'vat_amount', 'total']),
                'count' => $rows->count(),
            ],
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
        ];
    }

    /**
     * @return array{rows: Collection<int, array<string, mixed>>, totals: array<string, string>, from: string, to: string, storeId: int|null}
     */
    public function purchaseReturnRegisterPayload(string $from, string $to, ?int $storeId): array
    {
        $rows = $this->purchaseReturnRegisterRows($from, $to, $storeId);

        return [
            'rows' => $rows,
            'totals' => [
                ...$this->totalsFor($rows, ['taxable_amount', 'nontaxable_amount', 'vat_amount', 'total']),
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
    private function salesVatBookRows(string $from, string $to, ?int $storeId): Collection
    {
        $issued = Sale::query()
            ->with(['customer:id,name,tpin'])
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (Sale $sale) => $this->saleVatRow($sale, $sale->date->toDateString(), 'issued'));

        $cancelled = $this->cancelledInPeriod(Sale::query()->with(['customer:id,name,tpin']), $from, $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->get()
            ->map(fn (Sale $sale) => $this->saleVatRow($sale, $this->cancelDate($sale), 'cancelled'));

        $capitalIssued = CapitalSale::query()
            ->with(['customer:id,name,tpin'])
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (CapitalSale $sale) => $this->capitalSaleVatRow($sale, $sale->date->toDateString(), 'issued'));

        $capitalCancelled = $this->cancelledInPeriod(CapitalSale::query()->with(['customer:id,name,tpin']), $from, $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->get()
            ->map(fn (CapitalSale $sale) => $this->capitalSaleVatRow($sale, $this->cancelDate($sale), 'cancelled'));

        return $this->numbered($issued->concat($capitalIssued)->concat($cancelled)->concat($capitalCancelled));
    }

    /**
     * @return array<string, mixed>
     */
    private function saleVatRow(Sale $sale, string $date, string $entry): array
    {
        $sign = $entry === 'cancelled' ? -1 : 1;

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
            'taxable_amount' => $this->signed($sale->taxable_amount, $sign),
            'nontaxable_amount' => $this->signed($sale->nontaxable_amount, $sign),
            'vat_amount' => $this->signed($sale->vat_amount, $sign),
            'capital_amount' => Money::zero()->toString(),
            'total' => $this->signed($sale->total, $sign),
            'capital' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function capitalSaleVatRow(CapitalSale $sale, string $date, string $entry): array
    {
        $sign = $entry === 'cancelled' ? -1 : 1;

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
            'taxable_amount' => $this->signed($sale->taxable_amount, $sign),
            'nontaxable_amount' => $this->signed($sale->nontaxable_amount, $sign),
            'vat_amount' => $this->signed($sale->vat_amount, $sign),
            'capital_amount' => $this->signed($sale->taxable_amount, $sign),
            'total' => $this->signed($sale->total, $sign),
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
    private function purchaseVatBookRows(string $from, string $to, ?int $storeId): Collection
    {
        $issued = Purchase::query()
            ->with(['supplier:id,name,tpin', 'lines.account.group'])
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (Purchase $purchase) => $this->purchaseVatRow($purchase, $purchase->date->toDateString(), 'issued'));

        $cancelled = $this->cancelledInPeriod(Purchase::query()->with(['supplier:id,name,tpin', 'lines.account.group']), $from, $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->get()
            ->map(fn (Purchase $purchase) => $this->purchaseVatRow($purchase, $this->cancelDate($purchase), 'cancelled'));

        $capitalIssued = CapitalPurchase::query()
            ->with(['supplier:id,name,tpin'])
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (CapitalPurchase $purchase) => $this->capitalPurchaseVatRow($purchase, $purchase->date->toDateString(), 'issued'));

        $capitalCancelled = $this->cancelledInPeriod(CapitalPurchase::query()->with(['supplier:id,name,tpin']), $from, $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->get()
            ->map(fn (CapitalPurchase $purchase) => $this->capitalPurchaseVatRow($purchase, $this->cancelDate($purchase), 'cancelled'));

        return $this->numbered($issued->concat($capitalIssued)->concat($cancelled)->concat($capitalCancelled));
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseVatRow(Purchase $purchase, string $date, string $entry): array
    {
        $sign = $entry === 'cancelled' ? -1 : 1;

        return [
            'kind' => 'purchase',
            'entry' => $entry,
            'id' => $purchase->id,
            'date' => $date,
            'issued_on' => $purchase->date->toDateString(),
            'bill_number' => $purchase->bill_number,
            'supplier' => $purchase->supplier?->name,
            'supplier_pan' => $purchase->pan_number ?: $purchase->supplier?->tpin,
            'taxable_amount' => $this->signed($purchase->taxable_amount, $sign),
            'nontaxable_amount' => $this->signed($purchase->nontaxable_amount, $sign),
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
            'total' => $this->signed($purchase->total, $sign),
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

        return [
            'kind' => 'capital_purchase',
            'entry' => $entry,
            'id' => $purchase->id,
            'date' => $date,
            'issued_on' => $purchase->date->toDateString(),
            'bill_number' => $purchase->bill_number,
            'supplier' => $purchase->supplier?->name,
            'supplier_pan' => $purchase->supplier_pan ?: $purchase->supplier?->tpin,
            'taxable_amount' => $this->signed($purchase->taxable_amount, $sign),
            'nontaxable_amount' => $this->signed($purchase->nontaxable_amount, $sign),
            'vat_amount' => $this->signed($purchase->vat_amount, $sign),
            'capital_amount' => $this->signed($purchase->taxable_amount, $sign),
            // A whole CapitalPurchase document is already its own "capital"
            // bucket above, so it never contributes to the fixed-asset
            // breakdown of an ordinary Purchase's vat_amount.
            'fixed_asset_vat_amount' => Money::zero()->toString(),
            'total' => $this->signed($purchase->total, $sign),
            'capital' => true,
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
    private function salesReturnRegisterRows(string $from, string $to, ?int $storeId): Collection
    {
        $issued = SalesReturn::query()
            ->with(['sale:id,invoice_number,customer_id,buyer_name,buyer_pan', 'sale.customer:id,name,tpin'])
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (SalesReturn $return) => $this->salesReturnRow($return, $return->date->toDateString(), 'issued'));

        $cancelled = $this->cancelledInPeriod(
            SalesReturn::query()->with(['sale:id,invoice_number,customer_id,buyer_name,buyer_pan', 'sale.customer:id,name,tpin']),
            $from,
            $to,
        )
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->get()
            ->map(fn (SalesReturn $return) => $this->salesReturnRow($return, $this->cancelDate($return), 'cancelled'));

        return $this->numbered($issued->concat($cancelled));
    }

    /**
     * @return array<string, mixed>
     */
    private function salesReturnRow(SalesReturn $return, string $date, string $entry): array
    {
        $sign = $entry === 'cancelled' ? -1 : 1;

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
            'taxable_amount' => $this->signed($return->taxable_amount, $sign),
            'nontaxable_amount' => $this->signed($return->nontaxable_amount, $sign),
            'vat_amount' => $this->signed($return->vat_amount, $sign),
            'tds_amount' => $this->signed($return->tds_amount, $sign),
            'total' => $this->signed($return->total, $sign),
        ];
    }

    /**
     * Debit-note book - the purchase-side mirror of
     * salesReturnRegisterRows().
     *
     * @return Collection<int, array<string, mixed>>
     */
    private function purchaseReturnRegisterRows(string $from, string $to, ?int $storeId): Collection
    {
        $with = ['purchase:id,bill_number,supplier_id,pan_number', 'purchase.supplier:id,name,tpin'];

        $issued = PurchaseReturn::query()
            ->with($with)
            ->whereIn('status', ['posted', 'cancelled'])
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->orderBy('id')
            ->get()
            ->map(fn (PurchaseReturn $return) => $this->purchaseReturnRow($return, $return->date->toDateString(), 'issued'));

        $cancelled = $this->cancelledInPeriod(PurchaseReturn::query()->with($with), $from, $to)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->get()
            ->map(fn (PurchaseReturn $return) => $this->purchaseReturnRow($return, $this->cancelDate($return), 'cancelled'));

        return $this->numbered($issued->concat($cancelled));
    }

    /**
     * @return array<string, mixed>
     */
    private function purchaseReturnRow(PurchaseReturn $return, string $date, string $entry): array
    {
        $sign = $entry === 'cancelled' ? -1 : 1;

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
            'taxable_amount' => $this->signed($return->taxable_amount, $sign),
            'nontaxable_amount' => $this->signed($return->nontaxable_amount, $sign),
            'vat_amount' => $this->signed($return->vat_amount, $sign),
            'tds_amount' => $this->signed($return->tds_amount, $sign),
            'total' => $this->signed($return->total, $sign),
        ];
    }

    /**
     * Ages every still-open `credit`-mode sale by days elapsed between its
     * invoice date and `as_of`, bucketed Current (0-30) / 31-60 / 61-90 /
     * 90+, grouped by customer.
     *
     * Two audit fixes live here:
     *
     * - Outstanding is `Sale::outstandingAmount()` compared **exactly**. The
     *   old `<= 0.01` filter was a float tolerance that both hid a real one
     *   paisa debt and, worse, implied money comparisons need tolerances.
     * - An "Opening / unallocated" bucket carries whatever the customer's
     *   ledger balance holds that no open invoice explains: a migrated
     *   opening due, a receipt on account, a manual journal voucher. Those
     *   were invisible before (audit P1 "no ledger-based Debtors list"), so
     *   a tenant who imported opening balances saw an empty ageing report
     *   while the balance sheet showed lakhs of debtors.
     */
    public function agedReceivables(Request $request): Response
    {
        $asOf = $this->resolveAsOf($request);
        $storeId = $request->integer('store_id') ?: null;

        $sales = Sale::query()
            ->with('customer:id,name,account_id')
            ->where('payment_mode', 'credit')
            ->where('status', 'posted')
            ->whereDate('date', '<=', $asOf)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->get();

        $byParty = [];
        $accountIds = [];

        foreach ($sales as $sale) {
            $outstanding = $sale->outstandingAmount();

            if (! $outstanding->isPositive()) {
                continue;
            }

            $key = $sale->customer_id;
            $byParty[$key] ??= $this->emptyAgingRow($sale->customer?->name ?? 'Unknown');
            $bucket = $this->agingBucket((int) $sale->date->diffInDays(Carbon::parse($asOf)));

            $byParty[$key][$bucket] = Money::of($byParty[$key][$bucket])->plus($outstanding)->toString();
            $byParty[$key]['invoiced'] = Money::of($byParty[$key]['invoiced'])->plus($outstanding)->toString();

            if ($sale->customer?->account_id) {
                $accountIds[$key] = $sale->customer->account_id;
            }
        }

        $rows = $this->withOpeningBucket($byParty, $accountIds, Customer::query(), $asOf, $storeId, creditNormal: false);

        return Inertia::render('Tenant/Reports/AgedReceivables', [
            'rows' => $rows,
            'totals' => $this->sumAgingTotals($rows),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'asOf' => $asOf,
            'storeId' => $storeId,
        ]);
    }

    /**
     * Exact mirror of agedReceivables() for `credit`-mode purchases and
     * suppliers, via Purchase::outstandingAmount() instead of
     * Sale::outstandingAmount().
     */
    public function agedPayables(Request $request): Response
    {
        $asOf = $this->resolveAsOf($request);
        $storeId = $request->integer('store_id') ?: null;

        $purchases = Purchase::query()
            ->with('supplier:id,name,account_id')
            ->where('payment_mode', 'credit')
            ->where('status', 'posted')
            ->whereDate('date', '<=', $asOf)
            ->when($storeId, fn (Builder $query) => $query->where('store_id', $storeId))
            ->orderBy('date')
            ->get();

        $byParty = [];
        $accountIds = [];

        foreach ($purchases as $purchase) {
            $outstanding = $purchase->outstandingAmount();

            if (! $outstanding->isPositive()) {
                continue;
            }

            $key = $purchase->supplier_id;
            $byParty[$key] ??= $this->emptyAgingRow($purchase->supplier?->name ?? 'Unknown');
            $bucket = $this->agingBucket((int) $purchase->date->diffInDays(Carbon::parse($asOf)));

            $byParty[$key][$bucket] = Money::of($byParty[$key][$bucket])->plus($outstanding)->toString();
            $byParty[$key]['invoiced'] = Money::of($byParty[$key]['invoiced'])->plus($outstanding)->toString();

            if ($purchase->supplier?->account_id) {
                $accountIds[$key] = $purchase->supplier->account_id;
            }
        }

        $rows = $this->withOpeningBucket($byParty, $accountIds, Supplier::query(), $asOf, $storeId, creditNormal: true);

        return Inertia::render('Tenant/Reports/AgedPayables', [
            'rows' => $rows,
            'totals' => $this->sumAgingTotals($rows),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'asOf' => $asOf,
            'storeId' => $storeId,
        ]);
    }

    /**
     * Ledger-based Debtors list: every customer whose ledger account carries
     * a non-zero balance in the selected fiscal year. Unlike the ageing
     * report this owes nothing to invoices - it is the receivable side of
     * the trial balance, party by party, so it always reconciles with the
     * Sundry Debtors subgroup on the Balance Sheet.
     */
    public function debtors(Request $request): Response
    {
        return Inertia::render('Tenant/Reports/Debtors', $this->partyBalancePayload($request, Customer::query(), creditNormal: false));
    }

    public function debtorsExport(Request $request)
    {
        $data = $this->partyBalancePayload($request, Customer::query(), creditNormal: false);

        return Excel::download(new DebtorsExport($data['rows'], $data['total']), 'debtors.xlsx');
    }

    /**
     * Ledger-based Creditors list - the payable mirror of debtors().
     */
    public function creditors(Request $request): Response
    {
        return Inertia::render('Tenant/Reports/Creditors', $this->partyBalancePayload($request, Supplier::query(), creditNormal: true));
    }

    public function creditorsExport(Request $request)
    {
        $data = $this->partyBalancePayload($request, Supplier::query(), creditNormal: true);

        return Excel::download(new CreditorsExport($data['rows'], $data['total']), 'creditors.xlsx');
    }

    /**
     * Balances are scoped to one fiscal year's own voucher lines, never
     * summed across years. Audit P0-18: the next year's Opening Balance
     * voucher restates the same closing balances, so an all-years sum double
     * counts every party from the first year-end close onwards.
     *
     * Audit T15-8: legacy's debtors()/creditors() use HAVING SUM(...) > 0, so an
     * overpaid party (credit balance) is silently dropped there. This report keeps
     * those rows - an overpayment is real money owed back - but flags each one via
     * `is_credit_balance` so the difference from legacy is visible, not silent.
     *
     * @param  Builder<covariant Model>  $parties
     * @return array<string, mixed>
     */
    private function partyBalancePayload(Request $request, $parties, bool $creditNormal): array
    {
        $fiscalYears = FiscalYear::query()->orderByDesc('start_date')->get(['id', 'name', 'status']);
        $fiscalYearId = $request->integer('fiscal_year_id')
            ?: ($fiscalYears->firstWhere('status', FiscalYearStatus::Open)?->id ?? $fiscalYears->first()?->id);

        $records = $parties->orderBy('name')->get(['id', 'name', 'account_id', 'address', 'mobile_no']);
        $balances = $this->ledgerBalances($records->pluck('account_id')->filter()->all(), $fiscalYearId, null, $creditNormal);

        $rows = $records
            ->map(fn (Model $party) => [
                'id' => $party->getKey(),
                'name' => $party->name,
                'address' => $party->address,
                'mobile_no' => $party->mobile_no,
                'balance' => ($balances[$party->account_id] ?? Money::zero())->toString(),
            ])
            ->filter(fn (array $row) => ! Money::of($row['balance'])->isZero())
            ->map(fn (array $row) => [
                ...$row,
                'is_credit_balance' => Money::of($row['balance'])->isNegative(),
            ])
            ->values();

        return [
            'rows' => $rows,
            'total' => Money::sum($rows->map(fn (array $row) => Money::of($row['balance'])))->toString(),
            'fiscalYears' => $fiscalYears,
            'fiscalYearId' => $fiscalYearId,
            'creditBalanceNote' => $creditNormal
                ? 'A negative balance means this supplier owes money back (overpaid).'
                : 'A negative balance means this customer overpaid and is owed money back.',
        ];
    }

    /**
     * Folds the "Opening / unallocated" bucket into the per-party ageing
     * rows: the party's ledger balance minus everything the open invoices
     * above already explain. A party with only an opening balance and no open
     * invoice still gets a row, which is the whole point - a tenant who
     * imported opening dues used to see an empty ageing page while the
     * Balance Sheet showed lakhs of debtors (audit P1).
     *
     * The bucket is skipped entirely when a store is selected. The chart of
     * accounts is not store-scoped, so a party's ledger balance cannot be
     * attributed to one store; folding the whole balance into a single
     * store's page would show every party under every store.
     *
     * @param  array<int|string, array<string, string>>  $byParty
     * @param  array<int|string, int>  $accountIds
     * @param  Builder<covariant Model>  $parties
     * @return list<array<string, string>>
     */
    private function withOpeningBucket(array $byParty, array $accountIds, $parties, string $asOf, ?int $storeId, bool $creditNormal): array
    {
        if ($storeId === null) {
            $records = $parties->get(['id', 'name', 'account_id'])->keyBy(fn (Model $party) => $party->getKey());

            foreach ($records as $id => $party) {
                if ($party->account_id) {
                    $accountIds[$id] = $party->account_id;
                }
            }

            $fiscalYearId = FiscalYear::query()
                ->whereDate('start_date', '<=', $asOf)
                ->whereDate('end_date', '>=', $asOf)
                ->value('id');

            $balances = $this->ledgerBalances(array_values($accountIds), $fiscalYearId, $asOf, $creditNormal);

            foreach ($accountIds as $partyId => $accountId) {
                $balance = $balances[$accountId] ?? Money::zero();
                $invoiced = Money::of($byParty[$partyId]['invoiced'] ?? '0.00');
                $opening = $balance->minus($invoiced);

                if ($opening->isZero() && ! isset($byParty[$partyId])) {
                    continue;
                }

                $byParty[$partyId] ??= $this->emptyAgingRow($records->get($partyId)?->name ?? 'Unknown');
                $byParty[$partyId]['opening'] = $opening->toString();
            }
        }

        $rows = [];

        foreach ($byParty as $row) {
            $row['total'] = Money::sum(array_map(
                fn (string $key) => Money::of($row[$key]),
                ['opening', ...self::AGING_BUCKETS],
            ))->toString();

            unset($row['invoiced']);

            if (! Money::of($row['total'])->isZero()) {
                $rows[] = $row;
            }
        }

        usort($rows, fn (array $a, array $b) => strcasecmp($a['party'], $b['party']));

        return $rows;
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
     * @param  array<int, int>  $accountIds
     * @return array<int, Money>
     */
    private function ledgerBalances(array $accountIds, ?int $fiscalYearId, ?string $asOf, bool $creditNormal): array
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
     * @return array<string, string>
     */
    private function emptyAgingRow(string $party): array
    {
        $zero = Money::zero()->toString();

        return [
            'party' => $party,
            'opening' => $zero,
            'current' => $zero,
            'days31_60' => $zero,
            'days61_90' => $zero,
            'days90Plus' => $zero,
            'invoiced' => $zero,
            'total' => $zero,
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
            $totals[$key] = Money::sum($rows->map(fn (array $row) => Money::of($row[$key])))->toString();
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
        $asOf = $request->string('as_of')->toString();

        return $asOf !== ''
            ? Carbon::parse($asOf)->toDateString()
            : Carbon::now()->toDateString();
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
