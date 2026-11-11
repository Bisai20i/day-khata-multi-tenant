<?php

namespace App\Http\Controllers\Tenant\Inventory;

use App\Enums\FiscalYearStatus;
use App\Exports\ItemLedgerExport;
use App\Http\Controllers\Concerns\DescribesStockMovements;
use App\Http\Controllers\Controller;
use App\Models\CompanySetting;
use App\Models\FiscalYear;
use App\Models\Item;
use App\Models\ItemStockMovement;
use App\Models\Store;
use App\Support\Branches\BranchFilter;
use App\Support\Branches\BranchStock;
use App\Support\Branches\BranchStoreOptions;
use App\Support\Money\Quantity;
use App\Support\NepaliCalendar;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * One item's full stock history with an opening balance, a running balance
 * per movement and a closing balance: the stock-side twin of
 * AccountController::ledger(). The Stock Movement Register lists the same
 * movements, but it is built for browsing many items; this page answers
 * "how did this one item get to its current quantity".
 *
 * Kept out of ItemController, which is already the item master's CRUD,
 * import and unit screen. Stock is perpetual across fiscal years (no
 * closing entry resets it), so the window is a plain date range; it only
 * defaults to the open fiscal year so the first render stays small.
 */
class ItemLedgerController extends Controller
{
    use DescribesStockMovements;

    public function show(Request $request, Item $item): Response
    {
        $filter = BranchFilter::fromRequest($request);
        $data = $this->ledgerData($request, $item, $filter);

        return Inertia::render('Tenant/Inventory/Items/Ledger', array_merge([
            'item' => $item->only(['id', 'name', 'unit', 'barcode']),
            // Branches (plan 12, BR12): a multi-branch company is offered only
            // the stores of the branches the user may use, each named
            // "Store (Branch)"; BR15 narrows that further to the stores of
            // the branches the ledger covers. Not multi-branch: the same list
            // as before.
            'stores' => BranchStock::storeOptions($filter, BranchStoreOptions::apply(Store::where('is_active', true), $request, withBranch: true))
                ->orderBy('name')
                ->get(BranchStoreOptions::columns($request, ['id', 'name'])),
            'branchReport' => $filter->payload(),
        ], $data));
    }

    public function print(Request $request, Item $item): HttpResponse
    {
        $filter = BranchFilter::fromRequest($request);
        $data = $this->ledgerData($request, $item, $filter);
        $storeName = $data['storeId'] ? Store::whereKey($data['storeId'])->value('name') : null;

        $pdf = Pdf::loadView('pdf.item-ledger', [
            'title' => "Item Ledger - {$item->name}",
            'company' => CompanySetting::current(),
            'documentNumber' => $item->name,
            'documentDate' => $data['to'],
            'dateAd' => $data['to'],
            'dateBs' => NepaliCalendar::formatBs($data['to']),
            'item' => $item,
            'storeName' => $storeName,
            'from' => $data['from'],
            'to' => $data['to'],
            'openingBalance' => $data['openingBalance'],
            'closingBalance' => $data['closingBalance'],
            'entries' => $data['entries'],
            // Printed from BR16 on; null when not multi-branch.
            'branchFilterLabel' => $this->branchFilterLabel($filter),
        ]);

        return $pdf->stream("item-ledger-{$item->id}.pdf");
    }

    public function export(Request $request, Item $item): BinaryFileResponse
    {
        $filter = BranchFilter::fromRequest($request);
        $data = $this->ledgerData($request, $item, $filter);

        return Excel::download(
            new ItemLedgerExport($data['entries'], $data['openingBalance'], $data['closingBalance'], $this->branchFilterLabel($filter)),
            "item-ledger-{$item->id}.xlsx",
        );
    }

    /**
     * The branch label for the PDF and Excel header, or null for a tenant
     * that is not multi-branch.
     */
    private function branchFilterLabel(BranchFilter $filter): ?string
    {
        return $filter->isActive() ? $filter->label() : null;
    }

    /**
     * The opening balance is the item's net stock at the end of the day
     * before `from` (the same "as of the day before" rule the Stock Summary
     * uses for its own opening column), so a window that starts mid-history
     * still opens at the right quantity instead of zero. Cancelled movements
     * are excluded from both the opening sum and the rows.
     *
     * Branches (plan 12, BR15): `branch` / `branches[]` narrow the opening
     * sum and the rows to the SAME set, the covered branches' stores
     * (BranchFilter::stockScope()), so the running balance stays true. A
     * transfer between two of those stores shows both legs, which net to
     * zero. A `store_id` outside the covered branches is dropped.
     *
     * @return array{entries: array<int, array{date: string, type: string, storeName: ?string, quantity: string, unitCostRate: ?string, balance: string, reference: string}>, openingBalance: string, closingBalance: string, from: string, to: string, storeId: ?int}
     */
    private function ledgerData(Request $request, Item $item, BranchFilter $filter): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
        ]);

        [$from, $to] = $this->resolveDateRange($validated['from'] ?? null, $validated['to'] ?? null);
        $storeId = $filter->allowedStoreId(isset($validated['store_id']) ? (int) $validated['store_id'] : null);
        $stockScope = $filter->stockScope($storeId);

        $dayBefore = Carbon::parse($from)->subDay()->toDateString();
        $openingBalance = Item::currentStockByItem([$item->id], $stockScope, $dayBefore)[$item->id] ?? Quantity::zero();

        $movements = ItemStockMovement::query()
            ->where('item_id', $item->id)
            ->where('cancelled', false)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when(is_int($stockScope), fn ($query) => $query->where('store_id', $stockScope))
            ->when(is_array($stockScope), fn ($query) => $query->whereIn('store_id', $stockScope))
            ->with('store:id,name')
            ->with($this->stockMovementReferenceEagerLoad())
            ->orderBy('date')
            ->orderBy('id')
            ->get();

        $runningBalance = $openingBalance;

        $entries = $movements->map(function (ItemStockMovement $movement) use (&$runningBalance) {
            $quantity = $this->signedStockQuantity($movement);
            $runningBalance = $runningBalance->plus($quantity);

            return [
                'date' => $movement->date->toDateString(),
                'type' => $this->movementTypeLabel($movement->movement_type),
                'storeName' => $movement->store?->name,
                'quantity' => $quantity->toString(),
                'unitCostRate' => $movement->unit_cost_rate === null ? null : Quantity::of($movement->unit_cost_rate)->toString(),
                'balance' => $runningBalance->toString(),
                'reference' => $this->referenceDescription($movement->reference, $movement->narration),
            ];
        })->values()->all();

        return [
            'entries' => $entries,
            'openingBalance' => $openingBalance->toString(),
            'closingBalance' => $runningBalance->toString(),
            'from' => $from,
            'to' => $to,
            'storeId' => $storeId,
        ];
    }

    /**
     * Either bound falls back to the open fiscal year's (or, with none open,
     * to month-to-date), so "from only" still gets a sensible end date.
     *
     * @return array{0: string, 1: string}
     */
    private function resolveDateRange(?string $from, ?string $to): array
    {
        $fiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        $defaultFrom = $fiscalYear?->start_date->toDateString() ?? now()->startOfMonth()->toDateString();
        $defaultTo = $fiscalYear?->end_date->toDateString() ?? now()->toDateString();

        $from = $from !== null ? Carbon::parse($from)->toDateString() : $defaultFrom;
        $to = $to !== null ? Carbon::parse($to)->toDateString() : $defaultTo;

        if ($from > $to) {
            [$from, $to] = [$defaultFrom, $defaultTo];
        }

        return [$from, $to];
    }
}
