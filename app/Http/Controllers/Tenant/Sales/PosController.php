<?php

namespace App\Http\Controllers\Tenant\Sales;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\CompanySetting;
use App\Models\Customer;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Store;
use Illuminate\Database\Eloquent\Builder;
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
    /**
     * Party ledgers are never a settlement account - see
     * SaleController::settlementAccounts() for the full reasoning. Duplicated
     * here rather than shared, because the two controllers are owned by
     * different route files and neither should reach into the other.
     */
    private const PARTY_SUBGROUPS = ['Sundry Debtors', 'Sundry Creditors', 'Sales Agents'];

    public function index(): Response
    {
        $settings = CompanySetting::current();

        $items = Item::query()->where('is_active', true)->orderBy('name')
            ->get(['id', 'name', 'unit', 'is_vatable', 'is_stockable', 'item_category_id', 'sale_rate', 'image_path', 'barcode']);

        $stock = Item::currentStockByItem($items->pluck('id')->all());

        $items = $items->map(function (Item $item) use ($stock) {
            // An exact 4dp string, not a float: the cashier's screen compares
            // this against cart quantities through the shared money module,
            // which never accepts a float (CONTRACTS C8).
            $item->current_stock = $item->is_stockable
                ? ($stock[$item->id] ?? null)?->toString()
                : null;

            return $item;
        });

        return Inertia::render('Tenant/Sales/Pos', [
            'customers' => Customer::query()->orderBy('name')->get(['id', 'name', 'mobile_no']),
            'items' => $items,
            'categories' => ItemCategory::query()->where('is_active', true)->orderBy('name')->get(['id', 'name']),
            'bankAccounts' => $this->settlementAccounts(),
            'tdsAccounts' => $this->settlementAccounts(),
            'stores' => Store::where('is_active', true)->orderBy('name')->get(['id', 'name']),
            // POS defaults every new cart to the walk-in customer (audit
            // section 3 "Sales") - a quick counter sale usually has nobody
            // to name.
            'walkInCustomerId' => Customer::walkIn()?->id,
            'invoiceSettings' => [
                'default_vat_rate' => $settings->default_vat_rate,
                'default_store_id' => $settings->default_store_id,
                'sale_full_enabled' => (bool) $settings->sale_full_enabled,
                'sale_abbreviated_enabled' => (bool) $settings->sale_abbreviated_enabled,
                'sale_pan_enabled' => (bool) $settings->sale_pan_enabled,
            ],
        ]);
    }

    /**
     * Cash, bank and TDS ledgers: every balance-sheet account that is not a
     * party ledger.
     *
     * @return Collection<int, Account>
     */
    private function settlementAccounts(): Collection
    {
        return Account::query()
            ->where(function (Builder $query) {
                $query->whereHas('group.accountHead', fn (Builder $head) => $head->where('is_profit_and_loss', false))
                    ->orWhereHas('subgroup.accountGroup.accountHead', fn (Builder $head) => $head->where('is_profit_and_loss', false));
            })
            ->whereDoesntHave('subgroup', fn (Builder $subgroup) => $subgroup->whereIn('name', self::PARTY_SUBGROUPS))
            ->orderBy('name')
            ->get(['id', 'code', 'name']);
    }
}
