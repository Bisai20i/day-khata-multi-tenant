<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemStockMovement;
use App\Models\Store;
use Illuminate\Support\Collection;
use Inertia\Inertia;
use Inertia\Response;

/**
 * POS / walk-in quick-sale screen. Frontend-only: the actual sale is
 * submitted through the existing `POST /sales` route
 * (App\Http\Controllers\Tenant\Sales\SaleController::store()), so this
 * controller only needs to hand the page the same reference lists
 * SaleController::index() already provides, plus item categories for the
 * page's category filter row and each stockable item's current on-hand
 * quantity so the item tiles can show a stock badge and warn the cashier
 * when a cart's quantity would exceed it (mirroring the legacy POS's
 * "Total in all carts exceeds available stock" warning).
 */
class PosController extends Controller
{
    public function index(): Response
    {
        $stockByItem = $this->currentStockByItem();

        $items = Item::query()->where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'unit', 'is_vatable', 'is_stockable', 'item_category_id', 'sale_rate', 'image_path', 'barcode'])
            ->map(function (Item $item) use ($stockByItem) {
                $item->current_stock = $item->is_stockable ? round($stockByItem->get($item->id, 0.0), 4) : null;

                return $item;
            });

        return Inertia::render('Tenant/Sales/Pos', [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'mobile_no']),
            'items' => $items,
            'categories' => ItemCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'accounts' => Account::query()->orderBy('name')->get(['id', 'code', 'name']),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
        ]);
    }

    /**
     * Net on-hand quantity per item, across every store, keyed by item_id.
     * A single bulk query + in-memory grouping (same signed-sum-by-direction
     * approach as Item::currentStock()) rather than one query per item, since
     * this runs over the whole active catalog on every POS page load.
     *
     * @return Collection<int, float>
     */
    private function currentStockByItem(): Collection
    {
        return ItemStockMovement::query()
            ->where('cancelled', false)
            ->get(['item_id', 'quantity', 'movement_type'])
            ->groupBy('item_id')
            ->map(fn (Collection $movements) => (float) $movements->sum(
                fn (ItemStockMovement $movement) => (float) $movement->quantity * $movement->movement_type->direction(),
            ));
    }
}
