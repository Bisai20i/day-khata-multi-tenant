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
use App\Support\Inventory\PerpetualCosting;
use App\Support\Inventory\StockCosting;
use App\Support\Money\Money;
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

    public function index(Request $request): Response
    {
        $request->validate([
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
        ]);

        $items = Item::where('is_active', true)->where('is_stockable', true)->orderBy('name')->get(['id', 'name', 'unit', 'barcode']);
        $itemId = $request->integer('item_id') ?: ($items->first()?->id ?? null);
        $item = $itemId ? Item::find($itemId) : null;

        // An item reached by id (e.g. from the item master) may since have
        // been deactivated or made non-stockable; its history is still real,
        // so the picker must still be able to show it as the selection.
        if ($item && ! $items->contains('id', $item->id)) {
            $items = $items->push(Item::whereKey($item->id)->first(['id', 'name', 'unit', 'barcode']))
                ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)
                ->values();
        }

        if (! $item) {
            return Inertia::render('Tenant/Inventory/Items/Ledger', [
                'items' => $items,
                'item' => null,
                'stores' => [],
                'storeId' => null,
                'from' => null,
                'to' => null,
                'periodNote' => null,
                'openingBalance' => '0.0000',
                'closingBalance' => '0.0000',
                'openingValue' => '0.00',
                'closingValue' => '0.00',
                'totals' => ['in' => '0.0000', 'out' => '0.0000', 'net' => '0.0000'],
                'entries' => [],
                'branchReport' => null,
            ]);
        }

        $filter = BranchFilter::fromRequest($request);
        $data = $this->ledgerData($request, $item, $filter);

        return Inertia::render('Tenant/Inventory/Items/Ledger', array_merge([
            'items' => $items,
            'item' => $item->only(['id', 'name', 'unit', 'barcode']),
            'stores' => BranchStock::storeOptions($filter, BranchStoreOptions::apply(Store::where('is_active', true), $request, withBranch: true))
                ->orderBy('name')
                ->get(BranchStoreOptions::columns($request, ['id', 'name'])),
            'branchReport' => $filter->payload(),
        ], $data));
    }

    public function show(Request $request, Item $item): Response
    {
        if (! $request->has('item_id')) {
            $request->merge(['item_id' => $item->id]);
        }

        return $this->index($request);
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
            'openingValue' => $data['openingValue'],
            'closingValue' => $data['closingValue'],
            'totals' => $data['totals'],
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
        $storeName = $data['storeId'] ? Store::whereKey($data['storeId'])->value('name') : null;

        return Excel::download(
            new ItemLedgerExport(
                $data['entries'],
                $data['openingBalance'],
                $data['closingBalance'],
                $this->branchFilterLabel($filter),
                totals: $data['totals'],
                openingValue: $data['openingValue'],
                closingValue: $data['closingValue'],
                itemName: $item->unit ? "{$item->name} ({$item->unit})" : $item->name,
                from: $data['from'],
                to: $data['to'],
                storeName: $storeName ?? 'All stores',
            ),
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
     * Each row carries the signed `quantity` plus `qtyIn` / `qtyOut` (one of
     * them always zero, both non-negative), and `totals` sums them for the
     * window, all exact Quantity strings in the item's base unit. Movement
     * values are deliberately not summed: ItemStockMovement::value is null for
     * sales, transfers and write-offs, and cost_value is null for trading
     * items, so a cumulative column would silently under-report. Instead each
     * row's `value` is the running balance valued at the cost as of that
     * row's date (stockValuer()), so the closing value ties to the Stock
     * Valuation report. Money strings, or null when no value can be given.
     *
     * @return array{entries: array<int, array{date: string, type: string, storeName: ?string, quantity: string, qtyIn: string, qtyOut: string, unitCostRate: ?string, balance: string, value: ?string, reference: string, referenceUrl: ?string}>, openingBalance: string, closingBalance: string, openingValue: ?string, closingValue: ?string, totals: array{in: string, out: string, net: string}, from: string, to: string, periodNote: ?string, storeId: ?int}
     */
    private function ledgerData(Request $request, Item $item, BranchFilter $filter): array
    {
        $validated = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
        ]);

        [$from, $to, $periodNote] = $this->resolveDateRange($validated['from'] ?? null, $validated['to'] ?? null);
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

        $valueAt = $this->stockValuer($item, $stockScope, [
            $dayBefore,
            $to,
            ...$movements->map(fn (ItemStockMovement $movement) => $movement->date->toDateString())->all(),
        ]);

        $runningBalance = $openingBalance;
        $totalIn = Quantity::zero();
        $totalOut = Quantity::zero();

        $entries = $movements->map(function (ItemStockMovement $movement) use (&$runningBalance, &$totalIn, &$totalOut, $valueAt) {
            $quantity = $this->signedStockQuantity($movement);
            $runningBalance = $runningBalance->plus($quantity);

            $qtyIn = $quantity->isNegative() ? Quantity::zero() : $quantity;
            $qtyOut = $quantity->isNegative() ? $quantity->negated() : Quantity::zero();
            $totalIn = $totalIn->plus($qtyIn);
            $totalOut = $totalOut->plus($qtyOut);

            return [
                'date' => $movement->date->toDateString(),
                'type' => $this->movementTypeLabel($movement->movement_type),
                'storeName' => $movement->store?->name,
                'quantity' => $quantity->toString(),
                'qtyIn' => $qtyIn->toString(),
                'qtyOut' => $qtyOut->toString(),
                'unitCostRate' => $movement->unit_cost_rate === null ? null : Quantity::of($movement->unit_cost_rate)->toString(),
                'balance' => $runningBalance->toString(),
                'value' => $valueAt($runningBalance, $movement->date->toDateString()),
                'reference' => $this->referenceDescription($movement->reference, $movement->narration),
                'referenceUrl' => $this->referenceUrl($movement->reference),
            ];
        })->values()->all();

        // A perpetual item's value is an end-of-day figure (PerpetualCosting),
        // so on a day with several movements only the day's last row carries
        // it; the earlier rows would pair it with a different running balance.
        if ($item->isPerpetual()) {
            for ($index = count($entries) - 2; $index >= 0; $index--) {
                if ($entries[$index]['date'] === $entries[$index + 1]['date']) {
                    $entries[$index]['value'] = null;
                }
            }
        }

        return [
            'entries' => $entries,
            'openingBalance' => $openingBalance->toString(),
            'closingBalance' => $runningBalance->toString(),
            'openingValue' => $valueAt($openingBalance, $dayBefore),
            'closingValue' => $valueAt($runningBalance, $to),
            'totals' => [
                'in' => $totalIn->toString(),
                'out' => $totalOut->toString(),
                'net' => $totalIn->minus($totalOut)->toString(),
            ],
            'from' => $from,
            'to' => $to,
            'periodNote' => $periodNote,
            'storeId' => $storeId,
        ];
    }

    /**
     * Values a quantity of this item as of a date, the way the Stock Valuation
     * report values closing stock: the quantity is the (store-scoped) running
     * balance, the cost is never store-scoped (StockCosting's class docblock),
     * so a store's values add up to the all-stores value.
     *
     * A periodic item is valued at its weighted-average cost as of that date
     * (the purchase rate when nothing priced has come in yet). A perpetual
     * (manufacturing) item is valued at its PerpetualCosting value balance, the
     * figure its class account holds; that figure is per store, so with a
     * multi-branch store set it is not shown (null). A periodic item's cost for
     * every date in `$dates` comes from one grouped query
     * (StockCosting::costsPerBaseUnitByDate(), identical to costPerBaseUnit()
     * per date); a date outside the list still works via the single-date path.
     * A perpetual value is end-of-day, so ledgerData() keeps it only on each
     * day's last row.
     *
     * Returns a Money string, or null when no value can be given.
     *
     * @param  int|list<int>|null  $stockScope  BranchFilter::stockScope()
     * @param  list<string>  $dates  Y-m-d dates the valuer will be asked about
     * @return \Closure(Quantity, string): ?string
     */
    private function stockValuer(Item $item, int|array|null $stockScope, array $dates = []): \Closure
    {
        // date => cost per base unit (BigDecimal) for a periodic item, or the
        // value balance (Money) for a perpetual one; an item is only ever one.
        $cache = $item->isPerpetual() ? [] : StockCosting::costsPerBaseUnitByDate($item, $dates);

        return function (Quantity $balance, string $date) use ($item, $stockScope, &$cache): ?string {
            if ($item->isPerpetual()) {
                if (is_array($stockScope)) {
                    return null;
                }

                return ($cache[$date] ??= PerpetualCosting::valueBalance($item, $date, $stockScope))->toString();
            }

            $cost = $cache[$date] ??= StockCosting::costPerBaseUnit($item, $date);

            return $balance->isZero() || $cost->isZero()
                ? Money::zero()->toString()
                : Money::round($balance->toBigDecimal()->multipliedBy($cost))->toString();
        };
    }

    /**
     * Either bound falls back to the open fiscal year's (or, with none open,
     * to month-to-date), so "from only" still gets a sensible end date. The
     * third element explains any bound the server filled in or replaced, so
     * the page can say which range it actually applied (null when the
     * request named both bounds and they were used as given).
     *
     * @return array{0: string, 1: string, 2: ?string}
     */
    private function resolveDateRange(?string $from, ?string $to): array
    {
        $fiscalYear = FiscalYear::query()->where('status', FiscalYearStatus::Open)->first();

        $defaultFrom = $fiscalYear?->start_date->toDateString() ?? now()->startOfMonth()->toDateString();
        $defaultTo = $fiscalYear?->end_date->toDateString() ?? now()->toDateString();
        $defaultWindow = $fiscalYear ? 'the open fiscal year' : 'month to date';

        $appliedFrom = $from !== null ? Carbon::parse($from)->toDateString() : $defaultFrom;
        $appliedTo = $to !== null ? Carbon::parse($to)->toDateString() : $defaultTo;

        // A lone bound outside the default window collapses onto itself (a
        // single-day range), as the Stock Movement Register does; it is never
        // silently replaced by the whole window. Y-m-d strings compare
        // chronologically.
        if ($appliedFrom > $appliedTo) {
            $day = $from ?? $to;

            return [$day, $day, "The date given does not overlap {$defaultWindow}, so only {$day} is shown."];
        }

        $note = match (true) {
            $from === null && $to === null => null,
            $from === null => "No From date given; showing from {$appliedFrom} (default window: {$defaultWindow}).",
            $to === null => "No To date given; showing up to {$appliedTo} (default window: {$defaultWindow}).",
            default => null,
        };

        return [$appliedFrom, $appliedTo, $note];
    }
}
