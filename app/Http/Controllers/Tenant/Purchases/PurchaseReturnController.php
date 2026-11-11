<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Exports\PurchaseReturnListExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Tenant\Purchases\StoreUnlinkedPurchaseReturnRequest;
use App\Models\Account;
use App\Models\Branch;
use App\Models\CompanySetting;
use App\Models\Item;
use App\Models\PrintLog;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\Store;
use App\Models\Supplier;
use App\Rules\AccountUnderHead;
use App\Support\AmountInWords;
use App\Support\Branches\ActiveBranchSelection;
use App\Support\Branches\BranchAccounts;
use App\Support\Branches\BranchFormOptions;
use App\Support\Branches\BranchListFilter;
use App\Support\Branches\BranchStoreOptions;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;
use Maatwebsite\Excel\Facades\Excel;

class PurchaseReturnController extends Controller
{
    /**
     * Listing is server-side filtered (date range + supplier, the latter
     * via the parent purchase since a return has no supplier_id of its own)
     * and paginated - same `when()`/`paginate()->withQueryString()` shape
     * Central\Tenants\TenantController::index() established.
     */
    public function index(Request $request): Response
    {
        $from = $request->filled('from') ? $request->string('from')->toString() : null;
        $to = $request->filled('to') ? $request->string('to')->toString() : null;
        $supplierId = $request->filled('supplier_id') ? (int) $request->input('supplier_id') : null;

        // Branches (plan 12, BR12): the list, its totals row and the export
        // follow the top-bar switcher through filteredReturnsQuery(); each
        // row names its branch only when several branches can appear.
        $returns = $this->filteredReturnsQuery($from, $to, $supplierId, $request)
            ->with([
                'purchase.supplier:id,name', 'supplier:id,name', 'lines.purchaseLine.item:id,name,unit',
                'lines.item:id,name,unit', 'refundAccount:id,code,name',
            ])
            ->when(BranchListFilter::columnVisible($request), fn ($query) => $query->with('branch:id,name,code'))
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        return Inertia::render('Tenant/Purchases/Returns/Index', [
            'returns' => $returns,
            'filters' => [
                'from' => $from,
                'to' => $to,
                'supplier_id' => $supplierId,
            ],
            // Exact SQL sums over the whole filtered set (item 8, "totals
            // row") - never a page's worth of client-side addition.
            'totals' => $this->filteredTotals($from, $to, $supplierId, $request),
            // The return form picks its parent bill from a searched, paginated
            // page of purchases. It used to receive every posted purchase in
            // the tenant with all their lines, which stops being loadable long
            // before a real shop stops buying things.
            'purchaseSearch' => $request->filled('purchase_search') ? $request->string('purchase_search')->toString() : null,
            'searchablePurchases' => $this->searchablePurchases($request),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            // Money comes back from a supplier through an asset account, never
            // an expense or a liability. Neither picker offers the inter-branch
            // control accounts AccountUnderHead refuses (BranchAccounts).
            'refundAccounts' => BranchAccounts::exclude(Account::query())
                ->where(fn ($query) => $query
                    ->whereHas('group.accountHead', fn ($q) => $q->where('name', 'Assets'))
                    ->orWhereHas('subgroup.accountGroup.accountHead', fn ($q) => $q->where('name', 'Assets')))
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'bankAccounts' => BranchAccounts::exclude(Account::query())
                ->where(fn ($query) => $query
                    ->whereHas('group.accountHead', fn ($q) => $q->where('name', 'Assets'))
                    ->orWhereHas('subgroup.accountGroup.accountHead', fn ($q) => $q->where('name', 'Assets')))
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'stores' => $this->storeOptions($request),
            // Item 4 - the unlinked return form (opening stock / before
            // go-live) picks an item directly rather than a purchase line.
            'items' => Item::query()->where('is_active', true)->where('is_stockable', true)->orderBy('name')
                ->with(['units' => fn ($q) => $q->where('is_active', true)->orderBy('name')])
                ->get(['id', 'name', 'unit', 'purchase_rate', 'is_vatable', 'barcode']),
            'defaultVatRate' => (string) (CompanySetting::current()->default_vat_rate ?? '13.00'),
            // Branch picker of the UNLINKED return form (plan 12, BR12). A
            // linked return has no picker: it always lands in its purchase's
            // branch (DocumentBranch::inherited()).
            ...BranchFormOptions::props($request),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'purchase_id' => ['required', 'exists:purchases,id'],
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'refund_account_id' => ['nullable', 'exists:accounts,id', new AccountUnderHead('Assets')],
            // Retired stores and items cannot take new documents (flags G-13).
            'store_id' => ['nullable', 'integer', Rule::exists('stores', 'id')->where('is_active', true)],
            // Optional: a linked return always lands in its purchase's
            // branch, and naming any other branch is refused (plan 12,
            // section 5.4).
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'lines' => ['required', 'array', 'min:1'],
            // `distinct` plus the model's own per-line aggregation: naming the
            // same purchase line twice in one payload used to slip past the
            // remaining-quantity cap because each row was checked alone
            // (audit P0-14).
            'lines.*.purchase_line_id' => ['required', 'distinct', 'exists:purchase_lines,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001', 'decimal:0,4'],
        ]);

        try {
            $purchaseReturn = PurchaseReturn::post($data, $data['lines'], $request->user());
        } catch (InvalidArgumentException|AuthorizationException $e) {
            // Branch refusals (the purchase's branch is inactive or not the
            // user's, or another branch was named) go to `branch_id`, shown
            // beside the purchase picker; a store outside the purchase's
            // branch to the store field; the rest stays under `lines`.
            return back()->withErrors([BranchFormOptions::errorField($e, 'lines', storeField: 'store_id') => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.purchase-returns.index')
            ->with('status', 'Purchase return posted.')
            ->with('created', [
                'type' => 'purchase_return',
                'id' => $purchaseReturn->id,
                'print_url' => route('tenant.purchase-returns.print', $purchaseReturn),
            ]);
    }

    /**
     * An "unlinked" purchase return (item 4): goods from opening stock, or
     * from a purchase made before this system went live, with no Purchase
     * row to point at. See PurchaseReturn::postUnlinked().
     */
    public function storeUnlinked(StoreUnlinkedPurchaseReturnRequest $request): RedirectResponse
    {
        $data = $request->validated();

        try {
            $purchaseReturn = PurchaseReturn::postUnlinked($data, $data['lines'], $request->user());
        } catch (InvalidArgumentException|AuthorizationException $e) {
            // Same routing as the linked form: branch picker, store, else `lines`.
            return back()->withErrors([BranchFormOptions::errorField($e, 'lines', storeField: 'store_id') => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.purchase-returns.index')
            ->with('status', 'Unlinked purchase return posted.')
            ->with('created', [
                'type' => 'purchase_return',
                'id' => $purchaseReturn->id,
                'print_url' => route('tenant.purchase-returns.print', $purchaseReturn),
            ]);
    }

    /**
     * The exact total an unlinked return would credit, for the form to show
     * and send back as `expected_total` (flags G-16). Read-only, so a GET.
     * Lines left without a rate are valued at average cost on the server
     * (PurchaseReturn::quoteUnlinked()), which the browser cannot do.
     */
    public function quoteUnlinked(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date'],
            'vat_rate' => ['nullable', 'numeric', 'min:0', 'max:100', 'decimal:0,2'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.item_id' => ['required', 'integer', Rule::exists('items', 'id')->where('is_active', true)],
            'lines.*.item_unit_id' => ['nullable', 'integer', 'exists:item_units,id'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0.0001', 'decimal:0,4'],
            'lines.*.rate' => ['nullable', 'numeric', 'min:0', 'decimal:0,4'],
        ]);

        try {
            return response()->json(PurchaseReturn::quoteUnlinked($data, $data['lines']));
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Excel export of the purchase return list (item 8), honouring the same
     * from/to/supplier filters the Index page's own list uses, and the same
     * top-bar branch switcher (plan 12, BR12).
     */
    public function export(Request $request)
    {
        $from = $request->filled('from') ? $request->string('from')->toString() : null;
        $to = $request->filled('to') ? $request->string('to')->toString() : null;
        $supplierId = $request->filled('supplier_id') ? (int) $request->input('supplier_id') : null;

        $returns = $this->filteredReturnsQuery($from, $to, $supplierId, $request)
            ->with(['purchase.supplier:id,name', 'supplier:id,name'])
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $rows = $returns->values()->map(fn (PurchaseReturn $return, int $index): array => [
            'sn' => $index + 1,
            'date' => $return->date->toDateString(),
            'debit_note_number' => $return->debit_note_number,
            'supplier' => $return->documentSupplier()?->name,
            'total' => $return->total,
            'status' => $return->status === 'cancelled' ? 'Cancelled' : 'Posted',
        ]);

        $total = Money::sum($returns->map(fn (PurchaseReturn $return): Money => Money::of($return->total)))->toString();

        return Excel::download(new PurchaseReturnListExport($rows, $total), 'purchase-returns.xlsx');
    }

    public function cancel(Request $request, PurchaseReturn $purchaseReturn): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $purchaseReturn->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException|AuthorizationException $e) {
            return back()->withErrors(['reason' => $e->getMessage()]);
        }

        return redirect()->route('tenant.purchase-returns.index')->with('status', 'Purchase return cancelled.');
    }

    /**
     * Streams a printable PDF debit note inline (not a forced download), so
     * it opens in a new browser tab from a plain anchor link on the Index
     * page - mirrors SaleController::print()'s exact pattern.
     */
    public function print(Request $request, PurchaseReturn $purchaseReturn): HttpResponse
    {
        $purchaseReturn->load([
            'purchase.supplier', 'supplier', 'lines.purchaseLine.item', 'lines.purchaseLine.itemUnit',
            'lines.item', 'lines.itemUnit', 'journalVoucher.fiscalYear', 'refundAccount', 'bankAccount',
        ]);

        return Pdf::loadView('pdf.purchase-return', [
            'purchaseReturn' => $purchaseReturn,
            'company' => CompanySetting::current(),
            'documentNumber' => $purchaseReturn->documentNumber(),
            'documentDate' => $purchaseReturn->date->format('Y-m-d'),
            'copyNumber' => PrintLog::record($purchaseReturn, $request->user()),
            'dateAd' => $purchaseReturn->date->format('Y-m-d'),
            'dateBs' => NepaliCalendar::formatBs($purchaseReturn->date),
            'fiscalYearName' => $purchaseReturn->journalVoucher?->fiscalYear?->name,
            'amountInWords' => AmountInWords::rupees(Money::of($purchaseReturn->total)),
            ...$this->branchHeader($request, $purchaseReturn->branch_id),
        ])->stream("purchase-return-{$purchaseReturn->id}.pdf");
    }

    /**
     * The branch line of a printed debit note's header (plan 12, BR12), only
     * for a multi-branch company; everyone else gets nulls and the header
     * prints exactly as before.
     *
     * @return array{branchName: ?string, branchLabel: ?string}
     */
    private function branchHeader(Request $request, ?int $branchId): array
    {
        if ($branchId === null || ! ActiveBranchSelection::isMultiBranch($request)) {
            return ['branchName' => null, 'branchLabel' => null];
        }

        return [
            'branchName' => Branch::query()->whereKey($branchId)->value('name'),
            'branchLabel' => ActiveBranchSelection::label(),
        ];
    }

    /**
     * Active stores for the return forms. A multi-branch company also gets
     * each store's branch_id, so a linked return offers only its purchase's
     * branch's stores and the unlinked form only the chosen branch's (plan
     * 12, section 5.5); a branch-restricted user only sees stores of branches
     * they may use. Anyone else gets the same list as before.
     *
     * @return Collection<int, Store>
     */
    private function storeOptions(Request $request): Collection
    {
        // BranchStoreOptions (BR22 C5) also skips stores of a deactivated
        // branch, which nothing can post into; untouched when not
        // multi-branch, and the same columns either way.
        return BranchStoreOptions::apply(Store::where('is_active', true), $request)
            ->orderBy('name')
            ->get(BranchStoreOptions::columns($request, ['id', 'name']));
    }

    /**
     * The list's filters plus the top-bar branch switcher (a no-op unless a
     * multi-branch company is narrowed to one branch), so the page, its
     * totals row and the export always agree.
     *
     * @return Builder<PurchaseReturn>
     */
    private function filteredReturnsQuery(?string $from, ?string $to, ?int $supplierId, Request $request)
    {
        return BranchListFilter::apply(PurchaseReturn::query(), $request)
            ->when($from, fn ($query, string $from) => $query->whereDate('date', '>=', $from))
            ->when($to, fn ($query, string $to) => $query->whereDate('date', '<=', $to))
            ->when($supplierId, fn ($query, int $supplierId) => $query->where(fn ($inner) => $inner
                ->whereHas('purchase', fn ($q) => $q->where('supplier_id', $supplierId))
                ->orWhere('supplier_id', $supplierId)));
    }

    /**
     * @return array<string, string>
     */
    private function filteredTotals(?string $from, ?string $to, ?int $supplierId, Request $request): array
    {
        $row = $this->filteredReturnsQuery($from, $to, $supplierId, $request)->toBase()->selectRaw(
            'COALESCE(SUM(taxable_amount), 0) as taxable_amount, '
            .'COALESCE(SUM(nontaxable_amount), 0) as nontaxable_amount, '
            .'COALESCE(SUM(vat_amount), 0) as vat_amount, '
            .'COALESCE(SUM(round_off), 0) as round_off, '
            .'COALESCE(SUM(total), 0) as total'
        )->first();

        return [
            'taxable_amount' => Money::round($row->taxable_amount)->toString(),
            'nontaxable_amount' => Money::round($row->nontaxable_amount)->toString(),
            'vat_amount' => Money::round($row->vat_amount)->toString(),
            'round_off' => Money::round($row->round_off)->toString(),
            'total' => Money::round($row->total)->toString(),
        ];
    }

    /**
     * One searched page of returnable purchases, each line carrying the unit it
     * was bought in and how much of it is still returnable, so the form can
     * show both without a second round trip and without any client-side
     * arithmetic on quantities.
     *
     * @return LengthAwarePaginator<int, array<string, mixed>>
     */
    private function searchablePurchases(Request $request): LengthAwarePaginator
    {
        $search = $request->filled('purchase_search') ? $request->string('purchase_search')->toString() : null;

        $purchases = Purchase::query()
            ->where('status', 'posted')
            ->when($search, fn ($query, string $search) => $query->where(fn ($inner) => $inner
                ->where('bill_number', 'like', "%{$search}%")
                ->orWhere('id', $search)
                ->orWhereHas('supplier', fn ($supplier) => $supplier->where('name', 'like', "%{$search}%"))))
            ->with(['supplier:id,name', 'store:id,name', 'lines.item:id,name,unit', 'lines.itemUnit:id,name'])
            ->orderByDesc('date')
            ->orderByDesc('id')
            ->paginate(15, ['*'], 'purchase_page')
            ->withQueryString();

        $returned = $this->returnedQuantities($purchases->getCollection()->pluck('lines')->flatten());

        // A multi-branch company also gets each bill's branch_id: a linked
        // return is filed in that branch, so the form shows it (read-only)
        // and offers only that branch's stores (plan 12, BR12).
        $withBranch = ActiveBranchSelection::isMultiBranch($request);

        return $purchases->through(fn (Purchase $purchase): array => [
            'id' => $purchase->id,
            'date' => $purchase->date->toDateString(),
            'bill_number' => $purchase->bill_number,
            'total' => $purchase->total,
            'store_id' => $purchase->store_id,
            ...($withBranch ? ['branch_id' => (int) $purchase->branch_id] : []),
            'supplier' => ['id' => $purchase->supplier?->id, 'name' => $purchase->supplier?->name],
            'lines' => $purchase->lines->map(fn (PurchaseLine $line): array => [
                'id' => $line->id,
                'item_name' => $line->item?->name,
                'unit_name' => $line->itemUnit?->name ?? $line->item?->unit,
                'unit_conversion_factor' => $line->unit_conversion_factor,
                'quantity' => $line->quantity,
                // Free goods (item 3). Shown beside the billed quantity and,
                // more importantly, INCLUDED in what is still returnable:
                // bonus units are physically on the shelf, so a supplier can
                // ask for them back. PurchaseReturn::prepareLine() caps the
                // return at the same paid + bonus figure and decides which
                // part of a return is credited and which is free.
                'bonus_quantity' => $line->bonus_quantity ?? '0.0000',
                'rate' => $line->rate,
                'returned_quantity' => ($returned[$line->id] ?? Quantity::zero())->toString(),
                'remaining_quantity' => Quantity::of($line->quantity)
                    ->plus(Quantity::of($line->bonus_quantity ?? '0'))
                    ->minus($returned[$line->id] ?? Quantity::zero())
                    ->toString(),
            ])->values(),
        ]);
    }

    /**
     * Quantity already returned per purchase line, in one query for the whole
     * page. Cancelled returns do not count; everything else does, because a
     * return awaiting approval has reserved that quantity.
     *
     * @param  Collection<int, PurchaseLine>  $lines
     * @return array<int, Quantity>
     */
    private function returnedQuantities(Collection $lines): array
    {
        $lineIds = $lines->pluck('id')->all();

        if ($lineIds === []) {
            return [];
        }

        $returned = [];

        PurchaseReturnLine::query()
            ->whereIn('purchase_line_id', $lineIds)
            ->whereHas('purchaseReturn', fn ($query) => $query->where('status', '!=', 'cancelled'))
            ->get(['purchase_line_id', 'quantity'])
            ->each(function (PurchaseReturnLine $line) use (&$returned): void {
                $returned[$line->purchase_line_id] = ($returned[$line->purchase_line_id] ?? Quantity::zero())
                    ->plus(Quantity::of($line->quantity));
            });

        return $returned;
    }
}
