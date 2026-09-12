<?php

namespace App\Http\Controllers\Tenant\Purchases;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\PrintLog;
use App\Models\Purchase;
use App\Models\PurchaseLine;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnLine;
use App\Models\Store;
use App\Models\Supplier;
use App\Support\AmountInWords;
use App\Support\Money\Money;
use App\Support\Money\Quantity;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;
use InvalidArgumentException;

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

        $returns = PurchaseReturn::query()
            ->with(['purchase.supplier:id,name', 'lines.purchaseLine.item:id,name,unit', 'refundAccount:id,code,name'])
            ->when($from, fn ($query, string $from) => $query->whereDate('date', '>=', $from))
            ->when($to, fn ($query, string $to) => $query->whereDate('date', '<=', $to))
            ->when($supplierId, fn ($query, int $supplierId) => $query->whereHas(
                'purchase', fn ($query) => $query->where('supplier_id', $supplierId)
            ))
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
            // The return form picks its parent bill from a searched, paginated
            // page of purchases. It used to receive every posted purchase in
            // the tenant with all their lines, which stops being loadable long
            // before a real shop stops buying things.
            'purchaseSearch' => $request->filled('purchase_search') ? $request->string('purchase_search')->toString() : null,
            'searchablePurchases' => $this->searchablePurchases($request),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name']),
            // Money comes back from a supplier through an asset account, never
            // an expense or a liability.
            'refundAccounts' => Account::query()
                ->where(fn ($query) => $query
                    ->whereHas('group.accountHead', fn ($q) => $q->where('name', 'Assets'))
                    ->orWhereHas('subgroup.accountGroup.accountHead', fn ($q) => $q->where('name', 'Assets')))
                ->orderBy('name')
                ->get(['id', 'code', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'purchase_id' => ['required', 'exists:purchases,id'],
            'date' => ['required', 'date'],
            'reason' => ['nullable', 'string', 'max:255'],
            'refund_account_id' => ['nullable', 'exists:accounts,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
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
        } catch (InvalidArgumentException $e) {
            return back()->withErrors(['lines' => $e->getMessage()])->withInput();
        }

        return redirect()->route('tenant.purchase-returns.index')
            ->with('status', 'Purchase return posted.')
            ->with('created', [
                'type' => 'purchase_return',
                'id' => $purchaseReturn->id,
                'print_url' => route('tenant.purchase-returns.print', $purchaseReturn),
            ]);
    }

    public function cancel(Request $request, PurchaseReturn $purchaseReturn): RedirectResponse
    {
        $data = $request->validate([
            'reason' => ['required', 'string', 'max:500'],
        ]);

        try {
            $purchaseReturn->cancel($request->user(), $data['reason']);
        } catch (InvalidArgumentException $e) {
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
            'purchase.supplier', 'lines.purchaseLine.item', 'lines.purchaseLine.itemUnit',
            'journalVoucher.fiscalYear', 'refundAccount',
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
        ])->stream("purchase-return-{$purchaseReturn->id}.pdf");
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

        return $purchases->through(fn (Purchase $purchase): array => [
            'id' => $purchase->id,
            'date' => $purchase->date->toDateString(),
            'bill_number' => $purchase->bill_number,
            'total' => $purchase->total,
            'store_id' => $purchase->store_id,
            'supplier' => ['id' => $purchase->supplier?->id, 'name' => $purchase->supplier?->name],
            'lines' => $purchase->lines->map(fn (PurchaseLine $line): array => [
                'id' => $line->id,
                'item_name' => $line->item?->name,
                'unit_name' => $line->itemUnit?->name ?? $line->item?->unit,
                'unit_conversion_factor' => $line->unit_conversion_factor,
                'quantity' => $line->quantity,
                'rate' => $line->rate,
                'returned_quantity' => ($returned[$line->id] ?? Quantity::zero())->toString(),
                'remaining_quantity' => Quantity::of($line->quantity)
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
