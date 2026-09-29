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
        $data = $this->ledgerData($request, $item);

        return Inertia::render('Tenant/Inventory/Items/Ledger', array_merge([
            'item' => $item->only(['id', 'name', 'unit', 'barcode']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ], $data));
    }

    public function print(Request $request, Item $item): HttpResponse
    {
        $data = $this->ledgerData($request, $item);
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
        ]);

        return $pdf->stream("item-ledger-{$item->id}.pdf");
    }

    public function export(Request $request, Item $item): BinaryFileResponse
    {
        $data = $this->ledgerData($request, $item);

        return Excel::download(
            new ItemLedgerExport($data['entries'], $data['openingBalance'], $data['closingBalance']),
            "item-ledger-{$item->id}.xlsx",
        );
    }

    /**
     * The opening balance is the item's net stock at the end of the day
     * before `from` (the same "as of the day before" rule the Stock Summary
     * uses for its own opening column), so a window that starts mid-history
     * still opens at the right quantity instead of zero. Cancelled movements
     * are excluded from both the opening sum and the rows.
     *
     * @return array{entries: array<int, array{date: string, type: string, storeName: ?string, quantity: string, unitCostRate: ?string, balance: string, reference: string}>, openingBalance: string, closingBalance: string, from: string, to: string, storeId: ?int}
     */
    private function ledgerData(Request $request, Item $item): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
        ]);

        [$from, $to] = $this->resolveDateRange($validated['from'] ?? null, $validated['to'] ?? null);
        $storeId = isset($validated['store_id']) ? (int) $validated['store_id'] : null;

        $dayBefore = Carbon::parse($from)->subDay()->toDateString();
        $openingBalance = Item::currentStockByItem([$item->id], $storeId, $dayBefore)[$item->id] ?? Quantity::zero();

        $movements = ItemStockMovement::query()
            ->where('item_id', $item->id)
            ->where('cancelled', false)
            ->whereDate('date', '>=', $from)
            ->whereDate('date', '<=', $to)
            ->when($storeId !== null, fn ($query) => $query->where('store_id', $storeId))
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
